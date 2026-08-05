<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Support\Money\Money;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class MoneyTest extends TestCase
{
    public function test_it_reads_a_price_the_way_a_shop_writes_it(): void
    {
        $this->assertSame(5499, Money::parse('54,99 zł')?->grosze);
        $this->assertSame(199, Money::parse(' 1,99zł ')?->grosze);
        $this->assertSame(699, Money::parse('6,99')?->grosze);
        $this->assertSame(1000, Money::parse('10 zł')?->grosze);
    }

    public function test_it_reads_a_price_split_by_a_non_breaking_space(): void
    {
        $this->assertSame(129900, Money::parse("1\u{00A0}299,00 zł")?->grosze);
    }

    /**
     * Zero is a real price — "gratis" — so it must not stand in for "no price".
     * A missing price returning 0 would quietly win every comparison it entered.
     */
    public function test_a_text_with_no_number_is_no_price_rather_than_zero(): void
    {
        $this->assertNull(Money::parse('Gratis'));
        $this->assertNull(Money::parse(''));
        $this->assertSame(0, Money::parse('0,00 zł')?->grosze);
    }

    public function test_a_single_decimal_is_read_as_tenths(): void
    {
        $this->assertSame(250, Money::parse('2,5 zł')?->grosze);
    }

    public function test_it_adds_without_drift(): void
    {
        $total = new Money(0);

        foreach (range(1, 10) as $ignored) {
            $total = $total->plus(Money::fromZloty(0.1));
        }

        $this->assertSame(100, $total->grosze);
    }

    public function test_a_price_that_rose_saves_nothing_rather_than_a_negative_amount(): void
    {
        $this->assertSame(0, (new Money(500))->minus(new Money(800))->grosze);
    }

    public function test_it_reports_a_discount_only_when_there_is_one(): void
    {
        $this->assertSame(50, (new Money(500))->percentOff(new Money(1000)));
        $this->assertNull((new Money(500))->percentOff(null));
        $this->assertNull((new Money(500))->percentOff(new Money(500)));
        $this->assertNull((new Money(500))->percentOff(new Money(0)));
    }

    public function test_scaling_rounds_once_at_the_end(): void
    {
        $this->assertSame(333, (new Money(999))->scaledBy(1 / 3)->grosze);
    }

    public function test_a_negative_price_is_a_bug_rather_than_a_discount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Money(-1);
    }
}
