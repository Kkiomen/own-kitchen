<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Importing\Sources\Beszamel\BeszamelPageParser;
use PHPUnit\Framework\TestCase;

/**
 * Runs against pages saved from the live site. This parser exists only because
 * beszamel.se.pl's JSON-LD glues its ingredient lines together, so these tests are
 * the thing that would catch the site fixing that — or breaking it differently.
 */
class BeszamelPageParserTest extends TestCase
{
    private BeszamelPageParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new BeszamelPageParser;
    }

    /**
     * The JSON-LD for this page is one string: "…odsączone i opłukane1–2 łyżki
     * oliwy…". Read from the markup the lines are separate again.
     */
    public function test_it_recovers_the_ingredient_lines_the_json_ld_glued_together(): void
    {
        $lines = $this->parser->parseIngredients($this->fixture('fasola-z-airfryera'));

        $this->assertGreaterThan(5, count($lines));
        $this->assertSame(
            '2 puszki fasoli (czerwona, biała lub czarna), odsączone i opłukane',
            $lines[0]->rawText,
        );
        $this->assertSame('1 łyżeczka wędzonej papryki', $lines[2]->rawText);

        foreach ($lines as $line) {
            $this->assertNotSame('', trim($line->rawText));
        }
    }

    /**
     * The list is headed by its own "SKŁADNIKI" caption, which is not an ingredient
     * and would otherwise be imported as a product.
     */
    public function test_it_drops_the_lists_own_caption(): void
    {
        $lines = $this->parser->parseIngredients($this->fixture('fasola-z-airfryera'));
        $texts = array_map(static fn ($line): string => $line->rawText, $lines);

        $this->assertNotContains('SKŁADNIKI', $texts);
    }

    /**
     * The other half of the site gives every line its own <li> instead. Both shapes
     * are live, and reading only one turns the other into a single "ingredient" a
     * thousand characters long — which is exactly how this was found.
     */
    public function test_it_reads_the_older_layout_that_uses_one_list_item_per_line(): void
    {
        $lines = $this->parser->parseIngredients($this->fixture('szakszuka'));
        $texts = array_map(static fn ($line): string => $line->rawText, $lines);

        $this->assertContains('4 jajka', $texts);
        $this->assertContains('1/2 łyżki masła lub oliwy', $texts);
        $this->assertContains('ząbek czosnku', $texts);
        $this->assertGreaterThan(5, count($lines));
    }

    /**
     * A multi-part recipe captions its parts with a plain list item, which the
     * markup does not distinguish from an ingredient. Read as products they invent
     * junk like "Do smażenia" — which then swallows every real "olej do smażenia"
     * line in the database into itself.
     */
    public function test_it_reads_a_for_the_x_caption_as_a_section_rather_than_a_product(): void
    {
        $html = '<html><body><div class="ingredients__items"><span><ul>'
            .'<li>Na ciasto</li><li>200 g mąki pszennej</li>'
            .'<li>Do wykończenia</li><li>cukier puder</li>'
            .'</ul></span></div></body></html>';

        $lines = $this->parser->parseIngredients($html);

        $this->assertCount(2, $lines);
        $this->assertSame('200 g mąki pszennej', $lines[0]->rawText);
        $this->assertSame('Na ciasto', $lines[0]->section);
        $this->assertSame('Do wykończenia', $lines[1]->section);
    }

    /**
     * The narrow half of the same rule: these look similar and are real products.
     * Dropping one loses an ingredient outright, which is the worse failure.
     */
    public function test_it_does_not_mistake_an_ingredient_for_a_caption(): void
    {
        $html = '<html><body><div class="ingredients__items"><span><ul>'
            .'<li>Masło do smażenia</li><li>Sól i pieprz do smaku</li>'
            .'<li>Na 2 blachy ciasta</li>'
            .'</ul></span></div></body></html>';

        $texts = array_map(
            static fn ($line): string => $line->rawText,
            $this->parser->parseIngredients($html),
        );

        $this->assertContains('Masło do smażenia', $texts);
        $this->assertContains('Sól i pieprz do smaku', $texts);
        $this->assertContains('Na 2 blachy ciasta', $texts, 'An amount means it is a line, not a caption.');
    }

    public function test_a_page_without_an_ingredient_list_yields_nothing(): void
    {
        $this->assertSame([], $this->parser->parseIngredients('<html><body><p>Brak</p></body></html>'));
    }

    public function test_it_finds_recipe_links_on_a_listing_page(): void
    {
        $references = $this->parser->parseListing($this->fixture('listing'));

        $this->assertGreaterThan(10, count($references));

        $urls = array_map(static fn ($reference): string => $reference->url, $references);
        $this->assertSame(array_unique($urls), $urls);

        foreach ($references as $reference) {
            $this->assertStringEndsWith('.html', $reference->url);
            $this->assertStringNotContainsString('/', $reference->slug);
            $this->assertStringNotContainsString('.html', $reference->slug);
        }
    }

    /**
     * A listing links to its categories and to the site's other sections as well.
     * Fetching one of those as a recipe would fail on every run.
     */
    public function test_it_ignores_links_that_are_not_recipes(): void
    {
        $references = $this->parser->parseListing($this->fixture('listing'));
        $urls = array_map(static fn ($reference): string => $reference->url, $references);

        $this->assertNotContains('https://beszamel.se.pl/przepisy/przystawki-i-przekaski/', $urls);
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__."/../Fixtures/beszamel/{$name}.html");
    }
}
