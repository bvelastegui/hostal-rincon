<?php

namespace App\Http\Controllers;

use App\Actions\CalculateCashBalances;
use App\Actions\PresentCashMovement;
use App\Models\Advance;
use App\Models\CashMovement;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class MonthlyReportController extends Controller
{
    private const int PAGE_SIZE = 10;

    public function __invoke(Request $request, CalculateCashBalances $balances, PresentCashMovement $presentMovement): Response
    {
        $filters = $request->validate([
            'report' => ['nullable', 'in:saldos,ingresos,gastos,depositos,adelantos'],
            'month' => ['nullable', 'date_format:Y-m', 'regex:/^[1-9][0-9]{3}-[0-9]{2}$/'],
        ], [
            'month.date_format' => 'Selecciona un mes válido.',
            'month.regex' => 'Selecciona un mes válido.',
        ]);

        $currentMonth = now('America/Guayaquil')->startOfMonth();
        $month = isset($filters['month'])
            ? $currentMonth->setDate((int) substr($filters['month'], 0, 4), (int) substr($filters['month'], 5), 1)
            : $currentMonth;

        $report = $filters['report'] ?? 'saldos';
        $movementKind = match ($report) {
            'ingresos' => 'income',
            'gastos' => 'expense',
            'depositos' => 'deposit',
            default => null,
        };

        return Inertia::render('MonthlyReport', DB::transaction(fn (): array => [
            'month' => $month->format('Y-m'),
            'report' => $report,
            'balances' => $balances->handle(includeTransit: true, includeAccounts: true),
            'movements' => $this->movements($month, $movementKind),
            'advances' => $this->advances($month),
            'movementDetails' => $movementKind === null
                ? null
                : $this->movementDetails($request, $month, $movementKind, $presentMovement),
            'advanceDetails' => $report === 'adelantos' ? $this->advanceDetails($month) : null,
        ], attempts: 3));
    }

    /** @return list<array{kind: string, method: string, confirmedCents: int, pendingCents: int, returnedCents: int}> */
    private function movements(CarbonImmutable $month, ?string $kind): array
    {
        return array_values($this->movementsInMonth($month)
            ->whereIn('kind', $kind === null ? ['income', 'expense', 'deposit'] : [$kind])
            ->whereNull('voided_at')
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

    /** @return Builder<CashMovement> */
    private function movementsInMonth(CarbonImmutable $month): Builder
    {
        return CashMovement::query()->whereBetween('occurred_on', [$month->toDateString(), $month->endOfMonth()->toDateString()]);
    }

    /** @return array<string, mixed> */
    private function movementDetails(Request $request, CarbonImmutable $month, string $kind, PresentCashMovement $presentMovement): array
    {
        return $this->movementsInMonth($month)
            ->where('kind', $kind)
            ->with([...PresentCashMovement::BANK_RELATIONS, 'replacement:id,replaces_movement_id'])
            ->orderByDesc('occurred_on')->orderByDesc('id')
            ->paginate(self::PAGE_SIZE)->onEachSide(1)->withQueryString()
            ->through(fn (CashMovement $movement): array => $presentMovement->handle($movement, $request->user()))->toArray();
    }

    /** @return Builder<Advance> */
    private function advancesForReport(CarbonImmutable $month): Builder
    {
        return Advance::query()->effective()->where(fn (Builder $query) => $query
            ->whereBetween('advances.issued_on', [$month->toDateString(), $month->endOfMonth()->toDateTimeString()])
            ->orWhereBetween('advances.deducted_at', [$month->utc()->toDateTimeString(), $month->endOfMonth()->utc()->toDateTimeString()])
            ->orWhereNull('advances.deducted_at'));
    }

    /** @return array<string, mixed> */
    private function advanceDetails(CarbonImmutable $month): array
    {
        return $this->advancesForReport($month)
            ->with(['employee:id,name', 'cashMovement:id,payment_method,bank_account_id,voucher',
                'cashMovement.bankAccount:id,name,bank_id', 'cashMovement.bankAccount.bank:id,name'])
            ->orderByDesc('issued_on')->orderByDesc('id')
            ->paginate(self::PAGE_SIZE)->onEachSide(1)->withQueryString()
            ->through(fn (Advance $advance): array => [
                'id' => $advance->id,
                'employee' => $advance->employee->name ?? $advance->employee_name,
                'issuedOn' => $advance->issued_on->toDateString(),
                'deductedOn' => $advance->deducted_at?->setTimezone('America/Guayaquil')->toDateString(),
                'amountCents' => $advance->amount_cents,
                'paymentMethod' => $advance->cashMovement->payment_method ?? 'cash',
                'account' => $advance->cashMovement?->bankAccount?->displayName(),
                'voucher' => $advance->cashMovement?->voucher,
                'note' => $advance->note,
                'recorder' => $advance->recorder_name ?? $advance->responsible,
                'deductor' => $advance->deductor_name,
            ])->toArray();
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

        return array_values($this->advancesForReport($month)
            ->leftJoin('users', 'users.id', '=', 'advances.employee_id')
            ->leftJoin('cash_movements', 'cash_movements.id', '=', 'advances.cash_movement_id')
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
