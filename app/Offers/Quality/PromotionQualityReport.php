<?php

declare(strict_types=1);

namespace App\Offers\Quality;

use App\Models\Promotion;
use Illuminate\Support\Facades\DB;

/**
 * Measures how much of the leaflets we actually understood.
 *
 * The two lists it produces are the working tools, and they are read in opposite
 * directions. `topMatches` is where wrong matches hide: a product suddenly
 * holding forty offers is not popular, it is swallowing entries that belong
 * elsewhere — the same failure mode that once made every sauce in the catalogue
 * resolve to a heading. `unmatched` is where the missing vocabulary is, and it
 * is worth reading by frequency rather than alphabetically.
 */
final class PromotionQualityReport
{
    public function generate(int $unmatchedLimit = 25, int $matchLimit = 15): PromotionQualitySnapshot
    {
        $promotions = Promotion::query()->count();

        return new PromotionQualitySnapshot(
            promotions: $promotions,
            shops: (int) Promotion::query()->distinct()->count('shop_id'),
            matched: Promotion::query()->whereNotNull('ingredient_id')->count(),
            withPackSize: Promotion::query()->whereNotNull('pack_quantity')->count(),
            withUnitPrice: Promotion::query()->whereNotNull('unit_price_minor')->count(),
            withRegularPrice: Promotion::query()->whereNotNull('regular_price_minor')->count(),
            expired: Promotion::query()->whereDate('valid_to', '<', now()->toDateString())->count(),
            perShop: $this->perShop(),
            topMatches: $this->topMatches($matchLimit),
            unmatched: $this->unmatched($unmatchedLimit),
        );
    }

    /**
     * @return array<string, int>
     */
    private function perShop(): array
    {
        return DB::table('promotions')
            ->join('shops', 'shops.id', '=', 'promotions.shop_id')
            ->selectRaw('shops.name as name, count(*) as total')
            ->groupBy('shops.name')
            ->orderByDesc('total')
            ->pluck('total', 'name')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * @return array<string, int>
     */
    private function topMatches(int $limit): array
    {
        return DB::table('promotions')
            ->join('ingredients', 'ingredients.id', '=', 'promotions.ingredient_id')
            ->selectRaw('ingredients.name as name, count(*) as total')
            ->groupBy('ingredients.name')
            ->orderByDesc('total')
            ->limit($limit)
            ->pluck('total', 'name')
            ->map(static fn (mixed $total): int => (int) $total)
            ->all();
    }

    /**
     * @return list<string>
     */
    private function unmatched(int $limit): array
    {
        return array_values(array_map(
            static fn (mixed $title): string => (string) $title,
            Promotion::query()
                ->whereNull('ingredient_id')
                ->orderBy('title')
                ->limit($limit)
                ->pluck('title')
                ->all(),
        ));
    }
}
