<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Importing\Sources\AirFryerPrzepisy\AirFryerPrzepisyListingParser;
use PHPUnit\Framework\TestCase;

class AirFryerPrzepisyListingParserTest extends TestCase
{
    private const string BASE = 'https://airfryerprzepisy.pl';

    private AirFryerPrzepisyListingParser $parser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->parser = new AirFryerPrzepisyListingParser;
    }

    public function test_it_finds_recipe_links_on_a_listing_page(): void
    {
        $slugs = $this->parser->parseListing($this->fixture(), self::BASE);

        $this->assertContains('gulasz-wieprzowy-z-airfryera', $slugs);
        $this->assertGreaterThan(5, count($slugs));
        $this->assertSame(array_unique($slugs), $slugs);
    }

    /**
     * Each article also links to its categories. Importing those would fetch an
     * archive page as if it were a recipe and fail on every run.
     */
    public function test_it_ignores_the_category_and_tag_links_around_a_recipe(): void
    {
        $slugs = $this->parser->parseListing($this->fixture(), self::BASE);

        $this->assertNotContains('obiady', $slugs);
        $this->assertNotContains('desery', $slugs);
        $this->assertNotContains('przepisy', $slugs);
    }

    public function test_a_page_past_the_end_of_the_archive_yields_nothing(): void
    {
        $this->assertSame([], $this->parser->parseListing('<html><body><p>Nie znaleziono</p></body></html>', self::BASE));
    }

    private function fixture(): string
    {
        return (string) file_get_contents(__DIR__.'/../Fixtures/airfryerprzepisy/listing.html');
    }
}
