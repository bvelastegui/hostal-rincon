<?php

use App\Models\CashClosing;
use App\Models\CashOpening;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('los operadores pueden ver el turno pero no consultar el historial ni hacer cierres independientes', function () {
    $this->get(route('cash-closings.index'))->assertRedirectToRoute('login');

    $this->withoutVite()->actingAs(User::factory()->create())
        ->get(route('cash-closings.index'))->assertInertia(fn (Assert $page) => $page
        ->where('closings', null)
        ->where('canClose', false));
    $this->post(route('cash-closings.store'))->assertForbidden();
    $this->post(route('cash-closings.start-shift'))->assertForbidden();
});

test('atribuye el descuadre al turno y ajusta el saldo una sola vez sin cambiar de responsable', function (string $counted, int $cents, int $difference, ?string $note) {
    $admin = User::factory()->create(['is_admin' => true]);
    $responsible = User::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 20000]);
    $this->withoutVite()->actingAs($admin);
    $this->post(route('cash-closings.start-shift'), ['responsible_id' => $responsible->id])
        ->assertRedirectToRoute('cash-closings.index')->assertSessionHasNoErrors();
    $shift = CashShift::query()->sole();

    $data = [
        'request_id' => (string) Str::uuid(),
        'cash_shift_id' => $shift->id,
        'expected_cash_cents' => 20000,
        'counted_cash' => $counted,
        'note' => $note,
    ];

    foreach ([1, 2] as $_) {
        $this->from(route('cash-closings.index'))->post(route('cash-closings.store'), $data)
            ->assertRedirectToRoute('cash-closings.index')->assertSessionHasNoErrors();
    }

    $this->assertDatabaseCount('cash_closings', 1);
    $this->assertDatabaseHas('cash_closings', [
        'closed_by' => $admin->id,
        'cash_shift_id' => $shift->id,
        'expected_cash_cents' => 20000,
        'counted_cash_cents' => $cents,
        'difference_cents' => $difference,
        'cash_adjustment_cents' => $difference,
        'note' => $note,
    ]);

    $this->get(route('cash-closings.index'))->assertInertia(fn (Assert $page) => $page
        ->component('CashClosings')
        ->where('expectedCashCents', $cents)
        ->where('activeShift.id', $shift->id)
        ->where('closings.data.0.responsible', $responsible->name)
        ->where('closings.data.0.closedBy', $admin->name)
        ->where('closings.data.0.differenceCents', $difference)
        ->where('closings.data.0.adjustmentCents', $difference));

    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->has('movements.data', 0)->missing('cashAdjustments')->where('balances.cash', $cents));

    $data['request_id'] = (string) Str::uuid();
    $data['expected_cash_cents'] = $cents;
    $data['note'] = null;
    $this->post(route('cash-closings.store'), $data)->assertSessionHasNoErrors();
    $this->assertDatabaseCount('cash_closings', 2);
    $this->assertDatabaseCount('cash_shifts', 1);
})->with([
    'faltante' => ['195,25', 19525, -475, 'Diferencia en el conteo'],
    'sobrante' => ['204.50', 20450, 450, 'Efectivo adicional'],
    'cuadrado' => ['200', 20000, 0, null],
    'conteo en cero' => ['0', 0, -20000, 'Caja vacía'],
]);

