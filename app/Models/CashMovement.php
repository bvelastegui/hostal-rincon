<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $occurred_on
 * @property CarbonImmutable|null $confirmed_at
 * @property CarbonImmutable|null $returned_at
 */
#[Fillable([
    'request_id', 'request_fingerprint', 'kind', 'payment_method', 'amount_cents',
    'description', 'occurred_on', 'bank_id', 'bank_account_id', 'voucher',
    'responsible_id', 'responsible_name', 'recorded_by', 'recorder_name',
])]
class CashMovement extends Model
{
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

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'confirmed_at' => 'immutable_datetime',
            'returned_at' => 'immutable_datetime',
        ];
    }
}
