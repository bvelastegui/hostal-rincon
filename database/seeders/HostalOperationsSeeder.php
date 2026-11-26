<?php

namespace Database\Seeders;

use App\Actions\CalculateCashBalances;
use App\Models\Advance;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CashClosing;
use App\Models\CashMovement;
use App\Models\CashOpening;
use App\Models\CashShift;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use LogicException;

class HostalOperationsSeeder extends Seeder
{
    private const string TIMEZONE = 'America/Guayaquil';

    private const int MAX_CASH_CENTS = 20_000;

    public const string DEMO_PASSWORD = 'RinconDemo123!';

    public const string FIXTURE_VOUCHER = 'DEMO-MISMO-VOUCHER';

    private User $admin;

    private CashOpening $opening;

    private BankAccount $operatingAccount;

    private CalculateCashBalances $balances;

    public function run(CalculateCashBalances $balances): void
    {
        if (! app()->environment('local', 'staging', 'testing')) {
            throw new LogicException('La simulación de Hostal Rincón solo se permite en entornos de demostración.');
        }

        $this->balances = $balances;
        $now = CarbonImmutable::now(self::TIMEZONE);
        $today = $now->startOfDay();
        $firstDay = $today->subMonthsNoOverflow(2);
        $staging = app()->environment('staging');
        $demoPassword = $staging ? config('auth.staging_demo_employee_password') : self::DEMO_PASSWORD;

        if (! is_string($demoPassword) || ($staging && (Str::length($demoPassword) < 16 || $demoPassword === self::DEMO_PASSWORD))) {
            throw new LogicException('Configura STAGING_DEMO_EMPLOYEE_PASSWORD con al menos 16 caracteres y una contraseña exclusiva de QA.');
        }

        $password = Hash::make($demoPassword);
        $employees = DB::transaction(function () use ($now, $today, $firstDay, $password, $staging): array {
            $this->opening = CashOpening::query()->lockForUpdate()->sole();
            $this->ensureEmptyLedger();
            $first = $firstDay;
            $this->admin = $staging
                ? User::query()->where('email', SuperAdminSeeder::EMAIL)->where('is_admin', true)->sole()
                : $this->user('Administración de demostración', 'admin', $password, $first, isAdmin: true);
            $team = [
                $this->user('Ana Torres', 'ana', $password, $first),
                $this->user('Carlos Ruiz', 'carlos', $password, $first),
            ];
            $this->opening->forceFill([
                'opening_balance_cents' => 12_000,
                'opening_locked_at' => $first->utc(),
            ])->save();
            $main = $this->account('Banco Demo Norte', 'Cuenta operativa', 120_000);
            $this->operatingAccount = $main;
            $backup = $this->account('Banco Demo Sur', 'Cuenta de reserva', 0);
            $archived = $this->account('Banco Demo Histórico', 'Cuenta anterior', 15_000);

            $shift = $this->openShift($team[0], $first);
            $delayedTransfer = null;
            $changeDay = intdiv((int) $firstDay->diffInDays($today), 2);

            for ($start = $first, $slot = 0; $start->addHours(8) <= $now; $start = $start->addHours(8), $slot++) {
                $boundary = $start->addHours(8);
                $day = $start->startOfDay();
                $offset = (int) $firstDay->diffInDays($day);
                $operator = $team[0]->id === $shift->responsible_id ? $team[0] : $team[1];
                $incoming = $team[0]->id === $operator->id ? $team[1] : $team[0];
                $this->workShift($operator, $shift, $start, $slot, $backup);

                if ($start->hour !== 8) {
                    $shift = $this->handoff($shift, $operator, $incoming, $boundary, $slot, $now);

                    continue;
                }

                if ($offset === 0) {
                    $voucher = self::FIXTURE_VOUCHER;
                    $this->transferIncome($operator, $shift, $day->setTime(10, 0), $main, $backup->bank_id, $voucher);
                    $this->transferIncome($operator, $shift, $day->setTime(10, 15), $backup, $main->bank_id, $voucher);
                    $this->transferIncome($operator, $shift, $day->setTime(10, 30), $archived, $main->bank_id, 'DEMO-HISTORICO');
                    $this->advance($team[0], $day->setTime(11, 0), 15_000, 'Primer adelanto del ciclo salarial');
                }

                if ($offset === 1) {
                    $delayedTransfer = $this->transferIncome($operator, $shift, $day->setTime(10, 0), $main, $backup->bank_id);
                    $this->advance($team[0], $day->setTime(11, 0), 10_000, 'Segundo adelanto del ciclo salarial', $main);
                    $this->advance($team[1], $day->setTime(11, 10), 15_000, 'Primer adelanto del ciclo salarial');
                }

                if ($offset === 2 && $delayedTransfer !== null) {
                    $this->confirm($delayedTransfer, $this->admin, $day->setTime(10, 0), 'DEMO-CONFIRMADO-OTRO-TURNO', $main);
                    $this->advance($team[0], $day->setTime(11, 0), 5_000, 'Completa el límite de USD 300');
                    $this->advance($team[1], $day->setTime(11, 10), 14_999, 'Queda a un centavo del límite', $main);
                }

                if ($offset === 4) {
                    $archived->bank->forceFill(['is_active' => false])->save();
                    $archived->update(['name' => 'Cuenta anterior (archivada)', 'name_key' => 'cuenta anterior (archivada)']);
                }

                if ($offset === 5 || ($offset > 5 && $day->day === 3)) {
                    foreach ($team as $employee) {
                        $this->deductAdvances($employee, $day->setTime(11, 20));
                    }
                }

                if ($day->isLastOfMonth() && $offset > 5) {
                    $this->advance($incoming, $day->setTime(11, 30), 2_000, 'Adelanto de fin de mes descontado en el siguiente ciclo', $main);
                }

                if ($offset > 2 && $offset % 4 === 0) {
                    $this->transferIncome($operator, $shift, $day->setTime(10, 30), $main, $backup->bank_id, 'DEMO-ING-'.$offset);
                    $at = $day->setTime(12, 0);
                    $expense = $this->movement($this->admin, $shift, $at, 'expense', 1_975, 'Pago bancario a proveedor', [
                        'is_sensitive' => true,
                        'payment_method' => 'transfer',
                        'bank_id' => $main->bank_id,
                        'bank_account_id' => $main->id,
                    ]);
                    $this->confirm($expense, $this->admin, $at, 'DEMO-EGR-'.$offset, $main);
                }

                if ($offset % 6 === 3) {
                    $deposit = $this->deposit($operator, $shift, $day->setTime(12, 30), min(6_025, $this->cash()));
                    if ($offset % 12 === 3) {
                        $this->confirm($deposit, $this->admin, $day->setTime(14, 0), 'DEMO-DEP-'.$offset, $main);
                    } else {
                        $this->returnDeposit($deposit, $day->setTime(14, 0));
                    }
                }

                if ($offset === 7) {
                    $deposit = $this->deposit($operator, $shift, $day->setTime(12, 30), $this->cash());
                    $this->count($shift, $this->admin, $day->setTime(13, 0), 'independent', note: 'Conteo en cero: el efectivo está en tránsito');
                    $this->returnDeposit($deposit, $day->setTime(13, 30));
                } elseif ($offset % 2 === 0) {
                    $case = intdiv($offset, 2);
                    $delta = [-500, -275, 320, -125, 0, -900][$case % 6];
                    $resolution = $delta < 0 ? ['deduction', 'other', null][$case % 3] : null;
                    $this->count($shift, $this->admin, $day->setTime(15, 0), 'independent', $delta, resolution: $resolution);
                    if ($offset === 10) {
                        $this->count($shift, $this->admin, $day->setTime(15, 30), 'independent', note: 'Segundo corte del mismo turno');
                    }
                }

                if ($offset === $changeDay) {
                    $shift = $this->replaceEmployee($shift, $operator, $incoming, $boundary, $password);
                    $team = [$incoming, User::query()->findOrFail($shift->responsible_id)];
                } else {
                    $shift = $this->handoff($shift, $operator, $incoming, $boundary, $slot, $now);
                }
            }

            $operator = $team[0]->id === $shift->responsible_id ? $team[0] : $team[1];
            $this->transferIncome($operator, $shift, $now, $backup, $main->bank_id);
            $this->transferIncome($this->admin, $shift, $now, $backup, $main->bank_id, isSensitive: true);

            foreach ($team as $employee) {
                $this->deductAdvances($employee, $now);
                $this->advance($employee, $now, 15_000, 'Primer adelanto del ciclo actual');
            }

            $this->advance($team[0], $now, $team[0]->advance_limit_cents - 15_001, 'Pendiente a un centavo del límite', $main);
            $this->advance($team[1], $now, $team[1]->advance_limit_cents - 15_000, 'Saldo pendiente exactamente en el límite individual', $main);
            $this->cashMovement($operator, $shift, $now, 'income', 8_475, 'Cobro en efectivo del turno actual');
            $this->cashMovement($this->admin, $shift, $now, 'expense', 1_280, 'Compra de insumos registrada por administración', isSensitive: true);
            $this->deposit($operator, $shift, $now, 3_500, isSensitive: true);

            return $team;
        });

        $this->command?->info("Operación ficticia del {$firstDay->toDateString()} al {$today->toDateString()}.");
        $this->command?->line('Administración: '.$this->admin->email);
        $this->command?->line('Empleados vigentes: '.$employees[0]->email.' y '.$employees[1]->email);
        if (! $staging) {
            $this->command?->line('Contraseña de demostración: '.self::DEMO_PASSWORD);
        }
    }

