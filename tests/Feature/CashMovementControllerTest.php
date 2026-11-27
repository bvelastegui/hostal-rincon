<?php

use App\Models\Advance;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CashMovement;
use App\Models\CashOpening;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

test('exige una sesión para ver y registrar movimientos', function () {
    $this->get(route('movements.index'))->assertRedirectToRoute('login');
    $this->post(route('movements.store'), [])->assertRedirectToRoute('login');
});

test('valida un movimiento antes de confirmar sin registrar ni bloquear la caja', function () {
    $user = User::factory()->create(['is_admin' => true]);
    CashOpening::query()->update(['opening_balance_cents' => 5000]);
    $this->actingAs($user);

    $url = route('movements.store');
    $review = ['Precognition' => 'true'];
    $entry = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'income',
        'payment_method' => 'cash',
    ];

    $this->postJson($url, $entry, $review)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['amount', 'description']);
    $this->postJson($url, [...$entry, 'amount' => '-1,00', 'description' => 'Hospedaje'], $review)
        ->assertUnprocessable()
        ->assertJsonValidationErrors('amount');

    $valid = [...$entry, 'amount' => '25,05', 'description' => 'Hospedaje'];
    $this->postJson($url, $valid, $review)
        ->assertNoContent()
        ->assertHeader('Precognition-Success', 'true');
    $this->assertDatabaseCount('cash_movements', 0);
    expect(CashOpening::query()->sole()->opening_locked_at)->toBeNull();

    $this->post($url, $valid)->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('cash_movements', ['request_id' => $entry['request_id'], 'amount_cents' => 2505]);
});

test('la validación previa distingue guardar de guardar y confirmar una transferencia', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $origin = Bank::factory()->create();
    $account = BankAccount::factory()->create();
    $url = route('movements.store');
    $review = ['Precognition' => 'true'];
    $entry = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'income',
        'payment_method' => 'transfer',
        'description' => 'Hospedaje',
        'amount' => '25,05',
        'origin_bank_id' => $origin->id,
        'bank_account_id' => $account->id,
    ];

    $this->postJson($url, [...$entry, 'confirm' => '0'], $review)->assertNoContent();
    $this->postJson($url, [...$entry, 'confirm' => '1'], $review)
        ->assertUnprocessable()->assertJsonValidationErrors('voucher');
    $this->postJson($url, [...$entry, 'confirm' => '1', 'voucher' => 'PRUEBA-001'], $review)
        ->assertNoContent();
    $this->assertDatabaseCount('cash_movements', 0);
});

test('registra un cobro en efectivo y un egreso una sola vez al guardar', function () {
    $operator = User::factory()->create();
    $responsible = User::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 5000]);
    Advance::factory()->create(['amount_cents' => 1300]);
    Advance::factory()->create(['amount_cents' => 2000, 'cash_opening_id' => 1, 'deducted_at' => now()]);
    openMovementShift($operator);
    $this->actingAs($operator);

    $income = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'income',
        'payment_method' => 'cash',
        'description' => 'Hospedaje',
        'amount' => '100,25',
        'confirm' => false,
    ];
    foreach ([1, 2] as $_) {
        $this->post(route('movements.store'), $income)->assertRedirect()->assertSessionHasNoErrors();
    }
    $movement = CashMovement::query()->sole();
    $this->assertDatabaseHas('cash_movements', ['id' => $movement->id, 'recorded_by' => $operator->id, 'confirmed_by' => $operator->id]);

    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->component('CashMovements')
        ->where('balances.cash', 13025)
        ->where('movements.data.0.confirmed', true));

    $expense = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'expense',
        'payment_method' => 'cash',
        'description' => 'Limpieza',
        'amount' => '15.05',
        'responsible_id' => $responsible->id,
        'shift' => 'night',
    ];
    foreach ([1, 2] as $_) {
        $this->post(route('movements.store'), $expense)->assertRedirect()->assertSessionHasNoErrors();
    }

    $this->assertDatabaseCount('cash_movements', 2);
    $this->assertDatabaseHas('cash_openings', ['id' => 1, 'opening_balance_cents' => 5000]);
    expect(CashOpening::query()->sole()->opening_locked_at)->not->toBeNull();
    $this->assertDatabaseHas('cash_movements', [
        'request_id' => $expense['request_id'],
        'amount_cents' => 1505,
        'responsible_id' => null,
        'responsible_name' => null,
        'recorded_by' => $operator->id,
        'confirmed_by' => $operator->id,
        'cash_opening_id' => 1,
        'occurred_on' => now('America/Guayaquil')->toDateString(),
    ]);
    $this->post(route('movements.store'), [...$expense, 'amount' => '20'])->assertSessionHasErrors('request_id');

    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 11520)
        ->where('balances.total', null)
        ->has('movements.data', 2));
    $this->get(route('movements.index', ['kind' => 'expense', 'recorded_by' => $operator->id, 'status' => 'confirmed', 'source' => 'cash']))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 1)
            ->where('movements.data.0.kind', 'expense')
            ->where('filterOptions.recorders.0.id', $operator->id));
    $this->get(route('movements.index', ['recorded_by' => $responsible->id]))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 0));
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('movements.index', ['date' => '2000-01-01']))->assertInertia(fn (Assert $page) => $page
        ->where('date', '2000-01-01')->has('movements.data', 0));
});

