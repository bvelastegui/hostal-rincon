<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CashClosing;
use App\Models\CashMovement;
use App\Models\CashOpening;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    $this->withoutVite();
});

function startCorrectionShift(User $operator): CashShift
{
    return CashShift::query()->create([
        'responsible_id' => $operator->id,
        'responsible_name' => $operator->name,
        'started_by' => $operator->id,
        'started_at' => now(),
    ]);
}

/** @return array<string, int|string> */
function correctionMovement(array $attributes = []): array
{
    return [...[
        'request_id' => (string) Str::uuid(),
        'kind' => 'expense',
        'payment_method' => 'cash',
        'description' => 'Insumos de recepción',
        'amount' => '40',
    ], ...$attributes];
}

test('Administración reemplaza un egreso y conserva el movimiento original para auditoría', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    $shift = startCorrectionShift($operator);
    $this->actingAs($operator)->post(route('movements.store'), correctionMovement())->assertSessionHasNoErrors();
    $original = CashMovement::query()->sole();
    $correction = route('movements.correction', $original);
    $replace = route('movements.replace', $original);
    $annul = route('movements.annul', $original);
    $replacement = correctionMovement(['amount' => '70', 'description' => 'Insumos y transporte', 'void_reason' => 'El primer registro omitió el transporte.']);

    $this->get($correction)->assertForbidden();
    $this->post($replace, $replacement)->assertForbidden();
    $this->patch($annul, ['void_reason' => 'Prueba de permisos'])->assertForbidden();

    $this->actingAs($admin)->get($correction)->assertInertia(fn (Assert $page) => $page
        ->component('CashMovementForm')
        ->where('replacement.id', $original->id)
        ->where('replacement.amount', '40,00'));
    $this->post($replace, [...$replacement, 'void_reason' => ''])->assertSessionHasErrors('void_reason');
    $this->post($replace, [...$replacement, 'amount' => '100.01'])->assertSessionHasErrors('amount');
    $this->assertDatabaseCount('cash_movements', 1);
    expect($original->refresh()->voided_at)->toBeNull();

    $this->post($replace, $replacement)->assertRedirectToRoute('movements.index', ['date' => $original->occurred_on])
        ->assertSessionHasNoErrors();
    $this->post($replace, $replacement)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('cash_movements', 2);
    $new = CashMovement::query()->where('replaces_movement_id', $original->id)->sole();
    $this->assertDatabaseHas('cash_movements', [
        'id' => $original->id,
        'amount_cents' => 4000,
        'recorded_by' => $operator->id,
        'voided_by' => $admin->id,
        'voided_by_name' => $admin->name,
        'void_reason' => $replacement['void_reason'],
    ]);
    $this->assertDatabaseHas('cash_movements', [
        'id' => $new->id,
        'amount_cents' => 7000,
        'cash_shift_id' => $shift->id,
        'recorded_by' => $admin->id,
        'occurred_on' => $original->occurred_on,
    ]);
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 3000)
        ->where('movements.total', 1)
        ->where('movements.data.0.replacesId', $original->id)
        ->where('movements.data.0.expandHistory', false)
        ->where('movements.data.0.children.0.replacementId', $new->id)
        ->where('movements.data.0.children.0.status', 'voided')
        ->where('movements.data.0.children.0.voidReason', $replacement['void_reason']));
    $this->get(route('reports.monthly'))->assertInertia(fn (Assert $page) => $page
        ->has('movements', 1)->where('movements.0.confirmedCents', 7000));
    $this->actingAs($operator)->get(route('movements.index', ['status' => 'voided']))
        ->assertInertia(fn (Assert $page) => $page->has('movements.data', 1)
            ->where('movements.data.0.id', $new->id)
            ->where('movements.data.0.expandHistory', true)
            ->where('movements.data.0.children.0.status', 'voided')
            ->missing('movements.data.0.children.0.voidReason'));
});

