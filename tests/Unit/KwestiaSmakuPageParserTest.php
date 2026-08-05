<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Importing\Drafts\RecipeDraft;
use App\Importing\Sources\KwestiaSmaku\KwestiaSmakuPageParser;
use PHPUnit\Framework\TestCase;

/**
 * Runs against pages saved from the live site, so a layout change on
 * kwestiasmaku.com fails here rather than silently importing empty recipes.
 */
class KwestiaSmakuPageParserTest extends TestCase
{
    private KwestiaSmakuPageParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new KwestiaSmakuPageParser;
    }

    public function test_it_reads_the_headline_fields(): void
    {
        $draft = $this->parse('carbonara-z-kurkami');

        $this->assertSame('Carbonara z kurkami', $draft->title);
        $this->assertSame(2, $draft->servings);
        $this->assertSame('2 porcje', $draft->servingsLabel);
        $this->assertNotNull($draft->imageUrl);
    }

    public function test_it_reads_every_ingredient_line_in_order(): void
    {
        $draft = $this->parse('carbonara-z-kurkami');

        $this->assertCount(9, $draft->ingredientLines);
        $this->assertSame('150 g makaronu spaghetti', $draft->ingredientLines[0]->rawText);
        $this->assertSame('2 łyżki drobno posiekanej natki pietruszki', $draft->ingredientLines[6]->rawText);
    }

    public function test_it_reads_the_steps(): void
    {
        $draft = $this->parse('carbonara-z-kurkami');

        $this->assertCount(10, $draft->steps);
        $this->assertStringStartsWith('Zagotować wodę', $draft->steps[0]->rawText);
    }

    public function test_it_tags_lines_of_a_multi_part_recipe_with_their_section(): void
    {
        $draft = $this->parse('sernik-lotus');

        $sections = array_values(array_unique(array_filter(
            array_map(static fn ($line) => $line->section, $draft->ingredientLines),
        )));

        $this->assertSame(['Spód', 'Masa serowa', 'Polewa', 'Dekoracja'], $sections);
        $this->assertSame('Spód', $draft->ingredientLines[0]->section);
    }

    /**
     * Dressings and sauces write their method as one paragraph rather than a list.
     * Reading only <li> threw these recipes away entirely.
     */
    public function test_it_reads_a_recipe_whose_method_is_a_paragraph(): void
    {
        $draft = $this->parse('miodowy-sos-winegret');

        $this->assertNotEmpty($draft->ingredientLines);
        $this->assertGreaterThan(1, count($draft->steps), 'The paragraph should become several steps.');
        $this->assertStringStartsWith('Czosnek przecisnąć', $draft->steps[0]->rawText);

        foreach ($draft->steps as $step) {
            $this->assertNotSame('', trim($step->rawText));
        }
    }

    public function test_it_finds_recipe_links_on_a_listing_page(): void
    {
        $slugs = $this->parser->parseListing($this->fixture('listing'));

        $this->assertContains('sernik-baskijski', $slugs);
        $this->assertGreaterThan(20, count($slugs));
        $this->assertSame(array_unique($slugs), $slugs);
    }

    private function parse(string $name): RecipeDraft
    {
        return $this->parser->parseRecipe(
            $this->fixture($name),
            "https://www.kwestiasmaku.com/przepis/{$name}",
            $name,
        );
    }

    private function fixture(string $name): string
    {
        return (string) file_get_contents(__DIR__."/../Fixtures/kwestiasmaku/{$name}.html");
    }
}
