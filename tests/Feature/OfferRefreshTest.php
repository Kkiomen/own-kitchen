<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Promotion;
use App\Models\Shop;
use App\Offers\OfferRefresh;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rule the scheduler runs on. It is what makes the refresh unattended: no
 * fixed slot to miss, no record of the last run to keep in step.
 */
class OfferRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_never_having_read_a_leaflet_counts_as_stale(): void
    {
        $this->assertTrue($this->refresh()->isStale());
    }

    public function test_this_weeks_prices_are_left_alone(): void
    {
        $this->offerSeen(now()->subDays(2));

        $this->assertFalse($this->refresh()->isStale());
    }

    /**
     * Polish leaflets turn over weekly, so a week-old price is a price from a
     * leaflet that is no longer in the shop.
     */
    public function test_prices_older_than_a_cycle_are_read_again(): void
    {
        $this->offerSeen(now()->subDays(8));

        $this->assertTrue($this->refresh()->isStale());
    }

    /**
     * An offer running for a second week is touched rather than re-created, so
     * the age that matters is when it was last seen.
     */
    public function test_an_offer_seen_again_is_not_old(): void
    {
        $offer = $this->offerSeen(now()->subDays(30));
        $offer->forceFill(['updated_at' => now()])->save();

        $this->assertFalse($this->refresh()->isStale());
    }

    private function refresh(): OfferRefresh
    {
        return $this->app->make(OfferRefresh::class);
    }

    private function offerSeen(\DateTimeInterface $when): Promotion
    {
        $shop = Shop::query()->create(['slug' => 'biedronka', 'name' => 'Biedronka']);

        $offer = Promotion::query()->create([
            'shop_id' => $shop->id,
            'source' => 'gazetki',
            'external_id' => 'test-1',
            'title' => 'Masło extra 200 g',
            'price_minor' => 599,
            'url' => 'https://www.gazetki.pl/oferty/test-1',
        ]);

        $offer->forceFill(['created_at' => $when, 'updated_at' => $when])->save();

        return $offer;
    }
}
