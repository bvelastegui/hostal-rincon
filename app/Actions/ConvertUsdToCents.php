<?php

namespace App\Actions;

final class ConvertUsdToCents
{
    public const array OPENING_BALANCE_RULES = ['numeric', 'decimal:0,2', 'min:0', 'max:999999999.99'];

    public static function normalize(mixed $amount): mixed
    {
        return is_string($amount) ? str_replace(',', '.', $amount) : $amount;
    }

    public static function fromDecimal(string $amount): int
    {
        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $units * 100 + (int) str_pad($fraction, 2, '0');
    }
}
