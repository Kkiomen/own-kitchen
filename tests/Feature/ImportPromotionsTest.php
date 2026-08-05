<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UnitDimension;
use App\Models\Ingredient;
use App\Models\Promotion;
use App\Offers\ImportPromotions;
use App\Offers\OfferSourceRegistry;
use App\Offers\PromotionImportSummary;
use App\Support\Http\PageFetcher;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\ShopSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePageFetcher;
use Tests\TestCase;

class ImportPromotionsTest extends TestCase
{
    use RefreshDatabase;

    private const string LISTING = 'https://www.gazetki.pl/sklepy/biedronka/oferty';

    private FakePageFetcher $fetcher;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);
        $this->seed(ShopSeeder::class);

        $this->fetcher = new FakePageFetcher([
            self::LISTING => (string) file_get_contents(__DIR__.'/../Fixtures/gazetki/biedronka-oferty.html'),
        ]);

        $this->app->instance(PageFetcher::class, $this->fetcher);
    }

    public function test_it_stores_the_offers_on_a_listing_page(): void
    {
        $summary = $this->import();

        // Six cards, one of them a coupon with no price at all.
        $this->assertSame(5, $summary->stored);
        $this->assertSame(5, Promotion::query()->count());
    }

    public function test_an_offer_is_matched_to_the_canonical_product(): void
    {
        $this->import();

        $sugar = $this->promotion('Cukier biały');

        $this->assertSame('Cukier', $sugar->ingredient?->name);
        $this->assertFalse($sugar->needs_review);
    }

    public function test_it_keeps_the_price_the_offer_replaces_and_the_discount(): void
    {
        $this->import();

        $sugar = $this->promotion('Cukier biały');

        $this->assertSame(99, $sugar->price_minor);
        $this->assertSame(198, $sugar->regular_price_minor);
        $this->assertSame(50, $sugar->discount_percent);
    }

    public function test_a_pack_size_in_the_name_becomes_a_price_per_litre(): void
    {
        $this->import();

        $beer = $this->promotion('Piwo Garage 400 ml');

        $this->assertSame(400.0, $beer->pack_quantity);
        $this->assertSame(1248, $beer->unit_price_minor);
        $this->assertSame(UnitDimension::Volume, $beer->unit_price_per);
    }

    /**
     * The single most important rule here. "Karma dla psa wołowina" contains a
     * meat we know, and matching it would recommend dog food as the cheapest beef
     * in town — a recommendation that is worse than none at all.
     */
    public function test_dog_food_is_not_matched_to_the_meat_it_names(): void
    {
        $this->import();

        $karma = $this->promotion('Karma dla psa wołowina Dolina Noteci');

        $this->assertNull($karma->ingredient_id);
        $this->assertTrue($karma->needs_review);
    }

    /**
     * A leaflet is mostly not food. Inventing a product per unmatched entry would
     * hand thousands of aliases to rows nobody vouched for, and recipe import
     * would then resolve real ingredient lines onto them.
     */
    public function test_it_never_invents_a_product(): void
    {
        $before = Ingredient::query()->count();

        $this->import();

        $this->assertSame($before, Ingredient::query()->count());
    }

    /**
     * Nothing is dropped, so improving the matcher is a re-run over cached pages
     * rather than a re-crawl of thirteen chains.
     */
    public function test_an_unmatched_entry_is_still_stored_with_its_original_text(): void
    {
        $this->import();

        $this->assertDatabaseHas('promotions', [
            'title' => 'Karma dla psa wołowina Dolina Noteci',
            'ingredient_id' => null,
        ]);
    }

    public function test_re_running_updates_the_same_offers_rather_than_duplicating_them(): void
    {
        $this->import();
        $this->import();

        $this->assertSame(5, Promotion::query()->count());
    }

    /**
     * The paginator in the fixture offers 120 pages. A cap of one must mean one
     * request, or a working session on the matcher costs twenty minutes of
     * throttled crawling every time.
     */
    public function test_the_page_cap_is_honoured(): void
    {
        $this->import();

        $this->assertSame([self::LISTING], $this->fetcher->requestedUrls);
    }

    private function import(int $pages = 1): PromotionImportSummary
    {
        return $this->app->make(ImportPromotions::class)->run(
            source: $this->app->make(OfferSourceRegistry::class)->get('gazetki'),
            shopSlugs: ['biedronka'],
            maxPages: $pages,
        );
    }

    private function promotion(string $title): Promotion
    {
        return Promotion::query()->where('title', $title)->with('ingredient')->firstOrFail();
    }
}