test('valida el voucher por banco de origen y acredita a la cuenta de destino', function () {
    $user = User::factory()->create();
    $origin = Bank::factory()->create();
    $anotherOrigin = Bank::factory()->create();
    $inactiveBank = Bank::factory()->create(['is_active' => false]);
    $destination = Bank::factory()->create();
    $account = BankAccount::factory()->for($destination)->create(['opening_balance_cents' => 500]);
    $anotherAccount = BankAccount::factory()->create();
    openMovementShift($user);
    $this->actingAs($user);

    $receipt = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'income',
        'payment_method' => 'transfer',
        'description' => 'Hospedaje por transferencia',
        'amount' => '25.05',
        'origin_bank_id' => $origin->id,
        'bank_account_id' => $account->id,
    ];
    $this->post(route('movements.store'), [...$receipt, 'origin_bank_id' => null])->assertSessionHasErrors('origin_bank_id');
    $this->post(route('movements.store'), [...$receipt, 'origin_bank_id' => $inactiveBank->id])->assertSessionHasErrors('origin_bank_id');
    $this->post(route('movements.store'), $receipt)->assertRedirect()->assertSessionHasNoErrors();
    $movement = CashMovement::query()->sole();

    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balances.bank', 0)
        ->where('movements.data.0.originBankName', $origin->name)
        ->where('movements.data.0.accountBankName', $destination->name));
    $this->get(route('movements.index', ['status' => 'pending', 'source' => 'bank:'.$account->id]))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 1)
            ->where('filterOptions.accounts.0.id', $account->id)
            ->where('filters.source', 'bank:'.$account->id));
    $this->get(route('movements.index', ['source' => 'cash']))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 0));
    $this->get(route('movements.edit', $movement))->assertInertia(fn (Assert $page) => $page
        ->where('movement.originBankName', $origin->name)
        ->where('movement.accountBankName', $destination->name));

    $this->patch(route('movements.update', $movement), [])->assertSessionHasErrors('voucher');
    $this->patch(route('movements.update', $movement), ['voucher' => ' v-01 '])->assertRedirect()->assertSessionHasNoErrors();
    $this->patch(route('movements.update', $movement), ['voucher' => 'otro'])->assertRedirect()->assertSessionHasNoErrors();

    $this->assertDatabaseHas('cash_movements', [
        'id' => $movement->id,
        'bank_id' => $origin->id,
        'bank_account_id' => $account->id,
        'voucher' => 'V-01',
        'confirmed_by' => $user->id,
    ]);
    expect($account->refresh()->opening_locked_at)->not->toBeNull();

    $confirmation = [...$receipt, 'request_id' => (string) Str::uuid(), 'confirm' => true, 'voucher' => 'v-01'];
    $this->post(route('movements.store'), [...$confirmation, 'bank_account_id' => $anotherAccount->id])->assertSessionHasErrors('voucher');
    expect($anotherAccount->refresh()->opening_locked_at)->toBeNull();
    $this->post(route('movements.store'), [...$confirmation, 'origin_bank_id' => $anotherOrigin->id])->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('cash_movements', [
        'request_id' => $confirmation['request_id'],
        'bank_id' => $anotherOrigin->id,
        'bank_account_id' => $account->id,
        'voucher' => 'V-01',
    ]);
    $this->assertDatabaseCount('cash_movements', 2);
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page->where('balances.bank', 5010));

    $destination->is_active = false;
    $destination->save();
    $this->post(route('movements.store'), [...$receipt, 'request_id' => (string) Str::uuid()])
        ->assertSessionHasErrors('bank_account_id');
});

test('registra egresos bancarios con el usuario de sesión y resta solo de la cuenta bancaria', function () {
    $operator = User::factory()->create();
    $anotherUser = User::factory()->create();
    $account = BankAccount::factory()->create(['opening_balance_cents' => 5000]);
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    openMovementShift($operator);
    $this->actingAs($operator);

    $expense = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'expense',
        'payment_method' => 'transfer',
        'description' => 'Pago a proveedor',
        'amount' => '7.91',
        'bank_account_id' => $account->id,
        'responsible_id' => $anotherUser->id,
        'recorded_by' => $anotherUser->id,
    ];
    $this->post(route('movements.store'), $expense)->assertSessionHasErrors('voucher');
    $this->assertDatabaseCount('cash_movements', 0);

    $this->post(route('movements.store'), [...$expense, 'voucher' => 'PB-01'])
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('cash_movements', [
        'request_id' => $expense['request_id'],
        'bank_account_id' => $account->id,
        'voucher' => 'PB-01',
        'recorded_by' => $operator->id,
        'responsible_id' => null,
    ]);
    expect($account->refresh()->opening_locked_at)->not->toBeNull();
    expect(CashOpening::query()->sole()->opening_locked_at)->toBeNull();
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 10000)
        ->where('balances.bank', -791)
        ->where('balances.transit', null)
        ->where('balances.total', null));
});

