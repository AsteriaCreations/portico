<?php

namespace App\Support;

/**
 * Money as integer cents, for arithmetic and comparisons. Every money column
 * is decimal(8,2), which the database stores exactly; the errors come from
 * doing the sums in PHP floats, where 50.00 + 0.05 - 20.00 is not 30.05 and
 * a drawer that balances to the cent reads as 3.6e-15 over. Convert with
 * of() as values come in, add and compare as ints, and convert back only to
 * display or store.
 */
final class Cents
{
    /**
     * Accepts what money arrives as: a decimal cast or SQL SUM() string
     * ("30.05"), a float (SQLite's SUM(), a form field already cast), an
     * int, or null for nothing. Rounding to the nearest cent is exact for
     * every value a decimal(8,2) column -- or a sum of millions of them --
     * can hold: a double's error there is under a millionth of a cent, far
     * short of the half a cent that could round the wrong way.
     */
    public static function of(string|int|float|null $amount): int
    {
        return (int) round((float) $amount * 100);
    }

    /**
     * For display and for methods that still return floats. Exact at the
     * sign and at zero -- 0 cents is 0.0, never 3.6e-15 -- so comparing the
     * result against zero stays safe, though comparing cents is clearer.
     */
    public static function toFloat(int $cents): float
    {
        return $cents / 100;
    }

    /**
     * For writing to a decimal(8,2) column: "30.05", "-0.30", "0.00".
     */
    public static function toDecimal(int $cents): string
    {
        $sign = $cents < 0 ? '-' : '';
        $cents = abs($cents);

        return sprintf('%s%d.%02d', $sign, intdiv($cents, 100), $cents % 100);
    }

    /**
     * A percentage of an amount, rounded once to the cent, half-up (away
     * from zero): 15% of $10.30 is $1.545, which pays $1.55. The percentage
     * is a decimal(8,2) value like "33.33", taken in hundredths of a percent
     * so the whole calculation stays in ints -- no float ever rounds it.
     */
    public static function percentOf(int $cents, string|int|float $percent): int
    {
        $product = $cents * self::of($percent);
        $rounded = intdiv(abs($product) + 5000, 10000);

        return $product < 0 ? -$rounded : $rounded;
    }
}
