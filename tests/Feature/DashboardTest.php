<?php

use App\Models\BankAccount;
use App\Models\CashClosing;
use App\Models\CashMovement;
use App\Models\CashOpening;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;

test('guests are redirected to the login page', function () {
    $response = $this->get(route('dashboard'));
    $response->assertRedirect(route('login'));
});

test('los operadores son enviados a Turno incluso si tienen la caja abierta', function () {
    $operator = User::factory()->create();
    $this->actingAs($operator)->get(route('dashboard'))->assertRedirectToRoute('cash-closings.index');

    CashShift::query()->create([
        'responsible_id' => $operator->id,
        'responsible_name' => $operator->name,
        'started_by' => $operator->id,
        'started_at' => now(),
    ]);

    $this->get(route('dashboard'))->assertRedirectToRoute('cash-closings.index');
});

test('el panel muestra los saldos sin configurar y no carga el widget de configuración inicial', function () {
    $this->actingAs(User::factory()->create(['is_admin' => true]))->withoutVite();

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page->component('Dashboard')
        ->missing('pendingSettings')
        ->where('balances.cash', null)
        ->where('balances.total', null)
        ->where('attention.transfers', ['count' => 0, 'amountCents' => 0, 'dateFrom' => null, 'dateTo' => null])
        ->where('attention.deposits', ['count' => 0, 'amountCents' => 0])
        ->where('attention.shortages', ['count' => 0, 'amountCents' => 0]));
});

test('el panel concilia los saldos y enlaza únicamente transferencias, depósitos y faltantes pendientes', function () {
    $this->travelTo(now()->setDate(2026, 11, 24)->setTime(18, 0));
    $admin = User::factory()->create(['is_admin' => true]);
    $this->withoutVite()->actingAs($admin);
    $account = BankAccount::factory()->create(['opening_balance_cents' => 20000]);
    $opening = CashOpening::query()->sole();
    $opening->opening_balance_cents = 10000;
    $opening->save();

    $record = fn (array $details): CashMovement => CashMovement::query()->forceCreate([
        'request_id' => (string) Str::uuid(),
        'request_fingerprint' => (string) Str::uuid(),
        'kind' => 'income',
        'payment_method' => 'transfer',
        'description' => 'Movimiento de prueba',
        'occurred_on' => '2026-11-24',
        'recorded_by' => $admin->id,
        'recorder_name' => $admin->name,
        'bank_account_id' => $account->id,
        ...$details,
    ]);
    $olderTransfer = $record(['occurred_on' => '2026-11-21', 'amount_cents' => 2500]);
    $currentTransfer = $record(['amount_cents' => 1800]);
    $record(['amount_cents' => 5000, 'confirmed_at' => now()]);
    $record(['payment_method' => 'cash', 'bank_account_id' => null, 'amount_cents' => 1000, 'confirmed_at' => now()]);
    $deposit = ['kind' => 'deposit', 'payment_method' => 'cash', 'cash_opening_id' => $opening->id];
    $inTransit = $record([...$deposit, 'occurred_on' => '2026-11-21', 'bank_account_id' => null, 'amount_cents' => 2000]);
    $record([...$deposit, 'amount_cents' => 3000, 'confirmed_at' => now()]);
    $record([...$deposit, 'bank_account_id' => null, 'amount_cents' => 1000, 'returned_at' => now()]);

    foreach ([[-350, null], [-600, now()], [200, null]] as [$difference, $resolvedAt]) {
        CashClosing::query()->create([
            'request_id' => (string) Str::uuid(),
            'type' => 'independent',
            'closed_on' => '2026-11-21',
            'expected_cash_cents' => 10000,
            'counted_cash_cents' => 10000 + $difference,
            'difference_cents' => $difference,
            'cash_adjustment_cents' => $difference,
            'closed_by' => $admin->id,
            'closed_at' => now()->subDays(3),
            'shortage_resolved_at' => $resolvedAt,
        ]);
    }

    $this->get(route('dashboard'))->assertInertia(fn (Assert $page) => $page
        ->where('balances', ['cash' => 5250, 'bank' => 28000, 'transit' => 2000, 'total' => 35250])
        ->where('attention.transfers', ['count' => 2, 'amountCents' => 4300, 'dateFrom' => '2026-11-21', 'dateTo' => '2026-11-24'])
        ->where('attention.deposits', ['count' => 1, 'amountCents' => 2000])
        ->where('attention.shortages', ['count' => 1, 'amountCents' => 350]));

    $this->get(route('movements.index', [
        'kind' => 'income', 'status' => 'pending', 'date_from' => '2026-11-21', 'date_to' => '2026-11-24',
    ]))->assertInertia(fn (Assert $page) => $page
        ->has('movements.data', 2)
        ->where('movements.data.0.id', $currentTransfer->id)
        ->where('movements.data.1.id', $olderTransfer->id));
    $this->get(route('movements.index', ['kind' => 'deposit', 'status' => 'in_transit']))
        ->assertInertia(fn (Assert $page) => $page
            ->has('depositsInTransit.data', 1)
            ->where('depositsInTransit.data.0.id', $inTransit->id));
    $this->get(route('cash-closings.index', ['difference' => 'shortage', 'resolution' => 'pending']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('closings.per_page', 10)
            ->has('closings.data', 1)
            ->where('closings.data.0.differenceCents', -350));
});
