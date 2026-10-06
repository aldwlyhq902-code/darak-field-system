<?php

namespace App\Support;

use InvalidArgumentException;

/**
 * Exact money arithmetic for SAR amounts.
 *
 * Every amount is a decimal STRING ("1234.50"), never a float: binary floats
 * cannot represent most halala values, so sums drift (0.1 + 0.2 = 0.30000000000000004)
 * and a contract schedule can end up a halala off its total. Columns are already
 * `decimal`; this keeps the arithmetic between them exact too.
 *
 * Rounding is half-up (away from zero) to 2 places, applied only at defined
 * points: after a multiplication/division (VAT, percentages, rates, splits) and
 * when an external value enters through of(). Addition and subtraction of
 * already-rounded amounts are exact and never round.
 */
final class Money
{
    public const SCALE = 2;

    /** Working precision for intermediate products before the final rounding. */
    private const WORK_SCALE = 12;

    /** Normalise any numeric input to a rounded money string. */
    public static function of(int|float|string|null $amount, int $scale = self::SCALE): string
    {
        return self::round(self::normalize($amount), $scale);
    }

    public static function zero(): string
    {
        return '0.00';
    }

    public static function add(int|float|string|null ...$amounts): string
    {
        return self::sum($amounts);
    }

    public static function sub(int|float|string|null $amount, int|float|string|null ...$subtrahends): string
    {
        $result = self::of($amount);
        foreach ($subtrahends as $subtrahend) {
            $result = bcsub($result, self::of($subtrahend), self::SCALE);
        }

        return $result;
    }

    /** amount × factor, rounded half-up once at the end. */
    public static function mul(int|float|string|null $amount, int|float|string|null $factor, int $scale = self::SCALE): string
    {
        return self::round(bcmul(self::normalize($amount), self::normalize($factor), self::WORK_SCALE), $scale);
    }

    /** amount ÷ divisor, rounded half-up. Division by zero is a programming error. */
    public static function div(int|float|string|null $amount, int|float|string|null $divisor, int $scale = self::SCALE): string
    {
        $divisor = self::normalize($divisor);
        if (bccomp($divisor, '0', self::WORK_SCALE) === 0) {
            throw new InvalidArgumentException('Money division by zero.');
        }

        return self::round(bcdiv(self::normalize($amount), $divisor, self::WORK_SCALE), $scale);
    }

    /** `$percent` percent of the amount: percent('200', '2.5') = '5.00'. */
    public static function percent(int|float|string|null $amount, int|float|string|null $percent): string
    {
        return self::round(bcdiv(bcmul(self::normalize($amount), self::normalize($percent), self::WORK_SCALE), '100', self::WORK_SCALE));
    }

    /** VAT for a pre-VAT amount at a fractional rate (0.15 = 15%), rounded half-up. */
    public static function vat(int|float|string|null $amount, int|float|string|null $rate): string
    {
        return self::mul($amount, $rate);
    }

    /** @param iterable<int|float|string|null> $amounts */
    public static function sum(iterable $amounts): string
    {
        $total = self::zero();
        foreach ($amounts as $amount) {
            $total = bcadd($total, self::of($amount), self::SCALE);
        }

        return $total;
    }

    public static function compare(int|float|string|null $a, int|float|string|null $b): int
    {
        return bccomp(self::of($a), self::of($b), self::SCALE);
    }

    public static function equals(int|float|string|null $a, int|float|string|null $b): bool
    {
        return self::compare($a, $b) === 0;
    }

    public static function isZero(int|float|string|null $amount): bool
    {
        return self::compare($amount, 0) === 0;
    }

    public static function isPositive(int|float|string|null $amount): bool
    {
        return self::compare($amount, 0) > 0;
    }

    public static function isNegative(int|float|string|null $amount): bool
    {
        return self::compare($amount, 0) < 0;
    }

    public static function max(int|float|string|null $a, int|float|string|null ...$others): string
    {
        $result = self::of($a);
        foreach ($others as $other) {
            if (self::compare($other, $result) > 0) {
                $result = self::of($other);
            }
        }

        return $result;
    }

    public static function min(int|float|string|null $a, int|float|string|null ...$others): string
    {
        $result = self::of($a);
        foreach ($others as $other) {
            if (self::compare($other, $result) < 0) {
                $result = self::of($other);
            }
        }

        return $result;
    }

    public static function negate(int|float|string|null $amount): string
    {
        return bcsub('0', self::of($amount), self::SCALE);
    }

    /** a ÷ b × 100 as a float ratio for display (margins, collection rates). Null when b is zero. */
    public static function ratioPercent(int|float|string|null $part, int|float|string|null $whole, int $decimals = 1): ?float
    {
        if (self::isZero($whole)) {
            return null;
        }

        return (float) self::round(bcdiv(bcmul(self::normalize($part), '100', self::WORK_SCALE), self::normalize($whole), self::WORK_SCALE), $decimals);
    }

