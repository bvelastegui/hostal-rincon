<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

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
 * @property CarbonImmutable|null $voided_at
 * @property int|null $replaces_movement_id
 */
#[Fillable([
    'request_id', 'request_fingerprint', 'kind', 'payment_method', 'amount_cents',
    'description', 'occurred_on', 'is_sensitive', 'bank_id', 'bank_account_id', 'voucher',
    'responsible_id', 'responsible_name', 'recorded_by', 'recorder_name', 'cash_shift_id', 'replaces_movement_id',
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

    /** @return HasOne<CashMovement, $this> */
    public function replacement(): HasOne
    {
        return $this->hasOne(self::class, 'replaces_movement_id');
    }

    public function isSensitive(): bool
    {
        return $this->kind === 'advance' || $this->is_sensitive;
    }

    public function detailsVisibleTo(User $user): bool
    {
        return $user->is_admin || ! $this->isSensitive();
    }

    /**
     * @param  Builder<CashMovement>  $query
     * @return Builder<CashMovement>
     */
    public function scopeWithStatus(Builder $query, ?string $status): Builder
    {
        return match ($status) {
            'voided' => $query->whereNotNull('voided_at'),
            'confirmed' => $query->whereNotNull('confirmed_at')->whereNull('returned_at')->whereNull('voided_at'),
            'returned' => $query->whereNotNull('returned_at'),
            'pending' => $query->where('kind', '!=', 'deposit')->whereNull('confirmed_at')->whereNull('returned_at')->whereNull('voided_at'),
            'in_transit' => $query->where('kind', 'deposit')->whereNull('confirmed_at')->whereNull('returned_at')->whereNull('voided_at'),
            default => $query,
        };
    }

    /** @return 'voided'|'returned'|'confirmed'|'in_transit'|'pending' */
    public function status(): string
    {
        return match (true) {
            $this->voided_at !== null => 'voided',
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
            'voided_at' => 'immutable_datetime',
            'replaces_movement_id' => 'integer',
        ];
    }
}
