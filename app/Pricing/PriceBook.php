<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Enums\UnitDimension;
use App\Support\Money\Median;
use App\Support\Money\Money;
use App\Support\Money\UnitPrice;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use stdClass;

/**
 * What a product normally costs — two different answers, both needed.
 *
 * **A price per kilo**, when the sources described the pack well enough to work
 * one out. That is the figure to multiply an amount by: 300 g of butter.
 *
 * **A price per pack**, which is what most readings actually support. Barely 3%
 * of leaflet entries state a size — verified as a limit of the data rather than
 * a parser fault — so insisting on a unit price would throw away almost
 * everything the leaflets know and leave the estimate answering for staples
 * only. Most shopping-list lines have no amount either ("masło", not "300 g
 * masła"), and for those a typical pack price is not a worse answer than a unit
 * price: it is the *right* one.
 *
 * Neither is invented. A product with no readings has no price, in either sense,
 * and the estimate says so rather than guessing.
 *
 * **A median, never a mean**, and that is the one decision in here worth
 * defending. The readings come from sources that disagree by design: a national
 * average alongside thirteen chains' idea of a regular price, some of them for
 * premium brands. One 90 zł/kg parmesan among ten ordinary cheeses moves a mean
 * enough to be noticed on a bill and moves a median not at all. The estimate's
 * job is to be roughly right about a whole trolley, so it must not be dragged by
 * whichever source happened to mention an expensive thing.
 *
 * **Prices are kept per dimension, not per product.** A price per kilo and a
 * price per piece are different numbers about the same butter, and averaging
 * them together produces one belonging to neither. Which of them answers a given
 * line is decided when the line is priced, because only then is it known what
 * the line asked for.
 *
 * Loaded whole, in one query, and held for the request — the same reason
 * `MeasureBook` is a singleton. One shopping list asks it once per line, and the
 * plan asks it again for the same products a moment later.
 */
final class PriceBook
{
    /**
     * @var array<int, array<string, UnitPrice>>|null Unit price by dimension, keyed by ingredient id.
     */
    private ?array $unitPrices = null;

    /**
     * @var array<int, Money>|null Typical price of one pack, keyed by ingredient id.
     */
    private ?array $packPrices = null;

    /**
     * What one kilo / litre / piece of this product costs, in the dimension asked
     * for. Null when nothing recent enough says.
     *
     * Null is the important half. A product nobody has priced must produce
     * silence rather than a plausible figure: this feeds a number somebody reads
     * as "mniej więcej tyle zapłacę", and one invented line in it is worse than
     * an estimate that admits it is incomplete.
     */
    public function unitPriceFor(int $ingredientId, UnitDimension $per): ?UnitPrice
    {
        return $this->all()[$ingredientId][$per->value] ?? null;
    }

    /**
     * What one pack of this normally costs, whatever size a pack turns out to be.
     *
     * Deliberately vaguer than a unit price and deliberately available far more
     * often. A list line reading "masło" is asking a question of exactly this
     * shape — how much is butter — and answering it with silence because no
     * leaflet printed a gramaturę would make the estimate useless on most of what
     * a household buys.
     */
    public function packPriceFor(int $ingredientId): ?Money
    {
        return $this->packs()[$ingredientId] ?? null;
    }

    /**
     * Every dimension this product has a unit price in. They are different
     * questions, not ranked answers.
     *
     * @return array<string, UnitPrice>
     */
    public function dimensionsFor(int $ingredientId): array
    {
        return $this->all()[$ingredientId] ?? [];
    }

    /**
     * Every product this book can put any number on.
     *
     * The one definition of "priced": fresh enough, matched to a product, and
     * with at least a typical pack price. The coverage report asks this rather
     * than counting rows itself, so a report cannot claim coverage the estimate
     * would not actually deliver.
     *
     * @return list<int>
     */
    public function ingredientIds(): array
    {
        return array_keys($this->packs());
    }

    public function isEmpty(): bool
    {
        return $this->packs() === [];
    }

    /**
     * @return array<int, array<string, UnitPrice>>
     */
    private function all(): array
    {
        if ($this->unitPrices !== null) {
            return $this->unitPrices;
        }

        $prices = [];

        foreach ($this->medians() as $ingredientId => $byDimension) {
            foreach ($byDimension as $dimension => $grosze) {
                $prices[$ingredientId][$dimension] = new UnitPrice(
                    new Money($grosze),
                    UnitDimension::from($dimension),
                );
            }
        }

        return $this->unitPrices = $prices;
    }

    /**
     * @return array<int, Money>
     */
    private function packs(): array
    {
        if ($this->packPrices !== null) {
            return $this->packPrices;
        }

        $grouped = [];

        foreach ($this->rows(['ingredient_id', 'price_minor']) as $row) {
            $grouped[(int) $row->ingredient_id][] = (int) $row->price_minor;
        }

        $packs = [];

        foreach ($grouped as $ingredientId => $figures) {
            $packs[$ingredientId] = new Money(Median::of($figures));
        }

        return $this->packPrices = $packs;
    }

    /**
     * The median unit price for every (product, dimension) with recent readings.
     *
     * Done in PHP over the raw figures rather than in SQL: SQLite has no median,
     * and the alternatives are a window function per group or an average — the
     * first is harder to read than this loop, the second is the thing this class
     * exists not to do.
     *
     * @return array<int, array<string, int>>
     */
    private function medians(): array
    {
        $grouped = [];

        foreach ($this->rows(['ingredient_id', 'unit_price_per', 'unit_price_minor'], comparable: true) as $row) {
            $grouped[(int) $row->ingredient_id][(string) $row->unit_price_per][] = (int) $row->unit_price_minor;
        }

        $medians = [];

        foreach ($grouped as $ingredientId => $byDimension) {
            foreach ($byDimension as $dimension => $figures) {
                $medians[$ingredientId][$dimension] = Median::of($figures);
            }
        }

        return $medians;
    }

    /**
     * @param  list<string>  $columns
     * @return Collection<int, stdClass>
     */
    private function rows(array $columns, bool $comparable = false): Collection
    {
        return DB::table('price_observations')
            ->select($columns)
            ->whereNotNull('ingredient_id')
            ->when($comparable, static fn ($query) => $query
                ->whereNotNull('unit_price_minor')
                ->whereNotNull('unit_price_per'))
            ->whereDate('observed_on', '>=', now()->subDays($this->maxAgeDays())->toDateString())
            ->get();
    }

    /**
     * How old a reading may be and still be quoted.
     *
     * Well over a year by default, and deliberately so: the broadest source
     * publishes once a year, some months after the year ends, so a window of a
     * few months would throw away the only figures that exist for most staples
     * and leave the estimate blank. The cost of the long window is stated on the
     * screen — this is "mniej więcej", never a price.
     */
    private function maxAgeDays(): int
    {
        return max(1, (int) config('pricing.max_age_days', 500));
    }
}
