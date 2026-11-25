<?php

namespace App\Actions;

use App\Models\CashClosing;
use App\Models\CashMovement;
use App\Models\CashShift;
use App\Models\User;
use Illuminate\Validation\ValidationException;

final class CorrectCashMovement
{
    public function __construct(private CalculateCashBalances $balances) {}

    public function lastClosing(?CashShift $shift): ?CashClosing
    {
        return $shift === null ? null : CashClosing::query()->where('cash_shift_id', $shift->id)->latest('id')->first();
    }

    public function blockedReason(CashMovement $movement, ?CashShift $shift, ?CashClosing $closing): ?string
    {
        return match (true) {
            $movement->voided_at !== null => 'El movimiento ya está anulado.',
            $movement->kind === 'advance' => 'Los adelantos se gestionan desde su módulo.',
            $movement->returned_at !== null => 'El efectivo de este retiro ya volvió a caja.',
            $shift === null || $shift->delivered_cash_cents !== null || $movement->cash_shift_id !== $shift->id => 'Solo puedes corregir movimientos del turno abierto antes de entregar la caja.',
            $closing !== null && ($closing->last_movement_id === null
                ? $movement->created_at <= $closing->closed_at
                : $movement->id <= $closing->last_movement_id) => 'El movimiento ya está incluido en un cierre de caja.',
            default => null,
        };
    }

    public function void(CashMovement $movement, User $user, string $reason, ?CashShift $shift): void
    {
        $blocked = $this->blockedReason($movement, $shift, $this->lastClosing($shift));

        if ($blocked !== null) {
            throw ValidationException::withMessages(['movement' => $blocked]);
        }

        $movement->voided_at = now();
        $movement->voided_by = $user->id;
        $movement->voided_by_name = $user->name;
        $movement->void_reason = $reason;
        $movement->voided_voucher = $movement->voucher;
        $movement->voucher = null;
        $movement->save();
    }

    public function creditedBalance(CashMovement $movement): ?int
    {
        if ($movement->confirmed_at === null || ! in_array($movement->kind, ['income', 'deposit'], true)) {
            return null;
        }

        $account = $movement->bankAccount;

        return $account === null
            ? $this->balances->handle(includeTransit: true)['cash']
            : $this->balances->bankAccountBalance($account);
    }

    public function ensureCreditStillCovered(CashMovement $movement, ?int $before): void
    {
        if ($before !== null && $this->creditedBalance($movement) < min(0, $before)) {
            throw ValidationException::withMessages(['movement' => 'La corrección dejaría sin fondos a la fuente del abono original. Revisa el saldo disponible.']);
        }
    }
}
