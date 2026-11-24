<?php

namespace Database\Seeders;

use App\Models\Advance;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class AdvanceSeeder extends Seeder
{
    public function run(): void
    {
        foreach ([
            'ana@example.test' => ['Ana Torres', 25000],
            'carlos@example.test' => ['Carlos Ruiz', 30000],
        ] as $email => [$name, $amount]) {
            $employee = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Str::random(64)],
            );

            if ($employee->advances()->doesntExist()) {
                Advance::factory()->create([
                    'employee_id' => $employee->id,
                    'amount_cents' => $amount,
                    'responsible' => 'Operador de demostración',
                    'recorder_name' => 'Operador de demostración',
                ]);
            }
        }
    }
}
