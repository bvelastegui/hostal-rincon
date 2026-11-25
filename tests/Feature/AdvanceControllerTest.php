<?php

use App\Models\Advance;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CashMovement;
use App\Models\CashOpening;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('requiere una cuenta autenticada', function () {
    $this->get(route('advances.index'))->assertRedirect(route('login'));
});

test('permite llegar a 300 y no duplica el registro al reenviar la solicitud', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    $employee = User::factory()->create();
    Advance::factory()->for($employee, 'employee')->create(['amount_cents' => 25000, 'issued_on' => '2026-10-31']);
    CashOpening::query()->update(['opening_balance_cents' => 5000]);
    $data = ['request_id' => (string) Str::uuid(), 'amount' => '50.00', 'payment_method' => 'cash'];

    $this->actingAs($administrator)->post(route('advances.store', $employee), $data)
        ->assertRedirectToRoute('advances.index')->assertSessionHasNoErrors();
    $this->post(route('advances.store', $employee), [...$data, 'responsible' => 'Otro usuario'])
        ->assertRedirectToRoute('advances.index')->assertSessionHasNoErrors();

    $this->assertDatabaseCount('advances', 2);
    $this->assertDatabaseHas('advances', [
        'employee_id' => $employee->id,
        'request_id' => $data['request_id'],
        'amount_cents' => 5000,
        'responsible' => 'Administración',
        'recorded_by' => $administrator->id,
        'deducted_at' => null,
    ]);
    $this->withoutVite()->get(route('advances.index'))->assertInertia(
        fn (Assert $page) => $page->component('Advances')
            ->where('employees', fn ($employees) => collect($employees)->firstWhere('id', $employee->id)['pendingCents'] === 30000)
            ->where('auth.canManageAdvances', true),
    );
});

test('rechaza adelantos que exceden el tope o tienen precisión inválida', function (int $pending, string $amount) {
    $employee = User::factory()->create();
    Advance::factory()->for($employee, 'employee')->create(['amount_cents' => $pending]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))->post(route('advances.store', $employee), [
        'request_id' => (string) Str::uuid(),
        'amount' => $amount,
        'payment_method' => 'cash',
    ])->assertSessionHasErrors('amount');

    $this->assertDatabaseCount('advances', 1);
})->with([
    'exceso por un centavo' => [25000, '50.01'],
    'límite alcanzado' => [30000, '0.01'],
    'más de dos decimales' => [25000, '0.001'],
    'monto cero' => [25000, '0'],
]);

test('el empleado no puede acceder a adelantos ni ve la opción en el menú', function () {
    $employee = User::factory()->create();
    $advance = Advance::factory()->for($employee, 'employee')->create();

    $this->actingAs($employee)->withoutVite()->get(route('cash-closings.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.canManageAdvances', false));

    $this->get(route('advances.index'))->assertRedirectToRoute('cash-closings.index');
    $this->get(route('advances.create', $employee))->assertRedirectToRoute('cash-closings.index');
    $this->post(route('advances.store', $employee), [
        'request_id' => (string) Str::uuid(),
        'amount' => '50',
    ])->assertForbidden();
    $this->post(route('advances.deduct', $employee), [
        'latest_pending_id' => $advance->id,
    ])->assertForbidden();

    $this->assertDatabaseCount('advances', 1);
    expect($advance->refresh()->deducted_at)->toBeNull();
});

test('el descuento total conserva el historial sin crear otro egreso', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $employee = User::factory()->create();
    $advances = Advance::factory()->count(2)->for($employee, 'employee')->create(['amount_cents' => 15000]);
    $otherAdvance = Advance::factory()->create();

    $this->actingAs($admin)->post(route('advances.deduct', $employee), [
        'latest_pending_id' => $advances->last()->id,
    ])->assertRedirectToRoute('advances.index')->assertSessionHasNoErrors();

    $this->assertDatabaseCount('advances', 3);
    foreach ($advances as $advance) {
        $advance->refresh();
        expect($advance->deducted_at)->not->toBeNull();
        expect($advance->deducted_by)->toBe($admin->id);
        expect($advance->amount_cents)->toBe(15000);
    }
    expect($otherAdvance->refresh()->deducted_at)->toBeNull();
    $this->withoutVite()->get(route('advances.index', ['employee' => $employee->id]))
        ->assertInertia(fn (Assert $page) => $page->component('Advances')
            ->where('employees', fn ($employees) => collect($employees)->firstWhere('id', $employee->id)['pendingCents'] === 0)
            ->has('history.data', 2));
});

