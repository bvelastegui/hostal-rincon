<?php

namespace App\Http\Controllers;

use App\Http\Requests\ConfirmCashMovementRequest;
use App\Http\Requests\StoreCashMovementRequest;
use App\Models\Advance;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CashMovement;
use App\Models\CashOpening;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashMovementController extends Controller
{
    private const array BANK_RELATIONS = ['voucherBank:id,name', 'bankAccount:id,bank_id,name', 'bankAccount.bank:id,name'];

    public function index(Request $request): Response
    {
        $filters = $request->validate(['date' => ['nullable', 'date_format:Y-m-d']]);
        $date = $filters['date'] ?? now('America/Guayaquil')->toDateString();
        $canManageDeposits = Gate::allows('manage-deposits');

        return Inertia::render('CashMovements', [
            'date' => $date,
            'canManageDeposits' => $canManageDeposits,
            'balances' => $this->balances($canManageDeposits),
            'movements' => CashMovement::query()->where('occurred_on', $date)
                ->where(function ($query) use ($canManageDeposits): void {
                    $query->where('kind', '!=', 'deposit');

                    if ($canManageDeposits) {
                        $query->orWhereNotNull('confirmed_at')->orWhereNotNull('returned_at');
                    }
                })
                ->with(self::BANK_RELATIONS)
                ->latest('id')->paginate(20)->withQueryString()->through($this->movementData(...)),
            'depositsInTransit' => $canManageDeposits
                ? CashMovement::query()->where('kind', 'deposit')->whereNull('confirmed_at')->whereNull('returned_at')
                    ->with(self::BANK_RELATIONS)->oldest('id')->get()->map($this->movementData(...))
                : [],
        ]);
    }

    public function create(Request $request): Response
    {
        $kind = $request->validate(['kind' => ['nullable', 'in:income,expense,deposit']])['kind'] ?? 'income';

        if ($kind === 'deposit') {
            Gate::authorize('manage-deposits');
        }

        return Inertia::render('CashMovementForm', [
            'kind' => $kind,
            'requestId' => (string) Str::uuid(),
            'movement' => null,
            'banks' => $kind === 'deposit' ? [] : $this->bankAccounts(),
            'originBanks' => $kind === 'income' ? Bank::query()->active()->orderBy('name')->get(['id', 'name']) : [],
            'responsibleUsers' => $kind === 'deposit' ? User::query()->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function edit(CashMovement $movement): Response|RedirectResponse
    {
        if ($movement->kind === 'deposit') {
            Gate::authorize('manage-deposits');
        }

        if ($movement->confirmed_at !== null || $movement->returned_at !== null) {
            return to_route('movements.index', ['date' => $movement->occurred_on]);
        }

        return Inertia::render('CashMovementForm', [
            'kind' => $movement->kind,
            'requestId' => null,
            'movement' => $this->movementData($movement->load(self::BANK_RELATIONS)),
            'banks' => $movement->kind === 'deposit' ? $this->bankAccounts() : [],
            'originBanks' => [],
            'responsibleUsers' => [],
        ]);
    }

    public function store(StoreCashMovementRequest $request): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $kind = $data['kind'];
        $method = $kind === 'deposit' ? 'cash' : $data['payment_method'];
        $transfer = $method === 'transfer';
        $confirm = $kind === 'expense' || ($kind === 'income' && (! $transfer || $request->boolean('confirm')));
        $attributes = [
            'kind' => $kind,
            'payment_method' => $method,
            'amount_cents' => $request->amountCents(),
            'description' => $data['description'] ?? 'Retiro para depósito',
            'bank_account_id' => $transfer ? (int) $data['bank_account_id'] : null,
            'voucher' => $transfer ? ($data['voucher'] ?? null) : null,
            'responsible_id' => isset($data['responsible_id']) ? (int) $data['responsible_id'] : null,
        ];
        if (isset($data['origin_bank_id'])) {
            $attributes['bank_id'] = (int) $data['origin_bank_id'];
        }

        $fingerprint = hash('sha256', json_encode([$attributes, $confirm], JSON_THROW_ON_ERROR));

        try {
            $movement = DB::transaction(function () use ($attributes, $confirm, $data, $fingerprint, $user): CashMovement {
                $requestId = ['request_id' => $data['request_id']];
                $movement = CashMovement::query()->where($requestId)->first();

                if ($movement === null) {
                    $account = $attributes['bank_account_id'] === null
                        ? null
                        : $this->activeBankAccount($attributes['bank_account_id']);

                    if (isset($attributes['bank_id'])) {
                        $originBank = Bank::query()->lockForUpdate()->findOrFail($attributes['bank_id']);

                        if (! $originBank->is_active) {
                            throw ValidationException::withMessages(['origin_bank_id' => 'Selecciona un banco de origen activo.']);
                        }
                    }

                    $responsible = $attributes['responsible_id'] === null
                        ? null
                        : User::query()->findOrFail($attributes['responsible_id']);

                    $movement = CashMovement::query()->createOrFirst($requestId, [
                        ...$attributes,
                        'request_fingerprint' => $fingerprint,
                        'bank_id' => $attributes['bank_id'] ?? $account?->bank_id,
                        'occurred_on' => now('America/Guayaquil')->toDateString(),
                        'recorded_by' => $user->id,
                        'recorder_name' => $user->name,
                        'responsible_name' => $responsible?->name,
                    ]);
                }

                $this->ensureMatchingRequest($movement, $user, $fingerprint);

                if ($movement->wasRecentlyCreated) {
                    if ($movement->kind === 'deposit') {
                        $movement->cash_opening_id = $this->useCashOpening()->id;
                        $movement->save();
                    } elseif ($confirm) {
                        $this->confirmMovement($movement, $user, $attributes['voucher']);
                    }
                }

                return $movement;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $movement = CashMovement::query()->where('request_id', $data['request_id'])->first();

            if ($movement === null) {
                throw $this->duplicateVoucher();
            }

            $this->ensureMatchingRequest($movement, $user, $fingerprint);
        }

        return $this->saved($movement, match (true) {
            $kind === 'deposit' => 'Retiro registrado como dinero en tránsito.',
            $confirm => 'Movimiento registrado.',
            default => 'Cobro registrado como pendiente.',
        });
    }

    public function update(ConfirmCashMovementRequest $request, CashMovement $movement): RedirectResponse
    {
        $user = $request->user();
        $voucher = $request->validated('voucher');
        $accountId = $request->integer('bank_account_id');

        try {
            DB::transaction(function () use ($movement, $user, $voucher, $accountId): void {
                $lockedMovement = CashMovement::query()->lockForUpdate()->findOrFail($movement->id);

                if ($lockedMovement->returned_at !== null) {
                    throw ValidationException::withMessages(['movement' => 'El efectivo de este retiro ya volvió a caja.']);
                }

                if ($lockedMovement->confirmed_at === null) {
                    $account = $lockedMovement->kind === 'deposit' ? $this->activeBankAccount($accountId) : null;
                    $this->confirmMovement($lockedMovement, $user, $voucher ?? $lockedMovement->voucher, $account);
                }
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw $this->duplicateVoucher();
        }

        return $this->saved($movement, $movement->kind === 'deposit' ? 'Depósito confirmado.' : 'Cobro confirmado.');
    }

    public function returnToCash(Request $request, CashMovement $movement): RedirectResponse
    {
        abort_unless($movement->kind === 'deposit', 404);

        DB::transaction(function () use ($movement, $request): void {
            $lockedMovement = CashMovement::query()->lockForUpdate()->findOrFail($movement->id);

            if ($lockedMovement->confirmed_at !== null) {
                throw ValidationException::withMessages(['movement' => 'El depósito ya fue acreditado en el banco.']);
            }

            if ($lockedMovement->returned_at === null) {
                $lockedMovement->returned_at = now();
                $lockedMovement->returned_by = $request->user()->id;
                $lockedMovement->save();
            }
        }, attempts: 3);

        return $this->saved($movement, 'El dinero en tránsito volvió a efectivo.');
    }

    private function confirmMovement(CashMovement $movement, User $user, ?string $voucher, ?BankAccount $account = null): void
    {
        if ($movement->kind === 'deposit' || $movement->payment_method === 'transfer') {
            if ($voucher === null) {
                throw ValidationException::withMessages(['voucher' => 'Ingresa el número de voucher para confirmar el movimiento bancario.']);
            }

            $account ??= BankAccount::query()->whereKey($movement->bank_account_id)->lockForUpdate()->firstOrFail();

            if ($account->opening_locked_at === null) {
                $account->opening_locked_at = now();
                $account->save();
            }

            $movement->voucher = $voucher;
            if ($movement->kind === 'deposit') {
                $movement->bank_id = $account->bank_id;
            }
            $movement->bank_account_id = $account->id;
        } else {
            $movement->cash_opening_id = $this->useCashOpening()->id;
        }

        $movement->confirmed_at = now();
        $movement->confirmed_by = $user->id;
        $movement->save();
    }

    private function useCashOpening(): CashOpening
    {
        $opening = CashOpening::query()->lockForUpdate()->sole();

        if ($opening->opening_balance_cents === null) {
            throw ValidationException::withMessages(['amount' => 'La administradora debe registrar el saldo inicial de efectivo.']);
        }

        $opening->markUsed();

        return $opening;
    }

    private function activeBankAccount(int $id): BankAccount
    {
        $account = BankAccount::query()->lockForUpdate()->findOrFail($id);
        $bank = Bank::query()->lockForUpdate()->findOrFail($account->bank_id);

        if (! $bank->is_active) {
            throw ValidationException::withMessages(['bank_account_id' => 'Selecciona una cuenta de un banco activo.']);
        }

        return $account;
    }

    /** @return list<array{id: int, name: string}> */
    private function bankAccounts(): array
    {
        $accounts = BankAccount::query()->whereRelation('bank', 'is_active', true)
            ->with('bank:id,name')->orderBy('name')->get()
            ->map(fn (BankAccount $account): array => [
                'id' => $account->id,
                'name' => $account->bank->name.' · '.$account->name,
            ])->all();

        return array_values($accounts);
    }

    /** @return array{cash: int|null, bank: int, transit: int|null, total: int|null} */
    private function balances(bool $includeTransit): array
    {
        return DB::transaction(function () use ($includeTransit): array {
            $opening = CashOpening::query()->sole();
            $totals = CashMovement::query()->whereNull('returned_at')
                ->selectRaw('kind, payment_method,
                    SUM(CASE WHEN confirmed_at IS NOT NULL THEN amount_cents ELSE 0 END) as confirmed_cents,
                    SUM(CASE WHEN confirmed_at IS NULL THEN amount_cents ELSE 0 END) as pending_cents')
                ->groupBy('kind', 'payment_method')->get()
                ->mapWithKeys(fn (CashMovement $movement): array => [
                    $movement->kind.':'.$movement->payment_method => [
                        'confirmed' => (int) $movement->getAttribute('confirmed_cents'),
                        'pending' => (int) $movement->getAttribute('pending_cents'),
                    ],
                ]);

            $confirmed = fn (string $key): int => $totals->get($key)['confirmed'] ?? 0;
            $deposited = $confirmed('deposit:cash');
            $transit = $totals->get('deposit:cash')['pending'] ?? 0;
            $bank = (int) BankAccount::query()->sum('opening_balance_cents')
                + $confirmed('income:transfer') - $confirmed('expense:transfer') + $deposited;
            $cash = $opening->opening_balance_cents === null ? null : $opening->opening_balance_cents
                + $confirmed('income:cash') - $confirmed('expense:cash') - $deposited - $transit
                - (int) Advance::query()->where('cash_opening_id', $opening->id)->sum('amount_cents');

            return [
                'cash' => $cash,
                'bank' => $bank,
                'transit' => $includeTransit ? $transit : null,
                'total' => $cash === null ? null : $cash + $bank + ($includeTransit ? $transit : 0),
            ];
        }, attempts: 3);
    }

    /** @return array<string, mixed> */
    private function movementData(CashMovement $movement): array
    {
        return [
            'id' => $movement->id,
            'kind' => $movement->kind,
            'paymentMethod' => $movement->payment_method,
            'amountCents' => $movement->amount_cents,
            'description' => $movement->description,
            'date' => $movement->occurred_on,
            'originBankName' => $movement->kind === 'income' ? $movement->voucherBank?->name : null,
            'accountBankName' => $movement->bankAccount?->bank->name,
            'accountName' => $movement->bankAccount?->name,
            'voucher' => $movement->voucher,
            'responsibleName' => $movement->kind === 'deposit' ? $movement->responsible_name : null,
            'recorderName' => $movement->recorder_name,
            'confirmedOn' => $movement->confirmed_at?->setTimezone('America/Guayaquil')->toDateString(),
            'confirmed' => $movement->confirmed_at !== null,
            'returnedOn' => $movement->returned_at?->setTimezone('America/Guayaquil')->toDateString(),
        ];
    }

    private function ensureMatchingRequest(CashMovement $movement, User $user, string $fingerprint): void
    {
        if ($movement->recorded_by !== $user->id || $movement->request_fingerprint !== $fingerprint) {
            throw ValidationException::withMessages(['request_id' => 'La solicitud ya se utilizó. Abre un formulario nuevo.']);
        }
    }

    private function duplicateVoucher(): ValidationException
    {
        return ValidationException::withMessages(['voucher' => 'El voucher ya está registrado para el banco que lo emitió.']);
    }

    private function saved(CashMovement $movement, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('movements.index', ['date' => $movement->occurred_on]);
    }
}