    private function ensureEmptyLedger(): void
    {
        if ($this->opening->opening_balance_cents !== null || Bank::query()->exists() || BankAccount::query()->exists()
            || CashMovement::query()->exists() || CashShift::query()->exists() || CashClosing::query()->exists()
            || Advance::query()->exists() || User::query()->whereLike('email', '%@hostal.test')->exists()) {
            throw new LogicException('Usa una base recién migrada, sin datos de operación. La simulación no se mezcla con datos existentes.');
        }
    }

    private function user(string $name, string $handle, string $password, CarbonImmutable $at, bool $isAdmin = false, int $advanceLimitCents = User::DEFAULT_ADVANCE_LIMIT_CENTS): User
    {
        $user = new User;
        $user->forceFill([
            'name' => $name,
            'email' => $handle.'@hostal.test',
            'password' => $password,
            'email_verified_at' => $at->utc(),
            'is_admin' => $isAdmin,
            'advance_limit_cents' => $advanceLimitCents,
            'created_at' => $at->utc(),
        ])->save();

        return $user;
    }

    private function account(string $bankName, string $name, int $openingCents): BankAccount
    {
        $bank = Bank::query()->create(['name' => $bankName, 'name_key' => Str::lower($bankName)]);

        return BankAccount::query()->create([
            'bank_id' => $bank->id,
            'name' => $name,
            'name_key' => Str::lower($name),
            'opening_balance_cents' => $openingCents,
        ]);
    }

