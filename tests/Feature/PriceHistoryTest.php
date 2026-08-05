<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\PriceObservation;
use App\Models\Promotion;
use App\Models\Shop;
use App\Models\Unit;
use App\Models\User;
use App\Pricing\ImportPrices;
use App\Pricing\PriceHistory;
use App\Pricing\Sources\Leaflets\LeafletPriceSource;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\ShopSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * "Ile to zwykle kosztuje" — and never "ile kosztowało na promocji".
 *
 * The first three tests are the feature's whole point. A history built from
 * shelf prices would report that butter costs 4,99 zł, because that is what it
 * cost for the four days it was on offer in one chain — a chart of how good the
 * promotions were, presented as what the household pays. The guarantee is
 * enforced where the readings are written, so that is where it is pinned.
 */
class PriceHistoryTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);
        $this->seed(ShopSeeder::class);

        $this->user = User::factory()->create();
    }

    public function test_a_leaflet_contributes_its_regular_price_and_not_the_promotional_one(): void
    {
        $this->offer('Masło', price: 499, regular: 899);

        $this->importLeafletPrices();

        $this->assertSame(899, PriceObservation::query()->firstOrFail()->price_minor);
    }

    /**
     * No "before" figure means no evidence of a normal price. Storing the shelf
     * price instead would be the exact mistake, arrived at by the back door.
     */
    public function test_an_offer_with_no_regular_price_contributes_nothing(): void
    {
        $this->offer('Masło', price: 499, regular: null);

        $this->importLeafletPrices();

        $this->assertSame(0, PriceObservation::query()->count());
    }

    public function test_the_history_reports_the_regular_price(): void
    {
        $this->offer('Masło', price: 499, regular: 899);
        $this->importLeafletPrices();

        $days = $this->history()->of($this->ingredient('Masło'));

        $this->assertCount(1, $days);
        $this->assertSame(899, $days[0]['median']);
    }

    /**
     * Thirteen chains read in one afternoon is one day of evidence, not thirteen
     * points on a trend. The median is the day's answer; the spread is how much
     * the shops disagreed.
     */
    public function test_readings_from_one_day_become_one_row(): void
    {
        $this->observation('Masło', 800, '2026-08-01');
        $this->observation('Masło', 900, '2026-08-01');
        $this->observation('Masło', 1000, '2026-08-01');

        $days = $this->history()->of($this->ingredient('Masło'));

        $this->assertCount(1, $days);
        $this->assertSame(900, $days[0]['median']);
        $this->assertSame(800, $days[0]['low']);
        $this->assertSame(1000, $days[0]['high']);
        $this->assertSame(3, $days[0]['readings']);
    }

    public function test_days_are_newest_first(): void
    {
        $this->observation('Masło', 800, '2026-07-01');
        $this->observation('Masło', 900, '2026-08-01');

        $days = $this->history()->of($this->ingredient('Masło'));

        $this->assertSame('2026-08-01', $days[0]['date']);
        $this->assertSame('2026-07-01', $days[1]['date']);
    }

    public function test_the_change_is_measured_from_the_oldest_day_to_the_newest(): void
    {
        $this->observation('Masło', 800, '2026-07-01', unitPrice: 4000);
        $this->observation('Masło', 1000, '2026-08-01', unitPrice: 5000);

        $history = $this->history();
        $change = $history->change($history->of($this->ingredient('Masło')));

        $this->assertNotNull($change);
        $this->assertSame(1000, $change['difference']);
        $this->assertSame(25.0, $change['percent']);
        $this->assertSame('mass', $change['per']);
    }

    /** One point is not a trend, and an arrow drawn from it would be invention. */
    public function test_a_single_day_reports_no_change(): void
    {
        $this->observation('Masło', 800, '2026-08-01', unitPrice: 4000);

        $history = $this->history();

        $this->assertNull($history->change($history->of($this->ingredient('Masło'))));
    }

    /**
     * The one that came out of real data. A kilo of cheese from the statistical
     * office against a pack of unstated size from a leaflet reported "-76%",
     * which is not a price fall — it is two different questions subtracted from
     * one another.
     */
    public function test_pack_prices_alone_are_never_reported_as_a_change(): void
    {
        $this->observation('Ser żółty', 2712, '2025-12-31', unitPrice: 2712);
        $this->observation('Ser żółty', 639, '2026-08-05', unitPrice: null);

        $history = $this->history();

        $this->assertNull($history->change($history->of($this->ingredient('Ser żółty'))));
    }

    /** Per kilo now against per piece in December is two facts, not a change. */
    public function test_two_dimensions_are_never_compared(): void
    {
        $this->observation('Masło', 800, '2026-07-01', unitPrice: 4000, per: 'count');
        $this->observation('Masło', 1000, '2026-08-01', unitPrice: 5000, per: 'mass');

        $history = $this->history();

        $this->assertNull($history->change($history->of($this->ingredient('Masło'))));
    }

    /**
     * A product nobody priced would open a screen saying "nie wiem", so it is
     * not listed at all — the same rule that hides a category matching nothing.
     */
    public function test_only_products_with_readings_are_listed(): void
    {
        $this->observation('Masło', 800, '2026-08-01');

        $listed = array_column($this->history()->pricedProducts(), 'name');

        $this->assertSame(['Masło'], $listed);
    }

    public function test_the_screen_shows_a_products_readings(): void
    {
        $this->observation('Masło', 899, '2026-08-01');

        $this->actingAs($this->user)
            ->get(route('prices.show', $this->ingredient('Masło')))
            ->assertOk()
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->component('Prices/Show')
                ->where('product.name', 'Masło')
                ->where('days.0.median', 899),
            );
    }

    public function test_the_price_screens_need_an_account(): void
    {
        $this->get(route('prices.index'))->assertRedirect(route('login'));
    }

    private function history(): PriceHistory
    {
        return $this->app->make(PriceHistory::class);
    }

    private function importLeafletPrices(): void
    {
        $this->app->make(ImportPrices::class)->run(
            $this->app->make(LeafletPriceSource::class),
        );
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::query()->where('name', $name)->firstOrFail();
    }

    private function offer(string $product, int $price, ?int $regular): Promotion
    {
        return Promotion::query()->create([
            'shop_id' => Shop::query()->where('slug', 'lidl')->firstOrFail()->id,
            'source' => 'gazetki.pl',
            'external_id' => 'offer-'.$product.'-'.$price,
            'title' => $product.' 200 g',
            'ingredient_id' => $this->ingredient($product)->id,
            'needs_review' => false,
            'price_minor' => $price,
            'regular_price_minor' => $regular,
            'pack_quantity' => 200,
            'pack_unit_id' => Unit::query()->where('code', 'g')->firstOrFail()->id,
            'valid_to' => now()->addDays(3),
            'url' => 'https://www.gazetki.pl/oferty/test',
        ]);
    }

    private function observation(
        string $product,
        int $grosze,
        string $day,
        ?int $unitPrice = null,
        string $per = 'mass',
    ): void {
        static $sequence = 0;
        $sequence++;

        PriceObservation::query()->create([
            'source' => 'leaflets',
            'external_id' => 'reading-'.$sequence,
            'title' => $product.' 200 g',
            'ingredient_id' => $this->ingredient($product)->id,
            'needs_review' => false,
            'price_minor' => $grosze,
            'unit_price_minor' => $unitPrice,
            'unit_price_per' => $unitPrice === null ? null : $per,
            'observed_on' => $day,
        ]);
    }
}
