<?php

namespace App\Http\Controllers;

use App\Actions\CalculateCashBalances;
use App\Models\CashClosing;
use App\Models\CashMovement;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Inertia\Inertia;
use Inertia\Response;

class DashboardController extends Controller
{
    public function __invoke(CalculateCashBalances $calculateBalances): Response
    {
        Gate::authorize('manage-cash-settings');

        return Inertia::render('Dashboard', DB::transaction(function () use ($calculateBalances): array {
            $balances = $calculateBalances->handle(includeTransit: true);

            return [
                'balances' => $balances,
                'attention' => $this->attention($balances['transit'] ?? 0),
            ];
        }, attempts: 3));
    }

    /**
     * @return array{
     *     transfers: array{count: int, amountCents: int, dateFrom: string|null, dateTo: string|null},
     *     deposits: array{count: int, amountCents: int},
     *     shortages: array{count: int, amountCents: int}
     * }
     */
    private function attention(int $transitCents): array
    {
        $transfers = CashMovement::query()->where('kind', 'income')->withStatus('pending')
            ->selectRaw('COUNT(*) AS record_count, SUM(amount_cents) AS total_cents, MIN(occurred_on) AS first_date, MAX(occurred_on) AS last_date')
            ->toBase()->first();
        $shortages = CashClosing::query()->unresolvedShortages()
            ->selectRaw('COUNT(*) AS record_count, SUM(difference_cents) AS total_cents')
            ->toBase()->first();

        return [
            'transfers' => [
                'count' => (int) ($transfers->record_count ?? 0),
                'amountCents' => (int) ($transfers->total_cents ?? 0),
                'dateFrom' => $transfers->first_date ?? null,
                'dateTo' => $transfers->last_date ?? null,
            ],
            'deposits' => [
                'count' => CashMovement::query()->withStatus('in_transit')->count(),
                'amountCents' => $transitCents,
            ],
            'shortages' => [
                'count' => (int) ($shortages->record_count ?? 0),
                'amountCents' => -(int) ($shortages->total_cents ?? 0),
            ],
        ];
    }
}
