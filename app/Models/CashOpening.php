<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property positive-int $id
 * @property int|null $opening_balance_cents
 * @property CarbonImmutable|null $opening_locked_at
 */
class CashOpening extends Model
{
    public function markUsed(): void
    {
        if ($this->opening_balance_cents !== null && $this->opening_locked_at === null) {
            $this->opening_locked_at = now();
            $this->save();
        }
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