test('el resumen del operador solo incluye sus transferencias vigentes del turno activo', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    $origin = Bank::factory()->create();
    $account = BankAccount::factory()->create(['opening_balance_cents' => 10000]);
    CashOpening::query()->update(['opening_balance_cents' => 5000]);

    $previousShift = openMovementShift($operator);
    $transfer = [
        'kind' => 'income',
        'payment_method' => 'transfer',
        'description' => 'Cobro bancario',
        'origin_bank_id' => $origin->id,
        'bank_account_id' => $account->id,
    ];
    $register = fn (User $recorder, array $details) => $this->actingAs($recorder)->post(route('movements.store'), [
        ...$transfer,
        'request_id' => (string) Str::uuid(),
        ...$details,
    ])->assertSessionHasNoErrors();

    $register($operator, ['amount' => '40', 'confirm' => true, 'voucher' => 'TURNO-ANTERIOR']);
    $previousShift->update(['ended_at' => now()]);
    openMovementShift($operator);

    $register($operator, ['amount' => '30', 'confirm' => true, 'voucher' => 'COBRO-PROPIO']);
    $register($operator, ['kind' => 'expense', 'amount' => '7', 'origin_bank_id' => null, 'voucher' => 'PAGO-PROPIO']);
    $register($operator, ['amount' => '11']);
    $register($operator, ['amount' => '13', 'confirm' => true, 'voucher' => 'COBRO-ANULADO']);
    $voided = CashMovement::query()->latest('id')->firstOrFail();
    $this->actingAs($admin)->patch(route('movements.annul', $voided), [
        'void_reason' => 'Registro duplicado',
    ])->assertSessionHasNoErrors();
    $register($admin, ['amount' => '20', 'confirm' => true, 'voucher' => 'COBRO-ADMIN']);

    $this->actingAs($operator);
    foreach ([[], ['status' => 'pending']] as $filters) {
        $this->get(route('movements.index', $filters))->assertInertia(fn (Assert $page) => $page
            ->where('balances', ['cash' => 5000, 'bank' => 2300, 'transit' => null, 'total' => null]));
    }

    $this->actingAs($admin)->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balances', ['cash' => 5000, 'bank' => 18300, 'transit' => 0, 'total' => 23300]));
});

test('un egreso en efectivo solo puede usar el saldo que queda en caja después de los retiros', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    openMovementShift($operator);
    $store = route('movements.store');
    $this->actingAs($admin)->post($store, [
        'request_id' => (string) Str::uuid(),
        'kind' => 'deposit',
        'amount' => '70',
        'responsible_id' => $operator->id,
    ])->assertSessionHasNoErrors();
    $this->actingAs($operator);
    $expense = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'expense',
        'payment_method' => 'cash',
        'description' => 'Compra de insumos',
        'amount' => '30',
    ];

    $this->post($store, [...$expense, 'amount' => '30.01'])
        ->assertSessionHasErrors(['amount' => 'El monto supera el saldo disponible en caja: USD 30,00.']);
    $this->assertDatabaseMissing('cash_movements', ['request_id' => $expense['request_id']]);
    $this->post($store, $expense)->assertSessionHasNoErrors();
    $this->post($store, $expense)->assertSessionHasNoErrors();

    $this->assertDatabaseCount('cash_movements', 2);
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page->where('balances.cash', 0));
});

test('un egreso por transferencia usa solo el saldo acreditado de la cuenta seleccionada', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $account = BankAccount::factory()->create(['opening_balance_cents' => 1500]);
    BankAccount::factory()->create(['bank_id' => $account->bank_id, 'opening_balance_cents' => 10000]);
    $origin = Bank::factory()->create();
    $this->actingAs($admin);
    $store = route('movements.store');
    $this->post($store, [
        'request_id' => (string) Str::uuid(),
        'kind' => 'income',
        'payment_method' => 'transfer',
        'description' => 'Hospedaje pendiente de confirmación',
        'amount' => '20',
        'origin_bank_id' => $origin->id,
        'bank_account_id' => $account->id,
    ])->assertSessionHasNoErrors();
    $expense = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'expense',
        'payment_method' => 'transfer',
        'description' => 'Pago a proveedor',
        'amount' => '15',
        'bank_account_id' => $account->id,
        'voucher' => 'PAGO-01',
    ];

    $this->post($store, [...$expense, 'amount' => '15.01'])
        ->assertSessionHasErrors(['amount' => 'El monto supera el saldo disponible en la cuenta seleccionada: USD 15,00.']);
    $this->assertDatabaseMissing('cash_movements', ['request_id' => $expense['request_id']]);
    expect($account->refresh()->opening_locked_at)->toBeNull();
    $this->post($store, $expense)->assertSessionHasNoErrors();
    $this->post($store, [...$expense, 'request_id' => (string) Str::uuid(), 'voucher' => 'PAGO-02', 'amount' => '0.01'])
        ->assertSessionHasErrors(['amount' => 'El monto supera el saldo disponible en la cuenta seleccionada: USD 0,00.']);

    $this->assertDatabaseCount('cash_movements', 2);
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page->where('balances.bank', 10000));
});

