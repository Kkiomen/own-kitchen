<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UnitDimension;
use App\Support\Measurement\Quantity;
use App\Support\Measurement\UnitDefinition;
use InvalidArgumentException;
use PHPUnit\Framework\TestCase;

class QuantityTest extends TestCase
{
    public function test_it_converts_between_units_of_the_same_dimension(): void
    {
        $twoKilograms = new Quantity(2, $this->kilogram());

        $this->assertEqualsWithDelta(2000.0, $twoKilograms->convertTo($this->gram())->amount, 0.001);
    }

    public function test_it_treats_a_tablespoon_as_fifteen_millilitres(): void
    {
        $threeTablespoons = new Quantity(3, $this->tablespoon());

        $this->assertEqualsWithDelta(45.0, $threeTablespoons->toBase(), 0.001);
    }

    public function test_it_adds_quantities_expressed_in_different_units(): void
    {
        $total = (new Quantity(1, $this->kilogram()))->add(new Quantity(500, $this->gram()));

        $this->assertEqualsWithDelta(1.5, $total->amount, 0.001);
        $this->assertSame('kg', $total->unit->code);
    }

    public function test_it_refuses_to_mix_mass_with_volume(): void
    {
        $this->expectException(InvalidArgumentException::class);

        (new Quantity(100, $this->gram()))->add(new Quantity(1, $this->tablespoon()));
    }

    public function test_it_knows_whether_stock_covers_what_a_recipe_needs(): void
    {
        $inThePantry = new Quantity(1, $this->kilogram());

        $this->assertTrue($inThePantry->covers(new Quantity(999, $this->gram())));
        $this->assertFalse($inThePantry->covers(new Quantity(1001, $this->gram())));
    }

    public function test_stock_never_covers_a_requirement_measured_differently(): void
    {
        $inThePantry = new Quantity(1, $this->kilogram());

        $this->assertFalse($inThePantry->covers(new Quantity(1, $this->tablespoon())));
    }

    public function test_it_scales_for_a_different_number_of_servings(): void
    {
        $doubled = (new Quantity(150, $this->gram()))->multipliedBy(2);

        $this->assertEqualsWithDelta(300.0, $doubled->amount, 0.001);
    }

    public function test_subtracting_more_than_is_available_stops_at_zero(): void
    {
        $left = (new Quantity(100, $this->gram()))->subtract(new Quantity(250, $this->gram()));

        $this->assertEqualsWithDelta(0.0, $left->amount, 0.001);
    }

    public function test_it_rejects_a_negative_amount(): void
    {
        $this->expectException(InvalidArgumentException::class);

        new Quantity(-1, $this->gram());
    }

    private function gram(): UnitDefinition
    {
        return new UnitDefinition('g', UnitDimension::Mass, 1.0);
    }

    private function kilogram(): UnitDefinition
    {
        return new UnitDefinition('kg', UnitDimension::Mass, 1000.0);
    }

    private function tablespoon(): UnitDefinition
    {
        return new UnitDefinition('tbsp', UnitDimension::Volume, 15.0);
    }
}
