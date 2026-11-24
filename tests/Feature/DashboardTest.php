<?php

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CashOpening;
use App\Models\User;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('authenticated users can visit the dashboard', function () {
    $user = User::factory()->create();
    $this->actingAs($user);

    $this->withoutVite()->get(route('dashboard'))
        ->assertOk()
        ->assertInertia(fn (Assert $page) => $page->where('pendingSettings', []));
});

test('el panel muestra solo la configuración inicial pendiente para la administradora', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))->withoutVite();

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->component('Dashboard')
        ->where('pendingSettings', ['cash_opening', 'active_bank', 'bank_account']));

    $cashOpening = CashOpening::query()->sole();
    $cashOpening->opening_balance_cents = 0;
    $cashOpening->save();
    $activeBank = Bank::factory()->create();
    $inactiveBank = Bank::factory()->create(['is_active' => false]);
    BankAccount::factory()->for($inactiveBank)->create();

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('pendingSettings', ['bank_account']));

    BankAccount::factory()->for($activeBank)->create();

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('pendingSettings', []));

    $activeBank->is_active = false;
    $activeBank->save();

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('pendingSettings', ['active_bank', 'bank_account']));
});