test('la consulta inmediata comprueba fondos bancarios sin publicar el saldo al operador', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 8500]);
    $account = BankAccount::factory()->create(['opening_balance_cents' => 1500]);
    $secondAccount = BankAccount::factory()->create(['opening_balance_cents' => 9000]);
    $form = route('movements.create', ['kind' => 'expense']);
    $check = route('movements.check-bank-funds');
    $input = ['bank_account_id' => $account->id, 'amount' => '15,00'];

    $this->actingAs($admin)->get($form)->assertInertia(fn (Assert $page) => $page
        ->where('expenseCashCents', 8500)
        ->where('banks', fn ($banks) => collect($banks)->firstWhere('id', $account->id)['balanceCents'] === 1500));
    $this->actingAs($operator)->postJson($check, $input)->assertForbidden();

    openMovementShift($operator);
    $this->get($form)->assertInertia(fn (Assert $page) => $page
        ->where('expenseCashCents', 8500)
        ->missing('banks.0.balanceCents')
        ->missing('banks.1.balanceCents'));
    $this->postJson($check, $input)->assertNoContent();
    $this->postJson($check, [...$input, 'amount' => '15,01'])
        ->assertUnprocessable()
        ->assertJsonPath('errors.amount.0', 'El monto supera el saldo disponible en la cuenta seleccionada.');
    $this->postJson($check, [...$input, 'bank_account_id' => $secondAccount->id, 'amount' => '15,01'])->assertNoContent();

    $this->post(route('movements.store'), [
        ...$input,
        'request_id' => (string) Str::uuid(),
        'kind' => 'expense',
        'payment_method' => 'transfer',
        'description' => 'Pago bancario',
        'voucher' => 'PRUEBA-01',
        'amount' => '15,01',
    ])->assertSessionHasErrors(['amount' => 'El monto supera el saldo disponible en la cuenta seleccionada.']);
    $this->assertDatabaseCount('cash_movements', 0);
});

test('traslada efectivo a tránsito y luego a banco sin variar el total ni duplicar los importes', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    $responsible = User::factory()->create();
    $account = BankAccount::factory()->create(['opening_balance_cents' => 1000]);
    CashOpening::query()->update(['opening_balance_cents' => 25000]);
    $this->actingAs($administrator);

    $deposit = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'deposit',
        'amount' => '100,25',
        'responsible_id' => $responsible->id,
        'bank_account_id' => $account->id,
        'voucher' => 'NO-ACREDITADO',
        'confirm' => true,
    ];
    $this->post(route('movements.store'), [...$deposit, 'responsible_id' => null])->assertSessionHasErrors('responsible_id');
    foreach ([1, 2] as $_) {
        $this->post(route('movements.store'), $deposit)->assertRedirect()->assertSessionHasNoErrors();
    }

    $movement = CashMovement::query()->sole();
    $this->assertDatabaseHas('cash_movements', [
        'id' => $movement->id,
        'cash_opening_id' => 1,
        'responsible_name' => $responsible->name,
        'recorded_by' => $administrator->id,
        'bank_id' => null,
        'bank_account_id' => null,
        'voucher' => null,
        'confirmed_at' => null,
    ]);
    expect(CashOpening::query()->sole()->opening_locked_at)->not->toBeNull();
    expect($account->refresh()->opening_locked_at)->toBeNull();

    $this->get(route('movements.index', ['date' => '2000-01-01']))->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 14975)
        ->where('balances.bank', 1000)
        ->where('balances.transit', 10025)
        ->where('balances.total', 26000)
        ->has('movements.data', 0)
        ->where('depositsInTransit.data.0.id', $movement->id));
    $this->get(route('movements.index', ['kind' => 'deposit', 'status' => 'in_transit', 'source' => 'cash']))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 0)
            ->where('depositsInTransit.data.0.id', $movement->id));
    $this->get(route('movements.index', ['kind' => 'income']))
        ->assertInertia(fn (Assert $page) => $page->has('depositsInTransit.data', 0));
    $this->get(route('movements.index', ['kind' => 'deposit', 'date_from' => '2000-01-01', 'date_to' => '2000-01-02']))
        ->assertInertia(fn (Assert $page) => $page->has('depositsInTransit.data', 0));

    $confirmation = ['bank_account_id' => $account->id, 'voucher' => 'DEP-01'];
    $this->patch(route('movements.update', $movement), ['voucher' => 'DEP-01'])->assertSessionHasErrors('bank_account_id');
    $this->patch(route('movements.update', $movement), ['bank_account_id' => $account->id])->assertSessionHasErrors('voucher');
    foreach ([1, 2] as $_) {
        $this->patch(route('movements.update', $movement), $confirmation)->assertRedirect()->assertSessionHasNoErrors();
    }

    $this->patch(route('movements.return-to-cash', $movement))->assertSessionHasErrors('movement');
    $this->assertDatabaseCount('cash_movements', 1);
    $this->assertDatabaseHas('cash_movements', ['id' => $movement->id, 'bank_account_id' => $account->id, 'voucher' => 'DEP-01', 'confirmed_by' => $administrator->id]);
    expect($account->refresh()->opening_locked_at)->not->toBeNull();
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 14975)
        ->where('balances.bank', 11025)
        ->where('balances.transit', 0)
        ->where('balances.total', 26000)
        ->has('depositsInTransit.data', 0)
        ->where('movements.data.0.kind', 'deposit'));

    $this->post(route('movements.store'), [
        'request_id' => (string) Str::uuid(),
        'kind' => 'expense',
        'payment_method' => 'transfer',
        'description' => 'Pago',
        'amount' => '1',
        'bank_account_id' => $account->id,
        'voucher' => 'dep-01',
    ])->assertSessionHasErrors('voucher');
});