test('el cierre fija el límite por movimiento incluso si los registros comparten el mismo segundo', function () {
    $this->freezeSecond();
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    $shift = startCorrectionShift($operator);
    $this->actingAs($admin)->post(route('movements.store'), correctionMovement())->assertSessionHasNoErrors();
    $first = CashMovement::query()->sole();
    $this->post(route('cash-closings.store'), [
        'request_id' => (string) Str::uuid(),
        'cash_shift_id' => $shift->id,
        'expected_cash_cents' => 6000,
        'counted_cash' => '60',
    ])->assertSessionHasNoErrors();
    $closing = CashClosing::query()->sole();

    $this->post(route('movements.store'), correctionMovement(['amount' => '10']))->assertSessionHasNoErrors();
    $second = CashMovement::query()->latest('id')->firstOrFail();
    $this->get(route('movements.correction', $first))->assertForbidden();
    $this->post(route('movements.replace', $first), correctionMovement(['void_reason' => 'Importe erróneo']))
        ->assertSessionHasErrors('movement');

    $this->patch(route('movements.annul', $second), ['void_reason' => ''])
        ->assertSessionHasErrors('void_reason');
    $this->patch(route('movements.annul', $second), ['void_reason' => 'Registro duplicado'])
        ->assertSessionHasNoErrors();
    $this->patch(route('movements.annul', $second), ['void_reason' => 'Registro duplicado'])
        ->assertSessionHasNoErrors();
    $this->assertDatabaseCount('cash_closings', 1);
    expect($closing->refresh()->last_movement_id)->toBe($first->id);
    expect($second->refresh()->voided_at)->not->toBeNull();
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page->where('balances.cash', 6000));
});

test('bloquea correcciones de un turno entregado, sin turno, de otro turno o de un cierre anterior a la migración', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    $this->actingAs($admin)->post(route('movements.store'), correctionMovement(['amount' => '10']))->assertSessionHasNoErrors();
    $withoutShift = CashMovement::query()->sole();
    $shift = startCorrectionShift($operator);
    $this->post(route('movements.store'), correctionMovement(['amount' => '10']))->assertSessionHasNoErrors();
    $movement = CashMovement::query()->latest('id')->firstOrFail();
    $annul = route('movements.annul', $movement);
    $reason = ['void_reason' => 'Registro duplicado'];

    $this->patch(route('movements.annul', $withoutShift), $reason)->assertSessionHasErrors('movement');
    $this->post(route('cash-closings.store'), [
        'request_id' => (string) Str::uuid(),
        'cash_shift_id' => $shift->id,
        'expected_cash_cents' => 8000,
        'counted_cash' => '80',
    ])->assertSessionHasNoErrors();
    CashClosing::query()->update(['last_movement_id' => null]);
    $this->patch($annul, $reason)->assertSessionHasErrors(['movement' => 'El movimiento ya está incluido en un cierre de caja.']);

    $shift->update(['delivered_cash_cents' => 8000]);
    $this->patch($annul, $reason)->assertSessionHasErrors('movement');
    $shift->update(['ended_at' => now()]);
    startCorrectionShift($operator);
    $this->patch($annul, $reason)->assertSessionHasErrors('movement');
    expect($movement->refresh()->voided_at)->toBeNull();
});

test('mantiene el voucher auditado y permite reutilizarlo en el reemplazo de un depósito acreditado', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    $account = BankAccount::factory()->create(['opening_balance_cents' => 500]);
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    startCorrectionShift($operator);
    $this->actingAs($admin)->post(route('movements.store'), correctionMovement([
        'kind' => 'deposit', 'amount' => '30', 'responsible_id' => $operator->id,
    ]))->assertSessionHasNoErrors();
    $deposit = CashMovement::query()->sole();
    $this->patch(route('movements.update', $deposit), [
        'bank_account_id' => $account->id, 'voucher' => 'DEP-01',
    ])->assertSessionHasNoErrors();

    $this->post(route('movements.replace', $deposit), correctionMovement([
        'kind' => 'deposit', 'amount' => '25', 'responsible_id' => $operator->id,
        'bank_account_id' => $account->id, 'voucher' => 'DEP-01', 'void_reason' => 'Error al transcribir el importe.',
    ]))->assertSessionHasNoErrors();

    $this->assertDatabaseHas('cash_movements', ['id' => $deposit->id, 'voided_voucher' => 'DEP-01', 'voucher' => null]);
    $this->assertDatabaseHas('cash_movements', ['replaces_movement_id' => $deposit->id, 'voucher' => 'DEP-01', 'amount_cents' => 2500]);
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 7500)->where('balances.bank', 3000)->where('balances.transit', 0)
        ->where('movements.data.0.status', 'confirmed')
        ->where('movements.data.0.children.0.status', 'voided')
        ->where('movements.data.0.children.0.voucher', 'DEP-01'));
    $this->patch(route('movements.return-to-cash', $deposit))->assertSessionHasErrors('movement');
});

