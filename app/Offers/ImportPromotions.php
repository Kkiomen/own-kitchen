<?php

declare(strict_types=1);

namespace App\Offers;

use App\Models\Promotion;
use App\Offers\Contracts\OfferSource;
use Closure;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives one refresh of the shop leaflets.
 *
 * Source-agnostic by construction: it speaks only to the OfferSource port, so a
 * second aggregator never changes this class.
 */
final class ImportPromotions
{
    public function __construct(
        private readonly StorePromotionDraft $store,
        private readonly ShopDirectory $shops,
    ) {}

    /**
     * @param  list<string>|null  $shopSlugs  Null walks every shop the source knows.
     * @param  Closure(string, PromotionImportSummary): void|null  $onShopFinished
     */
    public function run(
        OfferSource $source,
        ?array $shopSlugs = null,
        int $maxPages = 10,
        ?Closure $onShopFinished = null,
    ): PromotionImportSummary {
        $report = $onShopFinished ?? static fn (string $slug, PromotionImportSummary $summary): null => null;

        $total = new PromotionImportSummary;

        foreach ($shopSlugs ?? $source->shops() as $slug) {
            $summary = $this->runShop($source, $slug, $maxPages);

            $total->stored += $summary->stored;
            $total->matched += $summary->matched;
            $total->pruned += $summary->pruned;
            $total->failed += $summary->failed;

            $report($slug, $summary);
        }

        return $total;
    }

    private function runShop(OfferSource $source, string $slug, int $maxPages): PromotionImportSummary
    {
        $summary = new PromotionImportSummary;
        $shop = $this->shops->for($slug);
        $startedAt = Carbon::now();

        foreach ($source->discover($slug, $maxPages) as $draft) {
            try {
                $promotion = $this->store->store($draft, $shop, $source->name());
                $summary->stored++;

                if ($promotion->ingredient_id !== null) {
                    $summary->matched++;
                }
            } catch (Throwable $exception) {
                // One malformed entry must not end a walk of four thousand — but
                // it must not disappear either, or a changed layout looks like a
                // quiet week for promotions.
                $summary->failed++;

                Log::warning('Could not store a promotion.', [
                    'source' => $source->name(),
                    'shop' => $slug,
                    'title' => $draft->title,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        $summary->pruned = $this->pruneStale($shop->id, $source->name(), $startedAt);

        return $summary;
    }

    /**
     * Forget offers this source has stopped publishing.
     *
     * `valid_to` alone is not enough: the listing states a duration for most
     * entries but not all, and an offer with no stated end would otherwise sit on
     * a shopping plan for ever. So anything this source has not shown us in a
     * fortnight is treated as withdrawn. The window is deliberately longer than
     * the refresh interval — a run that only reached page 3 of 120 must not
     * delete everything it did not get to.
     */
    private function pruneStale(int $shopId, string $source, Carbon $startedAt): int
    {
        return Promotion::query()
            ->where('shop_id', $shopId)
            ->where('source', $source)
            ->where('updated_at', '<', $startedAt->copy()->subDays((int) config('offers.stale_after_days', 14)))
            ->delete();
    }
}
