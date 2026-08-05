<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Models\PriceObservation;
use Illuminate\Support\Carbon;

/**
 * Whether the prices we hold are old enough to be worth re-reading.
 *
 * Asks the rows rather than keeping a record of the last run, exactly as
 * `OfferRefresh` does: the age of the data *is* the record, it cannot drift out
 * of step with reality, and a missed window costs hours rather than a week.
 *
 * Read from `updated_at`, not `observed_on`. They answer different questions: an
 * annual figure republished unchanged is six months old as a fact about prices
 * and current as a fact about our data, and it is the second one that decides
 * whether to go and ask again.
 */
final class PriceRefresh
{
    public function isStale(): bool
    {
        $newest = $this->lastImportedAt();

        // Nothing has ever been read. The estimate is blank until it is.
        if ($newest === null) {
            return true;
        }

        return $newest->lt(now()->subDays($this->days()));
    }

    public function lastImportedAt(): ?Carbon
    {
        $newest = PriceObservation::query()->max('updated_at');

        return $newest === null ? null : Carbon::parse((string) $newest);
    }

    private function days(): int
    {
        return max(1, (int) config('pricing.refresh_after_days', 7));
    }
}