test('exige revisar el total si se registró un adelanto después de abrir la confirmación', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $employee = User::factory()->create();
    $first = Advance::factory()->for($employee, 'employee')->create();
    Advance::factory()->for($employee, 'employee')->create();

    $this->actingAs($admin)->post(route('advances.deduct', $employee), [
        'latest_pending_id' => $first->id,
    ])->assertSessionHasErrors('latest_pending_id');

    expect($employee->advances()->pending()->count())->toBe(2);
});

test('la administradora no puede recibir un adelanto', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    $this->actingAs($administrator)->post(route('advances.store', $administrator), [
        'request_id' => (string) Str::uuid(),
        'amount' => '50',
        'payment_method' => 'cash',
    ])->assertNotFound();

    $this->assertDatabaseCount('advances', 0);
});

test('el historial conserva el nombre si se elimina la cuenta del empleado', function () {
    $employee = User::factory()->create(['name' => 'Ana Torres']);
    $advance = Advance::factory()->for($employee, 'employee')->create();

    $employee->delete();

    $this->assertDatabaseHas('advances', [
        'id' => $advance->id,
        'employee_id' => null,
        'employee_name' => 'Ana Torres',
    ]);
});

test('abre el formulario de adelantos en una página para el operador seleccionado', function () {
    $employee = User::factory()->create();
    Advance::factory()->for($employee, 'employee')->create(['amount_cents' => 25000]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))->withoutVite()->get(route('advances.create', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('AdvanceCreate')
            ->where('employee.id', $employee->id)
            ->where('pendingCents', 25000)
            ->where('limitCents', 30000));
});

test('permite a la administradora crear una cuenta de empleado operativa', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    $data = [
        'name' => 'Ana Torres',
        'email' => 'ana.torres@example.com',
        'password' => 'ClaveInicial123!',
        'password_confirmation' => 'ClaveInicial123!',
    ];

    $this->actingAs($administrator)->post(route('advances.employees.store'), $data)
        ->assertRedirectToRoute('advances.index')->assertSessionHasNoErrors();

    $employee = User::query()->where('email', $data['email'])->sole();
    expect($employee->is_admin)->toBeFalse();
    expect($employee->email_verified_at)->not->toBeNull();
    expect(Hash::check($data['password'], $employee->password))->toBeTrue();
});

test('permite editar los datos de operador y conserva la contraseña si no se cambia', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    $employee = User::factory()->create(['name' => 'Ana Torres', 'email' => 'ana@example.com']);

    $this->actingAs($administrator)->put(route('advances.employees.update', $employee), [
        'name' => 'Ana María Torres',
        'email' => 'ana.maria@example.com',
        'password' => '',
        'password_confirmation' => '',
    ])->assertRedirectToRoute('advances.index')->assertSessionHasNoErrors();

    $employee->refresh();
    expect($employee->name)->toBe('Ana María Torres');
    expect($employee->email)->toBe('ana.maria@example.com');
    expect($employee->is_admin)->toBeFalse();
    expect(Hash::check('password', $employee->password))->toBeTrue();
});

test('filtra el historial por mes calendario e incluye sus dos extremos', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    $employee = User::factory()->create();
    $first = Advance::factory()->for($employee, 'employee')->create(['issued_on' => '2026-10-01']);
    $last = Advance::factory()->for($employee, 'employee')->create(['issued_on' => '2026-10-31']);
    Advance::factory()->for($employee, 'employee')->create(['issued_on' => '2026-11-01']);

    $this->actingAs($administrator)->withoutVite()->get(route('advances.index', ['month' => '2026-10']))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('Advances')
            ->where('filters.month', '2026-10')
            ->has('history.data', 2)
            ->where('history.data.0.id', $last->id)
            ->where('history.data.1.id', $first->id));
});

test('el operador no puede crear ni modificar cuentas de empleados', function () {
    $operator = User::factory()->create();
    $employee = User::factory()->create(['name' => 'Ana Torres']);
    $this->actingAs($operator);

    $this->get(route('advances.employees.create'))->assertRedirectToRoute('cash-closings.index');
    $this->post(route('advances.employees.store'), [])->assertForbidden();
    $this->get(route('advances.employees.edit', $employee))->assertRedirectToRoute('cash-closings.index');
    $this->put(route('advances.employees.update', $employee), ['name' => 'Cambio'])->assertForbidden();

    expect($employee->refresh()->name)->toBe('Ana Torres');
    $this->assertDatabaseCount('users', 2);
});

