<?php

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Inertia\Testing\AssertableInertia as Assert;

beforeEach(function () {
    config(['app.desktop' => true]);
    $this->withoutVite();
});

function initialSetupData(array $overrides = []): array
{
    return array_replace([
        'name' => 'Administración Hostal',
        'email' => 'Admin@hostal.test',
        'password' => 'Hostal-Prueba123!',
        'password_confirmation' => 'Hostal-Prueba123!',
        'opening_balance' => '0',
    ], $overrides);
}

test('la configuración inicial se habilita únicamente para escritorio', function () {
    config(['app.desktop' => false]);

    $this->get(route('home'))->assertRedirectToRoute('login');
    $this->get(route('initial-setup.create'))->assertNotFound();
    $this->post(route('initial-setup.store'))->assertNotFound();
    $this->assertDatabaseCount('users', 0);
});

test('el primer inicio registra la cuenta administradora, el efectivo y las cuentas de un mismo banco', function () {
    $this->get(route('home'))->assertRedirectToRoute('initial-setup.create');
    $this->get(route('initial-setup.create'))->assertInertia(fn (Assert $page) => $page->component('auth/InitialSetup'));

    $this->post(route('initial-setup.store'), initialSetupData([
        'opening_balance' => '45,12',
        'accounts' => [
            ['bank' => 'Banco Ejemplo', 'name' => 'Principal', 'opening_balance' => '102,25'],
            ['bank' => 'banco ejemplo', 'name' => 'Caja chica', 'opening_balance' => '0'],
        ],
    ]))->assertSessionHasNoErrors()->assertRedirectToRoute('dashboard');

    $user = User::query()->sole();
    $this->assertAuthenticatedAs($user);
    expect($user->is_admin)->toBeTrue()
        ->and($user->email)->toBe('admin@hostal.test')
        ->and(Hash::check('Hostal-Prueba123!', $user->password))->toBeTrue();
    $this->assertDatabaseHas('cash_openings', ['opening_balance_cents' => 4512]);
    $this->assertDatabaseCount('banks', 1);
    $this->assertDatabaseHas('bank_accounts', ['name' => 'Principal', 'opening_balance_cents' => 10225]);
    $this->assertDatabaseHas('bank_accounts', ['name' => 'Caja chica', 'opening_balance_cents' => 0]);

    $this->get(route('initial-setup.create'))->assertNotFound();
    $this->post(route('initial-setup.store'))->assertNotFound();
});

test('un alias duplicado revierte todos los datos y permite configurar de nuevo sin cuentas', function () {
    $this->from(route('initial-setup.create'))
        ->post(route('initial-setup.store'), initialSetupData([
            'accounts' => [
                ['bank' => 'Banco Ejemplo', 'name' => 'Principal', 'opening_balance' => '20'],
                ['bank' => 'banco ejemplo', 'name' => 'PRINCIPAL', 'opening_balance' => '30'],
            ],
        ]))->assertSessionHasErrors('accounts.1.name');
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('banks', 0);
    $this->assertDatabaseCount('bank_accounts', 0);
    $this->assertDatabaseHas('cash_openings', ['opening_balance_cents' => null]);

    $this->post(route('initial-setup.store'), initialSetupData())->assertRedirectToRoute('dashboard');
    $this->assertDatabaseHas('cash_openings', ['opening_balance_cents' => 0]);
    $this->assertDatabaseCount('bank_accounts', 0);
});

test('una instalación con usuarios conserva el inicio de sesión', function () {
    User::factory()->create(['is_admin' => true]);

    $this->get(route('home'))->assertRedirectToRoute('login');
    $this->get(route('initial-setup.create'))->assertNotFound();
    $this->post(route('initial-setup.store'))->assertNotFound();
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseHas('cash_openings', ['opening_balance_cents' => null]);
});