    private function workShift(User $operator, CashShift $shift, CarbonImmutable $start, int $slot, BankAccount $backup): void
    {
        $this->cashMovement($operator, $shift, $start->addHour(), 'income', 3_500 + ($slot % 7) * 625, 'Cobro de hospedaje');
        $this->cashMovement($operator, $shift, $start->addMinutes(90), 'expense', 650 + ($slot % 5) * 150, 'Insumos y limpieza');
        $this->transferIncome($operator, $shift, $start->addMinutes(105), $this->operatingAccount, $backup->bank_id, 'DEMO-TURNO-'.$slot);

        if ($start->hour === 16 && $slot % 4 === 2) {
            $this->cashMovement($operator, $shift, $start->setTime(23, 50), 'income', 2_500, 'Llegada nocturna antes del relevo de medianoche');
        }
    }

    private function cash(): int
    {
        $cash = $this->balances->handle(true)['cash'] ?? throw new LogicException('Falta el saldo inicial de efectivo.');

        if ($cash < 0 || $cash > self::MAX_CASH_CENTS) {
            throw new LogicException('El efectivo de demostración quedó fuera del rango de USD 0 a 200.');
        }

        return $cash;
    }

    private function openShift(User $operator, CarbonImmutable $at, ?int $counted = null): CashShift
    {
        return CashShift::query()->create([
            'responsible_id' => $operator->id,
            'responsible_name' => $operator->name,
            'started_by' => $counted === null ? $this->admin->id : $operator->id,
            'started_at' => $at->utc(),
            'opening_cash_cents' => $counted ?? $this->cash(),
        ]);
    }

    private function handoff(CashShift $shift, User $outgoing, User $incoming, CarbonImmutable $boundary, int $slot, CarbonImmutable $now): CashShift
    {
        $at = $boundary->subMinutes(8);
        $deliveryDelta = $slot % 11 === 8 ? -250 : 0;
        $receiptDelta = $slot % 13 === 8 ? -175 : 0;
        $resolved = $boundary->addMinutes(45) <= $now;
        $delivered = $this->count($shift, $outgoing, $at, 'delivery', $deliveryDelta,
            resolution: $resolved && $deliveryDelta < 0 && $slot % 2 === 0 ? 'deduction' : null);
        $shift->update(['delivered_cash_cents' => $delivered]);

        if ($slot % 22 === 8) {
            $this->cashMovement($this->admin, null, $at->addMinute(), 'income', 700, 'Cobro registrado por administración durante la espera');
        }

        $received = $this->count($shift, $incoming, $boundary, 'receipt', $receiptDelta,
            resolution: $resolved && $receiptDelta < 0 && $slot % 2 === 0 ? 'other' : null);
        $shift->update(['ended_at' => $boundary->utc()]);

        return $this->openShift($incoming, $boundary, $received);
    }