test('la administradora filtra y resuelve faltantes sin volver a ajustar el saldo de caja', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $owner = User::factory()->create();
    $this->withoutVite()->actingAs($admin);
    CashOpening::query()->update(['opening_balance_cents' => 20000]);
    $this->post(route('cash-closings.start-shift'), ['responsible_id' => $owner->id])->assertSessionHasNoErrors();
    $shift = CashShift::query()->sole();
    $data = [
        'request_id' => (string) Str::uuid(),
        'cash_shift_id' => $shift->id,
        'expected_cash_cents' => 20000,
        'counted_cash' => '195',
        'note' => 'Faltante detectado en el corte',
    ];

    $this->post(route('cash-closings.store'), $data)->assertSessionHasNoErrors();
    $shortage = CashClosing::query()->sole();
    $this->post(route('cash-closings.store'), [
        ...$data, 'request_id' => (string) Str::uuid(), 'expected_cash_cents' => 19500, 'note' => null,
    ])->assertSessionHasNoErrors();
    $balanced = CashClosing::query()->latest('id')->firstOrFail();

    $today = now('America/Guayaquil')->toDateString();
    $filters = [
        'type' => 'independent',
        'responsible_id' => $owner->id,
        'difference' => 'shortage',
        'resolution' => 'pending',
        'date_from' => $today,
        'date_to' => $today,
    ];
    $pendingUrl = route('cash-closings.index', $filters);
    $this->get($pendingUrl)->assertInertia(fn (Assert $page) => $page
        ->has('closings.data', 1)->where('closings.data.0.id', $shortage->id)
        ->where('historyResponsibles.0.id', $owner->id));
    $resolutionUrl = route('cash-closings.resolve-shortage', $shortage);
    $resolution = ['method' => 'deduction', 'note' => 'Descuento registrado en el pago'];
    $this->actingAs($owner)->post($resolutionUrl, $resolution)->assertForbidden();
    $this->actingAs($admin)->from($pendingUrl)->post($resolutionUrl, ['method' => 'deduction'])
        ->assertSessionHasErrors('note');
    $this->post(route('cash-closings.resolve-shortage', $balanced), $resolution)->assertSessionHasErrors('method');
    $this->from($pendingUrl)->post($resolutionUrl, $resolution)->assertRedirect($pendingUrl)->assertSessionHasNoErrors();
    $this->post($resolutionUrl, ['method' => 'other', 'note' => 'Intento repetido'])->assertSessionHasNoErrors();
    $this->get($pendingUrl)->assertInertia(fn (Assert $page) => $page->has('closings.data', 0));
    $this->get(route('cash-closings.index', [...$filters, 'resolution' => 'resolved']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('closings.data', 1)
            ->where('closings.data.0.resolution.by', $admin->name)
            ->where('closings.data.0.resolution.method', 'deduction')
            ->where('closings.data.0.resolution.note', $resolution['note'])
            ->where('expectedCashCents', 19500));
    $this->assertDatabaseCount('cash_movements', 0);
});

test('rechaza el cierre sin apertura, con saldo desactualizado o con diferencia sin observación', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    $data = [
        'request_id' => (string) Str::uuid(),
        'expected_cash_cents' => 20000,
        'counted_cash' => '195',
    ];

    $this->post(route('cash-closings.store'), $data)->assertSessionHasErrors('expected_cash_cents');
    CashOpening::query()->update(['opening_balance_cents' => 21000]);
    $this->post(route('cash-closings.store'), $data)->assertSessionHasErrors('expected_cash_cents');
    $this->post(route('cash-closings.store'), [...$data, 'expected_cash_cents' => 21000, 'note' => '  '])
        ->assertSessionHasErrors('note');

    $this->assertDatabaseCount('cash_closings', 0);
});

test('la persona entrante confirma el relevo y la diferencia queda en el turno saliente', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    CashOpening::query()->update(['opening_balance_cents' => 20000]);

    $this->actingAs($admin)->post(route('cash-closings.start-shift'), ['responsible_id' => $outgoing->id])
        ->assertSessionHasNoErrors();
    $shift = CashShift::query()->sole();
    $data = [
        'request_id' => (string) Str::uuid(),
        'cash_shift_id' => $shift->id,
        'expected_cash_cents' => 20000,
        'counted_cash' => '195',
    ];

    $this->actingAs($outgoing)->post(route('cash-closings.receive-shift'), $data)->assertSessionHasErrors('cash_shift_id');
    $this->actingAs($incoming)->post(route('cash-closings.receive-shift'), $data)->assertSessionHasErrors('cash_shift_id');
    $this->assertDatabaseCount('cash_closings', 0);
    expect($shift->fresh()->ended_at)->toBeNull();

    $this->actingAs($outgoing)->post(route('cash-closings.prepare-handoff'), [...$data, 'counted_cash' => '198', 'note' => 'Conteo de salida'])
        ->assertRedirectToRoute('login');
    $this->assertDatabaseHas('cash_closings', [
        'cash_shift_id' => $shift->id,
        'closed_by' => $outgoing->id,
        'type' => 'delivery',
        'counted_cash_cents' => 19800,
        'cash_adjustment_cents' => -200,
    ]);
    expect($shift->fresh()->ended_at)->toBeNull();
    $this->actingAs($admin)->post(route('movements.store'), [
        'request_id' => (string) Str::uuid(),
        'kind' => 'income',
        'payment_method' => 'cash',
        'description' => 'Movimiento durante el relevo',
        'amount' => '2',
    ])->assertSessionHasNoErrors();

    $this->actingAs($incoming);
    $data['request_id'] = (string) Str::uuid();
    $this->post(route('cash-closings.receive-shift'), $data)->assertSessionHasErrors('note');
    $data['note'] = 'Revisado al recibir el turno';
    foreach ([1, 2] as $_) {
        $this->post(route('cash-closings.receive-shift'), $data)
            ->assertRedirectToRoute('cash-closings.index')->assertSessionHasNoErrors();
    }

    $this->assertDatabaseCount('cash_closings', 2);
    $this->assertDatabaseHas('cash_closings', [
        'cash_shift_id' => $shift->id,
        'closed_by' => $incoming->id,
        'type' => 'receipt',
        'cash_adjustment_cents' => -500,
    ]);
    expect($shift->fresh()->ended_at)->not->toBeNull();
    expect(CashShift::query()->whereNull('ended_at')->sole()->responsible_id)->toBe($incoming->id);
    expect(CashShift::query()->whereNull('ended_at')->sole()->opening_cash_cents)->toBe(19500);
    $this->assertDatabaseCount('cash_shifts', 2);

    $this->post(route('cash-closings.receive-shift'), [...$data, 'request_id' => (string) Str::uuid()])
        ->assertSessionHasErrors('cash_shift_id');
    $this->withoutVite()->get(route('cash-closings.index'))->assertInertia(fn (Assert $page) => $page
        ->where('expectedCashCents', 19500)
        ->where('activeShift.responsible', $incoming->name));
});

