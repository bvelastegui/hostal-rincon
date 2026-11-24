<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class SuperAdminSeeder extends Seeder
{
    public function run(): void
    {
        $user = User::query()->firstOrNew(['email' => 'soporte@nexodigital.com']);

        if (! $user->exists) {
            $password = config('auth.superadmin_password');
            $user->password = is_string($password) && $password !== ''
                ? $password
                : Str::random(64);
        }

        $user->forceFill([
            'name' => 'Soporte Nexo Digital',
            'is_admin' => true,
        ])->save();
    }
}
