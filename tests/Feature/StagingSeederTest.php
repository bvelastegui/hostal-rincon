<?php

use App\Models\Advance;
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
    expect(Advance::query()->where('recorded_by', '!=', $admin->id)->exists())->toBeFalse();
});

test('staging exige una contraseña de QA y conserva una base con movimientos previos', function () {
    app()->instance('env', 'staging');
    config(['auth.superadmin_password' => 'qa-fixture-password']);
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