test('entregar la caja devuelve a quien inicia sesión a la recepción del turno', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $this->withoutVite();
    CashOpening::query()->update(['opening_balance_cents' => 20000]);

    $this->actingAs($admin)->post(route('cash-closings.start-shift'), ['responsible_id' => $outgoing->id])
        ->assertSessionHasNoErrors();
    $data = [
        'request_id' => (string) Str::uuid(),
        'cash_shift_id' => CashShift::query()->sole()->id,
        'expected_cash_cents' => 20000,
        'counted_cash' => '200',
    ];
    $this->post(route('cash-closings.prepare-handoff'), $data)->assertSessionHasErrors('cash_shift_id');
    $target = route('cash-closings.index', ['accion' => 'recibir']);

    $this->actingAs($outgoing)->post(route('cash-closings.prepare-handoff'), $data)
        ->assertRedirectToRoute('login')
        ->assertSessionHas('url.intended', $target);
    $this->assertGuest();
    $this->assertDatabaseCount('cash_closings', 1);
    expect(CashShift::query()->sole()->responsible_id)->toBe($outgoing->id);

    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->where('handoff', 'Para recibir la caja, inicia sesión con tu propia cuenta.'));
    $this->post(route('login.store'), ['email' => $incoming->email, 'password' => 'password'])
        ->assertRedirect($target);
    $this->assertAuthenticatedAs($incoming);
    $this->get($target)->assertInertia(fn (Assert $page) => $page
        ->where('startReceiving', true)
        ->where('canReceive', true)
        ->where('activeShift.responsible', $outgoing->name));
});

test('la administradora puede cortar la caja aunque no haya turno abierto', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    $this->post(route('cash-closings.store'), [
        'request_id' => (string) Str::uuid(),
        'expected_cash_cents' => 10000,
        'counted_cash' => '100',
    ])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('cash_closings', ['cash_shift_id' => null, 'type' => 'independent']);
});

test('durante un turno los empleados solo pueden usar las funciones de caja que les corresponden', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $owner = User::factory()->create();
    $other = User::factory()->create();
    $this->withoutVite();
    CashOpening::query()->update(['opening_balance_cents' => 20000]);

    $this->actingAs($admin)->post(route('cash-closings.start-shift'), ['responsible_id' => $owner->id])
        ->assertSessionHasNoErrors();

    foreach ([$owner, $other] as $employee) {
        $this->actingAs($employee)->get(route('dashboard'))->assertRedirectToRoute('cash-closings.index');
        $this->get(route('profile.edit'))->assertRedirectToRoute('cash-closings.index');
        $this->patch(route('profile.update'), ['name' => 'Nombre nuevo'])->assertForbidden();
    }

    $this->get(route('movements.index'))->assertRedirectToRoute('cash-closings.index');
    $this->get(route('cash-closings.index'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.cashShiftAccess', 'restricted')
        ->where('auth.activeCashShift', null));
    $this->post(route('logout'))->assertRedirect();
    $this->assertGuest();

    $this->actingAs($owner)->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.cashShiftAccess', 'owner'));
    $this->actingAs($admin)->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.activeCashShift.responsible', $owner->name)
        ->where('auth.activeCashShift.awaitingReceipt', false));
    $this->get(route('profile.edit'))->assertOk();
    CashShift::query()->sole()->update(['delivered_cash_cents' => 20000]);
    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.activeCashShift.awaitingReceipt', true));
});

