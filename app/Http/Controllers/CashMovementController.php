<?php

namespace App\Http\Controllers;

use App\Actions\CalculateCashBalances;
use App\Actions\ConfirmCashMovement;
use App\Http\Requests\ConfirmCashMovementRequest;
use App\Http\Requests\StoreCashMovementRequest;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CashMovement;
use App\Models\CashOpening;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashMovementController extends Controller
{
    public function __construct(private ConfirmCashMovement $confirmation) {}

    private const array BANK_RELATIONS = ['voucherBank:id,name', 'bankAccount:id,bank_id,name', 'bankAccount.bank:id,name'];

    public function index(Request $request, CalculateCashBalances $calculateCashBalances): Response
    {
        $user = $request->user();
        $canManageDeposits = Gate::allows('manage-deposits');
        $kinds = $user->is_admin ? array_keys(CashMovement::OPERATOR_KIND) : array_values(array_unique(CashMovement::OPERATOR_KIND));
        $filters = $request->validate([
            'date' => [$canManageDeposits ? 'nullable' : 'exclude', 'date_format:Y-m-d'],
            'date_from' => $canManageDeposits ? ['nullable', 'required_with:date_to', 'date_format:Y-m-d'] : ['exclude'],
            'date_to' => $canManageDeposits ? ['nullable', 'required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'] : ['exclude'],
            'kind' => ['nullable', Rule::in($kinds)],
            'recorded_by' => ['nullable', 'integer', 'min:1'],
            'status' => ['nullable', 'in:pending,confirmed,in_transit,returned'],
            'source' => ['nullable', 'regex:/^(cash|bank:[1-9][0-9]{0,17})$/'],
        ], [], ['date_from' => 'fecha inicial', 'date_to' => 'fecha final']);
        $date = $canManageDeposits ? ($filters['date_from'] ?? $filters['date'] ?? now('America/Guayaquil')->toDateString()) : null;
        $dateTo = $filters['date_to'] ?? $date;
        $explicitRange = isset($filters['date_from']);
        $shift = CashShift::active();
        $restriction = CashShift::movementRestriction($user, $shift);
        $visibleShiftId = $restriction === null ? $shift?->id : null;
        $scope = CashMovement::query();

        if (! $user->is_admin) {
            $scope->where('cash_shift_id', $visibleShiftId ?? 0)
                ->where(fn ($query) => $query->whereIn('kind', ['income', 'expense', 'advance'])->orWhere('is_sensitive', true));
        }

        $filtered = $this->filterMovements(clone $scope, $filters, $user->is_admin);

        if ($explicitRange) {
            $filtered->whereBetween('occurred_on', [$date, $dateTo]);
        }

        $records = clone $filtered;

        if ($date !== null) {
            if (! $explicitRange) {
                $records->where('occurred_on', $date);
            }

            $records->where(fn ($query) => $query
                ->where('kind', '!=', 'deposit')->orWhereNotNull('confirmed_at')->orWhereNotNull('returned_at'));
        }

        return Inertia::render('CashMovements', [
            'date' => $date,
            'dateRange' => $date === null ? null : ['from' => $date, 'to' => $dateTo, 'explicit' => $explicitRange],
            'shiftId' => $canManageDeposits ? null : $visibleShiftId,
            'canManageDeposits' => $canManageDeposits,
            'movementRestriction' => $restriction,
            'filters' => [
                'kind' => $filters['kind'] ?? null,
                'recorded_by' => isset($filters['recorded_by']) ? (int) $filters['recorded_by'] : null,
                'status' => $filters['status'] ?? null,
                'source' => $filters['source'] ?? null,
            ],
            'filterOptions' => [
                'recorders' => User::query()->whereIn('id', (clone $scope)->select('recorded_by'))
                    ->orderBy('name')->get(['id', 'name']),
                'accounts' => BankAccount::query()->whereIn('id', (clone $scope)->select('bank_account_id'))
                    ->with('bank:id,name')->orderBy('name')->get(['id', 'bank_id', 'name'])
                    ->map(fn (BankAccount $account): array => ['id' => $account->id, 'name' => $account->displayName()]),
            ],
            'balances' => $calculateCashBalances->handle($canManageDeposits),
            'movements' => $records
                ->with(self::BANK_RELATIONS)
                ->latest('id')->paginate(20)->withQueryString()->through(fn (CashMovement $movement): array => $this->movementData($movement, $user)),
            'depositsInTransit' => $canManageDeposits
                ? $filtered->where('kind', 'deposit')->whereNull('confirmed_at')->whereNull('returned_at')
                    ->with(self::BANK_RELATIONS)->oldest('id')->get()->map(fn (CashMovement $movement): array => $this->movementData($movement, $user))
                : [],
        ]);
    }

    /**
     * @param  Builder<CashMovement>  $query
     * @param  array<string, mixed>  $filters
     * @return Builder<CashMovement>
     */
    private function filterMovements(Builder $query, array $filters, bool $isAdmin): Builder
    {
        if (isset($filters['kind'])) {
            $kinds = $isAdmin ? [$filters['kind']] : array_keys(CashMovement::OPERATOR_KIND, $filters['kind'], true);
            $query->whereIn('kind', $kinds);
        }

        if (isset($filters['recorded_by'])) {
            $query->where('recorded_by', $filters['recorded_by']);
        }

        $source = $filters['source'] ?? null;
        if ($source === 'cash') {
            $query->where('payment_method', 'cash')
                ->when(! $isAdmin, fn (Builder $query) => $query->whereNull('bank_account_id'));
        } elseif (is_string($source)) {
            $query->where('bank_account_id', (int) substr($source, 5));
        }

        return match ($filters['status'] ?? null) {
            'confirmed' => $query->whereNotNull('confirmed_at')->whereNull('returned_at'),
            'returned' => $query->whereNotNull('returned_at'),
            'pending' => $query->where('kind', '!=', 'deposit')->whereNull('confirmed_at')->whereNull('returned_at'),
            'in_transit' => $query->where('kind', 'deposit')->whereNull('confirmed_at')->whereNull('returned_at'),
            default => $query,
        };
    }

    public function create(Request $request): Response
    {
        $restriction = CashShift::movementRestriction($request->user(), CashShift::active());
        abort_if($restriction !== null, 403, $restriction ?? '');

        $kind = $request->validate(['kind' => ['nullable', 'in:income,expense,deposit']])['kind'] ?? 'income';

        if ($kind === 'deposit') {
            Gate::authorize('manage-deposits');
        }

        return Inertia::render('CashMovementForm', [
            'kind' => $kind,
            'requestId' => (string) Str::uuid(),
            'movement' => null,
            'banks' => $kind === 'deposit' ? [] : $this->confirmation->bankAccounts(),
            'originBanks' => $kind === 'income' ? Bank::query()->active()->orderBy('name')->get(['id', 'name']) : [],
            'responsibleUsers' => $kind === 'deposit' ? User::query()->orderBy('name')->get(['id', 'name']) : [],
        ]);
    }

    public function edit(Request $request, CashMovement $movement): Response|RedirectResponse
    {
        abort_if($movement->kind === 'advance', 404);

        $user = $request->user();
        $shift = CashShift::active();
        $restriction = CashShift::movementRestriction($user, $shift);
        abort_if($restriction !== null, 403, $restriction ?? '');
        $this->ensureMovementInShift($user, $shift, $movement);

        if ($movement->kind === 'deposit') {
            Gate::authorize('manage-deposits');
        }

        if ($movement->confirmed_at !== null || $movement->returned_at !== null) {
            return to_route('movements.index', ['date' => $movement->occurred_on]);
        }

        return Inertia::render('CashMovementForm', [
            'kind' => $movement->kind,
            'requestId' => null,
            'movement' => $this->movementData($movement->load(self::BANK_RELATIONS), $user),
            'banks' => $movement->kind === 'deposit' ? $this->confirmation->bankAccounts() : [],
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

        if ($data['is_sensitive'] ?? false) {
            $attributes['is_sensitive'] = true;
        }

        $fingerprint = hash('sha256', json_encode([$attributes, $confirm], JSON_THROW_ON_ERROR));

        try {
            $movement = DB::transaction(function () use ($attributes, $confirm, $data, $fingerprint, $user): CashMovement {
                CashOpening::query()->lockForUpdate()->sole();
                $requestId = ['request_id' => $data['request_id']];
                $movement = CashMovement::query()->where($requestId)->first();

                if ($movement === null) {
                    $shift = $this->authorizeMovement($user);
                    $account = $attributes['bank_account_id'] === null
                        ? null
                        : $this->confirmation->activeBankAccount($attributes['bank_account_id']);

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
                        'cash_shift_id' => $shift?->id,
                        'recorder_name' => $user->name,
                        'responsible_name' => $responsible?->name,
                    ]);
                }

                $this->ensureMatchingRequest($movement, $user, $fingerprint);

                if ($movement->wasRecentlyCreated) {
                    if ($movement->kind === 'deposit') {
                        $movement->cash_opening_id = $this->confirmation->useCashOpening()->id;
                        $movement->save();
                    } elseif ($confirm) {
                        $this->confirmation->handle($movement, $user, $attributes['voucher']);
                    }
                }

                return $movement;
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            $movement = CashMovement::query()->where('request_id', $data['request_id'])->first();

            if ($movement === null) {
                throw $this->confirmation->duplicateVoucher();
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
        abort_if($movement->kind === 'advance', 404);

        $user = $request->user();
        $voucher = $request->validated('voucher');
        $accountId = $request->integer('bank_account_id');

        try {
            DB::transaction(function () use ($movement, $user, $voucher, $accountId): void {
                CashOpening::query()->lockForUpdate()->sole();
                $shift = $this->authorizeMovement($user);
                $lockedMovement = CashMovement::query()->lockForUpdate()->findOrFail($movement->id);
                $this->ensureMovementInShift($user, $shift, $lockedMovement);

                if ($lockedMovement->returned_at !== null) {
                    throw ValidationException::withMessages(['movement' => 'El efectivo de este retiro ya volvió a caja.']);
                }

                if ($lockedMovement->confirmed_at === null) {
                    $account = $lockedMovement->kind === 'deposit' ? $this->confirmation->activeBankAccount($accountId) : null;
                    $this->confirmation->handle($lockedMovement, $user, $voucher ?? $lockedMovement->voucher, $account);
                }
            }, attempts: 3);
        } catch (UniqueConstraintViolationException) {
            throw $this->confirmation->duplicateVoucher();
        }

        return $this->saved($movement, $movement->kind === 'deposit' ? 'Depósito confirmado.' : 'Cobro confirmado.');
    }

    public function updateSensitivity(Request $request, CashMovement $movement): RedirectResponse
    {
        abort_if($movement->kind === 'advance', 404);
        $data = $request->validate(['is_sensitive' => ['required', 'boolean']]);
        $sensitive = (bool) $data['is_sensitive'];
        CashMovement::query()->whereKey($movement->id)->update(['is_sensitive' => $sensitive]);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $sensitive ? 'Movimiento marcado como sensible.' : 'Se quitó la marca de movimiento sensible.',
        ]);

        return back(fallback: route('movements.index'));
    }

    public function returnToCash(Request $request, CashMovement $movement): RedirectResponse
    {
        abort_unless($movement->kind === 'deposit', 404);

        DB::transaction(function () use ($movement, $request): void {
            CashOpening::query()->lockForUpdate()->sole();
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

    private function authorizeMovement(User $user): ?CashShift
    {
        $shift = CashShift::active();
        $restriction = CashShift::movementRestriction($user, $shift);

        if ($restriction !== null) {
            throw ValidationException::withMessages(['movement' => $restriction]);
        }

        return $shift?->delivered_cash_cents === null ? $shift : null;
    }

    private function ensureMovementInShift(User $user, ?CashShift $shift, CashMovement $movement): void
    {
        abort_if(! $movement->detailsVisibleTo($user)
            || (! $user->is_admin && ($shift === null || $movement->cash_shift_id !== $shift->id)), 404);
    }

    /** @return array<string, mixed> */
    private function movementData(CashMovement $movement, User $user): array
    {
        if (! $movement->detailsVisibleTo($user)) {
            return [
                'id' => $movement->id,
                'redacted' => true,
                'kind' => CashMovement::OPERATOR_KIND[$movement->kind] ?? 'transaction',
                'recorderName' => $movement->recorder_name,
                'date' => $movement->occurred_on,
                'status' => $movement->status(),
                'account' => $movement->bankAccount?->displayName() ?? ($movement->payment_method === 'cash' ? 'Efectivo' : null),
                'amountCents' => in_array($movement->kind, ['expense', 'advance'], true)
                    ? -$movement->amount_cents : $movement->amount_cents,
            ];
        }

        return [
            'id' => $movement->id,
            'redacted' => false,
            'isSensitive' => $movement->isSensitive(),
            'shiftId' => $movement->cash_shift_id,
            'kind' => $movement->kind,
            'paymentMethod' => $movement->payment_method,
            'amountCents' => $movement->amount_cents,
            'description' => $movement->description,
            'date' => $movement->occurred_on,
            'status' => $movement->status(),
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

    private function saved(CashMovement $movement, string $message): RedirectResponse
    {
        Inertia::flash('toast', ['type' => 'success', 'message' => $message]);

        return to_route('movements.index', ['date' => $movement->occurred_on]);
    }
}
