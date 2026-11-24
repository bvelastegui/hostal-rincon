<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;
use LogicException;

class AdvanceSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('local', 'staging', 'testing')) {
            throw new LogicException('Los adelantos de demostración requieren un entorno de pruebas.');
        }

        $recorder = User::query()->where('email', SuperAdminSeeder::EMAIL)->first();

        foreach ([
            'ana@example.test' => ['Ana Torres', 25000],
            'carlos@example.test' => ['Carlos Ruiz', 30000],
        ] as $email => [$name, $amount]) {
            $employee = User::query()->firstOrCreate(
                ['email' => $email],
                ['name' => $name, 'password' => Str::random(64)],
            );

            if ($employee->advances()->doesntExist()) {
                $employee->advances()->create([
                    'request_id' => (string) Str::uuid(),
                    'employee_name' => $employee->name,
                    'amount_cents' => $amount,
                    'issued_on' => now('America/Guayaquil')->toDateString(),
                    'responsible' => 'Administración',
                    'note' => 'Adelanto ficticio para pruebas de QA',
                    'recorded_by' => $recorder?->id,
                    'recorder_name' => $recorder->name ?? 'Administración de QA',
                ]);
            }
        }
    }
}
