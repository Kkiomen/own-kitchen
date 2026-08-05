<?php

declare(strict_types=1);

namespace App\Pricing\Quality;

use App\Models\PriceObservation;
use App\Pricing\PriceBook;
use Illuminate\Support\Facades\DB;

/**
 * How much of a shopping list the price data can actually answer for.
 *
 * Measured against **what the household buys**, not against the catalogue. A
 * price book covering four hundred products none of which are on anybody's list
 * is not coverage, and the same rule already governs the measures report and the
 * ingredient review queue: attack by frequency, count the lines that matter.
 */
final class PriceCoverageReport
{
    public function __construct(private readonly PriceBook $prices) {}

    public function generate(int $unmatchedLimit = 25, int $gapLimit = 20): PriceCoverageSnapshot
    {
        $observations = PriceObservation::query()->count();

        return new PriceCoverageSnapshot(
            observations: $observations,
            matched: PriceObservation::query()->whereNotNull('ingredient_id')->count(),
            priced: PriceObservation::query()->comparable()->count(),
            /*
             * Matched *and* recent, which is what the estimate can actually
             * spend. Deliberately not narrowed to readings with a unit price:
             * that number is separately reported and is always small, and
             * combining the two produced a "wciąż aktualne" figure of 6% for
             * data that was 98% current — a report that looked like an outage.
             */
            fresh: PriceObservation::query()
                ->whereNotNull('ingredient_id')
                ->fresh((int) config('pricing.max_age_days', 500))
                ->count(),
            perSource: $this->perSource(),
            pricedProducts: count($this->pricedIngredientIds()),
            listCoverage: $this->listCoverage(),
            gaps: $this->gaps($gapLimit),
            unmatched: $this->unmatched($unmatchedLimit),
        );
    }

    /**
     * @return array<string, int>
     */
    private function perSource(): array
    {
        $rows = DB::table('price_observations')
            ->selectRaw('source, count(*) as total')
            ->groupBy('source')
            ->pluck('total', 'source');

        $counts = [];

        foreach ($rows as $source => $total) {
            $counts[(string) $source] = (int) $total;
        }

        return $counts;
    }

    /**
     * The share of everything currently on a shopping list that has a price.
     *
     * Across every account, because this is a report about the data rather than
     * about one household — and there are two people on one account here anyway.
     *
     * @return array{items: int, priced: int}
     */
    private function listCoverage(): array
    {
        $wanted = DB::table('shopping_list_items')
            ->whereNotNull('ingredient_id')
            ->distinct()
            ->pluck('ingredient_id');

        $priced = $this->pricedIngredientIds();
        $covered = 0;

        foreach ($wanted as $id) {
            if (isset($priced[(int) $id])) {
                $covered++;
            }
        }

        return ['items' => $wanted->count(), 'priced' => $covered];
    }

    /**
     * Products a shopping list asks for and nothing can price, commonest first.
     *
     * This is the working list. The top of it is where one dictionary entry or
     * one new source buys the most coverage.
     *
     * @return array<string, int>
     */
    private function gaps(int $limit): array
    {
        $priced = $this->pricedIngredientIds();

        $rows = DB::table('shopping_list_items as item')
            ->join('ingredients as product', 'product.id', '=', 'item.ingredient_id')
            ->selectRaw('product.name as name, product.id as id, count(*) as total')
            ->whereNotNull('item.ingredient_id')
            ->groupBy('product.id', 'product.name')
            ->orderByDesc('total')
            ->get();

        $gaps = [];

        foreach ($rows as $row) {
            if (isset($priced[(int) $row->id])) {
                continue;
            }

            $gaps[(string) $row->name] = (int) $row->total;

            if (count($gaps) === $limit) {
                break;
            }
        }

        return $gaps;
    }

    /**
     * Asked of the price book rather than counted here, so a report cannot claim
     * coverage the estimate would not actually deliver: "priced" means fresh
     * enough, matched, and comparable, and that definition lives in one place.
     *
     * @return array<int, true>
     */
    private function pricedIngredientIds(): array
    {
        $ids = [];

        foreach ($this->prices->ingredientIds() as $id) {
            $ids[$id] = true;
        }

        return $ids;
    }

    /**
     * Readings nobody could place. Vocabulary the dictionary is missing — never a
     * reason to invent a product.
     *
     * @return list<string>
     */
    private function unmatched(int $limit): array
    {
        $titles = [];

        foreach (PriceObservation::query()
            ->whereNull('ingredient_id')
            ->orderBy('title')
            ->limit($limit)
            ->pluck('title') as $title) {
            $titles[] = (string) $title;
        }

        return $titles;
    }
}
