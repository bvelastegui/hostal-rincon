<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property positive-int $id
 * @property string $type
 * @property string $closed_on
 * @property int $expected_cash_cents
 * @property int $counted_cash_cents
 * @property int $difference_cents
 * @property int $cash_adjustment_cents
 * @property int|null $cash_shift_id
 * @property int $closed_by
 * @property string|null $note
 * @property CarbonImmutable $closed_at
 * @property CarbonImmutable|null $shortage_resolved_at
 * @property string|null $shortage_resolution_method
 * @property string|null $shortage_resolution_note
 */
#[Fillable([
    'request_id', 'type', 'closed_on', 'expected_cash_cents', 'counted_cash_cents',
    'difference_cents', 'note', 'closed_by', 'closed_at', 'cash_shift_id', 'cash_adjustment_cents',
    'shortage_resolved_at', 'shortage_resolved_by', 'shortage_resolution_method', 'shortage_resolution_note',
])]
class CashClosing extends Model
{
    /** @return BelongsTo<CashShift, $this> */
    public function cashShift(): BelongsTo
    {
        return $this->belongsTo(CashShift::class);
    }

    /** @return BelongsTo<User, $this> */
    public function closedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    /** @return BelongsTo<User, $this> */
    public function shortageResolvedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'shortage_resolved_by');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expected_cash_cents' => 'integer',
            'counted_cash_cents' => 'integer',
            'difference_cents' => 'integer',
            'cash_adjustment_cents' => 'integer',
            'closed_at' => 'immutable_datetime',
            'shortage_resolved_at' => 'immutable_datetime',
        ];
    }
}
