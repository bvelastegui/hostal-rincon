<?php

namespace Database\Seeders;

use App\Models\CashMovement;
use App\Models\CashOpening;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use LogicException;

class StagingSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->environment('staging')) {
            throw new LogicException('StagingSeeder solo se ejecuta con APP_ENV=staging.');
        }

        if (blank(config('auth.superadmin_password'))
            && ! User::query()->where('email', SuperAdminSeeder::EMAIL)->where('is_admin', true)->exists()) {
            throw new LogicException('Configura SUPERADMIN_PASSWORD antes de crear la cuenta de QA.');
        }

        DB::transaction(function (): void {
            CashOpening::query()->lockForUpdate()->sole();

            if (CashMovement::query()->where('voucher', HostalOperationsSeeder::FIXTURE_VOUCHER)->exists()) {
                $this->command?->info('La simulación de operación ya está cargada.');

                return;
            }

            $this->call([
                SuperAdminSeeder::class,
                HostalOperationsSeeder::class,
            ]);
        }, attempts: 3);
    }
}
