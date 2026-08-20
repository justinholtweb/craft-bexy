<?php

namespace justinholtweb\bexy\helpers;

/**
 * bexio takes decimals as strings and gives them back the same way.
 */
abstract class Money
{
    /**
     * Places bexio accepts on a unit price or a discount. Position totals are money and round to
     * two, but the unit price behind them does not — a CHF 0.415 unit price is legitimate, and
     * rounding it before sending shifts the line total.
     */
    public const PRICE_SCALE = 6;

    public const MONEY_SCALE = 2;

    /**
     * A price, as bexio wants it: a plain decimal string, no separators, no currency.
     */
    public static function price(float|int|string $value): string
    {
        return self::trim(number_format((float)$value, self::PRICE_SCALE, '.', ''));
    }

    /**
     * A money amount, rounded to the cent.
     */
    public static function amount(float|int|string $value): string
    {
        return number_format(round((float)$value, self::MONEY_SCALE), self::MONEY_SCALE, '.', '');
    }

    /**
     * A quantity. Commerce quantities are whole numbers, but the field is a decimal string on
     * bexio's side and units like hours or kilograms are not.
     */
    public static function quantity(float|int|string $value): string
    {
        return self::trim(number_format((float)$value, 4, '.', ''));
    }

    /**
     * Whether two amounts differ by more than the given tolerance.
     */
    public static function differs(float $a, float $b, float $tolerance): bool
    {
        return abs(round($a, 4) - round($b, 4)) > $tolerance + 0.000001;
    }

    /**
     * Anything bexio sends back as a decimal string, as a float.
     */
    public static function toFloat(mixed $value): float
    {
        if ($value === null || $value === '') {
            return 0.0;
        }

        return (float)str_replace(',', '', (string)$value);
    }

    /**
     * Drop trailing zeros so `12.500000` reads as `12.5`, without turning `12.00` into `12.`.
     */
    private static function trim(string $value): string
    {
        if (!str_contains($value, '.')) {
            return $value;
        }

        $trimmed = rtrim(rtrim($value, '0'), '.');

        return $trimmed === '' || $trimmed === '-' ? '0' : $trimmed;
    }
}
