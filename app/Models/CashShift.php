<?php

namespace App\Models;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

/**
 * @property int $id
 * @property int $responsible_id
 * @property string $responsible_name
 * @property int|null $opening_cash_cents
 * @property int|null $delivered_cash_cents
 * @property CarbonImmutable|null $ended_at
 */
#[Fillable(['responsible_id', 'responsible_name', 'started_by', 'started_at', 'ended_at', 'opening_cash_cents', 'delivered_cash_cents'])]
class CashShift extends Model
{
    public static function active(): ?self
    {
        return self::query()->whereNull('ended_at')->first();
    }

    public static function movementRestriction(User $user, ?self $shift): ?string
    {
        if ($user->is_admin) {
            return null;
        }

        return match (true) {
            $shift === null => 'No hay un turno de caja abierto. Abre tu caja desde Turno antes de registrar movimientos.',
            $shift->delivered_cash_cents !== null => 'La caja está pendiente de recepción. Confirma tu turno antes de registrar movimientos.',
            $shift->responsible_id !== $user->id => 'Existe un turno abierto a cargo de '.$shift->responsible_name.'. Solo esa persona puede registrar movimientos.',
            default => null,
        };
    }

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'started_at' => 'immutable_datetime',
            'ended_at' => 'immutable_datetime',
            'opening_cash_cents' => 'integer',
            'delivered_cash_cents' => 'integer',
        ];
    }
}