test('el operador registra un depósito en tránsito durante su turno y la administradora lo confirma', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    $other = User::factory()->create();
    $account = BankAccount::factory()->create(['opening_balance_cents' => 1000]);
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    $shift = openMovementShift($operator);
    $form = route('movements.create', ['kind' => 'deposit']);
    $deposit = [
        'kind' => 'deposit',
        'request_id' => (string) Str::uuid(),
        'amount' => '40,15',
        'responsible_id' => $operator->id,
        'confirm' => true,
        'bank_account_id' => $account->id,
        'voucher' => 'DEP-OPERADOR',
    ];

    $this->actingAs($other)->get($form)->assertRedirectToRoute('cash-closings.index');
    $this->post(route('movements.store'), $deposit)->assertForbidden();
    $this->actingAs($admin)->get($form)->assertInertia(fn (Assert $page) => $page
        ->where('defaultResponsibleId', $operator->id));
    $this->actingAs($operator)->get($form)->assertInertia(fn (Assert $page) => $page
        ->where('defaultResponsibleId', $operator->id)
        ->where('depositCashCents', 10000)
        ->has('banks', 0));

    $this->post(route('movements.store'), $deposit)->assertRedirect()->assertSessionHasNoErrors();
    $movement = CashMovement::query()->sole();
    $this->assertDatabaseHas('cash_movements', [
        'id' => $movement->id,
        'kind' => 'deposit',
        'cash_shift_id' => $shift->id,
        'responsible_id' => $operator->id,
        'recorded_by' => $operator->id,
        'amount_cents' => 4015,
        'confirmed_at' => null,
        'bank_account_id' => null,
        'voucher' => null,
    ]);
    $this->get(route('movements.index', ['kind' => 'deposit']))->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 5985)
        ->where('balances.bank', 0)
        ->has('movements.data', 1)
        ->where('movements.data.0.id', $movement->id)
        ->where('movements.data.0.status', 'in_transit'));
    $this->get(route('movements.index', ['kind' => 'transaction']))->assertInertia(fn (Assert $page) => $page
        ->has('movements.data', 0));

    $confirmation = ['bank_account_id' => $account->id, 'voucher' => 'DEP-OPERADOR'];
    $this->patch(route('movements.update', $movement), $confirmation)->assertForbidden();
    $this->actingAs($admin)->patch(route('movements.update', $movement), $confirmation)
        ->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseHas('cash_movements', [
        'id' => $movement->id,
        'bank_account_id' => $account->id,
        'voucher' => 'DEP-OPERADOR',
        'confirmed_by' => $admin->id,
    ]);
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 5985)
        ->where('balances.bank', 5015)
        ->where('balances.transit', 0)
        ->where('balances.total', 11000));
});

test('solo la administradora puede gestionar la confirmación y devolución de depósitos', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    $account = BankAccount::factory()->create(['opening_balance_cents' => 1000]);
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    openMovementShift($operator);

    $this->actingAs($administrator)->post(route('movements.store'), [
        'request_id' => (string) Str::uuid(),
        'kind' => 'deposit',
        'amount' => '40.15',
        'responsible_id' => $operator->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $movement = CashMovement::query()->sole();
    $confirm = ['bank_account_id' => $account->id, 'voucher' => 'DEP-DEVUELTO'];

    $this->actingAs($operator);
    $this->get(route('movements.edit', $movement))->assertForbidden();
    $this->patch(route('movements.update', $movement), $confirm)->assertForbidden();
    $this->patch(route('movements.return-to-cash', $movement))->assertForbidden();
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('canManageDeposits', false)
        ->where('depositsInTransit', null)
        ->where('balances.transit', null)
        ->where('balances.total', null)
        ->has('movements.data', 0));

    $this->actingAs($administrator);
    foreach ([1, 2] as $_) {
        $this->patch(route('movements.return-to-cash', $movement))->assertRedirect()->assertSessionHasNoErrors();
    }
    $this->patch(route('movements.update', $movement), $confirm)->assertSessionHasErrors('movement');

    $this->assertDatabaseCount('cash_movements', 1);
    $this->assertDatabaseHas('cash_movements', [
        'id' => $movement->id,
        'returned_by' => $administrator->id,
        'confirmed_at' => null,
        'bank_account_id' => null,
    ]);
    expect($movement->refresh()->returned_at)->not->toBeNull();
    expect($account->refresh()->opening_locked_at)->toBeNull();
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 10000)
        ->where('balances.bank', 1000)
        ->where('balances.transit', 0)
        ->where('balances.total', 11000)
        ->where('movements.data.0.returnedOn', now('America/Guayaquil')->toDateString())
        ->has('depositsInTransit.data', 0));

    $this->actingAs($operator)->get(route('movements.index'))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 0));
});