test('el cierre administrativo exige motivo y finaliza el turno sin cambiar la custodia a otra persona', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $owner = User::factory()->create();
    $this->withoutVite();
    CashOpening::query()->update(['opening_balance_cents' => 20000]);
    $this->actingAs($admin)->post(route('cash-closings.start-shift'), ['responsible_id' => $owner->id])
        ->assertSessionHasNoErrors();
    $shift = CashShift::query()->sole();
    $data = [
        'request_id' => (string) Str::uuid(),
        'cash_shift_id' => $shift->id,
        'expected_cash_cents' => 20000,
        'counted_cash' => '200',
    ];

    $this->actingAs($owner)->post(route('cash-closings.administrative-close'), $data)->assertForbidden();
    $this->actingAs($admin)->post(route('cash-closings.administrative-close'), $data)
        ->assertSessionHasErrors('note');
    $this->assertDatabaseCount('cash_closings', 0);
    expect($shift->fresh()->ended_at)->toBeNull();

    $data['note'] = 'La persona responsable se retiró sin completar la entrega.';
    foreach ([1, 2] as $_) {
        $this->post(route('cash-closings.administrative-close'), $data)->assertSessionHasNoErrors();
    }

    expect(CashShift::active())->toBeNull();
    $this->assertDatabaseCount('cash_shifts', 1);
    $this->assertDatabaseCount('cash_closings', 1);
    $this->get(route('cash-closings.index'))->assertInertia(fn (Assert $page) => $page
        ->where('activeShift', null)
        ->where('auth.activeCashShift', null)
        ->where('closings.data.0.type', 'administrative')
        ->where('closings.data.0.responsible', $owner->name)
        ->where('closings.data.0.closedBy', $admin->name)
        ->where('closings.data.0.note', $data['note']));
    $this->actingAs($owner)->get(route('dashboard'))->assertRedirectToRoute('cash-closings.index');
});

test('un empleado puede abrir su caja después de un cierre administrativo', function () {
    $admin = User::factory()->create(['is_admin' => true]);
    $outgoing = User::factory()->create();
    $incoming = User::factory()->create();
    $this->withoutVite();
    CashOpening::query()->update(['opening_balance_cents' => 20000]);

    $this->actingAs($admin)->post(route('cash-closings.start-shift'), ['responsible_id' => $outgoing->id])
        ->assertSessionHasNoErrors();
    $closedShift = CashShift::query()->sole();
    $this->post(route('cash-closings.administrative-close'), [
        'request_id' => (string) Str::uuid(),
        'cash_shift_id' => $closedShift->id,
        'expected_cash_cents' => 20000,
        'counted_cash' => '200',
        'note' => 'Cierre autorizado al final de la jornada',
    ])->assertSessionHasNoErrors();

    $this->actingAs($incoming);
    $this->get(route('movements.index'))->assertRedirectToRoute('cash-closings.index');
    $this->get(route('profile.edit'))->assertRedirectToRoute('cash-closings.index');
    $this->post(route('movements.store'))->assertForbidden();
    $this->get(route('cash-closings.index'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.cashShiftAccess', 'restricted')->where('activeShift', null));

    $data = [
        'request_id' => (string) Str::uuid(),
        'expected_cash_cents' => 20000,
        'counted_cash' => '198',
    ];
    $this->post(route('cash-closings.open-own-shift'), $data)->assertSessionHasErrors('note');
    expect(CashShift::active())->toBeNull();
    $data['note'] = 'Faltante detectado en el conteo inicial';
    foreach ([1, 2] as $_) {
        $this->post(route('cash-closings.open-own-shift'), $data)->assertSessionHasNoErrors();
    }

    $shift = CashShift::query()->whereNull('ended_at')->sole();
    expect($shift->responsible_id)->toBe($incoming->id);
    expect($shift->opening_cash_cents)->toBe(19800);
    $this->assertDatabaseCount('cash_shifts', 2);
    $this->assertDatabaseHas('cash_closings', [
        'cash_shift_id' => $shift->id,
        'type' => 'opening',
        'closed_by' => $incoming->id,
        'cash_adjustment_cents' => -200,
    ]);
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.cashShiftAccess', 'owner')->where('balances.cash', 19800));
    $this->actingAs($outgoing)->post(route('cash-closings.open-own-shift'), [
        ...$data, 'request_id' => (string) Str::uuid(),
    ])->assertSessionHasErrors('cash_shift_id');
    $this->assertDatabaseCount('cash_shifts', 2);
});
