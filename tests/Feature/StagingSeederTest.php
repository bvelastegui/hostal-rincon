<?php

use App\Models\Advance;
use App\Models\User;
use Database\Seeders\StagingSeeder;
use Database\Seeders\SuperAdminSeeder;
use Illuminate\Support\Facades\Hash;

test('siembra la administradora y dos empleados de QA en staging sin duplicar adelantos', function () {
    app()->instance('env', 'staging');
    config(['auth.superadmin_password' => 'qa-fixture-password']);
    $this->seed(StagingSeeder::class);

    $this->seed(StagingSeeder::class);

    $admin = User::query()->where('email', SuperAdminSeeder::EMAIL)->firstOrFail();
    expect($admin->is_admin)->toBeTrue()
        ->and(Hash::check('qa-fixture-password', $admin->password))->toBeTrue();
    expect(User::query()->operators()->count())->toBe(2);
    expect(Advance::query()->orderBy('amount_cents')->pluck('amount_cents')->all())->toBe([25000, 30000]);
    expect(Advance::query()->where('recorded_by', $admin->id)->count())->toBe(2);
});

test('rechaza el seeder de QA fuera de staging', function () {
    expect(fn () => $this->seed(StagingSeeder::class))->toThrow(LogicException::class);
    $this->assertDatabaseCount('users', 0);
    $this->assertDatabaseCount('advances', 0);
});
