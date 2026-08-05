<?php

declare(strict_types=1);

namespace App\Offers;

use App\Models\Promotion;
use Illuminate\Support\Carbon;

/**
 * Whether the leaflets are old enough to be worth reading again.
 *
 * The schedule used to be a fixed weekly slot, which is right for a server and
 * wrong for the machine this actually runs on: a desktop that is asleep at
 * 04:00 on a Monday misses the slot entirely and then carries last fortnight's
 * prices for a week without ever saying so. Asking "are these stale?" often
 * instead means a missed window costs the few hours until the next check rather
 * than the whole week, and it needs no memory of whether a run happened.
 *
 * The answer comes from the offers themselves rather than from a marker the
 * import writes: what matters to a plan is how old the prices on screen are,
 * and that is a fact about the rows.
 */
class OfferRefresh
{
    public function isStale(): bool
    {
        $newest = $this->lastImportedAt();

        // Nothing has ever been read, so anything at all is an improvement.
        if ($newest === null) {
            return true;
        }

        return $newest->lt(now()->subDays($this->days()));
    }

    /**
     * When the freshest offer we hold was last seen in a leaflet. Read off
     * `updated_at` rather than `created_at`, because an offer that runs for a
     * second week is touched, not re-created.
     */
    public function lastImportedAt(): ?Carbon
    {
        $newest = Promotion::query()->max('updated_at');

        return $newest === null ? null : Carbon::parse($newest);
    }

    private function days(): int
    {
        return max(1, (int) config('offers.refresh_after_days', 7));
    }
}
