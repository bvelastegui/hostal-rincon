<?php

namespace App\Actions;

final class ConvertUsdToCents
{
    public static function fromDecimal(string $amount): int
    {
        [$units, $fraction] = array_pad(explode('.', $amount, 2), 2, '');

        return (int) $units * 100 + (int) str_pad($fraction, 2, '0');
    }
}
