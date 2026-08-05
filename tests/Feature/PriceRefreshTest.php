<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\PriceObservation;
use App\Pricing\PriceRefresh;
use DateTimeInterface;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The rule the price scheduler runs on — the same shape as the leaflets', and
 * for the same reason: no fixed slot to miss on a desktop that may be asleep,
 * and no record of the last run to drift out of step with the data.
 */
class PriceRefreshTest extends TestCase
{
    use RefreshDatabase;

    public function test_never_having_read_a_price_counts_as_stale(): void
    {
        $this->assertTrue($this->refresh()->isStale());
    }

    public function test_prices_read_this_week_are_left_alone(): void
    {
        $this->readOn(now()->subDays(2));

        $this->assertFalse($this->refresh()->isStale());
    }

    public function test_prices_older_than_the_window_are_read_again(): void
    {
        $this->readOn(now()->subDays(8));

        $this->assertTrue($this->refresh()->isStale());
    }

    /**
     * Freshness is about our data, not about the prices.
     *
     * The two questions genuinely differ here: a yearly average is stamped with
     * the last day of its year and so is months old as a fact about prices, while
     * being entirely current as a fact about what we hold. Reading `observed_on`
     * instead would call the data stale for ever and re-import every hour,
     * spending the API's rate limit to be told the same figure.
     */
    public function test_an_old_figure_read_today_is_not_stale(): void
    {
        $observation = $this->readOn(now()->subDays(30));

        $observation->forceFill([
            'observed_on' => now()->subYears(1)->endOfYear()->toDateString(),
            'updated_at' => now(),
        ])->save();

        $this->assertFalse($this->refresh()->isStale());
    }

    private function refresh(): PriceRefresh
    {
        return $this->app->make(PriceRefresh::class);
    }

    private function readOn(DateTimeInterface $when): PriceObservation
    {
        $observation = PriceObservation::query()->create([
            'source' => 'gus',
            'external_id' => '4953',
            'title' => 'mąka pszenna - za 1kg',
            'price_minor' => 376,
            'observed_on' => now(),
        ]);

        $observation->forceFill(['created_at' => $when, 'updated_at' => $when])->save();

        return $observation;
    }
}
