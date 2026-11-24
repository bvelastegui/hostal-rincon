<?php

namespace Database\Factories;

use App\Models\Bank;
use App\Models\BankAccount;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<BankAccount> */
class BankAccountFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        $name = fake()->unique()->word();

        return [
            'bank_id' => Bank::factory(),
            'name' => $name,
            'name_key' => Str::lower($name),
            'opening_balance_cents' => 0,
        ];
    }
}
