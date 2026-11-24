<?php

use App\Models\Advance;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('requiere una cuenta autenticada', function () {
    $this->get(route('advances.index'))->assertRedirect(route('login'));
});

test('permite llegar a 300 y no duplica el registro al reenviar la solicitud', function () {
    $operator = User::factory()->create();
    $employee = User::factory()->create();
    Advance::factory()->for($employee, 'employee')->create(['amount_cents' => 25000, 'issued_on' => '2026-10-31']);
    $data = ['request_id' => (string) Str::uuid(), 'amount' => '50.00'];

    $this->actingAs($operator)->post(route('advances.store', $employee), $data)
        ->assertRedirectToRoute('advances.index')->assertSessionHasNoErrors();
    $this->post(route('advances.store', $employee), [...$data, 'responsible' => 'Otro usuario'])
        ->assertRedirectToRoute('advances.index')->assertSessionHasNoErrors();

    $this->assertDatabaseCount('advances', 2);
    $this->assertDatabaseHas('advances', [
        'employee_id' => $employee->id,
        'request_id' => $data['request_id'],
        'amount_cents' => 5000,
        'responsible' => 'Administración',
        'recorded_by' => $operator->id,
        'deducted_at' => null,
    ]);
    $this->withoutVite()->get(route('advances.index'))->assertInertia(
        fn (Assert $page) => $page->component('Advances')
            ->where('employees', fn ($employees) => collect($employees)->firstWhere('id', $employee->id)['pendingCents'] === 30000)
            ->where('canDeduct', false),
    );
});

test('rechaza adelantos que exceden el tope o tienen precisión inválida', function (int $pending, string $amount) {
    $employee = User::factory()->create();
    Advance::factory()->for($employee, 'employee')->create(['amount_cents' => $pending]);

    $this->actingAs(User::factory()->create())->post(route('advances.store', $employee), [
        'request_id' => (string) Str::uuid(),
        'amount' => $amount,
    ])->assertSessionHasErrors('amount');

    $this->assertDatabaseCount('advances', 1);
})->with([
    'exceso por un centavo' => [25000, '50.01'],
    'límite alcanzado' => [30000, '0.01'],
    'más de dos decimales' => [25000, '0.001'],
    'monto cero' => [25000, '0'],
]);

test('el operador no puede descontar adelantos', function () {
    $advance = Advance::factory()->create();

    $this->actingAs(User::factory()->create())->post(route('advances.deduct', $advance->employee_id), [
        'latest_pending_id' => $advance->id,
    ])->assertForbidden();

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
            ->where('canDeduct', true)
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
    $operator = User::factory()->create();

    $this->actingAs($operator)->post(route('advances.store', $administrator), [
        'request_id' => (string) Str::uuid(),
        'amount' => '50',
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

    $this->actingAs($employee)->withoutVite()->get(route('advances.create', $employee))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->component('AdvanceCreate')
            ->where('employee.id', $employee->id)
            ->where('pendingCents', 25000)
            ->where('limitCents', 30000));
});
