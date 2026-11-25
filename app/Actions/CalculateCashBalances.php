<?php

namespace App\Actions;

use App\Models\Advance;
use App\Models\BankAccount;
use App\Models\CashClosing;
use App\Models\CashMovement;
use App\Models\CashOpening;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class CalculateCashBalances
{
    /**
     * @return array{cash: int|null, bank: int, transit: int|null, total: int|null, accounts?: list<array{id: int, name: string, bank: string, balanceCents: int}>}
     */
    public function handle(bool $includeTransit, bool $includeAccounts = false): array
    {
        return DB::transaction(function () use ($includeTransit, $includeAccounts): array {
            $opening = CashOpening::query()->sole();
            $totals = $this->movementTotals(CashMovement::query())
                ->when($includeAccounts, fn ($query) => $query->addSelect('bank_account_id')->groupBy('bank_account_id'))
                ->get();

            $deposits = $totals->where('kind', 'deposit')->where('payment_method', 'cash');
            $deposited = (int) $deposits->sum('confirmed_cents');
            $transit = (int) $deposits->sum('pending_cents');
            $bank = (int) BankAccount::query()->sum('opening_balance_cents') + $this->bankChange($totals);
            $cash = $opening->opening_balance_cents === null ? null : $opening->opening_balance_cents
                + (int) $totals->where('kind', 'income')->where('payment_method', 'cash')->sum('confirmed_cents')
                - (int) $totals->whereIn('kind', ['expense', 'advance'])->where('payment_method', 'cash')->sum('confirmed_cents')
                - $deposited - $transit
                - (int) Advance::query()->where('cash_opening_id', $opening->id)->whereNull('cash_movement_id')->sum('amount_cents')
                + (int) CashClosing::query()->sum('cash_adjustment_cents');

            $balances = [
                'cash' => $cash,
                'bank' => $bank,
                'transit' => $includeTransit ? $transit : null,
                'total' => $cash === null ? null : $cash + $bank + ($includeTransit ? $transit : 0),
            ];

            if ($includeAccounts) {
                $changes = $totals->groupBy('bank_account_id')->map($this->bankChange(...));
                $balances['accounts'] = array_values(BankAccount::query()->with('bank:id,name')->orderBy('name')->orderBy('id')->get()
                    ->map(fn (BankAccount $account): array => [
                        'id' => $account->id,
                        'name' => $account->name,
                        'bank' => $account->bank->name,
                        'balanceCents' => $account->opening_balance_cents + ($changes->get($account->id) ?? 0),
                    ])->all());
            }

            return $balances;
        }, attempts: 3);
    }

    public function bankAccountBalance(BankAccount $account): int
    {
        $totals = $this->movementTotals(CashMovement::query()->where('bank_account_id', $account->id))->get();

        return $account->opening_balance_cents + $this->bankChange($totals);
    }

    public function ensureFundsAvailable(int $amountCents, ?BankAccount $account): void
    {
        $balanceCents = $account === null
            ? $this->handle(includeTransit: true)['cash']
            : $this->bankAccountBalance($account);

        if ($balanceCents === null) {
            throw ValidationException::withMessages(['amount' => 'La administradora debe registrar el saldo inicial de efectivo.']);
        }

        if ($amountCents > $balanceCents) {
            $available = number_format(max(0, $balanceCents) / 100, 2, ',', '.');
            $source = $account === null ? 'en caja' : 'en la cuenta seleccionada';

            throw ValidationException::withMessages(['amount' => "El monto supera el saldo disponible {$source}: USD {$available}."]);
        }
    }

    /**
     * @param  Builder<CashMovement>  $query
     * @return Builder<CashMovement>
     */
    private function movementTotals(Builder $query): Builder
    {
        return $query->whereNull('returned_at')->whereNull('voided_at')
            ->where(fn ($query) => $query->where('kind', '!=', 'advance')
                ->orWhere('payment_method', 'transfer')->orWhereNotNull('cash_opening_id'))
            ->selectRaw('kind, payment_method,
                SUM(CASE WHEN confirmed_at IS NOT NULL THEN amount_cents ELSE 0 END) as confirmed_cents,
                SUM(CASE WHEN confirmed_at IS NULL THEN amount_cents ELSE 0 END) as pending_cents')
            ->groupBy('kind', 'payment_method');
    }

    /** @param Collection<int, CashMovement> $totals */
    private function bankChange(Collection $totals): int
    {
        $transfers = $totals->where('payment_method', 'transfer');

        return (int) $transfers->where('kind', 'income')->sum('confirmed_cents')
            - (int) $transfers->whereIn('kind', ['expense', 'advance'])->sum('confirmed_cents')
            + (int) $totals->where('kind', 'deposit')->where('payment_method', 'cash')->sum('confirmed_cents');
    }
}
