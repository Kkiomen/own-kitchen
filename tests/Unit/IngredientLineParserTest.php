<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Importing\Parsing\IngredientLineParser;
use App\Importing\Parsing\PolishTextNormalizer;
use App\Importing\Parsing\UnitVocabulary;
use PHPUnit\Framework\TestCase;

/**
 * Every line below is copied verbatim from a real kwestiasmaku.com recipe.
 */
class IngredientLineParserTest extends TestCase
{
    private IngredientLineParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new IngredientLineParser(new PolishTextNormalizer, new UnitVocabulary);
    }

    public function test_it_reads_an_amount_a_unit_and_a_product(): void
    {
        $line = $this->parser->parse('150 g makaronu spaghetti');

        $this->assertSame(150.0, $line->quantity);
        $this->assertSame('g', $line->unitCode);
        $this->assertSame('makaronu spaghetti', $line->ingredientPhrase);
    }

    public function test_a_bare_count_means_pieces(): void
    {
        $line = $this->parser->parse('2 jajka');

        $this->assertSame(2.0, $line->quantity);
        $this->assertSame('piece', $line->unitCode);
        $this->assertSame('jajka', $line->ingredientPhrase);
    }

    public function test_it_understands_fractions(): void
    {
        $line = $this->parser->parse('1/2 małej cebuli');

        $this->assertSame(0.5, $line->quantity);
        $this->assertSame('cebuli', $line->ingredientPhrase);
        $this->assertSame('małej', $line->note);
    }

    public function test_it_maps_polish_measures_onto_unit_codes(): void
    {
        $this->assertSame('clove', $this->parser->parse('2 ząbki czosnku')->unitCode);
        $this->assertSame('tbsp', $this->parser->parse('1 łyżka cukru')->unitCode);
        $this->assertSame('tsp', $this->parser->parse('2 łyżeczki ekstraktu')->unitCode);
        $this->assertSame('cup', $this->parser->parse('1 szklanka mleka')->unitCode);
    }

    public function test_preparation_words_become_a_note_not_part_of_the_product_name(): void
    {
        $line = $this->parser->parse('2 łyżki drobno posiekanej natki pietruszki');

        $this->assertSame(2.0, $line->quantity);
        $this->assertSame('tbsp', $line->unitCode);
        $this->assertSame('natki pietruszki', $line->ingredientPhrase);
        $this->assertSame('drobno posiekanej', $line->note);
    }

    public function test_a_parenthetical_alternative_becomes_a_note(): void
    {
        $line = $this->parser->parse('40 g sera Pecorino (lub Parmezanu lub Grana Padano)');

        $this->assertSame(40.0, $line->quantity);
        $this->assertSame('sera Pecorino', $line->ingredientPhrase);
        $this->assertSame('lub Parmezanu lub Grana Padano', $line->note);
    }

    public function test_a_line_without_an_amount_keeps_the_product(): void
    {
        $line = $this->parser->parse('świeżo zmielony czarny pieprz');

        $this->assertNull($line->quantity);
        $this->assertNull($line->unitCode);
        $this->assertSame('zmielony czarny pieprz', $line->ingredientPhrase);
    }

    public function test_a_pinch_is_a_unit_in_its_own_right(): void
    {
        $line = $this->parser->parse('szczypta soli');

        $this->assertSame('soli', $line->ingredientPhrase);
    }

    public function test_it_reads_a_range_as_a_minimum_and_a_maximum(): void
    {
        $line = $this->parser->parse('1 - 2 łyżki oliwy');

        $this->assertSame(1.0, $line->quantity);
        $this->assertSame(2.0, $line->quantityMax);
        $this->assertSame('tbsp', $line->unitCode);
    }

    public function test_it_strips_footnote_markers(): void
    {
        $line = $this->parser->parse('1 kg solonego serka kremowego (cream cheese)*');

        $this->assertSame(1.0, $line->quantity);
        $this->assertSame('kg', $line->unitCode);
        $this->assertSame('solonego serka kremowego', $line->ingredientPhrase);
    }

    public function test_a_percentage_stays_in_the_product_name(): void
    {
        $line = $this->parser->parse('200 g śmietanki 30%');

        $this->assertSame('śmietanki 30%', $line->ingredientPhrase);
    }

    public function test_it_flags_optional_ingredients(): void
    {
        $this->assertTrue($this->parser->parse('sól do smaku')->isOptional);
    }

    /**
     * A label in front of the amount defeated quantity extraction, so the whole
     * line was treated as the product's name.
     */
    public function test_a_leading_label_does_not_swallow_the_line(): void
    {
        $line = $this->parser->parse('opcjonalnie: 1 łyżeczka sosu worcestershire');

        $this->assertTrue($line->isOptional);
        $this->assertSame(1.0, $line->quantity);
        $this->assertSame('tsp', $line->unitCode);
        $this->assertSame('sosu worcestershire', $line->ingredientPhrase);
    }

    public function test_it_strips_other_leading_labels_too(): void
    {
        $this->assertSame('natki pietruszki', $this->parser->parse('do podania: natki pietruszki')->ingredientPhrase);
        $this->assertSame('sezam', $this->parser->parse('dla chętnych - sezam')->ingredientPhrase);
    }

    public function test_it_handles_a_decimal_written_with_a_comma(): void
    {
        $line = $this->parser->parse('1,5 kg ziemniaków');

        $this->assertSame(1.5, $line->quantity);
        $this->assertSame('kg', $line->unitCode);
    }
}
