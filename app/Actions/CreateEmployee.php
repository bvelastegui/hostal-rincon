<?php

namespace App\Actions;

use App\Models\User;
use Illuminate\Support\Facades\Hash;

class CreateEmployee
{
    public function __invoke(
        string $name,
        string $password,
        ?string $email = null,
        ?string $username = null,
        ?int $advanceLimitCents = null,
    ): void {
        $employee = new User([
            'name' => $name,
            'email' => $email,
            'username' => $username,
            'password' => Hash::make($password),
        ]);
        $employee->is_admin = false;
        $employee->advance_limit_cents = $advanceLimitCents ?? User::DEFAULT_ADVANCE_LIMIT_CENTS;
        if ($email !== null) {
            $employee->forceFill(['email_verified_at' => now()]);
        }
        $employee->save();
    }
}
