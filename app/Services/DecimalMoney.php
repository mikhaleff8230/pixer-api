<?php

namespace App\Services;

final class DecimalMoney
{
    public static function cents(string|int $amount): int
    {
        return (int) round(self::scaled((string) $amount, 4) / 100, 0, PHP_ROUND_HALF_UP);
    }

    public static function decimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);
        return $sign . intdiv($cents, 100) . '.' . str_pad((string) ($cents % 100), 2, '0', STR_PAD_LEFT);
    }

    public static function addMarkup(string $amount, string $percent): string
    {
        $amountTenThousandths = self::scaled($amount, 4);
        $basisPoints = self::scaled($percent, 2);
        return self::decimal((int) round($amountTenThousandths * (10000 + $basisPoints) / 1000000, 0, PHP_ROUND_HALF_UP));
    }

    private static function scaled(string $amount, int $scale): int
    {
        $value = trim($amount);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '+-');
        [$whole, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $whole = preg_replace('/\D/', '', $whole) ?: '0';
        $fraction = preg_replace('/\D/', '', $fraction) ?? '';
        $roundDigit = (int) ($fraction[$scale] ?? 0);
        $fraction = substr(str_pad($fraction, $scale, '0'), 0, $scale);
        $scaled = ((int) $whole * (10 ** $scale)) + (int) $fraction;
        if ($roundDigit >= 5) $scaled++;
        return $negative ? -$scaled : $scaled;
    }
}
