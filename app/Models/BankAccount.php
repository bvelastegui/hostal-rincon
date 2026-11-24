<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\BankAccountFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property int $id
 * @property string $name
 * @property int $opening_balance_cents
 * @property CarbonImmutable|null $opening_locked_at
 */
#[Fillable(['bank_id', 'name', 'name_key', 'opening_balance_cents'])]
class BankAccount extends Model
{
    /** @use HasFactory<BankAccountFactory> */
    use HasFactory;

    /** @return BelongsTo<Bank, $this> */
    public function bank(): BelongsTo
    {
        return $this->belongsTo(Bank::class);
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'opening_balance_cents' => 'integer',
            'opening_locked_at' => 'immutable_datetime',
        ];
    }
}
