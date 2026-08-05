<?php

declare(strict_types=1);

namespace App\Shopping;

use App\Models\Shop;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

/**
 * Which chains the household is willing to drive to.
 *
 * The rule everything else depends on is here and only here: **an empty
 * selection means "all of them", not "none"**. Nobody has chosen on a fresh
 * account, and reading that as "no shops" would answer the plan with an empty
 * screen and the cost estimate with nothing at all — a feature that switches
 * itself off until it is configured, which is how a screen ends up looking
 * broken. `ids()` therefore returns null for "do not narrow anything" rather
 * than an empty array, and every caller has to tell the two apart.
 *
 * There is no default set of chains either. Guessing that a household shops at
 * Biedronka and Lidl would put a stop on a plan nobody asked for, and it would
 * be indistinguishable on screen from a choice they had made.
 */
final class SelectedShops
{
    /**
     * The chosen chains, or null when nothing has been chosen.
     *
     * @return list<int>|null
     */
    public function ids(User $user): ?array
    {
        $ids = $user->shops()->pluck('shops.id')->all();

        return $ids === [] ? null : array_values(array_map(intval(...), $ids));
    }

    /**
     * Every chain, with the household's choice marked. Chains are listed in the
     * order they are passed rather than alphabetically — that order is the one
     * the shops are actually driven past.
     *
     * @return Collection<int, Shop>
     */
    public function all(User $user): Collection
    {
        $chosen = $this->ids($user);

        return Shop::query()
            ->inWalkingOrder()
            ->withCount(['promotions as active_promotions_count' => static function ($query): void {
                $query->active();
            }])
            ->get()
            ->each(static function (Shop $shop) use ($chosen): void {
                // Nothing chosen reads as everything chosen, so the screen shows
                // what the plan will actually do rather than a row of empty boxes.
                $shop->setAttribute('selected', $chosen === null || in_array($shop->id, $chosen, true));
            });
    }

    /**
     * Replaces the whole selection: the screen sends what is ticked, not a diff.
     *
     * Ticking every chain stores every chain rather than collapsing back to the
     * empty "not chosen yet" state. The two behave identically today, but they
     * are different statements — one is a decision, the other is its absence —
     * and quietly rewriting the first into the second would lose it.
     *
     * @param  list<int>  $shopIds
     */
    public function replace(User $user, array $shopIds): void
    {
        $user->shops()->sync($shopIds);
    }
}
