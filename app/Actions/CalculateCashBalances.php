<?php

namespace App\Actions;

use App\Models\Advance;
use App\Models\BankAccount;
use App\Models\CashClosing;
use App\Models\CashMovement;
use App\Models\CashOpening;
use Illuminate\Support\Facades\DB;

final class CalculateCashBalances
{
    /** @return array{cash: int|null, bank: int, transit: int|null, total: int|null} */
    public function handle(bool $includeTransit): array
    {
        return DB::transaction(function () use ($includeTransit): array {
            $opening = CashOpening::query()->sole();
            $totals = CashMovement::query()->whereNull('returned_at')
                ->selectRaw('kind, payment_method,
                    SUM(CASE WHEN confirmed_at IS NOT NULL THEN amount_cents ELSE 0 END) as confirmed_cents,
                    SUM(CASE WHEN confirmed_at IS NULL THEN amount_cents ELSE 0 END) as pending_cents')
                ->groupBy('kind', 'payment_method')->get()
                ->mapWithKeys(fn (CashMovement $movement): array => [
                    $movement->kind.':'.$movement->payment_method => [
                        'confirmed' => (int) $movement->getAttribute('confirmed_cents'),
                        'pending' => (int) $movement->getAttribute('pending_cents'),
                    ],
                ]);

            $confirmed = fn (string $key): int => $totals->get($key)['confirmed'] ?? 0;
            $deposited = $confirmed('deposit:cash');
            $transit = $totals->get('deposit:cash')['pending'] ?? 0;
            $bank = (int) BankAccount::query()->sum('opening_balance_cents')
                + $confirmed('income:transfer') - $confirmed('expense:transfer') + $deposited;
            $cash = $opening->opening_balance_cents === null ? null : $opening->opening_balance_cents
                + $confirmed('income:cash') - $confirmed('expense:cash') - $deposited - $transit
                - (int) Advance::query()->where('cash_opening_id', $opening->id)->sum('amount_cents')
                + (int) CashClosing::query()->sum('cash_adjustment_cents');

            return [
                'cash' => $cash,
                'bank' => $bank,
                'transit' => $includeTransit ? $transit : null,
                'total' => $cash === null ? null : $cash + $bank + ($includeTransit ? $transit : 0),
            ];
        }, attempts: 3);
    }
}