test('valida montos y requiere apertura de caja para contabilizar efectivo', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $receipt = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'income',
        'payment_method' => 'cash',
        'description' => 'Cobro',
        'confirm' => true,
    ];
    foreach (['0', '-1', '3,001', '1e2', '10'] as $invalidAmount) {
        $this->post(route('movements.store'), [...$receipt, 'amount' => $invalidAmount])
            ->assertSessionHasErrors('amount');
    }
    $this->assertDatabaseCount('cash_movements', 0);
});

test('permite crear y confirmar en un solo paso con una apertura de cero', function () {
    $operator = User::factory()->create();
    openMovementShift($operator);
    $this->actingAs($operator);
    CashOpening::query()->update(['opening_balance_cents' => 0]);
    $receipt = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'income',
        'payment_method' => 'cash',
        'description' => 'Habitación',
        'amount' => '15,75',
        'confirm' => true,
    ];
    $this->post(route('movements.store'), $receipt)->assertRedirect()->assertSessionHasNoErrors();
    $this->post(route('movements.store'), $receipt)->assertRedirect()->assertSessionHasNoErrors();
    $this->assertDatabaseCount('cash_movements', 1);
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page->where('balances.cash', 1575));
});

test('solo el propietario de un turno operativo y la administradora pueden registrar movimientos', function () {
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    CashOpening::query()->update(['opening_balance_cents' => 20000]);
    $data = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'income',
        'payment_method' => 'cash',
        'description' => 'Pago en recepción',
        'amount' => '5',
    ];

    $this->actingAs($other)->post(route('movements.store'), $data)->assertForbidden();
    $shift = openMovementShift($owner);
    $this->get(route('movements.create'))->assertRedirectToRoute('cash-closings.index');
    $this->post(route('movements.store'), $data)->assertForbidden();

    $this->actingAs($owner)->post(route('movements.store'), $data)->assertSessionHasNoErrors();
    expect(CashMovement::query()->sole()->cash_shift_id)->toBe($shift->id);
    $shift->update(['delivered_cash_cents' => 20500]);

    $next = [...$data, 'request_id' => (string) Str::uuid()];
    $this->post(route('movements.store'), $next)->assertForbidden();
    $this->actingAs($admin)->post(route('movements.store'), $next)->assertSessionHasNoErrors();
    expect(CashMovement::query()->latest('id')->firstOrFail()->cash_shift_id)->toBeNull();
});

test('aísla los movimientos por turno aunque el operador haya trabajado en el turno anterior', function () {
    $operator = User::factory()->create();
    $admin = User::factory()->create(['is_admin' => true]);
    $origin = Bank::factory()->create();
    $destination = BankAccount::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 20000]);
    $historical = openMovementShift($operator);

    $receipt = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'income',
        'payment_method' => 'transfer',
        'description' => 'Transferencia de otro turno',
        'amount' => '15',
        'origin_bank_id' => $origin->id,
        'bank_account_id' => $destination->id,
    ];
    $this->actingAs($operator)->post(route('movements.store'), $receipt)->assertSessionHasNoErrors();
    $oldMovement = CashMovement::query()->sole();
    $historical->update(['ended_at' => now()]);

    $shift = openMovementShift($operator);
    $this->post(route('movements.store'), [
        ...$receipt,
        'request_id' => (string) Str::uuid(),
        'description' => 'Transferencia del turno en curso',
    ])->assertSessionHasNoErrors();
    $shiftMovement = CashMovement::query()->where('cash_shift_id', $shift->id)->sole();
    CashMovement::query()->whereKey($shiftMovement->id)
        ->update(['occurred_on' => now('America/Guayaquil')->subDay()->toDateString()]);

    $this->get(route('movements.index', ['date' => now('America/Guayaquil')->toDateString()]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('date', null)->where('shiftId', $shift->id)
            ->has('movements.data', 1)->where('movements.data.0.id', $shiftMovement->id));
    $this->get(route('movements.edit', $oldMovement))->assertNotFound();
    $this->patch(route('movements.update', $oldMovement), ['voucher' => 'V-01'])->assertNotFound();
    $this->get(route('movements.edit', $shiftMovement))->assertOk();

    $this->actingAs(User::factory()->create())->get(route('movements.index'))
        ->assertRedirectToRoute('cash-closings.index');

    $this->actingAs($admin)->get(route('movements.index'))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 1)
            ->where('movements.data.0.id', $oldMovement->id));
    $today = now('America/Guayaquil')->toDateString();
    $yesterday = now('America/Guayaquil')->subDay()->toDateString();
    $this->get(route('movements.index', ['date_from' => $yesterday, 'date_to' => $today, 'kind' => 'income']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('dateRange.from', $yesterday)->where('dateRange.to', $today)
            ->has('movements.data', 2));
    $this->get(route('movements.index', ['date_from' => $today, 'date_to' => $yesterday]))
        ->assertSessionHasErrors('date_to');
    $this->get(route('movements.edit', $oldMovement))->assertOk();
    $this->patch(route('movements.update', $oldMovement), ['voucher' => 'V-01'])->assertSessionHasNoErrors();
});

