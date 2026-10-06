<?php

namespace Tests\Unit;

use App\Support\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_addition_is_exact_where_floats_drift(): void
    {
        $this->assertNotSame(0.3, 0.1 + 0.2); // the float problem this class exists for
        $this->assertSame('0.30', Money::add('0.1', '0.2'));
        $this->assertSame('0.30', Money::add(0.1, 0.2));
        $this->assertSame('0.30', Money::of(0.1 + 0.2));

        $tenCents = array_fill(0, 1000, '0.10');
        $this->assertSame('100.00', Money::sum($tenCents));
        $this->assertSame('0.01', Money::sub('1.10', '1.09'));
    }

    public function test_vat_at_fifteen_percent_rounds_half_up_once(): void
    {
        $this->assertSame('15.00', Money::vat('100', '0.15'));
        $this->assertSame('0.15', Money::vat('1.00', '0.15'));
        // 0.10 × 0.15 = 0.015 → half-up to 0.02 (a float round() can land on 0.01)
        $this->assertSame('0.02', Money::vat('0.10', '0.15'));
        // 33.33 × 0.15 = 4.9995 → 5.00
        $this->assertSame('5.00', Money::vat('33.33', '0.1500'));
        $this->assertSame('-0.02', Money::vat('-0.10', '0.15'));
        $this->assertSame('1.01', Money::of('1.005'));
    }

    public function test_percent_mul_div_and_comparisons(): void
    {
        $this->assertSame('5.00', Money::percent('200', '2.5'));
        $this->assertSame('3000.00', Money::mul('1000', 3));
        $this->assertSame('33.33', Money::div('100', 3));
        $this->assertSame(0, Money::compare('0.30', 0.1 + 0.2));
        $this->assertTrue(Money::isZero('-0.001'));
        $this->assertSame('0.00', Money::negate('0'));
        $this->assertSame('7.50', Money::max('2', '7.5', '-1'));
        $this->assertSame('-1.00', Money::min('2', '7.5', '-1'));
        $this->assertSame(25.0, Money::ratioPercent('25', '100'));
        $this->assertNull(Money::ratioPercent('25', '0'));
    }

    public function test_allocation_always_sums_to_the_total_and_last_part_absorbs_remainder(): void
    {
        $parts = Money::allocate('100.00', [1, 1, 1]);
        $this->assertSame(['33.33', '33.33', '33.34'], array_values($parts));
        $this->assertSame('100.00', Money::sum($parts));

        $parts = Money::allocate('1000.01', ['a' => 3, 'b' => 3, 'c' => 3, 'd' => 1]);
        $this->assertSame('1000.01', Money::sum($parts));
        $this->assertSame(['a', 'b', 'c', 'd'], array_keys($parts));
    }

    public function test_formatting_uses_strings_not_floats(): void
    {
        $this->assertSame('1,234,567.80', Money::format('1234567.8'));
        $this->assertSame('0.30', Money::format(0.1 + 0.2));
        $this->assertSame('-1,000.00', Money::format('-1000'));
        $this->assertSame('1,235', Money::format('1234.5', 0));
        $this->assertSame('99999999999999.99', Money::plain('99999999999999.99'));
        $this->assertSame('0.00', Money::of(null));
        $this->assertSame('0.00', Money::of(1.5e-7));
    }

    public function test_rejects_non_numeric_and_zero_division(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::of('12abc');
    }

    public function test_division_by_zero_is_refused(): void
    {
        $this->expectException(InvalidArgumentException::class);
        Money::div('1', '0');
    }
}
