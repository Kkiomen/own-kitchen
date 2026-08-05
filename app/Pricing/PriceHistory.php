<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Enums\UnitDimension;
use App\Models\Ingredient;
use App\Support\Money\Median;
use Illuminate\Support\Facades\DB;

/**
 * What one product has cost, reading by reading.
 *
 * **Never a promotional price, and that is not a filter applied here.** It is
 * what `price_observations` is: `Sources\Leaflets\LeafletPriceSource` emits a
 * leaflet's "before" figure and skips an offer that prints none, and the other
 * source is a national average. A history built from shelf prices would say
 * butter costs 4,99 zł because that is what it cost for the four days it was on
 * offer — a chart of how good this week's promotions were, dressed up as what
 * the household actually pays. `PriceHistoryTest` pins that guarantee at the
 * source rather than trusting this docblock.
 *
 * **Grouped by day, not plotted over time.** The readings are dense in the
 * shops and sparse in the calendar: 32 readings of coffee across three days is
 * the real shape of this data, because a leaflet crawl reads thirteen chains in
 * one afternoon and the next crawl is a week later. A line drawn through that
 * would invent a trend between two points and would look most confident exactly
 * where it knows least. So a day is one row: what the middle reading was, how
 * far apart the cheapest and dearest were, and how many shops were talking.
 */
final class PriceHistory
{
    /**
     * How many days of readings a screen shows. Well past a year of weekly
     * crawls, and a bound so one product cannot render a thousand rows.
     */
    private const int MAX_DAYS = 40;

    /**
     * One product's readings, newest day first.
     *
     * @return list<array{
     *     date: string,
     *     median: int,
     *     low: int,
     *     high: int,
     *     readings: int,
     *     shops: list<string>,
     *     sources: list<string>,
     *     unitPrice: int|null,
     *     unitPricePer: string|null
     * }>
     */
    public function of(Ingredient $ingredient): array
    {
        $rows = DB::table('price_observations as observation')
            ->leftJoin('shops as shop', 'shop.id', '=', 'observation.shop_id')
            ->where('observation.ingredient_id', $ingredient->id)
            ->orderByDesc('observation.observed_on')
            ->select([
                'observation.observed_on',
                'observation.source',
                'observation.price_minor',
                'observation.unit_price_minor',
                'observation.unit_price_per',
                'shop.name as shop_name',
            ])
            ->get();

        $byDay = [];

        foreach ($rows as $row) {
            // The column is a date, but SQLite hands back whatever was written
            // into it — a timestamp, for rows the importer stamped with one.
            $day = substr((string) $row->observed_on, 0, 10);

            $byDay[$day] ??= ['prices' => [], 'unitPrices' => [], 'shops' => [], 'sources' => [], 'per' => null];
            $byDay[$day]['prices'][] = (int) $row->price_minor;

            if ($row->unit_price_minor !== null && $row->unit_price_per !== null) {
                /*
                 * Kept per dimension, exactly as `PriceBook` does: a price per
                 * kilo and a price per piece are different numbers about the
                 * same butter, and one median over both belongs to neither. The
                 * first dimension a day mentions is the one that day reports.
                 */
                $byDay[$day]['per'] ??= (string) $row->unit_price_per;

                if ($byDay[$day]['per'] === (string) $row->unit_price_per) {
                    $byDay[$day]['unitPrices'][] = (int) $row->unit_price_minor;
                }
            }

            if ($row->shop_name !== null) {
                $byDay[$day]['shops'][(string) $row->shop_name] = true;
            }

            $byDay[$day]['sources'][(string) $row->source] = true;
        }

        $days = [];

        foreach (array_slice($byDay, 0, self::MAX_DAYS, preserve_keys: true) as $date => $day) {
            $days[] = [
                'date' => $date,
                'median' => Median::of($day['prices']),
                'low' => min($day['prices']),
                'high' => max($day['prices']),
                'readings' => count($day['prices']),
                'shops' => array_keys($day['shops']),
                'sources' => array_map($this->sourceLabel(...), array_keys($day['sources'])),
                'unitPrice' => $day['unitPrices'] === [] ? null : Median::of($day['unitPrices']),
                'unitPricePer' => $day['unitPrices'] === [] ? null : $day['per'],
            ];
        }

        return $days;
    }

