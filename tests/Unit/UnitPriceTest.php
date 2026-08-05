<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UnitDimension;
use App\Support\Measurement\Quantity;
use App\Support\Measurement\UnitDefinition;
use App\Support\Money\Money;
use App\Support\Money\UnitPrice;
use PHPUnit\Framework\TestCase;

class UnitPriceTest extends TestCase
{
    public function test_a_pack_price_becomes_a_price_per_kilo(): void
    {
        $price = UnitPrice::of(new Money(899), $this->grams(200));

        $this->assertSame(4495, $price?->price->grosze);
        $this->assertSame('kg', $price?->label());
    }

    public function test_a_pack_price_becomes_a_price_per_litre(): void
    {
        $price = UnitPrice::of(new Money(499), $this->millilitres(400));

        $this->assertSame(1248, $price?->price->grosze);
        $this->assertSame('l', $price?->label());
    }

    /**
     * The whole reason this class exists: the smaller number on the shelf is not
     * the cheaper butter.
     */
    public function test_the_cheaper_pack_is_not_always_the_cheaper_product(): void
    {
        $small = UnitPrice::of(new Money(899), $this->grams(200));
        $large = UnitPrice::of(new Money(1249), $this->grams(500));

        $this->assertNotNull($small);
        $this->assertNotNull($large);
        $this->assertTrue($large->isCheaperThan($small));
    }

    public function test_an_unknown_pack_size_stays_unknown(): void
    {
        $this->assertNull(UnitPrice::of(new Money(899), null));
    }

    public function test_a_zero_sized_pack_is_not_infinitely_cheap(): void
    {
        $this->assertNull(UnitPrice::of(new Money(899), $this->grams(0)));
    }

    public function test_prices_per_different_dimensions_are_not_comparable(): void
    {
        $perKilo = UnitPrice::of(new Money(899), $this->grams(200));
        $perLitre = UnitPrice::of(new Money(499), $this->millilitres(400));

        $this->assertNotNull($perKilo);
        $this->assertNotNull($perLitre);
        $this->assertFalse($perKilo->isComparableWith($perLitre));
        $this->assertFalse($perLitre->isCheaperThan($perKilo));
    }

    private function grams(float $amount): Quantity
    {
        return new Quantity($amount, new UnitDefinition('g', UnitDimension::Mass, 1.0));
    }

    private function millilitres(float $amount): Quantity
    {
        return new Quantity($amount, new UnitDefinition('ml', UnitDimension::Volume, 1.0));
    }
}
