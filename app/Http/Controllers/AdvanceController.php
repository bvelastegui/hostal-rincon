<?php

namespace App\Http\Controllers;

use App\Models\Advance;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdvanceController extends Controller
{
    public function index(Request $request): Response
    {
        $filters = $request->validate([
            'employee' => ['nullable', 'integer', 'exists:users,id'],
            'status' => ['nullable', 'in:pending,deducted'],
        ]);

        $employees = User::query()->operators()->orderBy('name')
            ->withSum(['advances as pending_cents' => fn (Builder $query) => $query->pending()], 'amount_cents')
            ->withMax(['advances as latest_pending_id' => fn (Builder $query) => $query->pending()], 'id')
            ->get(['id', 'name']);

        $history = Advance::query()
            ->when($filters['employee'] ?? null, fn (Builder $query, int|string $id) => $query->where('employee_id', $id))
            ->when(($filters['status'] ?? null) === 'pending', fn (Builder $query) => $query->pending())
            ->when(($filters['status'] ?? null) === 'deducted', fn (Builder $query) => $query->whereNotNull('deducted_at'))
            ->orderByDesc('issued_on')->orderByDesc('id')
            ->paginate(15, ['id', 'employee_id', 'employee_name', 'amount_cents', 'issued_on', 'responsible', 'note', 'recorder_name', 'deducted_at', 'deductor_name'])
            ->withQueryString();

        return Inertia::render('Advances', [
            'employees' => $employees->map(fn (User $employee): array => [
                'id' => $employee->id,
                'name' => $employee->name,
                'pendingCents' => (int) $employee->getAttribute('pending_cents'),
                'latestPendingId' => $employee->getAttribute('latest_pending_id') === null
                    ? null
                    : (int) $employee->getAttribute('latest_pending_id'),
            ]),
            'history' => $history,
            'filters' => $filters,
            'limitCents' => Advance::LIMIT_CENTS,
            'canDeduct' => Gate::allows('deduct-advances'),
        ]);
    }

    public function create(User $employee): Response
    {
        abort_if($employee->is_admin, 404);

        return Inertia::render('AdvanceCreate', [
            'employee' => ['id' => $employee->id, 'name' => $employee->name],
            'pendingCents' => (int) $employee->advances()->pending()->sum('amount_cents'),
            'limitCents' => Advance::LIMIT_CENTS,
            'requestToken' => (string) Str::uuid(),
        ]);
    }

    public function store(Request $request, User $employee): RedirectResponse
    {
        $data = $request->validate([
            'request_id' => ['required', 'uuid'],
            'amount' => ['required', 'numeric', 'decimal:0,2', 'gt:0', 'max:'.(Advance::LIMIT_CENTS / 100)],
            'note' => ['nullable', 'string', 'max:500'],
        ], [
            'amount.required' => 'Ingresa el monto del adelanto.',
            'amount.decimal' => 'Ingresa un monto con hasta dos decimales.',
            'amount.gt' => 'El monto debe ser mayor que cero.',
            'amount.max' => 'El monto supera el límite de adelantos.',
        ]);

        [$units, $fraction] = array_pad(explode('.', (string) $data['amount'], 2), 2, '');
        $amountCents = (int) $units * 100 + (int) str_pad($fraction, 2, '0');
        $user = $request->user();

        DB::transaction(function () use ($employee, $data, $amountCents, $user): void {
            $employee = User::query()->lockForUpdate()->operators()->findOrFail($employee->id);
            $previous = Advance::query()->where('request_id', $data['request_id'])->first();
            if ($previous !== null) {
                if ($previous->employee_id === $employee->id
                    && $previous->recorded_by === $user->id
                    && $previous->amount_cents === $amountCents
                    && $previous->note === ($data['note'] ?? null)) {
                    return;
                }

                throw ValidationException::withMessages(['request_id' => 'Esta solicitud ya se utilizó. Cierra el formulario y vuelve a abrirlo.']);
            }

            $pendingCents = (int) $employee->advances()->pending()->sum('amount_cents');
            if ($pendingCents + $amountCents > Advance::LIMIT_CENTS) {
                $available = number_format((Advance::LIMIT_CENTS - $pendingCents) / 100, 2, ',', '.');

                throw ValidationException::withMessages(['amount' => "El monto supera el disponible de USD {$available}."]);
            }

            $employee->advances()->create([
                'request_id' => $data['request_id'],
                'amount_cents' => $amountCents,
                'employee_name' => $employee->name,
                'issued_on' => now('America/Guayaquil')->toDateString(),
                'responsible' => 'Administración',
                'note' => $data['note'] ?? null,
                'recorded_by' => $user->id,
                'recorder_name' => $user->name,
            ]);
        }, attempts: 3);

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Adelanto registrado.']);

        return to_route('advances.index');
    }

    public function deduct(Request $request, User $employee): RedirectResponse
    {
        Gate::authorize('deduct-advances');

        $data = $request->validate(['latest_pending_id' => ['required', 'integer', 'min:1']]);
        $user = $request->user();

        DB::transaction(function () use ($employee, $data, $user): void {
            $employee = User::query()->lockForUpdate()->findOrFail($employee->id);
            $pending = $employee->advances()->pending();
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
