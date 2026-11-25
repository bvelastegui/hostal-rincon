<?php

use App\Actions\CalculateCashBalances;
use App\Models\Advance;
use App\Models\BankAccount;
use App\Models\CashClosing;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\User;
use Database\Seeders\StagingSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Support\Facades\Hash;

test('carga la operación completa de staging una sola vez con credenciales separadas', function () {
    app()->instance('env', 'staging');
    config([
        'auth.superadmin_password' => 'qa-fixture-password',
        'auth.staging_demo_employee_password' => 'qa-fixture-employee-password',
    ]);
    $this->seed(StagingSeeder::class);
    $movements = CashMovement::query()->count();
    $shifts = CashShift::query()->count();

    $this->seed(StagingSeeder::class);

    $admin = User::query()->where('email', SuperAdminSeeder::EMAIL)->firstOrFail();
    expect($admin->is_admin)->toBeTrue()
        ->and(Hash::check('qa-fixture-password', $admin->password))->toBeTrue();
    expect(User::query()->operators()->count())->toBe(3);
    expect(User::query()->operators()->get()->filter(fn (User $user): bool => Hash::check('qa-fixture-employee-password', $user->password))->count())
        ->toBe(2);
    expect(CashShift::active())->not->toBeNull();
    expect($movements)->toBeGreaterThan(600);
    $this->assertDatabaseCount('cash_movements', $movements);
    $this->assertDatabaseCount('cash_shifts', $shifts);
    expect(CashClosing::query()->max('counted_cash_cents'))->toBeLessThanOrEqual(20_000);
    $advances = Advance::query()->with('cashMovement')->get();
    $advanceMovements = $advances->map(fn (Advance $advance) => $advance->cashMovement);
    expect($advances->count())->toBeGreaterThan(8);
    expect($advanceMovements->pluck('payment_method')->unique()->values()->all())->toEqualCanonicalizing(['cash', 'transfer']);
    foreach ($advances as $advance) {
        $movement = $advance->cashMovement;
        expect($movement)->not->toBeNull()
            ->and($movement->kind)->toBe('advance')
            ->and($movement->isSensitive())->toBeTrue()
            ->and($movement->recorded_by)->toBe($admin->id)
            ->and($movement->confirmed_at)->not->toBeNull()
            ->and($movement->occurred_on)->toBe($advance->issued_on->toDateString())
            ->and($movement->amount_cents)->toBe($advance->amount_cents);

        if ($movement->payment_method === 'transfer') {
            expect($movement->bank_account_id)->not->toBeNull()
                ->and($movement->voucher)->toStartWith('DEMO-ADE-');
        } else {
            expect($movement->cash_opening_id)->not->toBeNull();
        }
    }
    expect($advances->pluck('issued_on')->map(fn ($date) => $date->format('Y-m'))->unique()->count())
        ->toBeGreaterThanOrEqual(2);
    expect($advances->whereNull('deducted_at')->groupBy('employee_id')->map->sum('amount_cents')->values()->all())
        ->toEqualCanonicalizing([29_999, 30_000]);

    $currentMovements = CashMovement::query()->where('cash_shift_id', CashShift::active()->id)->get();
    expect($currentMovements->where('is_sensitive', true)->pluck('kind')->unique()->values()->all())
        ->toEqualCanonicalizing(['income', 'expense', 'deposit', 'advance'])
        ->and($currentMovements->where('is_sensitive', false)->isNotEmpty())->toBeTrue();
    $backup = BankAccount::query()->where('name_key', 'cuenta de reserva')->sole();
    expect(app(CalculateCashBalances::class)->bankAccountBalance($backup))->toBe(4_750);
});

test('staging exige una contraseña de QA y conserva una base con movimientos previos', function () {
    app()->instance('env', 'staging');
    config([
        'auth.superadmin_password' => 'qa-fixture-password',
        'auth.staging_demo_employee_password' => null,
    ]);
    expect(fn () => $this->seed(StagingSeeder::class))->toThrow(LogicException::class, 'STAGING_DEMO_EMPLOYEE_PASSWORD');
    $this->assertDatabaseCount('users', 0);

    config(['auth.staging_demo_employee_password' => 'qa-fixture-employee-password']);
    Advance::factory()->create();
    expect(fn () => $this->seed(StagingSeeder::class))->toThrow(LogicException::class, 'sin datos de operación');
    $this->assertDatabaseCount('advances', 1);
    $this->assertDatabaseCount('cash_shifts', 0);
});

test('rechaza el seeder de QA fuera de staging', function () {
    expect(fn () => $this->seed(StagingSeeder::class))->toThrow(LogicException::class);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('advances', 0);
});
