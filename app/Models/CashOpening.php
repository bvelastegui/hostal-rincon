<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int|null $opening_balance_cents
 * @property CarbonImmutable|null $opening_locked_at
 */
class CashOpening extends Model
{
    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'opening_balance_cents' => 'integer',
            'opening_locked_at' => 'immutable_datetime',
        ];
    }
}
