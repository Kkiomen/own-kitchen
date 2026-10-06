<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Planning\Season;
use Carbon\CarbonImmutable;
use Tests\TestCase;

/**
 * `database/data/seasons.php` asked about real dates.
 */
class SeasonTest extends TestCase
{
    /** A name that drifted from the dictionary would quietly never match. */
    public function test_every_seasonal_product_is_a_product_the_dictionary_knows(): void
    {
        /** @var list<array{name: string}> $dictionary */
        $dictionary = require database_path('data/ingredients.php');
        $names = array_column($dictionary, 'name');

        foreach ($this->season()->seasonalProducts() as $product) {
            $this->assertContains($product, $names, "Season lists '{$product}', which the dictionary does not.");
        }
    }

    /** Easter moves; a fixed window would be a month wrong one year in three. */
    public function test_an_easter_dish_follows_easter(): void
    {
        $season = $this->season();

        // Easter Sunday: 5 April 2026, 28 March 2027.
        $this->assertTrue($season->allows('easter', CarbonImmutable::parse('2026-04-01')));
        $this->assertFalse($season->allows('easter', CarbonImmutable::parse('2026-04-20')));
        $this->assertTrue($season->allows('easter', CarbonImmutable::parse('2027-03-20')));
    }

    public function test_a_window_may_wrap_the_year(): void
    {
        $season = $this->season();

        $this->assertTrue($season->allows('christmas', CarbonImmutable::parse('2026-12-24')));
        $this->assertTrue($season->allows('christmas', CarbonImmutable::parse('2027-01-03')));
        $this->assertFalse($season->allows('christmas', CarbonImmutable::parse('2026-10-13')));
    }

    /** "Podbiła pół świata" is not Christmas, and "grillowany" is any month. */
    public function test_an_occasion_is_read_from_whole_words(): void
    {
        $season = $this->season();

        $this->assertSame('christmas', $season->occasionOf('Świąteczny piernik'));
        $this->assertNull($season->occasionOf('Szakszuka podbiła pół świata'));
        $this->assertNull($season->occasionOf('Grillowany kurczak z warzywami'));
        $this->assertSame('early_summer', $season->occasionOf('Chłodnik litewski'));
        $this->assertSame('christmas', $season->occasionOf('Barszcz czerwony na wigilię'));
        $this->assertSame('spring_greens', $season->occasionOf('Zielony barszcz z pokrzywy i szczawiu'));
    }

    public function test_strict_produce_sinks_out_of_season_and_the_rest_only_rises_in_it(): void
    {
        $season = $this->season();
        $december = CarbonImmutable::parse('2026-12-08');

        $this->assertLessThan(0, $season->fitOf(['Szparagi'], $december));
        $this->assertSame(0, $season->fitOf(['Pomidor'], $december));
        $this->assertSame(1, $season->fitOf(['Dynia'], CarbonImmutable::parse('2026-10-13')));
    }

    private function season(): Season
    {
        return $this->app->make(Season::class);
    }
}