function openMovementShift(User $owner): CashShift
{
    return CashShift::query()->create([
        'responsible_id' => $owner->id,
        'responsible_name' => $owner->name,
        'started_by' => $owner->id,
        'started_at' => now(),
    ]);
}

test('los movimientos sensibles del turno exponen únicamente los campos permitidos a operadores y bloquean los accesos directos', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    $otherOperator = User::factory()->create();
    $shift = openMovementShift($operator);
    CashOpening::query()->update(['opening_balance_cents' => 50000]);
    $origin = Bank::factory()->create();
    $account = BankAccount::factory()->create();

    $details = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'income',
        'payment_method' => 'transfer',
        'amount' => '78.25',
        'description' => 'Cobro confidencial de prueba',
        'origin_bank_id' => $origin->id,
        'bank_account_id' => $account->id,
        'voucher' => 'PRIVADO-7825',
    ];
    $this->actingAs($admin)->post(route('movements.store'), [...$details, 'is_sensitive' => true])
        ->assertSessionHasNoErrors();
    $privateMovement = CashMovement::query()->sole();
    $reserved = [
        'id' => $privateMovement->id,
        'redacted' => true,
        'kind' => 'income',
        'recorderName' => $admin->name,
        'amountCents' => 7825,
        'date' => $privateMovement->occurred_on,
        'status' => 'pending',
        'account' => $account->displayName(),
    ];

    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.canManageMovementSensitivity', true)
        ->where('movements.data.0.isSensitive', true)
        ->where('movements.data.0.description', $details['description'])
        ->where('movements.data.0.voucher', $details['voucher']));
    $this->get(route('movements.edit', $privateMovement))->assertOk();

    $this->actingAs($operator);
    $ordinary = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'expense',
        'payment_method' => 'cash',
        'amount' => '9',
        'description' => 'Compra de insumos',
    ];
    $this->post(route('movements.store'), [...$ordinary, 'is_sensitive' => true])->assertSessionHasErrors('is_sensitive');
    $this->post(route('movements.store'), $ordinary)->assertSessionHasNoErrors();

    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.canManageMovementSensitivity', false)
        ->has('movements.data', 2)
        ->where('movements.data.0.description', $ordinary['description'])
        ->where('movements.data.1', $reserved)
        ->has('filterOptions.recorders', 2)
        ->where('filterOptions.recorders', fn ($recorders) => collect($recorders)->pluck('id')->contains($admin->id))
        ->has('filterOptions.accounts', 1)
        ->where('filterOptions.accounts.0.id', $account->id));
    foreach ([
        ['kind' => 'income'],
        ['recorded_by' => $admin->id],
        ['status' => 'pending'],
        ['source' => 'bank:'.$account->id],
    ] as $filter) {
        $this->get(route('movements.index', $filter))->assertInertia(fn (Assert $page) => $page
            ->has('movements.data', 1)->where('movements.data.0', $reserved));
    }
    $this->get(route('movements.edit', $privateMovement))->assertNotFound();
    $this->patch(route('movements.update', $privateMovement), ['voucher' => 'INTENTO'])->assertNotFound();
    $this->patch(route('movements.update-sensitivity', $privateMovement), ['is_sensitive' => false])->assertForbidden();
    expect($privateMovement->refresh()->confirmed_at)->toBeNull();

    $this->actingAs($admin)->from(route('movements.index'))->patch(route('movements.update-sensitivity', $privateMovement), ['is_sensitive' => false])
        ->assertRedirectToRoute('movements.index')->assertSessionHasNoErrors();
    $this->actingAs($operator)->get(route('movements.index', ['source' => 'bank:'.$account->id]))
        ->assertInertia(fn (Assert $page) => $page
            ->where('movements.data.0.isSensitive', false)
            ->where('movements.data.0.description', $details['description']));
    $this->actingAs($admin)->patch(route('movements.update-sensitivity', $privateMovement), ['is_sensitive' => true])
        ->assertSessionHasNoErrors();
    $shift->update(['ended_at' => now()]);
    openMovementShift($otherOperator);
    $this->actingAs($otherOperator)->get(route('movements.index'))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 0));
});

