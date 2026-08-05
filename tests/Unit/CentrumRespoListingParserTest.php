<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Importing\Sources\CentrumRespo\CentrumRespoListingParser;
use PHPUnit\Framework\TestCase;

class CentrumRespoListingParserTest extends TestCase
{
    private const string BASE = 'https://centrumrespo.pl';

    private CentrumRespoListingParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new CentrumRespoListingParser;
    }

    public function test_it_finds_recipe_links_on_a_listing_page(): void
    {
        $slugs = $this->parser->parseListing($this->fixture(), self::BASE);

        $this->assertContains('lunchbox-z-pieczonym-falafelem', $slugs);
        $this->assertGreaterThan(5, count($slugs));
        $this->assertSame(array_unique($slugs), $slugs);
    }

    /**
     * The page also links to the categories it belongs to. Importing one as a
     * recipe would fetch an archive page and fail on every run.
     */
    public function test_it_ignores_the_category_links_around_the_grid(): void
    {
        $slugs = $this->parser->parseListing($this->fixture(), self::BASE);

        $this->assertNotContains('kategoria', $slugs);

        foreach ($slugs as $slug) {
            $this->assertStringNotContainsString('/', $slug);
        }
    }

    /**
     * The search box carries a "recently viewed" list of recipes from anywhere on
     * the site. Those are not part of the category being walked, so taking every
     * recipe link on the page would quietly import unrelated dishes as meal prep.
     */
    public function test_it_ignores_the_recipes_suggested_by_the_search_box(): void
    {
        $slugs = $this->parser->parseListing($this->fixture(), self::BASE);

        $this->assertNotContains('nalesniki-z-hummusem-i-kurczakiem', $slugs);
        $this->assertNotContains('owsianka-snickers', $slugs);
    }

    /**
     * Past the last page the site answers 200 with an empty grid rather than a 404,
     * so an empty result is what ends the walk.
     */
    public function test_a_page_past_the_end_of_the_listing_yields_nothing(): void
    {
        $this->assertSame([], $this->parser->parseListing(
            '<html><body><div class="przepisy__grid"></div></body></html>',
            self::BASE,
        ));
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/centrumrespo/listing.html');
    }
}
