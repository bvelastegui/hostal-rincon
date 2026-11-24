<?php

namespace App\Http\Controllers;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CashOpening;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(): Response
    {
        return Inertia::render('Dashboard', [
            'pendingSettings' => Gate::allows('manage-cash-settings') ? $this->pendingSettings() : [],
        ]);
    }

    /** @return list<'cash_opening'|'active_bank'|'bank_account'> */
    private function pendingSettings(): array
    {
        $pending = [];

        if (! CashOpening::query()->whereNotNull('opening_balance_cents')->exists()) {
            $pending[] = 'cash_opening';
        }

        $hasActiveBank = Bank::query()->active()->exists();

        if (! $hasActiveBank) {
            $pending[] = 'active_bank';
        }

        if (! $hasActiveBank || ! BankAccount::query()->whereRelation('bank', 'is_active', true)->exists()) {
            $pending[] = 'bank_account';
        }

        return $pending;
    }
}
