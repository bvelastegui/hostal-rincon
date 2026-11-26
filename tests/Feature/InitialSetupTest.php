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
        'username' => 'Admin.Hostal',
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

test('Laravel valida la cuenta y el efectivo por paso sin guardar la configuración', function () {
    $url = route('initial-setup.store');
    $account = ['Precognition' => 'true', 'Precognition-Validate-Only' => 'name,username,password'];
    $cash = ['Precognition' => 'true', 'Precognition-Validate-Only' => 'opening_balance'];

    $this->postJson($url, [], $account)->assertUnprocessable()
        ->assertJsonValidationErrors(['name', 'username', 'password'])
        ->assertJsonMissingValidationErrors('opening_balance');

    $data = initialSetupData();
    unset($data['opening_balance']);

    $this->postJson($url, [...$data, 'password_confirmation' => 'Otra'], $account)
        ->assertUnprocessable()->assertJsonValidationErrors('password');

    $this->postJson($url, $data, $account)
        ->assertNoContent()->assertHeader('Precognition-Success', 'true');

    $this->postJson($url, ['opening_balance' => '-0,01'], $cash)
        ->assertUnprocessable()->assertJsonValidationErrors('opening_balance')
        ->assertJsonMissingValidationErrors('username');

    $this->postJson($url, ['opening_balance' => '125,50'], $cash)
        ->assertNoContent()->assertHeader('Precognition-Success', 'true');

    $banks = ['Precognition' => 'true', 'Precognition-Validate-Only' => 'accounts,accounts.*,accounts.*.bank,accounts.*.name,accounts.*.opening_balance'];
    $this->postJson($url, ['accounts' => [['bank' => 'Banco', 'name' => '', 'opening_balance' => '0']]], $banks)
        ->assertUnprocessable()->assertJsonValidationErrors('accounts.0.name');
    $this->postJson($url, [], $banks)->assertNoContent();

    $this->assertGuest();
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('bank_accounts', 0);
    $this->assertDatabaseHas('cash_openings', ['opening_balance_cents' => null]);
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
        ->and($user->username)->toBe('admin.hostal')
        ->and($user->email)->toBeNull()
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

test('el asistente guarda operadores opcionales con límites individuales y protege sus contraseñas', function () {
    $first = [
        'name' => 'Recepción en la mañana',
        'username' => 'Recepcion.Manana',
        'password' => ' Prueba123! ',
        'password_confirmation' => ' Prueba123! ',
        'advance_limit' => '125,50',
    ];
    $second = [
        ...$first,
        'name' => 'Recepción en la tarde',
        'username' => 'recepcion.tarde',
        'advance_limit' => '0',
    ];
    $url = route('initial-setup.store');
    $this->from(route('initial-setup.create'))->post($url, initialSetupData([
        'employees' => [$first, [...$second, 'username' => 'recepcion.manana']],
    ]))->assertSessionHasErrors(['employees.0.username', 'employees.1.username'])
        ->assertSessionMissing('_old_input.employees');
    $this->post($url, initialSetupData([
        'employees' => [[...$first, 'username' => 'admin.hostal', 'password_confirmation' => 'distinta']],
    ]))->assertSessionHasErrors(['employees.0.username', 'employees.0.password']);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseHas('cash_openings', ['opening_balance_cents' => null]);

    $this->post($url, initialSetupData(['employees' => [$first, $second]]))
        ->assertSessionHasNoErrors()->assertRedirectToRoute('dashboard');

    expect(User::query()->where('is_admin', true)->sole()->username)->toBe('admin.hostal');
    $this->assertAuthenticatedAs(User::query()->where('is_admin', true)->sole());
    $operator = User::query()->operators()->where('username', 'recepcion.manana')->sole();
    expect($operator->advance_limit_cents)->toBe(12_550)
        ->and($operator->email)->toBeNull()
        ->and(Hash::check(' Prueba123! ', $operator->password))->toBeTrue();
    $this->assertDatabaseHas('users', ['username' => 'recepcion.tarde', 'is_admin' => false, 'advance_limit_cents' => 0]);
});

test('una instalación con usuarios conserva el inicio de sesión', function () {
    User::factory()->create(['is_admin' => true]);

    $this->get(route('home'))->assertRedirectToRoute('login');
    $this->get(route('initial-setup.create'))->assertNotFound();
    $this->post(route('initial-setup.store'))->assertNotFound();
    $this->assertDatabaseCount('users', 1);
    $this->assertDatabaseHas('cash_openings', ['opening_balance_cents' => null]);
});

test('el acceso de escritorio usa el nombre de usuario y conserva la contraseña', function () {
    config(['fortify.username' => 'username']);
    $this->post(route('initial-setup.store'), initialSetupData())->assertRedirectToRoute('dashboard');
    $user = User::query()->sole();

    $this->post(route('logout'))->assertRedirect();
    $this->get(route('login'))->assertInertia(fn (Assert $page) => $page
        ->component('auth/Login')
        ->where('auth.loginField', 'username'));
    $this->post(route('login.store'), [
        'email' => 'admin@hostal.test',
        'password' => 'Hostal-Prueba123!',
    ])->assertSessionHasErrors('username');
    $this->assertGuest();
    $this->post(route('login.store'), [
        'username' => 'ADMIN.HOSTAL',
        'password' => 'Hostal-Prueba123!',
    ])->assertRedirectToRoute('dashboard');
    $this->assertAuthenticatedAs($user);
});

test('administración crea y edita empleados de escritorio sin pedir correo', function () {
    $administrator = User::factory()->create([
        'is_admin' => true,
        'username' => 'admin.hostal',
        'email' => null,
    ]);
    $employeeData = [
        'name' => 'Recepción',
        'username' => 'Recepcion.Uno',
        'password' => 'Hostal-Prueba123!',
        'password_confirmation' => 'Hostal-Prueba123!',
    ];

    $this->actingAs($administrator);
    $this->post(route('advances.employees.store'), $employeeData)
        ->assertSessionHasNoErrors()->assertRedirectToRoute('advances.index');
    $employee = User::query()->operators()->sole();
    expect($employee->username)->toBe('recepcion.uno')
        ->and($employee->email)->toBeNull();
    $this->put(route('advances.employees.update', $employee), [
        'name' => 'Recepción Principal',
        'username' => 'ADMIN.HOSTAL',
    ])->assertSessionHasErrors('username');

    $this->put(route('advances.employees.update', $employee), [
        'name' => 'Recepción Principal',
        'username' => 'recepcion.dos',
    ])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('users', ['id' => $employee->id, 'username' => 'recepcion.dos', 'email' => null]);

    $this->patch(route('profile.update'), [
        'name' => $administrator->name,
        'username' => 'administracion',
    ])->assertSessionHasNoErrors();
    $this->assertDatabaseHas('users', ['id' => $administrator->id, 'username' => 'administracion', 'email' => null]);
});
