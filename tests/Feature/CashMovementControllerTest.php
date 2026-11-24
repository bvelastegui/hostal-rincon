<?php

use App\Models\Advance;
use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CashMovement;
use App\Models\CashOpening;
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

test('registra un cobro en efectivo y un egreso una sola vez al guardar', function () {
    $operator = User::factory()->create();
    $responsible = User::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 5000]);
    Advance::factory()->create(['amount_cents' => 1300]);
    Advance::factory()->create(['amount_cents' => 2000, 'cash_opening_id' => 1, 'deducted_at' => now()]);
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
        ->where('balances.total', 11520)
        ->has('movements.data', 2));
    $this->get(route('movements.index', ['date' => '2000-01-01']))->assertInertia(fn (Assert $page) => $page
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
        ->where('balances.bank', 500)
        ->where('movements.data.0.originBankName', $origin->name)
        ->where('movements.data.0.accountBankName', $destination->name));
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
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page->where('balances.bank', 5510));

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
        ->where('balances.bank', 4209)
        ->where('balances.transit', null)
        ->where('balances.total', 14209));
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
        ->where('depositsInTransit.0.id', $movement->id));

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
        ->has('depositsInTransit', 0)
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

test('solo la administradora puede consultar y gestionar depósitos y devoluciones', function () {
    $administrator = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    $account = BankAccount::factory()->create(['opening_balance_cents' => 1000]);
    CashOpening::query()->update(['opening_balance_cents' => 10000]);

    $this->actingAs($administrator)->post(route('movements.store'), [
        'request_id' => (string) Str::uuid(),
        'kind' => 'deposit',
        'amount' => '40.15',
        'responsible_id' => $operator->id,
    ])->assertRedirect()->assertSessionHasNoErrors();
    $movement = CashMovement::query()->sole();
    $confirm = ['bank_account_id' => $account->id, 'voucher' => 'DEP-DEVUELTO'];

    $this->actingAs($operator);
    $this->get(route('movements.create', ['kind' => 'deposit']))->assertForbidden();
    $this->post(route('movements.store'), ['kind' => 'deposit'])->assertForbidden();
    $this->get(route('movements.edit', $movement))->assertForbidden();
    $this->patch(route('movements.update', $movement), $confirm)->assertForbidden();
    $this->patch(route('movements.return-to-cash', $movement))->assertForbidden();
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('canManageDeposits', false)
        ->where('depositsInTransit', [])
        ->where('balances.transit', null)
        ->where('balances.total', 6985)
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
        ->has('depositsInTransit', 0));

    $this->actingAs($operator)->get(route('movements.index'))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 0));
});

test('valida montos y requiere apertura de caja para contabilizar efectivo', function () {
    $this->actingAs(User::factory()->create());
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
    $this->actingAs(User::factory()->create());
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