    /**
     * Split a total by weights so the parts always add back to the total exactly;
     * the LAST part absorbs the rounding remainder.
     *
     * @param  array<int|string, int|float|string>  $weights
     * @return array<int|string, string>
     */
    public static function allocate(int|float|string|null $total, array $weights): array
    {
        if ($weights === []) {
            return [];
        }

        $total = self::of($total);
        $weightSum = '0';
        foreach ($weights as $weight) {
            $weightSum = bcadd($weightSum, self::normalize($weight), self::WORK_SCALE);
        }
        if (bccomp($weightSum, '0', self::WORK_SCALE) <= 0) {
            throw new InvalidArgumentException('Money allocation needs a positive total weight.');
        }

        $keys = array_keys($weights);
        $lastKey = end($keys);
        $allocated = self::zero();
        $parts = [];
        foreach ($weights as $key => $weight) {
            if ($key === $lastKey) {
                $parts[$key] = bcsub($total, $allocated, self::SCALE);
                break;
            }
            $share = bcdiv(bcmul($total, self::normalize($weight), self::WORK_SCALE), $weightSum, self::WORK_SCALE);
            $parts[$key] = self::round($share);
            $allocated = bcadd($allocated, $parts[$key], self::SCALE);
        }

        return $parts;
    }

    /**
     * Display format: thousands separators and a fixed number of decimals,
     * computed on the string so a large amount never passes through a float.
     */
    public static function format(int|float|string|null $amount, int $decimals = self::SCALE, string $thousands = ','): string
    {
        $value = self::round(self::normalize($amount), $decimals);
        $negative = str_starts_with($value, '-');
        $value = ltrim($value, '-');
        [$integer, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $integer = strrev(implode($thousands, str_split(strrev($integer), 3)));
        $formatted = $decimals > 0 ? $integer.'.'.str_pad($fraction, $decimals, '0') : $integer;

        return ($negative && trim($formatted, '0.,') !== '' ? '-' : '').$formatted;
    }

    /** Plain fixed-decimal string for CSV/JSON exports ("1234.50", no separators). */
    public static function plain(int|float|string|null $amount, int $decimals = self::SCALE): string
    {
        return self::of($amount, $decimals);
    }

    /**
     * For consumers that need a PHP number (charts, scoring, legacy JSON). The
     * value is rounded to halalas FIRST, so the float is the nearest binary
     * representation of an exact amount and never carries accumulated drift.
     */
    public static function toFloat(int|float|string|null $amount): float
    {
        return (float) self::of($amount);
    }

    /** Half-up (away from zero) rounding of a decimal string. */
    public static function round(string $value, int $scale = self::SCALE): string
    {
        $value = self::normalize($value);
        $offset = '0.'.str_repeat('0', $scale).'5';
        $rounded = str_starts_with($value, '-')
            ? bcsub($value, $offset, $scale)
            : bcadd($value, $offset, $scale);

        // bcmath keeps the sign of "-0.00"; money never needs a negative zero.
        return bccomp($rounded, '0', $scale) === 0 ? bcadd('0', '0', $scale) : $rounded;
    }

    private static function normalize(int|float|string|null $amount): string
    {
        if ($amount === null || $amount === '') {
            return '0';
        }
        if (is_int($amount)) {
            return (string) $amount;
        }
        if (is_float($amount)) {
            if (! is_finite($amount)) {
                throw new InvalidArgumentException('Money amount must be finite.');
            }

            // The shortest round-trip repr (0.1 => "0.1"), not the binary
            // expansion, is the value the author meant.
            return self::expand(var_export($amount, true));
        }

        $amount = trim($amount);
        if (! is_numeric($amount)) {
            throw new InvalidArgumentException("Invalid money amount [{$amount}].");
        }

        return self::expand(ltrim($amount, '+'));
    }

    /** Expand exponent notation ("1.5E-7") into a plain decimal string bcmath accepts. */
    private static function expand(string $number): string
    {
        if (! str_contains(strtolower($number), 'e')) {
            return str_starts_with($number, '.') ? '0'.$number : (str_starts_with($number, '-.') ? '-0'.substr($number, 1) : $number);
        }

        [$mantissa, $exponent] = explode('e', strtolower($number), 2);
        $exponent = (int) $exponent;
        $result = $exponent >= 0
            ? bcmul($mantissa, bcpow('10', (string) $exponent), self::WORK_SCALE)
            : bcdiv($mantissa, bcpow('10', (string) -$exponent), self::WORK_SCALE + -$exponent);

        return $result;
    }
}