    /**
     * The products worth opening at all: those something has priced.
     *
     * The other ~1 500 would each open a screen saying "nie wiem", so they are
     * not offered. That is the same rule the quick-pick row follows — a button
     * that matches nothing is a dead button, not an invitation.
     *
     * @return list<array{id: int, slug: string, name: string, category: string, readings: int, latest: string}>
     */
    public function pricedProducts(): array
    {
        $rows = DB::table('price_observations as observation')
            ->join('ingredients as product', 'product.id', '=', 'observation.ingredient_id')
            ->groupBy('product.id', 'product.slug', 'product.name', 'product.category')
            ->orderBy('product.name')
            ->select(['product.id', 'product.slug', 'product.name', 'product.category'])
            ->selectRaw('count(*) as readings, max(observation.observed_on) as latest')
            ->get();

        $products = [];

        foreach ($rows as $row) {
            $products[] = [
                'id' => (int) $row->id,
                'slug' => (string) $row->slug,
                'name' => (string) $row->name,
                'category' => (string) $row->category,
                'readings' => (int) $row->readings,
                'latest' => substr((string) $row->latest, 0, 10),
            ];
        }

        return $products;
    }

    /**
     * How the newest day compares with the oldest one on screen.
     *
     * **Unit prices only, and only within one dimension.** The first version of
     * this compared the days' pack prices and produced "Ser żółty −76%" from
     * real data — because the older reading was 27,12 zł for a *kilo* from the
     * statistical office and the newer one 6,39 zł for a *pack* of unstated
     * size. That is precisely the comparison `UnitPrice` exists to prevent, and
     * a percentage is the most confident-looking way to get it wrong: nobody
     * inspects an arrow.
     *
     * So this needs a per-kilo (or per-litre, or per-piece) figure at both ends,
     * in the same dimension, and answers null otherwise. Null is the common case
     * — barely 3% of leaflet entries state a pack size — and a screen saying
     * nothing about the trend is right far more often than one saying something
     * it cannot support.
     *
     * @param  list<array{date: string, unitPrice: int|null, unitPricePer: string|null}>  $days
     *                                                                                    as returned by `of()`, newest first
     * @return array{from: string, to: string, per: string, difference: int, percent: float}|null
     */
    public function change(array $days): ?array
    {
        $comparable = array_values(array_filter(
            $days,
            static fn (array $day): bool => $day['unitPrice'] !== null && $day['unitPricePer'] !== null,
        ));

        if (count($comparable) < 2) {
            return null;
        }

        $newest = $comparable[0];
        $oldest = null;

        // The most distant day quoted in the same dimension as the newest one.
        // A kilo price now against a piece price in December is two facts, not a
        // change.
        foreach (array_reverse($comparable) as $day) {
            if ($day['unitPricePer'] === $newest['unitPricePer']) {
                $oldest = $day;

                break;
            }
        }

        if ($oldest === null || $oldest === $newest || $oldest['unitPrice'] === 0) {
            return null;
        }

        $difference = $newest['unitPrice'] - $oldest['unitPrice'];

        return [
            'from' => $oldest['date'],
            'to' => $newest['date'],
            'per' => (string) $newest['unitPricePer'],
            'difference' => $difference,
            'percent' => round($difference / $oldest['unitPrice'] * 100, 1),
        ];
    }

    /**
     * What the estimate would currently charge for this product, so the history
     * and the shopping list cannot appear to disagree about the same thing.
     *
     * @return array{pack: int|null, unit: int|null, unitPer: string|null}
     */
    public function typical(Ingredient $ingredient, PriceBook $prices): array
    {
        $unit = null;
        $unitPer = null;

        foreach (UnitDimension::cases() as $dimension) {
            $price = $prices->unitPriceFor($ingredient->id, $dimension);

            if ($price !== null) {
                $unit = $price->price->grosze;
                $unitPer = $dimension->value;

                break;
            }
        }

        return [
            'pack' => $prices->packPriceFor($ingredient->id)?->grosze,
            'unit' => $unit,
            'unitPer' => $unitPer,
        ];
    }

    private function sourceLabel(string $source): string
    {
        return match ($source) {
            'gus' => 'GUS — średnia krajowa',
            'leaflets' => 'Gazetki — ceny regularne',
            default => $source,
        };
    }
}