    private function replaceEmployee(CashShift $shift, User $outgoing, User $remaining, CarbonImmutable $at, string $password): CashShift
    {
        $this->count($shift, $this->admin, $at, 'administrative', -300, 'Fin de relación laboral. Cierre excepcional por administración.', 'deduction');
        $shift->update(['ended_at' => $at->utc()]);
        $this->deductAdvances($outgoing, $at);
        $outgoing->forceFill(['password' => Str::random(64), 'remember_token' => null])->save();
        $this->cashMovement($this->admin, null, $at, 'income', 2_400, 'Cobro atendido por administración sin turno abierto');

        $replacement = $this->user('Diana Vega', 'diana', $password, $at, advanceLimitCents: 25_000);
        $this->makeCashRoom($replacement, null, $at, 50);
        $newShift = $this->openShift($replacement, $at, $this->cash() + 50);
        $this->count($newShift, $replacement, $at, 'opening', 50, 'Conteo de apertura por la nueva empleada');
        $this->advance($replacement, $at->addMinutes(10), 8_000, 'Primer adelanto tras la incorporación');
        $this->deductAdvances($remaining, $at->addMinutes(15));

        return $newShift;
    }

    private function count(CashShift $shift, User $actor, CarbonImmutable $at, string $type, int $delta = 0, ?string $note = null, ?string $resolution = null): int
    {
        if ($delta > 0) {
            $this->makeCashRoom($actor, $shift, $at, $delta);
        }

        $expected = $this->cash();
        $delta = max(-$expected, $delta);
        $resolution = $delta < 0 ? $resolution : null;
        $counted = $expected + $delta;

        CashClosing::query()->create([
            'request_id' => (string) Str::uuid(),
            'type' => $type,
            'cash_shift_id' => $shift->id,
            'closed_on' => $at->toDateString(),
            'expected_cash_cents' => $expected,
            'counted_cash_cents' => $counted,
            'difference_cents' => $delta,
            'cash_adjustment_cents' => $delta,
            'note' => $note ?? match (true) {
                $delta < 0 => 'Faltante observado al contar el efectivo',
                $delta > 0 => 'Sobrante observado al contar el efectivo',
                default => null,
            },
            'closed_by' => $actor->id,
            'closed_at' => $at->utc(),
            'shortage_resolved_at' => $resolution === null ? null : $at->addMinutes(45)->utc(),
            'shortage_resolved_by' => $resolution === null ? null : $this->admin->id,
            'shortage_resolution_method' => $resolution,
            'shortage_resolution_note' => match ($resolution) {
                'deduction' => 'Descuento aplicado y documentado fuera del sistema',
                'other' => 'Se revisaron los comprobantes y se documentó el cierre del incidente',
                default => null,
            },
        ]);

        $this->cash();

        return $counted;
    }

    private function advance(User $employee, CarbonImmutable $at, int $amount, string $note, ?BankAccount $account = null): void
    {
        if ($amount > $employee->remainingAdvanceCents((int) $employee->pendingAdvances()->sum('amount_cents'))) {
            throw new LogicException('La simulación intentó superar el límite de adelantos.');
        }

        $shift = CashShift::active();
        if ($account === null) {
            $needed = $amount - $this->cash();

            if ($needed > 0) {
                $this->cashMovement($this->admin, $shift, $at, 'income', $needed, 'Cobro de hospedaje en efectivo');
            }
        } elseif ($amount > $this->balances->bankAccountBalance($account)) {
            throw new LogicException('La cuenta de demostración no tiene saldo para el adelanto.');
        }

        $movement = $this->movement($this->admin, $shift, $at, 'advance', $amount, 'Adelanto de sueldo · '.$employee->name, [
            'is_sensitive' => true,
            'payment_method' => $account === null ? 'cash' : 'transfer',
            'bank_id' => $account?->bank_id,
            'bank_account_id' => $account?->id,
        ]);
        $this->confirm($movement, $this->admin, $at, $account === null ? null : 'DEMO-ADE-'.$movement->id, $account);

        $employee->advances()->create([
            'request_id' => (string) Str::uuid(),
            'cash_opening_id' => $movement->cash_opening_id,
            'cash_movement_id' => $movement->id,
            'employee_name' => $employee->name,
            'issued_on' => $at->toDateString(),
            'amount_cents' => $amount,
            'responsible' => 'Administración',
            'recorded_by' => $this->admin->id,
            'recorder_name' => $this->admin->name,
            'note' => $note,
        ]);
        $this->cash();
    }