test('un depósito reservado conserva la fecha, el estado y la cuenta visible durante el turno', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    openMovementShift($operator);
    CashOpening::query()->update(['opening_balance_cents' => 20000]);
    $this->actingAs($admin)->post(route('movements.store'), [
        'request_id' => (string) Str::uuid(),
        'kind' => 'deposit',
        'amount' => '65',
        'responsible_id' => $operator->id,
        'is_sensitive' => true,
    ])->assertSessionHasNoErrors();
    $deposit = CashMovement::query()->sole();

    $reserved = [
        'id' => $deposit->id,
        'redacted' => true,
        'kind' => 'transaction',
        'recorderName' => $admin->name,
        'amountCents' => 6500,
        'date' => $deposit->occurred_on,
        'status' => 'in_transit',
        'account' => 'Efectivo',
    ];
    $this->actingAs($operator)->get(route('movements.index', ['status' => 'in_transit', 'kind' => 'transaction']))->assertInertia(fn (Assert $page) => $page
        ->where('movements.data.0', $reserved)
        ->where('depositsInTransit', null));

    $account = BankAccount::factory()->create();
    $this->actingAs($admin)->patch(route('movements.update', $deposit), ['voucher' => 'DEP-65', 'bank_account_id' => $account->id])
        ->assertSessionHasNoErrors();
    $this->actingAs($operator)->get(route('movements.index', ['source' => 'cash']))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 0));
    $this->get(route('movements.index', ['source' => 'bank:'.$account->id]))->assertInertia(fn (Assert $page) => $page
        ->where('movements.data.0', [...$reserved, 'status' => 'confirmed', 'account' => $account->displayName()]));
});

test('la administradora pagina por separado y el operador ve todos los movimientos de su turno', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    openMovementShift($operator);
    CashOpening::query()->update(['opening_balance_cents' => 10000]);

    $this->actingAs($operator);
    for ($number = 1; $number <= 12; $number++) {
        $this->post(route('movements.store'), [
            'request_id' => (string) Str::uuid(),
            'kind' => 'income',
            'payment_method' => 'cash',
            'amount' => '5',
            'description' => 'Cobro '.$number,
        ])->assertSessionHasNoErrors();
    }

    $this->actingAs($admin);
    for ($number = 1; $number <= 11; $number++) {
        $this->post(route('movements.store'), [
            'request_id' => (string) Str::uuid(),
            'kind' => 'deposit',
            'amount' => '2',
            'responsible_id' => $operator->id,
        ])->assertSessionHasNoErrors();
    }

    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('movements.per_page', 10)
        ->where('movements.total', 12)
        ->has('movements.data', 10)
        ->where('depositsInTransit.per_page', 10)
        ->where('depositsInTransit.total', 11)
        ->has('depositsInTransit.data', 10));

    $this->get(route('movements.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->where('movements.current_page', 2)
        ->where('movements.from', 11)
        ->has('movements.data', 2)
        ->where('depositsInTransit.current_page', 1));

    $this->get(route('movements.index', ['transit_page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->where('movements.current_page', 1)
        ->where('depositsInTransit.current_page', 2)
        ->has('depositsInTransit.data', 1));

    $this->actingAs($operator)->get(route('movements.index', ['page' => 2]))
        ->assertInertia(fn (Assert $page) => $page
            ->has('movements.data', 12)
            ->missing('movements.total')
            ->where('depositsInTransit', null));
});

test('un retiro para depósito solo puede usar el efectivo que queda en caja', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $this->withoutVite()->actingAs($admin);
    $opening = CashOpening::query()->sole();
    $opening->opening_balance_cents = 10000;
    $opening->save();

    $form = route('movements.create', ['kind' => 'deposit']);
    $store = route('movements.store');
    $first = [
        'request_id' => (string) Str::uuid(),
        'kind' => 'deposit',
        'responsible_id' => $admin->id,
        'amount' => '70',
    ];
    $this->post($store, [...$first, 'amount' => '100.01'])
        ->assertSessionHasErrors(['amount' => 'El retiro supera el efectivo disponible en caja: USD 100,00.']);
    $this->assertDatabaseCount('cash_movements', 0);
    expect($opening->refresh()->opening_locked_at)->toBeNull();

    $this->post($store, $first)->assertSessionHasNoErrors();
    $this->post($store, $first)->assertSessionHasNoErrors();
    $this->get($form)->assertInertia(fn (Assert $page) => $page->where('depositCashCents', 3000));
    $second = [...$first, 'request_id' => (string) Str::uuid()];
    $this->post($store, [...$second, 'amount' => '30.01'])
        ->assertSessionHasErrors(['amount' => 'El retiro supera el efectivo disponible en caja: USD 30,00.']);

    $this->post($store, [...$second, 'amount' => '30'])->assertSessionHasNoErrors();
    $this->assertDatabaseCount('cash_movements', 2);
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 0)->where('balances.transit', 10000)->where('balances.total', 10000));

    $returned = CashMovement::query()->where('request_id', $second['request_id'])->sole();
    $this->patch(route('movements.return-to-cash', $returned))->assertSessionHasNoErrors();
    $this->get($form)->assertInertia(fn (Assert $page) => $page->where('depositCashCents', 3000));
    $this->post($store, [...$second, 'request_id' => (string) Str::uuid(), 'amount' => '30'])->assertSessionHasNoErrors();
});
