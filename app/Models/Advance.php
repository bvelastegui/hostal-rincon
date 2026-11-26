<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Database\Factories\AdvanceFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * @property CarbonImmutable $issued_on
 * @property CarbonImmutable|null $deducted_at
 */
#[Fillable(['request_id', 'cash_opening_id', 'cash_movement_id', 'employee_name', 'amount_cents', 'issued_on', 'responsible', 'note', 'recorded_by', 'recorder_name'])]
class Advance extends Model
{
    /** @use HasFactory<AdvanceFactory> */
    use HasFactory;

    public const int LIMIT_CENTS = 30_000;

    /** @return BelongsTo<User, $this> */
    public function employee(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /** @return BelongsTo<CashMovement, $this> */
    public function cashMovement(): BelongsTo
    {
        return $this->belongsTo(CashMovement::class);
    }

    /** @param Builder<Advance> $query */
    public function scopePending(Builder $query): void
    {
        $query->whereNull('deducted_at');
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'amount_cents' => 'integer',
            'issued_on' => 'immutable_date',
            'deducted_at' => 'immutable_datetime',
        ];
    }
}
