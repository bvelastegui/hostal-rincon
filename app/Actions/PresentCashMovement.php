<?php

namespace App\Actions;

use App\Models\CashMovement;
use App\Models\User;

final class PresentCashMovement
{
    public const array BANK_RELATIONS = ['voucherBank:id,name', 'bankAccount:id,bank_id,name', 'bankAccount.bank:id,name'];

    /** @return array<string, mixed> */
    public function handle(CashMovement $movement, User $user, bool $canCorrect = false): array
    {
        if (! $movement->detailsVisibleTo($user)) {
            return [
                'id' => $movement->id,
                'redacted' => true,
                'kind' => CashMovement::OPERATOR_KIND[$movement->kind] ?? 'transaction',
                'recorderName' => $movement->recorder_name,
                'date' => $movement->occurred_on,
                'status' => $movement->status(),
                'account' => $movement->bankAccount?->displayName() ?? ($movement->payment_method === 'cash' ? 'Efectivo' : null),
                'amountCents' => in_array($movement->kind, ['expense', 'advance'], true)
                    ? -$movement->amount_cents : $movement->amount_cents,
            ];
        }

        return [
            'id' => $movement->id,
            'redacted' => false,
            'isSensitive' => $movement->isSensitive(),
            'shiftId' => $movement->cash_shift_id,
            'kind' => $movement->kind,
            'paymentMethod' => $movement->payment_method,
            'amountCents' => $movement->amount_cents,
            'description' => $movement->description,
            'date' => $movement->occurred_on,
            'status' => $movement->status(),
            'originBankName' => $movement->kind === 'income' ? $movement->voucherBank?->name : null,
            'accountBankName' => $movement->bankAccount?->bank->name,
            'accountName' => $movement->bankAccount?->name,
            'voucher' => $movement->voucher ?? $movement->voided_voucher,
            'canCorrect' => $canCorrect,
            ...($user->is_admin ? [
                'replacesId' => $movement->replaces_movement_id,
                'replacementId' => $movement->relationLoaded('replacement') ? $movement->replacement?->id : null,
                'voidedBy' => $movement->voided_by_name,
                'voidReason' => $movement->void_reason,
                'voidedOn' => $movement->voided_at?->setTimezone('America/Guayaquil')->format('d/m/Y H:i'),
            ] : []),
            'responsibleName' => $movement->kind === 'deposit' ? $movement->responsible_name : null,
            'recorderName' => $movement->recorder_name,
            'confirmedOn' => $movement->confirmed_at?->setTimezone('America/Guayaquil')->toDateString(),
            'confirmed' => $movement->confirmed_at !== null,
            'returnedOn' => $movement->returned_at?->setTimezone('America/Guayaquil')->toDateString(),
        ];
    }
}
