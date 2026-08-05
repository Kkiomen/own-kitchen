<?php

declare(strict_types=1);

namespace App\Pricing\Sources\Gus;

use App\Pricing\Contracts\PriceSource;
use App\Pricing\Drafts\PriceDraft;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;

/**
 * Average retail prices as Statistics Poland publishes them.
 *
 * This is the impartial half of what the app knows about prices, and it is here
 * because the other half cannot do this job alone: a leaflet only ever states
 * what is *cheap this week*, so an estimate built from leaflets alone is
 * systematically low, and lowest exactly on the things the household buys most.
 *
 * Two things about the data have to be understood before trusting a figure:
 *
 * - **It is an annual average, not today's price.** The office's monthly series
 *   stopped being published in 2019; only the yearly one is current. So a figure
 *   is stamped with the last day of the year it describes and is quoted as "mniej
 *   więcej", never as a price. `pricing.max_age_days` is set well past a year for
 *   this reason — a stricter window would throw away the only broad source there
 *   is and leave the estimate empty.
 * - **It covers staples, not a catalogue.** Roughly ninety labels: flour, milk,
 *   butter, eggs, oil, sugar, rice, pasta, cuts of meat, cheese. Fresh fruit and
 *   vegetables are in the discontinued monthly series and are simply absent, which
 *   is why leaflets remain the second source rather than a nicety.
 *
 * The API is open and needs no key, so there is no crawl policy to honour beyond
 * its rate limit — which a batched read of two requests does not come near.
 */
final class GusBdlPriceSource implements PriceSource
{
    /**
     * "Żywność i napoje bezalkoholowe" under average retail prices.
     *
     * Its sibling subjects are alcohol, tobacco, clothing and housing — none of
     * which a shopping list is priced from, and the tobacco one would put
     * cigarettes into a food catalogue if it were matched against products.
     */
    private const string FOOD_SUBJECT = 'P1466';

    /**
     * How many years back to ask for.
     *
     * The office publishes a year some months after it ends, so asking only about
     * the current one returns nothing at all for the first part of every year.
     * Three covers that gap without dragging two decades of history over the wire.
     */
    private const int YEARS = 3;

    public function __construct(
        private readonly GusBdlClient $client,
        private readonly GusVariableName $names,
    ) {}

    public function name(): string
    {
        return 'gus';
    }

    public function label(): string
    {
        return 'GUS — przeciętne ceny detaliczne';
    }

    /**
     * @return iterable<PriceDraft>
     */
    public function fetch(): iterable
    {
        $variables = $this->client->variables(self::FOOD_SUBJECT);

        if ($variables === []) {
            return;
        }

        $values = $this->client->nationalValues(
            array_map(static fn (array $variable): int => $variable['id'], $variables),
            $this->years(),
        );

        foreach ($variables as $variable) {
            $published = $values[$variable['id']] ?? [];

            if ($published === []) {
                // A label the office still lists but no longer publishes. Common:
                // roughly half of them are retired series kept for their history.
                continue;
            }

            $year = max(array_keys($published));
            $parsed = $this->names->parse($variable['name']);

            yield new PriceDraft(
                externalId: (string) $variable['id'],
                // The label as published, not the product read out of it. A wrong
                // match is diagnosed from what the source said.
                title: $variable['name'],
                price: Money::fromZloty($published[$year]),
                packQuantity: $parsed->pack?->amount,
                packUnitCode: $parsed->pack?->unitCode,
                /*
                 * The last day of the year the figure describes, not the day it
                 * was read. Stamping it today would make a two-year-old average
                 * look like this morning's price, and re-running the command would
                 * refresh that lie every time.
                 */
                observedOn: CarbonImmutable::create($year, 12, 31)->startOfDay(),
            );
        }
    }

    /**
     * @return list<int>
     */
    private function years(): array
    {
        $current = (int) CarbonImmutable::now()->year;

        return array_map(
            static fn (int $back): int => $current - $back,
            range(0, self::YEARS - 1),
        );
    }
}
