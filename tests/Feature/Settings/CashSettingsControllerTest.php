<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('solo la administración ve y modifica la configuración de caja', function () {
    $account = BankAccount::factory()->create();

    $this->get(route('cash-settings.edit'))->assertRedirectToRoute('login');
    $this->get(route('bank-settings.accounts.create'))->assertRedirectToRoute('login');

    $this->actingAs(User::factory()->create())->withoutVite()->get(route('cash-closings.index'))
        ->assertInertia(fn (Assert $page) => $page->where('auth.canManageCashSettings', false));
    $this->get(route('cash-settings.edit'))->assertRedirectToRoute('cash-closings.index');
    $this->get(route('bank-settings.edit'))->assertRedirectToRoute('cash-closings.index');
    $this->get(route('bank-settings.accounts.create'))->assertRedirectToRoute('cash-closings.index');
    $this->get(route('bank-settings.accounts.edit', $account))->assertRedirectToRoute('cash-closings.index');
    $this->patch(route('bank-settings.status.update', $account->bank), ['is_active' => false])->assertForbidden();
    $this->put(route('cash-settings.opening.update'), ['opening_balance' => '12'])->assertForbidden();
    $this->post(route('bank-settings.store'), ['name' => 'Banco ficticio'])->assertForbidden();
    $this->post(route('bank-settings.accounts.store'), [])->assertForbidden();
    $this->patch(route('bank-settings.accounts.update', $account), ['opening_balance' => '12'])->assertForbidden();

    $this->assertDatabaseHas('cash_openings', ['opening_balance_cents' => null]);
    $this->assertDatabaseCount('banks', 1);
    $this->assertDatabaseHas('banks', ['id' => $account->bank->id, 'is_active' => true]);
    $this->assertDatabaseHas('bank_accounts', ['id' => $account->id, 'opening_balance_cents' => 0]);
});

test('configura y corrige la apertura en USD antes del primer movimiento', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))->withoutVite()
        ->get(route('cash-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page->component('settings/Cash')
            ->where('auth.canManageCashSettings', true)
            ->where('cashOpening.balanceCents', null)
            ->where('cashOpening.locked', false)
            ->missing('banks')
            ->missing('accounts'));

    $this->put(route('cash-settings.opening.update'), ['opening_balance' => '0'])
        ->assertRedirectToRoute('cash-settings.edit')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('cash_openings', ['opening_balance_cents' => 0]);

    $this->put(route('cash-settings.opening.update'), ['opening_balance' => '120,09'])
        ->assertRedirectToRoute('cash-settings.edit')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('cash_openings', ['opening_balance_cents' => 12009, 'opening_locked_at' => null]);
});

test('rechaza saldos iniciales inválidos', function (string $amount) {
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->put(route('cash-settings.opening.update'), ['opening_balance' => $amount])
        ->assertSessionHasErrors('opening_balance');

    $this->assertDatabaseHas('cash_openings', ['opening_balance_cents' => null]);
})->with(['negativo' => '-0.01', 'tres decimales' => '1,001', 'notación científica' => '1e2', 'supera el máximo' => '1000000000']);

test('registra bancos y cuentas con saldos exactos y detecta duplicados', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->post(route('bank-settings.store'), ['name' => 'Banco de prueba'])
        ->assertRedirectToRoute('bank-settings.edit')->assertSessionHasNoErrors();
    $bank = Bank::query()->sole();
    $this->post(route('bank-settings.store'), ['name' => 'BANCO   DE PRUEBA'])
        ->assertSessionHasErrors('name');

    $data = ['bank_id' => $bank->id, 'name' => 'Operación', 'opening_balance' => '250.42'];
    $this->post(route('bank-settings.accounts.store'), $data)
        ->assertRedirectToRoute('bank-settings.edit')->assertSessionHasNoErrors();
    $account = BankAccount::query()->sole();
    $this->assertDatabaseHas('bank_accounts', [
        'id' => $account->id,
        'bank_id' => $bank->id,
        'opening_balance_cents' => 25042,
    ]);

    $this->post(route('bank-settings.accounts.store'), [...$data, 'name' => 'OPERACIÓN'])
        ->assertSessionHasErrors('name');
    $this->post(route('bank-settings.accounts.store'), [...$data, 'bank_id' => $bank->id + 1000])
        ->assertSessionHasErrors('bank_id');
    $this->assertDatabaseCount('banks', 1);
    $this->assertDatabaseCount('bank_accounts', 1);

    $otherBank = Bank::factory()->create();
    $this->post(route('bank-settings.accounts.store'), [...$data, 'bank_id' => $otherBank->id])
        ->assertRedirectToRoute('bank-settings.edit')->assertSessionHasNoErrors();
    $this->assertDatabaseCount('bank_accounts', 2);
});

