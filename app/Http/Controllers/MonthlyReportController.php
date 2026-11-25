<?php

namespace App\Http\Controllers;

use App\Actions\CalculateCashBalances;
use App\Models\Advance;
use App\Models\CashMovement;
use Carbon\CarbonImmutable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MonthlyReportController extends Controller
{
    public function __invoke(Request $request, CalculateCashBalances $balances): Response
    {
        $filters = $request->validate([
            'month' => ['nullable', 'date_format:Y-m', 'regex:/^[1-9][0-9]{3}-[0-9]{2}$/'],
        ], [
            'month.date_format' => 'Selecciona un mes válido.',
            'month.regex' => 'Selecciona un mes válido.',
        ]);

        $currentMonth = now('America/Guayaquil')->startOfMonth();
        $month = isset($filters['month'])
            ? $currentMonth->setDate((int) substr($filters['month'], 0, 4), (int) substr($filters['month'], 5), 1)
            : $currentMonth;

        return Inertia::render('MonthlyReport', DB::transaction(fn (): array => [
            'month' => $month->format('Y-m'),
            'balances' => $balances->handle(includeTransit: true, includeAccounts: true),
            'movements' => $this->movements($month),
            'advances' => $this->advances($month),
        ], attempts: 3));
    }

    /** @return list<array{kind: string, method: string, confirmedCents: int, pendingCents: int, returnedCents: int}> */
    private function movements(CarbonImmutable $month): array
    {
        return array_values(CashMovement::query()
            ->where('kind', '!=', 'advance')
            ->whereNull('voided_at')
            ->whereBetween('occurred_on', [$month->toDateString(), $month->endOfMonth()->toDateString()])
            ->selectRaw('kind, payment_method,
                SUM(CASE WHEN returned_at IS NULL AND confirmed_at IS NOT NULL THEN amount_cents ELSE 0 END) AS confirmed_cents,
                SUM(CASE WHEN returned_at IS NULL AND confirmed_at IS NULL THEN amount_cents ELSE 0 END) AS pending_cents,
                SUM(CASE WHEN returned_at IS NOT NULL THEN amount_cents ELSE 0 END) AS returned_cents')
            ->groupBy('kind', 'payment_method')->orderBy('kind')->orderBy('payment_method')
            ->get()
            ->map(fn (CashMovement $row): array => [
                'kind' => (string) $row->kind,
                'method' => (string) $row->payment_method,
                'confirmedCents' => (int) $row->getAttribute('confirmed_cents'),
                'pendingCents' => (int) $row->getAttribute('pending_cents'),
                'returnedCents' => (int) $row->getAttribute('returned_cents'),
            ])->all());
    }

    /** @return list<array{name: string, issuedCents: int, issuedCashCents: int, issuedTransferCents: int, deductedCents: int, pendingCents: int}> */
    private function advances(CarbonImmutable $month): array
    {
        $firstDate = $month->toDateString();
        $lastDate = $month->endOfMonth()->toDateTimeString();
        $firstTimestamp = $month->utc()->toDateTimeString();
        $lastTimestamp = $month->endOfMonth()->utc()->toDateTimeString();

        // Los adelantos históricos conservan el nombre aunque ya no exista la cuenta.
        $employeeName = 'COALESCE(users.name, advances.employee_name)';

        return array_values(Advance::query()
            ->leftJoin('users', 'users.id', '=', 'advances.employee_id')
            ->leftJoin('cash_movements', 'cash_movements.id', '=', 'advances.cash_movement_id')
            ->where(fn ($query) => $query->whereBetween('advances.issued_on', [$firstDate, $lastDate])
                ->orWhereBetween('advances.deducted_at', [$firstTimestamp, $lastTimestamp])
                ->orWhereNull('advances.deducted_at'))
            ->selectRaw("$employeeName AS report_name,
                SUM(CASE WHEN advances.issued_on BETWEEN ? AND ? AND COALESCE(cash_movements.payment_method, 'cash') = 'cash' THEN advances.amount_cents ELSE 0 END) AS issued_cash_cents,
                SUM(CASE WHEN advances.issued_on BETWEEN ? AND ? AND cash_movements.payment_method = 'transfer' THEN advances.amount_cents ELSE 0 END) AS issued_transfer_cents,
                SUM(CASE WHEN advances.deducted_at BETWEEN ? AND ? THEN advances.amount_cents ELSE 0 END) AS deducted_cents,
                SUM(CASE WHEN advances.deducted_at IS NULL THEN advances.amount_cents ELSE 0 END) AS pending_cents",
                [$firstDate, $lastDate, $firstDate, $lastDate, $firstTimestamp, $lastTimestamp])
            ->groupBy('advances.employee_id')
            ->groupByRaw($employeeName)
            ->orderBy('report_name')
            ->orderBy('advances.employee_id')
            ->get()
            ->map(fn (Advance $row): array => [
                'name' => (string) $row->getAttribute('report_name'),
                'issuedCents' => (int) $row->getAttribute('issued_cash_cents') + (int) $row->getAttribute('issued_transfer_cents'),
                'issuedCashCents' => (int) $row->getAttribute('issued_cash_cents'),
                'issuedTransferCents' => (int) $row->getAttribute('issued_transfer_cents'),
                'deductedCents' => (int) $row->getAttribute('deducted_cents'),
                'pendingCents' => (int) $row->getAttribute('pending_cents'),
            ])->all());
    }
}
