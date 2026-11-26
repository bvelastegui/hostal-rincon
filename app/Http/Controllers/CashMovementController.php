<?php

namespace App\Http\Controllers;

use App\Actions\CalculateCashBalances;
use App\Actions\ConfirmCashMovement;
use App\Actions\CorrectCashMovement;
use App\Actions\GroupCashMovementHistory;
use App\Actions\PresentCashMovement;
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
    public function __construct(
        private ConfirmCashMovement $confirmation,
        private CorrectCashMovement $corrections,
        private PresentCashMovement $presentMovement,
    ) {}

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
            'status' => ['nullable', 'in:pending,confirmed,in_transit,returned,voided'],
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

        $period = clone $scope;
        if ($explicitRange) {
            $period->whereBetween('occurred_on', [$date, $dateTo]);
        } elseif ($date !== null) {
            $period->where(fn (Builder $query) => $query->where('occurred_on', $date)->orWhere('kind', 'deposit'));
        }

        $filtered = $this->filterMovements(clone $period, $filters, $user->is_admin);
        $history = new GroupCashMovementHistory($period);
        $roots = $history->roots($filtered, isset($filters['status']) || isset($filters['source']) || isset($filters['recorded_by']));
        $records = clone $roots;

        if ($date !== null) {
            if (! $explicitRange) {
                $records->where('occurred_on', $date);
            }

            $records->where(fn ($query) => $query
                ->where('kind', '!=', 'deposit')->orWhereNotNull('confirmed_at')
                ->orWhereNotNull('returned_at')->orWhereNotNull('voided_at'));
        }

        $relations = $user->is_admin ? [...PresentCashMovement::BANK_RELATIONS, 'replacement:id,replaces_movement_id'] : PresentCashMovement::BANK_RELATIONS;
        $records->with($relations)->latest('id');
        $lastClosing = $user->is_admin ? $this->corrections->lastClosing($shift) : null;
        $serialize = fn (CashMovement $movement): array => $this->presentMovement->handle($movement, $user,
            $user->is_admin && $this->corrections->blockedReason($movement, $shift, $lastClosing) === null);

        if ($user->is_admin) {
            $page = $records->paginate(self::TABLE_PAGE_SIZE)->onEachSide(1)->withQueryString();
            $movements = $page->setCollection($history->serialize($page->getCollection(), $relations, $serialize));
        } else {
            $movements = ['data' => $history->serialize($records->get(), $relations, $serialize)];
        }

        $transit = null;
        if ($canManageDeposits) {
            $transitPage = $roots->withStatus('in_transit')->with($relations)->oldest('id')
                ->paginate(self::TABLE_PAGE_SIZE, pageName: 'transit_page')->onEachSide(1)->withQueryString();
            $transit = $transitPage->setCollection($history->serialize($transitPage->getCollection(), $relations, $serialize));
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
            'balances' => $user->is_admin
                ? $calculateCashBalances->handle(includeTransit: true)
                : $calculateCashBalances->forOperator($user->id, $visibleShiftId),
            'movements' => $movements,
            'depositsInTransit' => $transit,
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

        return $query->withStatus($filters['status'] ?? null);
    }

    public function create(Request $request, CalculateCashBalances $calculateCashBalances): Response
    {
        $restriction = CashShift::movementRestriction($request->user(), CashShift::active());
        abort_if($restriction !== null, 403, $restriction ?? '');

        $kind = $request->validate(['kind' => ['nullable', 'in:income,expense,deposit']])['kind'] ?? 'income';

        if ($kind === 'deposit') {
            Gate::authorize('manage-deposits');
        }

        return $this->newMovementForm($kind, $calculateCashBalances);
    }

    public function correction(CashMovement $movement, CalculateCashBalances $balances): Response
    {
        $shift = CashShift::active();
        $blocked = $this->corrections->blockedReason($movement, $shift, $this->corrections->lastClosing($shift));
        abort_if($blocked !== null, 403, $blocked ?? '');

        return $this->newMovementForm($movement->kind, $balances, $movement);
    }

    private function newMovementForm(string $kind, CalculateCashBalances $balances, ?CashMovement $original = null): Response
    {
        $cash = $kind === 'deposit' ? $balances->handle(includeTransit: true)['cash'] : null;

        return Inertia::render('CashMovementForm', [
            'kind' => $kind,
            'requestId' => (string) Str::uuid(),
            'movement' => null,
            'replacement' => $original === null ? null : [
                'id' => $original->id,
                'date' => $original->occurred_on,
                'description' => $original->description,
                'paymentMethod' => $original->payment_method,
                'amount' => number_format($original->amount_cents / 100, 2, ',', ''),
                'accountId' => $original->bank_account_id,
                'originBankId' => $original->kind === 'income' ? $original->bank_id : null,
                'voucher' => $original->voucher,
                'responsibleId' => $original->responsible_id,
                'confirmed' => $original->confirmed_at !== null,
                'isSensitive' => $original->isSensitive(),
            ],
            'depositCashCents' => $cash === null ? null : $cash + ($original->amount_cents ?? 0),
            'banks' => $kind !== 'deposit' || $original?->confirmed_at !== null ? $this->confirmation->bankAccounts() : [],
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

        if ($movement->confirmed_at !== null || $movement->returned_at !== null || $movement->voided_at !== null) {
            return to_route('movements.index', ['date' => $movement->occurred_on]);
        }

        return Inertia::render('CashMovementForm', [
            'kind' => $movement->kind,
            'requestId' => null,
            'movement' => $this->presentMovement->handle($movement->load(PresentCashMovement::BANK_RELATIONS), $user),
            'banks' => $movement->kind === 'deposit' ? $this->confirmation->bankAccounts() : [],
            'originBanks' => [],
            'responsibleUsers' => [],
        ]);
    }

    public function replace(StoreCashMovementRequest $request, CalculateCashBalances $balances, CashMovement $movement): RedirectResponse
    {
        return $this->recordMovement($request, $balances, $movement);
    }

    public function store(StoreCashMovementRequest $request, CalculateCashBalances $balances): RedirectResponse
    {
        return $this->recordMovement($request, $balances);
    }

    private function recordMovement(StoreCashMovementRequest $request, CalculateCashBalances $calculateCashBalances, ?CashMovement $original = null): RedirectResponse
    {
        $user = $request->user();
        $data = $request->validated();
        $kind = $data['kind'];
        $method = $kind === 'deposit' ? 'cash' : $data['payment_method'];
        $transfer = $method === 'transfer';
        $confirmDeposit = $kind === 'deposit' && $original?->confirmed_at !== null;
        $confirm = $confirmDeposit || $kind === 'expense' || ($kind === 'income' && (! $transfer || $request->boolean('confirm')));
        $attributes = [
            'kind' => $kind,
            'payment_method' => $method,
            'amount_cents' => $request->amountCents(),
            'description' => $data['description'] ?? 'Retiro para depósito',
            'bank_account_id' => $transfer || $confirmDeposit ? (int) $data['bank_account_id'] : null,
            'voucher' => $transfer || $confirmDeposit ? ($data['voucher'] ?? null) : null,
            'responsible_id' => isset($data['responsible_id']) ? (int) $data['responsible_id'] : null,
        ];
        if (isset($data['origin_bank_id'])) {
            $attributes['bank_id'] = (int) $data['origin_bank_id'];
        }

        if ($data['is_sensitive'] ?? false) {
            $attributes['is_sensitive'] = true;
        }

        $payload = [$attributes, $confirm];
        if ($original !== null) {
            $payload[] = [$original->id, $data['void_reason']];
        }
        $fingerprint = hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR));

        try {
            $movement = DB::transaction(function () use ($attributes, $confirm, $confirmDeposit, $data, $fingerprint, $user, $calculateCashBalances, $original): CashMovement {
                $opening = CashOpening::query()->lockForUpdate()->sole();
                $requestId = ['request_id' => $data['request_id']];
                $movement = CashMovement::query()->where($requestId)->first();

                if ($movement !== null) {
                    $this->ensureMatchingRequest($movement, $user, $fingerprint);

                    return $movement;
                }

                $corrected = null;
                $previousCredit = null;
                $shift = $this->authorizeMovement($user);

                if ($original !== null) {
                    $corrected = CashMovement::query()->lockForUpdate()->findOrFail($original->id);

                    if ($corrected->kind === 'deposit' && $confirmDeposit !== ($corrected->confirmed_at !== null)) {
                        throw ValidationException::withMessages(['movement' => 'El depósito cambió de estado. Vuelve a abrir el formulario.']);
                    }

                    $previousCredit = $this->corrections->creditedBalance($corrected);
                    $this->corrections->void($corrected, $user, $data['void_reason'], $shift);
                }

                if ($attributes['kind'] === 'deposit') {
                    $this->confirmation->useCashOpening();
                    $availableCash = $calculateCashBalances->handle(includeTransit: true)['cash'] ?? 0;

                    if ($attributes['amount_cents'] > $availableCash) {
                        $available = number_format(max(0, $availableCash) / 100, 2, ',', '.');

                        throw ValidationException::withMessages(['amount' => "El retiro supera el efectivo disponible en caja: USD {$available}."]);
                    }
                }

                $account = $attributes['bank_account_id'] === null
                    ? null
                    : $this->confirmation->activeBankAccount($attributes['bank_account_id']);

                if ($attributes['kind'] === 'expense') {
                    $calculateCashBalances->ensureFundsAvailable($attributes['amount_cents'], $account);
                }

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
                    'replaces_movement_id' => $corrected?->id,
                    'occurred_on' => $corrected->occurred_on ?? now('America/Guayaquil')->toDateString(),
                    'recorded_by' => $user->id,
                    'cash_shift_id' => $shift?->id,
                    'recorder_name' => $user->name,
                    'responsible_name' => $responsible?->name,
                ]);

                $this->ensureMatchingRequest($movement, $user, $fingerprint);

                if ($movement->wasRecentlyCreated) {
                    if ($movement->kind === 'deposit') {
                        $movement->cash_opening_id = $opening->id;
                        $movement->save();
                    }

                    if ($confirm) {
                        $this->confirmation->handle($movement, $user, $attributes['voucher'], $confirmDeposit ? $account : null);
                    }

                    if ($corrected !== null) {
                        $this->corrections->ensureCreditStillCovered($corrected, $previousCredit);
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
            $original !== null => 'Movimiento anulado y reemplazado.',
            $kind === 'deposit' => 'Retiro registrado como dinero en tránsito.',
            $confirm => 'Movimiento registrado.',
            default => 'Cobro registrado como pendiente.',
        });
    }

    public function annul(Request $request, CashMovement $movement): RedirectResponse
    {
        $data = $request->validate(['void_reason' => ['required', 'string', 'max:255']], [], [
            'void_reason' => 'motivo de anulación',
        ]);

        DB::transaction(function () use ($request, $movement, $data): void {
            CashOpening::query()->lockForUpdate()->sole();
            $record = CashMovement::query()->lockForUpdate()->findOrFail($movement->id);
            $user = $request->user();

            if ($record->voided_by === $user->id && $record->void_reason === $data['void_reason'] && $record->voided_at !== null) {
                return;
            }

            $before = $this->corrections->creditedBalance($record);
            $this->corrections->void($record, $user, $data['void_reason'], $this->authorizeMovement($user));
            $this->corrections->ensureCreditStillCovered($record, $before);
        }, attempts: 3);

        return $this->saved($movement, 'Movimiento anulado.');
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
                $this->ensureMovementIsActive($lockedMovement);

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
        DB::transaction(function () use ($movement, $sensitive): void {
            $record = CashMovement::query()->lockForUpdate()->findOrFail($movement->id);
            $this->ensureMovementIsActive($record);
            $record->is_sensitive = $sensitive;
            $record->save();
        }, attempts: 3);

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
            $this->ensureMovementIsActive($lockedMovement);

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

    private function ensureMovementIsActive(CashMovement $movement): void
    {
        if ($movement->voided_at !== null) {
            throw ValidationException::withMessages(['movement' => 'El movimiento ya está anulado.']);
        }
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
