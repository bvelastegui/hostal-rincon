<?php

namespace App\Http\Controllers;

use App\Actions\CalculateCashBalances;
use App\Http\Requests\StoreCashClosingRequest;
use App\Models\CashClosing;
use App\Models\CashOpening;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class CashClosingController extends Controller
{
    public function index(Request $request, CalculateCashBalances $balances): Response
    {
        $shift = CashShift::active();
        $isOwner = $shift?->responsible_id === $request->user()->id;
        $canClose = Gate::allows('manage-cash-closings');
        $filters = $canClose ? $request->validate([
            'date_from' => ['nullable', 'required_with:date_to', 'date_format:Y-m-d'],
            'date_to' => ['nullable', 'required_with:date_from', 'date_format:Y-m-d', 'after_or_equal:date_from'],
            'type' => ['nullable', 'in:independent,delivery,receipt,administrative,opening'],
            'responsible_id' => ['nullable', 'integer', 'min:1'],
            'difference' => ['nullable', 'in:shortage,surplus,balanced'],
            'resolution' => ['nullable', 'in:pending,resolved'],
        ], [], ['date_from' => 'fecha inicial', 'date_to' => 'fecha final']) : [];

        return Inertia::render('CashClosings', [
            'expectedCashCents' => $balances->handle(true)['cash'],
            'requestId' => (string) Str::uuid(),
            'canClose' => $canClose,
            'canReceive' => $shift?->delivered_cash_cents !== null && ! $isOwner,
            'canDeliver' => $isOwner && $shift->delivered_cash_cents === null,
            'isOwner' => $isOwner,
            'startReceiving' => $request->query('accion') === 'recibir',
            'activeShift' => $shift === null ? null : [
                'id' => $shift->id,
                'responsible' => $shift->responsible_name,
                'openingCashCents' => $shift->opening_cash_cents,
                'deliveredCashCents' => $shift->delivered_cash_cents,
            ],
            'responsibles' => $canClose && $shift === null
                ? User::query()->orderBy('name')->get(['id', 'name'])
                : [],
            ...($canClose ? $this->history($filters) : ['closings' => null]),
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    private function history(array $filters): array
    {
        $query = CashClosing::query()->with(['closedBy:id,name', 'cashShift:id,responsible_id,responsible_name', 'shortageResolvedBy:id,name']);

        if (isset($filters['date_from'], $filters['date_to'])) {
            $query->whereBetween('closed_on', [$filters['date_from'], $filters['date_to']]);
        }

        if (isset($filters['type'])) {
            $query->whereIn('type', $filters['type'] === 'receipt' ? ['receipt', 'shift'] : [$filters['type']]);
        }

        if (isset($filters['responsible_id'])) {
            $query->whereRelation('cashShift', 'responsible_id', $filters['responsible_id']);
        }

        match ($filters['difference'] ?? null) {
            'shortage' => $query->shortages(),
            'surplus' => $query->where('difference_cents', '>', 0),
            'balanced' => $query->where('difference_cents', 0),
            default => null,
        };

        if (isset($filters['resolution'])) {
            match ($filters['resolution']) {
                'pending' => $query->unresolvedShortages(),
                'resolved' => $query->shortages()->whereNotNull('shortage_resolved_at'),
                default => null,
            };
        }

        return [
            'historyFilters' => [
                'date_from' => $filters['date_from'] ?? null,
                'date_to' => $filters['date_to'] ?? null,
                'type' => $filters['type'] ?? null,
                'responsible_id' => isset($filters['responsible_id']) ? (int) $filters['responsible_id'] : null,
                'difference' => $filters['difference'] ?? null,
                'resolution' => $filters['resolution'] ?? null,
            ],
            'historyResponsibles' => User::query()->whereIn('id', CashShift::query()
                ->whereIn('id', CashClosing::query()->select('cash_shift_id'))->select('responsible_id'))
                ->orderBy('name')->get(['id', 'name']),
            'closings' => $query->latest('id')->simplePaginate(15)->withQueryString()->through(fn (CashClosing $closing): array => [
                'id' => $closing->id,
                'shiftId' => $closing->cash_shift_id,
                'responsible' => $closing->cashShift?->responsible_name,
                'type' => $closing->type,
                'closedAt' => $closing->closed_at->setTimezone('America/Guayaquil')->format('d/m/Y H:i'),
                'closedBy' => $closing->closedBy?->name,
                'confirmedByIsResponsible' => $closing->closed_by === $closing->cashShift?->responsible_id,
                'expectedCashCents' => $closing->expected_cash_cents,
                'countedCashCents' => $closing->counted_cash_cents,
                'differenceCents' => $closing->difference_cents,
                'adjustmentCents' => $closing->cash_adjustment_cents,
                'note' => $closing->note,
                'resolution' => $closing->shortage_resolved_at === null ? null : [
                    'at' => $closing->shortage_resolved_at->setTimezone('America/Guayaquil')->format('d/m/Y H:i'),
                    'by' => $closing->shortageResolvedBy?->name,
                    'method' => $closing->shortage_resolution_method,
                    'note' => $closing->shortage_resolution_note,
                ],
            ]),
        ];
    }

    public function resolveShortage(Request $request, CashClosing $closing): RedirectResponse
    {
        $data = $request->validate([
            'method' => ['required', 'in:deduction,other'],
            'note' => ['required', 'string', 'max:500'],
        ], [], ['method' => 'forma de resolución', 'note' => 'nota de resolución']);

        $changed = DB::transaction(function () use ($request, $closing, $data): bool {
            $record = CashClosing::query()->lockForUpdate()->findOrFail($closing->id);

            if ($record->difference_cents >= 0) {
                throw ValidationException::withMessages(['method' => 'Solo se pueden resolver registros con faltante.']);
            }

            if ($record->shortage_resolved_at !== null) {
                return false;
            }

            $record->update([
                'shortage_resolved_at' => now(),
                'shortage_resolved_by' => $request->user()->id,
                'shortage_resolution_method' => $data['method'],
                'shortage_resolution_note' => $data['note'],
            ]);

            return true;
        }, attempts: 3);

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => $changed ? 'Faltante marcado como resuelto.' : 'El faltante ya estaba resuelto.',
        ]);

        return back();
    }

    public function startShift(Request $request, CalculateCashBalances $balances): RedirectResponse
    {
        $request->validate(['responsible_id' => ['required', 'integer', 'exists:users,id']], [], [
            'responsible_id' => 'responsable de caja',
        ]);

        DB::transaction(function () use ($request, $balances): void {
            CashOpening::query()->lockForUpdate()->sole();

            if (CashShift::active() !== null) {
                throw ValidationException::withMessages(['responsible_id' => 'Ya hay un turno de caja activo.']);
            }

            $responsible = User::query()->findOrFail($request->integer('responsible_id'));
            $cash = $balances->handle(true)['cash'];

            if ($cash === null || $cash < 0) {
                throw ValidationException::withMessages(['responsible_id' => 'Configura y revisa el saldo de efectivo antes de iniciar el turno.']);
            }

            $this->openShift($responsible, $request->user()->id, $cash);
        }, attempts: 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Turno de caja iniciado.']);

        return to_route('cash-closings.index');
    }

    public function store(StoreCashClosingRequest $request, CalculateCashBalances $balances): RedirectResponse
    {
        return $this->recordCount($request, $balances, 'independent');
    }

    public function openOwnShift(StoreCashClosingRequest $request, CalculateCashBalances $balances): RedirectResponse
    {
        return $this->recordCount($request, $balances, 'opening');
    }

    public function receiveShift(StoreCashClosingRequest $request, CalculateCashBalances $balances): RedirectResponse
    {
        return $this->recordCount($request, $balances, 'receipt');
    }

    public function administrativeClose(StoreCashClosingRequest $request, CalculateCashBalances $balances): RedirectResponse
    {
        return $this->recordCount($request, $balances, 'administrative');
    }

    public function prepareHandoff(StoreCashClosingRequest $request, CalculateCashBalances $balances): RedirectResponse
    {
        return $this->recordCount($request, $balances, 'delivery');
    }

    private function signOutForHandoff(Request $request): RedirectResponse
    {
        Auth::logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        redirect()->setIntendedUrl(route('cash-closings.index', ['accion' => 'recibir']));

        return to_route('login')
            ->with('handoff', 'Para recibir la caja, inicia sesión con tu propia cuenta.');
    }

    private function recordCount(StoreCashClosingRequest $request, CalculateCashBalances $balances, string $type): RedirectResponse
    {
        $requestId = $request->validated('request_id');
        $shiftId = $request->filled('cash_shift_id') ? $request->integer('cash_shift_id') : null;
        $expected = $request->integer('expected_cash_cents');
        $counted = $request->countedCashCents();
        $note = $request->validated('note');
        $user = $request->user();

        DB::transaction(function () use ($balances, $requestId, $shiftId, $expected, $counted, $note, $user, $type): void {
            $opening = CashOpening::query()->lockForUpdate()->sole();
            $existing = CashClosing::query()->where('request_id', $requestId)->first();

            if ($existing !== null) {
                if ($existing->closed_by !== $user->id || ($type !== 'opening' && $existing->cash_shift_id !== $shiftId)
                    || $existing->type !== $type || $existing->expected_cash_cents !== $expected
                    || $existing->counted_cash_cents !== $counted || $existing->note !== $note) {
                    throw ValidationException::withMessages(['request_id' => 'Esta solicitud ya se utilizó. Abre un formulario nuevo.']);
                }

                return;
            }

            $shift = CashShift::active();

            if ($type === 'opening' && $shift !== null) {
                throw ValidationException::withMessages(['cash_shift_id' => 'Ya hay un turno de caja activo.']);
            }

            if ($shift?->id !== $shiftId || ($shift === null && ! in_array($type, ['independent', 'opening'], true))) {
                throw ValidationException::withMessages(['cash_shift_id' => 'El turno activo cambió. Revisa quién tiene la caja antes de confirmar.']);
            }

            if ($type === 'administrative' && $note === null) {
                throw ValidationException::withMessages(['note' => 'Ingresa el motivo del cierre administrativo.']);
            }

            if ($type === 'delivery' && ($shift?->responsible_id !== $user->id || $shift->delivered_cash_cents !== null)) {
                throw ValidationException::withMessages(['cash_shift_id' => 'Solo el responsable del turno abierto puede entregar la caja.']);
            }

            if ($type === 'receipt' && ($shift?->delivered_cash_cents === null || $shift->responsible_id === $user->id)) {
                throw ValidationException::withMessages(['cash_shift_id' => 'El responsable debe registrar la entrega antes de que otra persona pueda recibir la caja.']);
            }

            $current = $balances->handle(true)['cash'];

            if ($current === null) {
                throw ValidationException::withMessages(['expected_cash_cents' => 'Configura el saldo inicial de efectivo para registrar el cierre.']);
            }

            if ($current !== $expected) {
                throw ValidationException::withMessages(['expected_cash_cents' => 'El saldo esperado cambió. Revisa el saldo actualizado antes de confirmar.']);
            }

            $difference = $counted - $current;

            if ($difference !== 0 && $note === null) {
                throw ValidationException::withMessages(['note' => 'Ingresa una observación para registrar el faltante o sobrante.']);
            }

            $closedAt = now();
            $countShift = $type === 'opening' ? $this->openShift($user, $user->id, $counted) : $shift;
            CashClosing::query()->create([
                'request_id' => $requestId,
                'type' => $type,
                'cash_shift_id' => $countShift?->id,
                'closed_on' => $closedAt->setTimezone('America/Guayaquil')->toDateString(),
                'expected_cash_cents' => $current,
                'counted_cash_cents' => $counted,
                'difference_cents' => $difference,
                'cash_adjustment_cents' => $difference,
                'note' => $note,
                'closed_by' => $user->id,
                'closed_at' => $closedAt,
            ]);
            $opening->markUsed();

            if ($type === 'delivery') {
                $shift->update(['delivered_cash_cents' => $counted]);
            } elseif ($type === 'receipt' || $type === 'administrative') {
                $shift->update(['ended_at' => $closedAt]);

                if ($type === 'receipt') {
                    $this->openShift($user, $user->id, $counted);
                }
            }
        }, attempts: 3);

        if ($type === 'delivery') {
            return $this->signOutForHandoff($request);
        }

        Inertia::flash('toast', [
            'type' => 'success',
            'message' => match ($type) {
                'receipt' => 'Recibiste la caja e iniciaste un nuevo turno.',
                'administrative' => 'Turno finalizado mediante cierre administrativo.',
                'opening' => 'Caja abierta. Puedes registrar los movimientos de tu turno.',
                default => 'Corte de caja registrado.',
            },
        ]);

        return to_route($type === 'receipt' ? 'movements.index' : 'cash-closings.index');
    }

    private function openShift(User $responsible, int $startedBy, int $cash): CashShift
    {
        return CashShift::query()->create([
            'responsible_id' => $responsible->id,
            'responsible_name' => $responsible->name,
            'started_by' => $startedBy,
            'started_at' => now(),
            'opening_cash_cents' => $cash,
        ]);
    }
}
