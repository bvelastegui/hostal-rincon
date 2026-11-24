<?php

namespace Database\Factories;

use App\Models\Advance;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

/** @extends Factory<Advance> */
class AdvanceFactory extends Factory
{
    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'employee_id' => User::factory(),
            'employee_name' => fn (array $attributes): string => User::findOrFail($attributes['employee_id'])->name,
            'request_id' => fake()->uuid(),
            'amount_cents' => 5000,
            'issued_on' => now('America/Guayaquil')->toDateString(),
            'responsible' => fake()->name(),
            'recorder_name' => fake()->name(),
        ];
    }
}
