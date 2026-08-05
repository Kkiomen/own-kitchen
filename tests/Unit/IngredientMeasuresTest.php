<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Enums\UnitDimension;
use App\Support\Measurement\IngredientMeasures;
use App\Support\Measurement\Quantity;
use App\Support\Measurement\UnitDefinition;
use PHPUnit\Framework\TestCase;

class IngredientMeasuresTest extends TestCase
{
    public function test_a_counted_measure_becomes_grams(): void
    {
        $onion = new IngredientMeasures(gramsPerUnit: ['piece' => 150.0]);

        $this->assertSame(300.0, $onion->toGrams($this->pieces(2))?->amount);
    }

    /**
     * The pair is the point: both of these are "czosnek", and they differ by a
     * factor of nine.
     */
    public function test_two_measures_of_one_product_weigh_different_amounts(): void
    {
        $garlic = new IngredientMeasures(gramsPerUnit: ['clove' => 5.0, 'piece' => 45.0]);

        $this->assertSame(10.0, $garlic->toGrams($this->cloves(2))?->amount);
        $this->assertSame(45.0, $garlic->toGrams($this->pieces(1))?->amount);
    }

    public function test_a_density_covers_every_volume_measure_at_once(): void
    {
        $oil = new IngredientMeasures(densityGramsPerMl: 0.91);

        $this->assertEqualsWithDelta(91.0, $oil->toGrams($this->millilitres(100))?->amount, 0.001);
        $this->assertEqualsWithDelta(13.65, $oil->toGrams($this->tablespoons(1))?->amount, 0.001);
        $this->assertEqualsWithDelta(227.5, $oil->toGrams($this->glasses(1))?->amount, 0.001);
    }

    /**
     * A Polish recipe's "łyżka mąki" is a heaped spoon, not 15 ml of flour. The
     * explicit weight has to beat the density or every baking recipe reads light.
     */
    public function test_an_explicit_weight_beats_the_density(): void
    {
        $flour = new IngredientMeasures(densityGramsPerMl: 0.53, gramsPerUnit: ['tbsp' => 15.0]);

        $this->assertSame(15.0, $flour->toGrams($this->tablespoons(1))?->amount);
        // The glass has no explicit weight, so it still goes through the density.
        $this->assertEqualsWithDelta(132.5, $flour->toGrams($this->glasses(1))?->amount, 0.001);
    }

    public function test_grams_pass_straight_through(): void
    {
        $this->assertSame(250.0, IngredientMeasures::none()->toGrams($this->grams(250))?->amount);
    }

    /**
     * The rule the whole class exists to protect: no weight means no answer, never
     * an average quietly standing in for one.
     */
    public function test_a_product_nobody_weighed_converts_to_nothing(): void
    {
        $this->assertNull(IngredientMeasures::none()->toGrams($this->pieces(2)));
        $this->assertNull(IngredientMeasures::none()->toGrams($this->tablespoons(2)));
    }

    public function test_grams_convert_back_into_the_measure_you_shop_in(): void
    {
        $onion = new IngredientMeasures(gramsPerUnit: ['piece' => 150.0]);

        $this->assertSame(3.0, $onion->fromGrams($this->grams(450), $this->pieceUnit())?->amount);
    }

    public function test_covers_answers_across_units_when_it_can(): void
    {
        $onion = new IngredientMeasures(gramsPerUnit: ['piece' => 150.0]);

        $this->assertTrue($onion->covers($this->grams(400), $this->pieces(2)));
        $this->assertFalse($onion->covers($this->grams(200), $this->pieces(2)));
    }

    /**
     * Three answers, not two. Null is "cannot say" and callers must read it as
     * silence — telling someone they are out of onions because nobody recorded
     * what an onion weighs is worse than saying nothing.
     */
    public function test_covers_says_cannot_tell_rather_than_short(): void
    {
        $this->assertNull(IngredientMeasures::none()->covers($this->grams(400), $this->pieces(2)));
        $this->assertNull(IngredientMeasures::none()->covers(null, $this->pieces(2)));
    }

    public function test_two_shelves_in_different_units_add_up(): void
    {
        $onion = new IngredientMeasures(gramsPerUnit: ['piece' => 150.0]);

        // Two in the fridge plus 300 g in the pantry is four onions' worth, and it
        // stays in pieces because that is how the first shelf was counted.
        $total = $onion->sum($this->pieces(2), $this->grams(300));

        $this->assertSame(4.0, $total?->amount);
        $this->assertSame('piece', $total?->unit->code);
    }

    public function test_a_total_that_cannot_be_formed_stays_unknown(): void
    {
        $this->assertNull(IngredientMeasures::none()->sum($this->pieces(2), $this->grams(300)));
        $this->assertNull(IngredientMeasures::none()->sum(null, $this->grams(300)));
    }

    private function grams(float $amount): Quantity
    {
        return new Quantity($amount, UnitDefinition::base(UnitDimension::Mass));
    }

    private function millilitres(float $amount): Quantity
    {
        return new Quantity($amount, UnitDefinition::base(UnitDimension::Volume));
    }

    private function tablespoons(float $amount): Quantity
    {
        return new Quantity($amount, new UnitDefinition('tbsp', UnitDimension::Volume, 15.0));
    }

    private function glasses(float $amount): Quantity
    {
        return new Quantity($amount, new UnitDefinition('cup', UnitDimension::Volume, 250.0));
    }

    private function pieces(float $amount): Quantity
    {
        return new Quantity($amount, $this->pieceUnit());
    }

    private function cloves(float $amount): Quantity
    {
        return new Quantity($amount, new UnitDefinition('clove', UnitDimension::Count, 1.0));
    }

    private function pieceUnit(): UnitDefinition
    {
        return new UnitDefinition('piece', UnitDimension::Count, 1.0);
    }
}
