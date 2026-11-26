<?php

namespace App\Http\Controllers;

use App\Actions\CalculateCashBalances;
use App\Actions\ConfirmCashMovement;
use App\Actions\CorrectCashMovement;
use App\Actions\CreateEmployee;
use App\Http\Requests\SaveAdvanceEmployeeRequest;
use App\Http\Requests\StoreAdvanceRequest;
use App\Models\Advance;
use App\Models\BankAccount;
use App\Models\CashClosing;
use App\Models\CashMovement;
use App\Models\CashOpening;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdvanceController extends Controller
{
    public function __construct(
        private CorrectCashMovement $corrections,
        private ConfirmCashMovement $confirmation,
        private CalculateCashBalances $balances,
    ) {}

    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'employee' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'in:pending,deducted,voided'],
            'month' => ['nullable', 'date_format:Y-m'],
            'payment_method' => ['nullable', 'in:cash,transfer'],
        ]);

        $employees = User::query()->operators()->orderBy('name')
            ->withSum('pendingAdvances as pending_cents', 'amount_cents')
            ->withMax('pendingAdvances as latest_pending_id', 'id')
            ->get(['id', 'name', 'advance_limit_cents']);

        $shift = CashShift::active();
        $closing = $this->corrections->lastClosing($shift);
        $history = Advance::query()
            ->when($filters['employee'] ?? null, fn (Builder $query, int|string $id) => $query->where('employee_id', $id))
            ->when($filters['month'] ?? null, fn (Builder $query, string $month) => $query
                ->where('issued_on', '>=', $month.'-01')
                ->where('issued_on', '<', Carbon::createFromFormat('!Y-m', $month)->addMonth()->startOfMonth()->toDateString()))
            ->when(($filters['status'] ?? null) === 'pending', fn (Builder $query) => $query->pending())
            ->when(($filters['status'] ?? null) === 'deducted', fn (Builder $query) => $query->effective()->whereNotNull('deducted_at'))
            ->when(($filters['status'] ?? null) === 'voided', fn (Builder $query) => $query->whereHas('cashMovement', fn (Builder $movement) => $movement->whereNotNull('voided_at')))
            ->when(($filters['payment_method'] ?? null) === 'cash', fn (Builder $query) => $query->where(fn (Builder $query) => $query
                ->whereNull('cash_movement_id')->orWhereRelation('cashMovement', 'payment_method', 'cash')))
            ->when(($filters['payment_method'] ?? null) === 'transfer', fn (Builder $query) => $query->whereRelation('cashMovement', 'payment_method', 'transfer'))
            ->with(['cashMovement:id,kind,payment_method,bank_account_id,voucher,confirmed_at,voided_voucher,voided_at,void_reason,voided_by_name,replaces_movement_id,returned_at,cash_shift_id,created_at', 'cashMovement.bankAccount:id,name,bank_id', 'cashMovement.bankAccount.bank:id,name'])
            ->orderByDesc('issued_on')->orderByDesc('id')
            ->paginate(self::TABLE_PAGE_SIZE, ['id', 'employee_id', 'employee_name', 'amount_cents', 'issued_on', 'responsible', 'note', 'recorder_name', 'deducted_at', 'deductor_name', 'cash_movement_id'])
            ->onEachSide(1)->withQueryString()
            ->through(fn (Advance $advance): array => [
                ...$advance->toArray(),
                'canCorrect' => $this->correctionBlocked($advance, $shift, $closing) === null,
            ]);

        return Inertia::render('Advances', [
            'employees' => $employees->map(fn (User $employee): array => [
                'id' => $employee->id,
                'name' => $employee->name,
                'limitCents' => $employee->advance_limit_cents,
                'pendingCents' => (int) $employee->getAttribute('pending_cents'),
                'latestPendingId' => $employee->getAttribute('latest_pending_id') === null
                    ? null
                    : (int) $employee->getAttribute('latest_pending_id'),
            ]),
            'history' => $history,
            'filters' => $filters,
        ]);
    }

    public function createEmployee(): Response
    {
        return Inertia::render('AdvanceEmployeeForm', [
            'employee' => null,
            'limitCents' => User::DEFAULT_ADVANCE_LIMIT_CENTS,
        ]);
    }

    public function editEmployee(User $employee): Response
    {
        abort_if($employee->is_admin, 404);

        return Inertia::render('AdvanceEmployeeForm', [
            'employee' => $employee->only(['id', 'name', 'email', 'username']),
            'limitCents' => $employee->advance_limit_cents,
        ]);
    }

    public function storeEmployee(SaveAdvanceEmployeeRequest $request, CreateEmployee $createEmployee): RedirectResponse
    {
        $data = $request->validated();
        $createEmployee(
            $data['name'],
            $data['password'],
            email: $data['email'] ?? null,
            username: $data['username'] ?? null,
            advanceLimitCents: $request->advanceLimitCents(),
        );

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Empleado creado.']);

        return to_route('advances.index');
    }

    public function updateEmployee(SaveAdvanceEmployeeRequest $request, User $employee): RedirectResponse
    {
        $data = $request->validated();
        $employee->fill(Arr::only($data, ['name', 'email', 'username']));

        $limit = $request->advanceLimitCents();
        if ($limit !== null) {
            $employee->advance_limit_cents = $limit;
        }

        if ($request->filled('password')) {
            $employee->password = Hash::make($data['password']);
        }
        $employee->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Datos del empleado actualizados.']);

        return to_route('advances.index');
    }

    public function create(User $employee): Response
    {
        abort_if($employee->is_admin, 404);

        return $this->entryForm($employee);
    }

    public function correction(Advance $advance): Response
    {
        $shift = CashShift::active();
        $blocked = $this->correctionBlocked($advance, $shift, $this->corrections->lastClosing($shift));
        abort_if($blocked !== null, 403, $blocked ?? '');

        return $this->entryForm($advance->employee()->operators()->firstOrFail(), $advance);
    }

    private function entryForm(User $employee, ?Advance $advance = null): Response
    {
        $balances = $this->balances->handle(includeTransit: true, includeAccounts: true);
        $bankBalances = array_column($balances['accounts'] ?? [], 'balanceCents', 'id');
        $original = $advance?->cashMovement;
        $creditedCents = $advance->amount_cents ?? 0;
        $cash = $balances['cash'];
        if ($original?->bank_account_id !== null) {
            $bankBalances[$original->bank_account_id] = ($bankBalances[$original->bank_account_id] ?? 0) + $creditedCents;
        } elseif ($original !== null && $cash !== null) {
            $cash += $creditedCents;
        }

        return Inertia::render('AdvanceCreate', [
            'employee' => ['id' => $employee->id, 'name' => $employee->name],
            'pendingCents' => max(0, (int) $employee->pendingAdvances()->sum('amount_cents') - $creditedCents),
            'limitCents' => $employee->advance_limit_cents,
            'requestToken' => (string) Str::uuid(),
            'cashBalanceCents' => $cash,
            'bankAccounts' => array_map(fn (array $account): array => [
                ...$account,
                'balanceCents' => $bankBalances[$account['id']] ?? 0,
            ], $this->confirmation->bankAccounts()),
            'replacement' => $advance === null ? null : [
                'id' => $advance->id,
                'amountCents' => $advance->amount_cents,
                'note' => $advance->note,
                'paymentMethod' => $original?->payment_method,
                'accountId' => $original?->bank_account_id,
                'voucher' => $original?->voucher,
            ],
        ]);
    }

    public function store(StoreAdvanceRequest $request, User $employee): RedirectResponse
    {
        return $this->record($request, $employee->id);
    }

    public function replace(StoreAdvanceRequest $request, Advance $advance): RedirectResponse
    {
        abort_if($advance->employee_id === null, 404);

        return $this->record($request, $advance->employee_id, $advance);
    }

    private function record(StoreAdvanceRequest $request, int $employeeId, ?Advance $advance = null): RedirectResponse
    {
        $data = $request->validated();
        $amountCents = $request->amountCents();
        $user = $request->user();
        $method = $data['payment_method'];
        $accountId = isset($data['bank_account_id']) ? (int) $data['bank_account_id'] : null;
        $voucher = $data['voucher'] ?? null;

        try {
            DB::transaction(function () use ($employeeId, $advance, $data, $amountCents, $user, $method, $accountId, $voucher): void {
                CashOpening::query()->lockForUpdate()->sole();
                $employee = User::query()->lockForUpdate()->operators()->findOrFail($employeeId);
                $previous = Advance::query()->with('cashMovement')->where('request_id', $data['request_id'])->first();

                if ($previous !== null) {
                    if ($previous->employee_id === $employee->id
                        && $previous->recorded_by === $user->id
                        && $previous->amount_cents === $amountCents
                        && $previous->note === ($data['note'] ?? null)
                        && ($previous->cashMovement->payment_method ?? 'cash') === $method
                        && $previous->cashMovement?->bank_account_id === $accountId
                        && ($previous->cashMovement->voucher ?? $previous->cashMovement?->voided_voucher) === $voucher
                        && $previous->cashMovement?->replaces_movement_id === $advance?->cash_movement_id
                        && ($advance === null || ($advance->cash_movement_id !== null
                            && $advance->cashMovement?->void_reason === $data['void_reason']))) {
                        return;
                    }

                    throw ValidationException::withMessages(['request_id' => 'Esta solicitud ya se utilizó. Cierra el formulario y vuelve a abrirlo.']);
                }

                $shift = CashShift::active();
                $original = null;
                $correctedAdvance = null;
                if ($advance !== null) {
                    $correctedAdvance = $employee->advances()->lockForUpdate()->findOrFail($advance->id);
                    $original = $correctedAdvance->cashMovement()->lockForUpdate()->first();
                    $correctedAdvance->setRelation('cashMovement', $original);
                    $blocked = $this->correctionBlocked($correctedAdvance, $shift, $this->corrections->lastClosing($shift));
                    if ($blocked !== null) {
                        throw ValidationException::withMessages(['advance' => $blocked]);
                    }

                    if ($original !== null) {
                        $this->corrections->void($original, $user, $data['void_reason'], $shift, forAdvance: true);
                    }
                }

                $pendingCents = (int) $employee->pendingAdvances()->sum('amount_cents');
                $remainingCents = $employee->remainingAdvanceCents($pendingCents);
                if ($amountCents > $remainingCents) {
                    $available = number_format($remainingCents / 100, 2, ',', '.');

                    throw ValidationException::withMessages(['amount' => "El monto supera el disponible de USD {$available}."]);
                }

                $account = $accountId === null ? null : $this->confirmation->activeBankAccount($accountId);
                $this->balances->ensureFundsAvailable($amountCents, $account);
                $date = $correctedAdvance?->issued_on->toDateString() ?? now('America/Guayaquil')->toDateString();
                $movement = CashMovement::query()->create([
                    'request_id' => (string) Str::uuid(),
                    'request_fingerprint' => hash('sha256', $data['request_id']),
                    'kind' => 'advance',
                    'replaces_movement_id' => $original?->id,
                    'is_sensitive' => true,
                    'payment_method' => $method,
                    'amount_cents' => $amountCents,
                    'description' => Str::limit('Adelanto de sueldo · '.$employee->name, 255, ''),
                    'occurred_on' => $date,
                    'bank_id' => $account?->bank_id,
                    'bank_account_id' => $account?->id,
                    'recorded_by' => $user->id,
                    'recorder_name' => $user->name,
                    'cash_shift_id' => $shift?->delivered_cash_cents === null ? $shift?->id : null,
                ]);
                $this->confirmation->handle($movement, $user, $voucher, $account);

                $employee->advances()->create([
                    'cash_opening_id' => $movement->cash_opening_id,
                    'cash_movement_id' => $movement->id,
                    'request_id' => $data['request_id'],
                    'amount_cents' => $amountCents,
                    'employee_name' => $employee->name,
                    'issued_on' => $date,
                    'responsible' => 'Administración',
                    'note' => $data['note'] ?? null,
                    'recorded_by' => $user->id,
                    'recorder_name' => $user->name,
                ]);
            }, attempts: 3);
        } catch (UniqueConstraintViolationException $exception) {
            if ($accountId === null || ! CashMovement::query()->where('bank_id', BankAccount::query()->whereKey($accountId)->select('bank_id'))->where('voucher', $voucher)->exists()) {
                throw $exception;
            }

            throw $this->confirmation->duplicateVoucher();
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $advance === null ? 'Adelanto registrado.' : 'Adelanto corregido.',
        ]);

        return to_route('advances.index');
    }

    private function correctionBlocked(Advance $advance, ?CashShift $shift, ?CashClosing $closing): ?string
    {
        if ($advance->deducted_at !== null) {
            return 'El adelanto ya fue descontado.';
        }

        $movement = $advance->cashMovement;
        if ($advance->employee_id === null || $movement === null || $movement->kind !== 'advance' || $movement->confirmed_at === null) {
            return 'El adelanto no tiene un egreso confirmado que se pueda corregir.';
        }

        return $this->corrections->blockedReason($movement, $shift, $closing, forAdvance: true);
    }

    public function deduct(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate(['latest_pending_id' => ['required', 'integer', 'min:1']]);
        $user = $request->user();

        DB::transaction(function () use ($employee, $data, $user): void {
            $employee = User::query()->lockForUpdate()->findOrFail($employee->id);
            $pending = $employee->pendingAdvances();
            $latestPendingId = $pending->max('id');

            if ($latestPendingId === null) {
                return;
            }

            if ((int) $latestPendingId !== (int) $data['latest_pending_id']) {
                throw ValidationException::withMessages([
                    'latest_pending_id' => 'Cambió el saldo pendiente. Cierra el formulario y vuelve a revisar el total.',
                ]);
            }

            $pending->update([
                'deducted_at' => now(),
                'deducted_by' => $user->id,
                'deductor_name' => $user->name,
            ]);
        }, attempts: 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Adelantos marcados como descontados.']);

        return to_route('advances.index');
    }
}
