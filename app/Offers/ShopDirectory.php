<?php

declare(strict_types=1);

namespace App\Offers;

use App\Models\Shop;

/**
 * Turns a source's slug for a chain into the one `Shop` row that stands for it.
 *
 * Lookup-or-create against a unique slug rather than trusting anything upstream
 * to have inserted it first — the same rule the catalogue applies to ingredients
 * and units, and for the same reason: two Biedronka rows would split one week's
 * promotions across two stops on a plan.
 */
final class ShopDirectory
{
    /**
     * @var array<string, Shop>
     */
    private array $cache = [];

    public function for(string $slug): Shop
    {
        return $this->cache[$slug] ??= Shop::query()->firstOrCreate(
            ['slug' => $slug],
            [
                'name' => $this->configuredName($slug),
                'position' => $this->configuredPosition($slug),
            ],
        );
    }

    private function configuredName(string $slug): string
    {
        /** @var array<string, string> $names */
        $names = config('offers.shops', []);

        // A slug nobody named still gets a readable label rather than blocking an
        // import: "delikatesy-centrum" reads well enough as a heading.
        return $names[$slug] ?? ucwords(str_replace('-', ' ', $slug));
    }

    /**
     * Configured order, so a plan lists the shops in the order they were declared
     * — which is the order the household actually passes them.
     */
    private function configuredPosition(string $slug): int
    {
        /** @var array<string, string> $names */
        $names = config('offers.shops', []);

        $position = array_search($slug, array_keys($names), true);

        return $position === false ? count($names) : $position;
    }
}
