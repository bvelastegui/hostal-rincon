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

test('el reporte mensual es exclusivo de la administradora incluso si el operador tiene la caja abierta', function () {
    $report = route('reports.monthly');
    $this->get($report)->assertRedirectToRoute('login');

    $operator = User::factory()->create();
    CashShift::query()->create([
        'responsible_id' => $operator->id,
        'responsible_name' => $operator->name,
        'started_by' => $operator->id,
        'started_at' => now(),
        'opening_cash_cents' => 0,
    ]);

    $this->actingAs($operator)->get($report)->assertRedirectToRoute('cash-closings.index');
    $this->get(route('movements.index'))->assertInertia(fn (Assert $page) => $page
        ->where('auth.canViewMonthlyReport', false));
});

test('el reporte usa el mes de Ecuador y muestra una apertura sin configurar', function () {
    $this->travelTo(now()->setDate(2026, 12, 1)->setTime(4, 59));
    $this->actingAs(User::factory()->create(['is_admin' => true]));

    $this->get(route('reports.monthly'))->assertOk()->assertInertia(fn (Assert $page) => $page
        ->component('MonthlyReport')
        ->where('auth.canViewMonthlyReport', true)
        ->where('month', '2026-11')
        ->where('report', 'saldos')
        ->where('movementDetails', null)
        ->where('advanceDetails', null)
        ->where('balances.cash', null)
        ->where('balances.total', null)
        ->has('balances.accounts', 0)
        ->has('movements', 0)
        ->has('advances', 0));

    $this->getJson(route('reports.monthly', ['month' => '2026-13']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('month');

    $this->getJson(route('reports.monthly', ['report' => 'invalido']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('report');
});

test('el reporte distingue cobros, gastos, depósitos y sus efectos sobre los saldos vigentes', function () {
    $this->travelTo(now()->setDate(2026, 11, 30)->setTime(18, 0));
    $admin = User::factory()->create(['is_admin' => true]);
    $employee = User::factory()->create();
    $origin = Bank::factory()->create();
    $account = BankAccount::factory()->create(['name' => 'Cuenta principal']);
    $otherAccount = BankAccount::factory()->create(['name' => 'Cuenta secundaria', 'opening_balance_cents' => 1000]);
    CashOpening::query()->update(['opening_balance_cents' => 10000]);
    Advance::factory()->for($employee, 'employee')->create([
        'cash_opening_id' => CashOpening::query()->sole()->id,
        'issued_on' => '2026-11-01',
        'amount_cents' => 2000,
    ]);
    $this->actingAs($admin);

    $record = function (array $data): CashMovement {
        $id = (string) Str::uuid();
        $this->post(route('movements.store'), [
            'request_id' => $id,
            'description' => 'Movimiento de prueba',
            ...$data,
        ])->assertSessionHasNoErrors();

        return CashMovement::query()->where('request_id', $id)->sole();
    };

    $record(['kind' => 'income', 'payment_method' => 'cash', 'amount' => '200']);
    $expense = $record(['kind' => 'expense', 'payment_method' => 'cash', 'amount' => '30']);
    $deposit = $record(['kind' => 'deposit', 'payment_method' => 'cash', 'amount' => '150', 'responsible_id' => $employee->id]);
    $returned = $record(['kind' => 'deposit', 'payment_method' => 'cash', 'amount' => '50', 'responsible_id' => $employee->id]);
    $this->patch(route('movements.return-to-cash', $returned))->assertSessionHasNoErrors();
    $transfer = $record([
        'kind' => 'income',
        'payment_method' => 'transfer',
        'amount' => '45',
        'origin_bank_id' => $origin->id,
        'bank_account_id' => $account->id,
    ]);
    $this->travelTo(now()->setDate(2026, 12, 1)->setTime(5, 0));
    $record([
        'kind' => 'expense',
        'payment_method' => 'transfer',
        'amount' => '5',
        'bank_account_id' => $otherAccount->id,
        'voucher' => 'GASTO-DIC',
    ]);

    $report = route('reports.monthly', ['month' => '2026-11']);
    $this->get($report)->assertInertia(fn (Assert $page) => $page
        ->where('balances.cash', 10000)
        ->where('balances.transit', 15000)
        ->where('balances.bank', 500)
        ->where('balances.total', 25500)
        ->where('balances.accounts.0.balanceCents', 0)
        ->where('balances.accounts.1.balanceCents', 500)
        ->where('movements', [
            ['kind' => 'deposit', 'method' => 'cash', 'confirmedCents' => 0, 'pendingCents' => 15000, 'returnedCents' => 5000],
            ['kind' => 'expense', 'method' => 'cash', 'confirmedCents' => 3000, 'pendingCents' => 0, 'returnedCents' => 0],
            ['kind' => 'income', 'method' => 'cash', 'confirmedCents' => 20000, 'pendingCents' => 0, 'returnedCents' => 0],
            ['kind' => 'income', 'method' => 'transfer', 'confirmedCents' => 0, 'pendingCents' => 4500, 'returnedCents' => 0],
        ]));

    $this->get(route('reports.monthly', ['month' => '2026-11', 'report' => 'ingresos']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report', 'ingresos')
            ->has('movements', 2)
            ->where('movements.0.kind', 'income')
            ->where('movements.0.confirmedCents', 20000)
            ->where('movements.1.pendingCents', 4500)
            ->where('movementDetails.total', 2)
            ->where('movementDetails.data.0.id', $transfer->id)
            ->where('movementDetails.data.0.status', 'pending')
            ->where('movementDetails.data.0.originBankName', $origin->name)
            ->where('advanceDetails', null));

    $this->get(route('reports.monthly', ['month' => '2026-11', 'report' => 'gastos']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report', 'gastos')
            ->has('movements', 1)
            ->where('movements.0.kind', 'expense')
            ->where('movements.0.confirmedCents', 3000)
            ->where('movementDetails.total', 1)
            ->where('movementDetails.data.0.id', $expense->id)
            ->where('movementDetails.data.0.kind', 'expense'));

    $depositsReport = route('reports.monthly', ['month' => '2026-11', 'report' => 'depositos']);
    $this->get($depositsReport)->assertInertia(fn (Assert $page) => $page
        ->where('movementDetails.total', 2)
        ->where('movementDetails.data.0.id', $returned->id)
        ->where('movementDetails.data.0.status', 'returned')
        ->where('movementDetails.data.1.status', 'in_transit')
        ->where('movementDetails.data.1.responsibleName', $employee->name));

    $this->patch(route('movements.update', $deposit), [
        'bank_account_id' => $account->id,
        'voucher' => 'DEPOSITO-01',
    ])->assertSessionHasNoErrors();
    $this->patch(route('movements.update', $transfer), ['voucher' => 'COBRO-01'])->assertSessionHasNoErrors();

    $this->get($depositsReport)->assertInertia(fn (Assert $page) => $page
        ->where('movementDetails.data.1.status', 'confirmed')
        ->where('movementDetails.data.1.accountName', $account->name)
        ->where('movementDetails.data.1.voucher', 'DEPOSITO-01')
        ->where('movementDetails.data.1.confirmedOn', '2026-12-01'));

    $this->get($report)->assertInertia(fn (Assert $page) => $page
        ->where('movements.0.confirmedCents', 15000)
        ->where('movements.3.confirmedCents', 4500)
        ->where('movements.3.pendingCents', 0)
        ->where('balances.cash', 10000)
        ->where('balances.transit', 0)
        ->where('balances.bank', 20000)
        ->where('balances.accounts.0.balanceCents', 19500)
        ->where('balances.total', 30000));
});

test('el reporte conserva descuentos por fecha y adelantos pendientes de otros meses', function () {
    $this->travelTo(now()->setDate(2026, 12, 2)->setTime(12, 0));
    $employee = User::factory()->create(['name' => 'Ana']);
    foreach ([
        ['issued_on' => '2026-10-30', 'amount_cents' => 3000, 'deducted_at' => '2026-11-01 05:00:00'],
        ['issued_on' => '2026-11-01', 'amount_cents' => 1000, 'deducted_at' => '2026-12-01 04:59:59'],
        ['issued_on' => '2026-11-30', 'amount_cents' => 2000, 'deducted_at' => '2026-12-01 05:00:00'],
        ['issued_on' => '2026-11-15', 'amount_cents' => 4000],
        ['issued_on' => '2026-12-01', 'amount_cents' => 5000],
    ] as $advance) {
        Advance::factory()->for($employee, 'employee')->create($advance);
    }

    Advance::factory()->create([
        'employee_id' => null,
        'employee_name' => 'Berta',
        'issued_on' => '2026-11-15',
        'amount_cents' => 600,
    ]);

    $this->actingAs(User::factory()->create(['is_admin' => true]))
        ->get(route('reports.monthly', ['month' => '2026-11', 'report' => 'adelantos']))
        ->assertInertia(fn (Assert $page) => $page
            ->where('report', 'adelantos')
            ->where('movementDetails', null)
            ->where('advanceDetails.total', 6)
            ->where('advanceDetails.data.0.issuedOn', '2026-12-01')
            ->where('advanceDetails.data.0.deductedOn', null)
            ->where('advanceDetails.data.1.deductedOn', '2026-12-01')
            ->where('advances', [
                ['name' => 'Ana', 'issuedCents' => 7000, 'issuedCashCents' => 7000, 'issuedTransferCents' => 0, 'deductedCents' => 4000, 'pendingCents' => 9000],
                ['name' => 'Berta', 'issuedCents' => 600, 'issuedCashCents' => 600, 'issuedTransferCents' => 0, 'deductedCents' => 0, 'pendingCents' => 600],
            ]));
});
