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
        // "oraz" joins this line to the one above and names nothing itself; it
        // became a product 32 times, including where the butter was readable.
        $this->assertSame('masła', $this->parser->parse('oraz 50 g masła')->ingredientPhrase);
    }

    /**
     * Fresh is not a different product. Left in the name, "świeżych" became one —
     * 232 lines of it, covering yeast, raspberries and basil leaves alike.
     * **Dried is deliberately not treated this way**: "suszonych pomidorów"
     * reduced to "pomidorów" would put fresh tomatoes in a recipe wanting jarred.
     */
    public function test_fresh_is_a_descriptor_but_dried_is_part_of_the_name(): void
    {
        $this->assertSame('drożdży', $this->parser->parse('25 g świeżych drożdży')->ingredientPhrase);
        $this->assertSame('malin', $this->parser->parse('1/2 szklanki świeżych malin')->ingredientPhrase);
        $this->assertSame('suszonych pomidorów', $this->parser->parse('100 g suszonych pomidorów')->ingredientPhrase);
    }

    /**
     * A describing word between the number and the measure used to hide the
     * measure completely, and the line fell back to the bare-count rule.
     *
     * "1 płaska łyżeczka soli" became one *piece* of salt and "2 duże ząbki
     * czosnku" became two pieces of garlic — two cloves are 10 g and two heads
     * are 90 g, so this is a real amount, not a cosmetic slip. 679 lines were
     * counted in pieces this way. Descriptors are therefore taken out before the
     * measure is read, never after.
     */
    public function test_a_describing_word_does_not_hide_the_measure(): void
    {
        $salt = $this->parser->parse('1 płaska łyżeczka soli');

        $this->assertSame('tsp', $salt->unitCode);
        $this->assertSame('soli', $salt->ingredientPhrase);

        $garlic = $this->parser->parse('2 duże ząbki czosnku');

        $this->assertSame('clove', $garlic->unitCode);
        $this->assertSame('czosnku', $garlic->ingredientPhrase);

        $corn = $this->parser->parse('1 mała puszka kukurydzy');

        $this->assertSame('can', $corn->unitCode);
        $this->assertSame('kukurydzy', $corn->ingredientPhrase);
    }

    /**
     * An instruction attached to an ingredient is not part of its name. Left in,
     * "przeciśniętego przez praskę" became a product and took the garlic with it
     * — the same mechanism as "do podania" taking the parmesan.
     */
    public function test_how_something_was_put_through_a_press_is_not_its_name(): void
    {
        $this->assertSame('czosnku', $this->parser->parse('2 ząbki czosnku przeciśniętego przez praskę')->ingredientPhrase);
    }

    /**
     * The same labels at the other end of the sentence, which is where the sites
     * actually write them most often.
     *
     * Left in place the whole phrase becomes the product's name, and "do podania"
     * was invented as a product — after which it owned that spelling and took the
     * parmesan, the chives and the soured cream on 93 lines with it. That is the
     * "Sos:" mechanism arriving from the right-hand side.
     */
    public function test_it_strips_a_serving_note_from_the_end_of_a_line(): void
    {
        $this->assertSame('parmezan', $this->parser->parse('parmezan do podania')->ingredientPhrase);
        $this->assertSame('natka pietruszki', $this->parser->parse('natka pietruszki do dekoracji')->ingredientPhrase);
        // "świeża" is a descriptor and moves to the note, so what is left is the herb.
        $this->assertSame('kolendra', $this->parser->parse('świeża kolendra, do podania')->ingredientPhrase);
        $this->assertSame('śmietany', $this->parser->parse('2 łyżki śmietany do podania')->ingredientPhrase);
    }

    /**
     * "do smażenia" and "do formy" are not on that list and must not be read as
     * if they were: "olej do smażenia" names the oil it is asking for. This is
     * the narrow half of the rule the section-caption work already learned — a
     * caption read as a product is recoverable, a product read as a label is not.
     */
    public function test_it_leaves_a_purpose_that_is_part_of_the_name(): void
    {
        $this->assertSame('olej do smażenia', $this->parser->parse('olej do smażenia')->ingredientPhrase);
        $this->assertSame('masło do formy', $this->parser->parse('masło do formy')->ingredientPhrase);
    }

    /** A line that is nothing but a label keeps its text, or a review has nothing to read. */
    public function test_a_line_that_is_only_a_label_is_not_emptied(): void
    {
        $this->assertSame('do podania', $this->parser->parse('do podania')->ingredientPhrase);
    }

    public function test_it_handles_a_decimal_written_with_a_comma(): void
    {
        $line = $this->parser->parse('1,5 kg ziemniaków');

        $this->assertSame(1.5, $line->quantity);
        $this->assertSame('kg', $line->unitCode);
    }
}
