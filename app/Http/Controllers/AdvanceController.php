<?php

namespace App\Http\Controllers;

use App\Actions\ConvertUsdToCents;
use App\Models\Advance;
use App\Models\CashOpening;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
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
            'month' => ['nullable', 'date_format:Y-m'],
        ]);

        $employees = User::query()->operators()->orderBy('name')
            ->withSum('pendingAdvances as pending_cents', 'amount_cents')
            ->withMax('pendingAdvances as latest_pending_id', 'id')
            ->get(['id', 'name']);

        $history = Advance::query()
            ->when($filters['employee'] ?? null, fn (Builder $query, int|string $id) => $query->where('employee_id', $id))
            ->when($filters['month'] ?? null, fn (Builder $query, string $month) => $query
                ->where('issued_on', '>=', $month.'-01')
                ->where('issued_on', '<', Carbon::createFromFormat('!Y-m', $month)->addMonth()->startOfMonth()->toDateString()))
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
        ]);
    }

    public function createEmployee(): Response
    {
        return Inertia::render('AdvanceEmployeeForm', ['employee' => null]);
    }

    public function editEmployee(User $employee): Response
    {
        abort_if($employee->is_admin, 404);

        return Inertia::render('AdvanceEmployeeForm', [
            'employee' => ['id' => $employee->id, 'name' => $employee->name, 'email' => $employee->email],
        ]);
    }

    public function storeEmployee(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', 'unique:users,email'],
            'password' => ['required', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'Ingresa el nombre del empleado.',
            'email.required' => 'Ingresa el correo del empleado.',
            'email.unique' => 'Ya existe una cuenta con este correo.',
            'password.required' => 'Define una contraseña inicial para el empleado.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $employee = User::query()->create([
            'name' => $data['name'],
            'email' => $data['email'],
            'password' => Hash::make($data['password']),
        ]);
        $employee->forceFill(['email_verified_at' => now()])->save();

        Inertia::flash('toast', ['type' => 'success', 'message' => 'Empleado creado.']);

        return to_route('advances.index');
    }

    public function updateEmployee(Request $request, User $employee): RedirectResponse
    {
        abort_if($employee->is_admin, 404);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($employee->id)],
            'password' => ['nullable', 'string', 'min:8', 'confirmed'],
        ], [
            'name.required' => 'Ingresa el nombre del empleado.',
            'email.required' => 'Ingresa el correo del empleado.',
            'email.unique' => 'Ya existe una cuenta con este correo.',
            'password.confirmed' => 'Las contraseñas no coinciden.',
            'password.min' => 'La contraseña debe tener al menos 8 caracteres.',
        ]);

        $employee->name = $data['name'];
        $employee->email = $data['email'];
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

        return Inertia::render('AdvanceCreate', [
            'employee' => ['id' => $employee->id, 'name' => $employee->name],
            'pendingCents' => (int) $employee->pendingAdvances()->sum('amount_cents'),
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

        $amountCents = ConvertUsdToCents::fromDecimal((string) $data['amount']);
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

            $pendingCents = (int) $employee->pendingAdvances()->sum('amount_cents');
            if ($pendingCents + $amountCents > Advance::LIMIT_CENTS) {
                $available = number_format((Advance::LIMIT_CENTS - $pendingCents) / 100, 2, ',', '.');

                throw ValidationException::withMessages(['amount' => "El monto supera el disponible de USD {$available}."]);
            }

            $opening = CashOpening::query()->lockForUpdate()->sole();
            $opening->markUsed();

            $employee->advances()->create([
                'cash_opening_id' => $opening->opening_balance_cents === null ? null : $opening->id,
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