test('abre la vista de nueva cuenta con los bancos activos y devuelve allí los errores', function () {
    $activeBank = Bank::factory()->create();
    $inactiveBank = Bank::factory()->create(['is_active' => false]);
    $createUrl = route('bank-settings.accounts.create');

    $this->actingAs(User::factory()->create(['is_admin' => true]))->withoutVite()
        ->get($createUrl)
        ->assertInertia(fn (Assert $page) => $page->component('settings/BankAccountForm')
            ->where('account', null)
            ->has('banks', 1)
            ->where('banks.0.id', $activeBank->id)
            ->missing('accounts'));

    $this->from($createUrl)->post(route('bank-settings.accounts.store'), [
        'bank_id' => $inactiveBank->id,
        'name' => 'Operación',
        'opening_balance' => '25',
    ])->assertRedirect($createUrl)->assertSessionHasErrors('bank_id');
    $this->assertDatabaseCount('bank_accounts', 0);

    $this->patch(route('bank-settings.status.update', $activeBank), ['is_active' => false]);
    $this->get($createUrl)->assertInertia(fn (Assert $page) => $page->has('banks', 0));
});

test('permite editar el nombre de una cuenta aunque su saldo inicial esté bloqueado', function () {
    $account = BankAccount::factory()->create();
    $updateUrl = route('bank-settings.accounts.update', $account);
    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->patch($updateUrl, ['name' => 'Cuenta operativa', 'opening_balance' => '8,11'])
        ->assertRedirectToRoute('bank-settings.edit')->assertSessionHasNoErrors();

    $account->refresh();
    $account->opening_locked_at = now();
    $account->save();

    $editUrl = route('bank-settings.accounts.edit', $account);
    $this->withoutVite()->get($editUrl)
        ->assertInertia(fn (Assert $page) => $page->component('settings/BankAccountForm')
            ->has('banks', 0)
            ->where('account.id', $account->id)
            ->where('account.name', 'Cuenta operativa')
            ->where('account.bankName', $account->bank->name)
            ->where('account.balanceCents', 811)
            ->where('account.locked', true));

    $this->from($editUrl)->patch($updateUrl, ['name' => 'Cambio rechazado', 'opening_balance' => '9'])
        ->assertRedirect($editUrl)->assertSessionHasErrors('opening_balance');
    $this->assertDatabaseHas('bank_accounts', [
        'id' => $account->id,
        'name' => 'Cuenta operativa',
        'opening_balance_cents' => 811,
    ]);

    $otherBank = Bank::factory()->create();
    $this->patch($updateUrl, ['name' => 'Cuenta principal', 'bank_id' => $otherBank->id])
        ->assertRedirectToRoute('bank-settings.edit')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('bank_accounts', [
        'id' => $account->id,
        'name_key' => 'cuenta principal',
        'bank_id' => $account->bank->id,
        'opening_balance_cents' => 811,
    ]);
    $this->withoutVite()->get(route('bank-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('accounts.0.name', 'Cuenta principal')
            ->where('accounts.0.bankName', $account->bank->name)
            ->where('accounts.0.balanceCents', 811)
            ->where('accounts.0.locked', true));
});

test('solo permite aliases distintos dentro del mismo banco al editar una cuenta', function () {
    $bank = Bank::factory()->create();
    $account = BankAccount::factory()->for($bank)->create(['opening_balance_cents' => 2500]);
    BankAccount::factory()->for($bank)->create(['name' => 'Cuenta principal', 'name_key' => 'cuenta principal']);
    BankAccount::factory()->create(['name' => 'Cuenta alternativa', 'name_key' => 'cuenta alternativa']);
    $updateUrl = route('bank-settings.accounts.update', $account);
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->patch($updateUrl, ['name' => 'CUENTA   PRINCIPAL', 'opening_balance' => '30'])
        ->assertSessionHasErrors('name');
    $this->assertDatabaseHas('bank_accounts', [
        'id' => $account->id,
        'name' => $account->name,
        'opening_balance_cents' => 2500,
    ]);

    $this->patch($updateUrl, ['name' => 'Cuenta   alternativa'])
        ->assertRedirectToRoute('bank-settings.edit')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('bank_accounts', [
        'id' => $account->id,
        'name' => 'Cuenta alternativa',
        'opening_balance_cents' => 2500,
    ]);

    $this->patch($updateUrl, ['opening_balance' => '0'])
        ->assertRedirectToRoute('bank-settings.edit')->assertSessionHasNoErrors();
    $this->assertDatabaseHas('bank_accounts', ['id' => $account->id, 'opening_balance_cents' => 0]);
});

test('desactiva y reactiva bancos sin afectar cuentas existentes', function () {
    $account = BankAccount::factory()->create(['opening_balance_cents' => 12345]);
    $bank = $account->bank;
    $this->actingAs(User::factory()->create(['is_admin' => true]))->withoutVite()
        ->get(route('bank-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page->component('settings/Banks')
            ->missing('cashOpening')
            ->where('banks.0.active', true));

    $statusRoute = route('bank-settings.status.update', $bank);
    $this->patch($statusRoute, ['is_active' => 'no admitido'])->assertSessionHasErrors('is_active');
    $this->assertDatabaseHas('banks', ['id' => $bank->id, 'is_active' => true]);
    foreach ([false, false] as $active) {
        $this->patch($statusRoute, ['is_active' => $active])
            ->assertRedirectToRoute('bank-settings.edit')->assertSessionHasNoErrors();
    }

    $this->get(route('bank-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('banks.0.active', false)
            ->where('accounts.0.bankActive', false)
            ->where('accounts.0.balanceCents', 12345));

    $data = ['bank_id' => $bank->id, 'name' => 'Cuenta nueva', 'opening_balance' => '100'];
    $this->post(route('bank-settings.accounts.store'), $data)->assertSessionHasErrors('bank_id');
    $this->assertDatabaseCount('bank_accounts', 1);

    $this->patch($statusRoute, ['is_active' => true])
        ->assertRedirectToRoute('bank-settings.edit')->assertSessionHasNoErrors();
    $this->post(route('bank-settings.accounts.store'), $data)
        ->assertRedirectToRoute('bank-settings.edit')->assertSessionHasNoErrors();
    $this->assertDatabaseCount('bank_accounts', 2);
});

test('un adelanto registra el uso de la apertura vigente y bloquea su edición', function () {
    $employee = User::factory()->create();
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $earlierRequest = (string) Str::uuid();
    $this->post(route('advances.store', $employee), ['request_id' => $earlierRequest, 'amount' => '10'])
        ->assertSessionHasNoErrors();
    $this->put(route('cash-settings.opening.update'), ['opening_balance' => '100,00'])
        ->assertSessionHasNoErrors();
    $this->assertDatabaseHas('cash_openings', ['opening_balance_cents' => 10000, 'opening_locked_at' => null]);

    $laterRequest = (string) Str::uuid();
    $this->post(route('advances.store', $employee), ['request_id' => $laterRequest, 'amount' => '10'])
        ->assertSessionHasNoErrors();

    $this->put(route('cash-settings.opening.update'), ['opening_balance' => '200'])
        ->assertSessionHasErrors('opening_balance');
    $this->assertDatabaseHas('advances', ['request_id' => $earlierRequest, 'cash_opening_id' => null]);
    $this->assertDatabaseHas('advances', ['request_id' => $laterRequest, 'cash_opening_id' => 1]);

    $this->withoutVite()->get(route('cash-settings.edit'))
        ->assertInertia(fn (Assert $page) => $page
            ->where('cashOpening.balanceCents', 10000)
            ->where('cashOpening.locked', true));
});