test('la anulación de un cobro acreditado se rechaza si dejaría la cuenta sin fondos', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    $origin = Bank::factory()->create();
    $account = BankAccount::factory()->create(['opening_balance_cents' => 0]);
    startCorrectionShift($operator);
    $this->actingAs($admin)->post(route('movements.store'), correctionMovement([
        'kind' => 'income', 'payment_method' => 'transfer', 'amount' => '40',
        'origin_bank_id' => $origin->id, 'bank_account_id' => $account->id, 'voucher' => 'COBRO-01', 'confirm' => true,
    ]))->assertSessionHasNoErrors();
    $income = CashMovement::query()->sole();
    $this->post(route('movements.store'), correctionMovement([
        'payment_method' => 'transfer', 'amount' => '30',
        'bank_account_id' => $account->id, 'voucher' => 'PAGO-01',
    ]))->assertSessionHasNoErrors();

    $this->patch(route('movements.annul', $income), ['void_reason' => 'Registro equivocado'])->assertSessionHasErrors('movement');

    $this->assertDatabaseHas('cash_movements', ['id' => $income->id, 'voided_at' => null, 'voucher' => 'COBRO-01']);
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page->where('balances.bank', 1000));
});

test('las correcciones sucesivas comparten página y los filtros encuentran el original', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    startCorrectionShift($operator);
    $this->actingAs($operator)->post(route('movements.store'), correctionMovement())->assertSessionHasNoErrors();
    $original = CashMovement::query()->sole();
    $this->actingAs($admin)->post(route('movements.replace', $original), correctionMovement([
        'amount' => '30', 'void_reason' => 'Primer ajuste.',
    ]))->assertSessionHasNoErrors();
    $firstReplacement = CashMovement::query()->where('replaces_movement_id', $original->id)->sole();
    $this->post(route('movements.replace', $firstReplacement), correctionMovement([
        'amount' => '20', 'void_reason' => 'Segundo ajuste.',
    ]))->assertSessionHasNoErrors();
    $lastReplacement = CashMovement::query()->where('replaces_movement_id', $firstReplacement->id)->sole();
    foreach (range(1, 10) as $index) {
        $this->post(route('movements.store'), correctionMovement([
            'description' => 'Egreso adicional '.$index, 'amount' => '1',
        ]))->assertSessionHasNoErrors();
    }

    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('movements.total', 11)->has('movements.data', 10));
    $this->get(route('movements.index', ['page' => 2]))->assertInertia(fn (Assert $page) => $page
        ->has('movements.data', 1)
        ->where('movements.data.0.id', $lastReplacement->id)
        ->where('movements.data.0.children.0.id', $firstReplacement->id)
        ->where('movements.data.0.children.0.children.0.id', $original->id));
    $this->get(route('movements.index', ['recorded_by' => $operator->id]))->assertInertia(fn (Assert $page) => $page
        ->where('movements.total', 1)
        ->where('movements.data.0.expandHistory', true)
        ->where('movements.data.0.children.0.expandHistory', true)
        ->where('movements.data.0.children.0.children.0.id', $original->id));
});

test('un retiro reemplazado en tránsito conserva su original solo en el grupo visible para Administración', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $operator = User::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    startCorrectionShift($operator);
    $deposit = ['kind' => 'deposit', 'amount' => '30', 'responsible_id' => $operator->id];
    $this->actingAs($admin)->post(route('movements.store'), correctionMovement($deposit))->assertSessionHasNoErrors();
    $original = CashMovement::query()->sole();
    $this->post(route('movements.replace', $original), correctionMovement([
        ...$deposit, 'is_sensitive' => 1, 'void_reason' => 'Corrección de custodia.',
    ]))->assertSessionHasNoErrors();

    $this->get(route('movements.index', ['status' => 'voided']))->assertInertia(fn (Assert $page) => $page
        ->where('movements.total', 0)
        ->where('depositsInTransit.total', 1)
        ->where('depositsInTransit.data.0.status', 'in_transit')
        ->where('depositsInTransit.data.0.expandHistory', true)
        ->where('depositsInTransit.data.0.children.0.id', $original->id));
    $this->actingAs($operator)->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->has('movements.data', 1)
        ->where('movements.data.0.redacted', true)
        ->missing('movements.data.0.children'));
});
