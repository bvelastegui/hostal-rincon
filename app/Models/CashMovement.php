<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property int|null $cash_opening_id
 * @property int|null $bank_account_id
 * @property string $payment_method
 * @property bool $is_sensitive
 * @property string|null $voucher
 * @property int|null $cash_shift_id
 * @property string $occurred_on
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $returned_at
 */
#[Fillable([
    'request_id', 'request_fingerprint', 'kind', 'payment_method', 'amount_cents',
    'description', 'occurred_on', 'is_sensitive', 'bank_id', 'bank_account_id', 'voucher',
    'responsible_id', 'responsible_name', 'recorded_by', 'recorder_name', 'cash_shift_id',
])]
class CashMovement extends Model
{
    public const array OPERATOR_KIND = [
        'income' => 'income',
        'expense' => 'expense',
        'advance' => 'expense',
        'deposit' => 'transaction',
    ];

    /** @return BelongsTo<Bank, $this> */
    public function voucherBank(): BelongsTo
    {
        return $this->belongsTo(Bank::class, 'bank_id');
    }

    /** @return BelongsTo<BankAccount, $this> */
    public function bankAccount(): BelongsTo
    {
        return $this->belongsTo(BankAccount::class);
    }

    public function isSensitive(): bool
    {
        return $this->kind === 'advance' || $this->is_sensitive;
    }

    public function detailsVisibleTo(User $user): bool
    {
        return $user->is_admin || ! $this->isSensitive();
    }

    /** @return 'returned'|'confirmed'|'in_transit'|'pending' */
    public function status(): string
    {
        return match (true) {
            $this->returned_at !== null => 'returned',
            $this->confirmed_at !== null => 'confirmed',
            $this->kind === 'deposit' => 'in_transit',
            default => 'pending',
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'is_sensitive' => 'boolean',
            'bank_account_id' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
        ];
    }
}