test('un adelanto actualiza una sola fuente y se muestra con importe reservado en el turno del operador', function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(18, 0));
    $admin = User::factory()->create(['is_admin' => true]);
    $employee = User::factory()->create();
    $account = BankAccount::factory()->create(['opening_balance_cents' => 20000]);
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    CashShift::query()->create([
        'responsible_id' => $employee->id,
        'responsible_name' => $employee->name,
        'started_by' => $admin->id,
        'started_at' => now(),
        'opening_cash_cents' => 10000,
    ]);
    $this->withoutVite()->actingAs($admin);

    $route = route('advances.store', $employee);
    $cash = ['request_id' => (string) Str::uuid(), 'payment_method' => 'cash', 'amount' => '25'];
    $transfer = [
        'request_id' => (string) Str::uuid(),
        'payment_method' => 'transfer',
        'amount' => '50',
        'bank_account_id' => $account->id,
        'voucher' => ' v-01 ',
    ];
    foreach ([$cash, $transfer, $transfer] as $data) {
        $this->post($route, $data)->assertRedirectToRoute('advances.index')->assertSessionHasNoErrors();
    }
    $this->post($route, [...$transfer, 'voucher' => 'OTRO'])->assertSessionHasErrors('request_id');
    $this->assertDatabaseCount('advances', 2);
    $this->assertDatabaseCount('cash_movements', 2);
    $bankAdvance = Advance::query()->where('request_id', $transfer['request_id'])->sole();
    $this->assertDatabaseHas('cash_movements', [
        'id' => $bankAdvance->cash_movement_id,
        'kind' => 'advance',
        'amount_cents' => 5000,
        'payment_method' => 'transfer',
        'bank_id' => $account->bank_id,
        'bank_account_id' => $account->id,
        'voucher' => 'V-01',
        'confirmed_by' => $admin->id,
    ]);

    $movementUrl = route('movements.index', ['kind' => 'advance']);
    $this->get($movementUrl)->assertInertia(fn (Assert $page) => $page
        ->has('movements.data', 2)
        ->where('movements.data.0.kind', 'advance')
        ->where('balances.cash', 7500)
        ->where('balances.bank', 15000));
    $this->get(route('advances.index'))->assertInertia(fn (Assert $page) => $page
        ->where('history.data.0.cash_movement.payment_method', 'transfer')
        ->where('history.data.0.cash_movement.bank_account.name', $account->name));

    $this->post(route('advances.deduct', $employee), ['latest_pending_id' => $bankAdvance->id])->assertSessionHasNoErrors();
    $this->get(route('reports.monthly'))->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 7500)
        ->where('balances.accounts.0.balanceCents', 15000)
        ->where('advances.0.issuedCashCents', 2500)
        ->where('advances.0.issuedTransferCents', 5000)
        ->where('advances.0.deductedCents', 7500)
        ->where('advances.0.pendingCents', 0)
        ->has('movements', 0));
    $this->assertDatabaseCount('cash_movements', 2);

    $this->patch(route('movements.update-sensitivity', $bankAdvance->cash_movement_id), ['is_sensitive' => false])->assertNotFound();
    $this->actingAs($employee)->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->has('movements.data', 2)
        ->where('movements.data.0', [
            'id' => $bankAdvance->cash_movement_id,
            'redacted' => true,
            'kind' => 'expense',
            'recorderName' => $admin->name,
            'amountCents' => -5000,
            'date' => '2026-11-24',
            'status' => 'confirmed',
            'account' => $account->displayName(),
        ])
        ->where('movements.data.1.redacted', true)
        ->where('movements.data.1.kind', 'expense')
        ->where('movements.data.1.amountCents', -2500));
    $this->get(route('movements.index', ['kind' => 'expense']))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 2));
    $this->getJson($movementUrl)->assertUnprocessable()->assertJsonValidationErrors('kind');
    $this->get(route('movements.edit', $bankAdvance->cash_movement_id))->assertNotFound();
});

