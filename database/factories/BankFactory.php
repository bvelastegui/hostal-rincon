<?php

namespace Database\Factories;

use App\Models\Bank;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Bank> */
class BankFactory extends Factory
{
    /** @return array<string, string> */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return ['name' => $name, 'name_key' => Str::lower($name)];
    }
}
