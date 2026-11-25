<?php

namespace App\Actions;

use App\Models\Bank;
use App\Models\BankAccount;
use App\Models\CashMovement;
use App\Models\CashOpening;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class ConfirmCashMovement
{
    public function handle(CashMovement $movement, User $user, ?string $voucher, ?BankAccount $account = null): void
    {
        if ($movement->kind === 'deposit' || $movement->payment_method === 'transfer') {
            if ($voucher === null) {
                throw ValidationException::withMessages(['voucher' => 'Ingresa el número de voucher para confirmar el movimiento bancario.']);
            }

            $account ??= BankAccount::query()->whereKey($movement->bank_account_id)->lockForUpdate()->firstOrFail();

            if ($account->opening_locked_at === null) {
                $account->opening_locked_at = now();
                $account->save();
            }

            $movement->voucher = $voucher;
            if ($movement->kind === 'deposit') {
                $movement->bank_id = $account->bank_id;
            }
            $movement->bank_account_id = $account->id;
        } else {
            $movement->cash_opening_id = $this->useCashOpening()->id;
        }

        $movement->confirmed_at = now();
        $movement->confirmed_by = $user->id;
        $movement->save();
    }

    public function useCashOpening(): CashOpening
    {
        $opening = CashOpening::query()->lockForUpdate()->sole();

        if ($opening->opening_balance_cents === null) {
            throw ValidationException::withMessages(['amount' => 'La administradora debe registrar el saldo inicial de efectivo.']);
        }

        $opening->markUsed();

        return $opening;
    }

    public function activeBankAccount(int $id): BankAccount
    {
        $account = BankAccount::query()->lockForUpdate()->findOrFail($id);
        $bank = Bank::query()->lockForUpdate()->findOrFail($account->bank_id);

        if (! $bank->is_active) {
            throw ValidationException::withMessages(['bank_account_id' => 'Selecciona una cuenta de un banco activo.']);
        }

        return $account;
    }

    /** @return list<array{id: int, name: string}> */
    public function bankAccounts(): array
    {
        $accounts = BankAccount::query()->whereRelation('bank', 'is_active', true)
            ->with('bank:id,name')->orderBy('name')->get()
            ->map(fn (BankAccount $account): array => [
                'id' => $account->id,
                'name' => $account->displayName(),
            ])->all();

        return array_values($accounts);
    }

    public function duplicateVoucher(): ValidationException
    {
        return ValidationException::withMessages(['voucher' => 'El voucher ya está registrado para el banco que lo emitió.']);
    }
}