test('un adelanto bancario requiere cuenta activa y voucher único en el banco de origen', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $employee = User::factory()->create();
    $account = BankAccount::factory()->create(['opening_balance_cents' => 3000]);
    $inactiveAccount = BankAccount::factory()->for(Bank::factory()->create(['is_active' => false]))->create();
    $this->withoutVite()->actingAs($admin);

    $this->get(route('advances.create', $employee))->assertInertia(fn (Assert $page) => $page
        ->has('bankAccounts', 1)->where('bankAccounts.0.id', $account->id));
    $route = route('advances.store', $employee);
    $data = ['request_id' => (string) Str::uuid(), 'amount' => '10'];
    $this->post($route, $data)->assertSessionHasErrors('payment_method');
    $transfer = [...$data, 'payment_method' => 'transfer'];
    $this->post($route, $transfer)->assertSessionHasErrors(['voucher', 'bank_account_id']);
    $this->post($route, [...$transfer, 'bank_account_id' => $inactiveAccount->id, 'voucher' => 'V-DUP'])
        ->assertSessionHasErrors('bank_account_id');

    $this->post(route('movements.store'), [
        ...$transfer,
        'request_id' => (string) Str::uuid(),
        'kind' => 'expense',
        'description' => 'Egreso previo',
        'bank_account_id' => $account->id,
        'voucher' => 'V-DUP',
    ])->assertSessionHasNoErrors();
    $this->post($route, [...$transfer, 'bank_account_id' => $account->id, 'voucher' => ' v-dup '])
        ->assertSessionHasErrors('voucher');

    $this->assertDatabaseCount('advances', 0);
    expect(CashMovement::query()->sole()->kind)->toBe('expense');
});

test('un adelanto en efectivo solo puede usar lo que queda en caja después de un retiro en tránsito', function () {
    $employee = User::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    $this->withoutVite()->actingAs(User::factory()->create(['is_admin' => true]));
    $this->post(route('movements.store'), [
        'request_id' => (string) Str::uuid(),
        'kind' => 'deposit',
        'amount' => '70',
        'responsible_id' => $employee->id,
    ])->assertSessionHasNoErrors();

    $this->get(route('advances.create', $employee))->assertInertia(fn (Assert $page) => $page
        ->where('cashBalanceCents', 3000));
    $route = route('advances.store', $employee);
    $advance = ['request_id' => (string) Str::uuid(), 'payment_method' => 'cash'];
    $this->post($route, [...$advance, 'amount' => '30.01'])
        ->assertSessionHasErrors(['amount' => 'El monto supera el saldo disponible en caja: USD 30,00.']);
    $this->assertDatabaseCount('advances', 0);

    $this->post($route, [...$advance, 'amount' => '30'])->assertSessionHasNoErrors();
    $this->post($route, [...$advance, 'amount' => '30'])->assertSessionHasNoErrors();
    $this->post($route, [...$advance, 'request_id' => (string) Str::uuid(), 'amount' => '0.01'])
        ->assertSessionHasErrors(['amount' => 'El monto supera el saldo disponible en caja: USD 0,00.']);

    $this->assertDatabaseCount('advances', 1);
    $this->assertDatabaseCount('cash_movements', 2);
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 0)->where('balances.transit', 7000));
});

test('una transferencia solo puede usar el saldo confirmado de la cuenta seleccionada', function () {
    $employee = User::factory()->create();
    $account = BankAccount::factory()->create(['name' => 'Cuenta principal', 'opening_balance_cents' => 1500]);
    BankAccount::factory()->create(['name' => 'Cuenta secundaria', 'opening_balance_cents' => 50000]);
    $origin = Bank::factory()->create();
    $this->withoutVite()->actingAs(User::factory()->create(['is_admin' => true]));

    $this->post(route('movements.store'), [
        'request_id' => (string) Str::uuid(),
        'kind' => 'income',
        'description' => 'Cobro por verificar',
        'payment_method' => 'transfer',
        'amount' => '100',
        'origin_bank_id' => $origin->id,
        'bank_account_id' => $account->id,
    ])->assertSessionHasNoErrors();

    $this->get(route('advances.create', $employee))->assertInertia(fn (Assert $page) => $page
        ->where('cashBalanceCents', null)
        ->where('bankAccounts.0.id', $account->id)
        ->where('bankAccounts.0.balanceCents', 1500));
    $route = route('advances.store', $employee);
    $advance = [
        'request_id' => (string) Str::uuid(),
        'payment_method' => 'transfer',
        'bank_account_id' => $account->id,
        'voucher' => 'A-15',
    ];
    $this->post($route, [...$advance, 'amount' => '15.01'])
        ->assertSessionHasErrors(['amount' => 'El monto supera el saldo disponible en la cuenta seleccionada: USD 15,00.']);
    $this->assertDatabaseCount('advances', 0);

    $this->post($route, [...$advance, 'amount' => '15'])->assertSessionHasNoErrors();
    $this->assertDatabaseCount('advances', 1);
    $this->assertDatabaseCount('cash_movements', 2);
    $this->get(route('advances.create', $employee))->assertInertia(fn (Assert $page) => $page
        ->where('bankAccounts.0.balanceCents', 0)
        ->where('bankAccounts.1.balanceCents', 50000));
});