    private function deductAdvances(User $employee, CarbonImmutable $at): void
    {
        $employee->pendingAdvances()->update([
            'deducted_at' => $at->utc(),
            'deducted_by' => $this->admin->id,
            'deductor_name' => $this->admin->name,
        ]);
    }

    private function cashMovement(User $actor, ?CashShift $shift, CarbonImmutable $at, string $kind, int $amount, string $description, bool $isSensitive = false): void
    {
        if ($kind === 'income') {
            $this->makeCashRoom($actor, $shift, $at, $amount);
        }

        $movement = $this->movement($actor, $shift, $at, $kind, $amount, $description, ['is_sensitive' => $isSensitive]);
        $this->confirm($movement, $actor, $at);
        $this->cash();
    }

    private function makeCashRoom(User $responsible, ?CashShift $shift, CarbonImmutable $at, int $incoming): void
    {
        if ($incoming > self::MAX_CASH_CENTS) {
            throw new LogicException('Un ingreso de demostración supera el límite de efectivo. Regístralo por transferencia.');
        }

        $cash = $this->cash();
        if ($cash + $incoming > self::MAX_CASH_CENTS) {
            $deposit = $this->deposit($responsible, $shift, $at, $cash - max(0, self::MAX_CASH_CENTS - $incoming - 2_000));
            $this->confirm($deposit, $this->admin, $at, 'DEMO-EXCEDENTE-'.$deposit->id, $this->operatingAccount);
        }
    }

    private function transferIncome(User $actor, CashShift $shift, CarbonImmutable $at, BankAccount $account, int $originId, ?string $voucher = null, bool $isSensitive = false): CashMovement
    {
        $movement = $this->movement($actor, $shift, $at, 'income', 4_750, 'Cobro por transferencia', [
            'is_sensitive' => $isSensitive,
            'payment_method' => 'transfer',
            'bank_id' => $originId,
            'bank_account_id' => $account->id,
        ]);

        if ($voucher !== null) {
            $this->confirm($movement, $actor, $at, $voucher, $account);
        }

        return $movement;
    }

    private function deposit(User $responsible, ?CashShift $shift, CarbonImmutable $at, int $amount, bool $isSensitive = false): CashMovement
    {
        if ($amount <= 0 || $amount > $this->cash()) {
            throw new LogicException('El retiro de demostración debe ser positivo y estar cubierto por el efectivo.');
        }

        return $this->movement($this->admin, $shift, $at, 'deposit', $amount, 'Retiro para depósito', [
            'is_sensitive' => $isSensitive,
            'responsible_id' => $responsible->id,
            'responsible_name' => $responsible->name,
        ]);
    }

    private function returnDeposit(CashMovement $deposit, CarbonImmutable $at): void
    {
        $deposit->forceFill(['returned_at' => $at->utc(), 'returned_by' => $this->admin->id])->save();
        $this->cash();
    }

    /** @param array<string, mixed> $details */
    private function movement(User $actor, ?CashShift $shift, CarbonImmutable $at, string $kind, int $amount, string $description, array $details = []): CashMovement
    {
        $requestId = (string) Str::uuid();
        $method = $details['payment_method'] ?? 'cash';
        $movement = new CashMovement;
        $movement->forceFill([
            'request_id' => $requestId,
            'request_fingerprint' => hash('sha256', $requestId),
            'cash_shift_id' => $shift?->id,
            'cash_opening_id' => $method === 'cash' ? $this->opening->id : null,
            'occurred_on' => $at->toDateString(),
            'created_at' => $at->utc(),
            'kind' => $kind,
            'payment_method' => $method,
            'amount_cents' => $amount,
            'description' => $description,
            'recorded_by' => $actor->id,
            'recorder_name' => $actor->name,
            ...$details,
        ])->save();

        return $movement;
    }

    private function confirm(CashMovement $movement, User $actor, CarbonImmutable $at, ?string $voucher = null, ?BankAccount $account = null): void
    {
        if ($account !== null && $account->opening_locked_at === null) {
            $account->opening_locked_at = $at->utc();
            $account->save();
        }

        if ($account !== null && $movement->getAttribute('kind') === 'deposit') {
            $movement->forceFill(['bank_account_id' => $account->id, 'bank_id' => $account->bank_id]);
        }

        $movement->forceFill(['voucher' => $voucher, 'confirmed_at' => $at->utc(), 'confirmed_by' => $actor->id])->save();
    }
}
