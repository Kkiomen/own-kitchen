<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Models\Ingredient;
use App\Models\PriceObservation;
use App\Models\Promotion;
use App\Models\Shop;
use App\Models\Unit;
use App\Pricing\Contracts\PriceSource;
use App\Pricing\Drafts\PriceDraft;
use App\Pricing\ImportPrices;
use App\Pricing\PriceSourceRegistry;
use App\Pricing\Sources\Gus\GusBdlPriceSource;
use App\Pricing\Sources\Gus\GusVariableName;
use App\Pricing\Sources\Leaflets\LeafletPriceSource;
use App\Support\Money\Money;
use Carbon\CarbonImmutable;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\ShopSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class PriceImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);
        $this->seed(ShopSeeder::class);
    }

    /**
     * The office states the pack inside the label. Without reading it out, every
     * figure is a price for an unknown amount of something.
     */
    public function test_it_reads_the_pack_out_of_a_statistical_label(): void
    {
        $names = $this->app->make(GusVariableName::class);

        foreach ([
            'mąka pszenna - za 1kg' => ['mąka pszenna', 1.0, 'kg'],
            'masło świeże o zawartości tłuszczu ok. 82,5% - za 200g' => [
                'masło świeże o zawartości tłuszczu ok. 82,5%', 200.0, 'g',
            ],
            'kasza gryczana, prażona - za 0,5kg' => ['kasza gryczana, prażona', 0.5, 'kg'],
            // The office's own disambiguation marker for a redefined series.
            'miód pszczeli (1) - za 400g' => ['miód pszczeli', 400.0, 'g'],
        ] as $label => [$product, $amount, $unit]) {
            $parsed = $names->parse($label);

            $this->assertSame($product, $parsed->product, $label);
            $this->assertSame($amount, $parsed->pack?->amount, $label);
            $this->assertSame($unit, $parsed->pack?->unitCode, $label);
        }
    }

    /**
     * The API paginates in tens whatever you ask it for, and says so only in
     * `totalRecords`. Reading the first page and stopping looked exactly like a
     * source that publishes a fifth of what it does — a successful request, well
     * formed JSON, and most of the catalogue silently missing.
     */
    public function test_it_follows_the_pagination_rather_than_trusting_one_page(): void
    {
        Http::fake([
            '*variables*page=0*' => Http::response($this->variablesPage(0, [
                ['id' => 1, 'n1' => 'mąka pszenna - za 1kg'],
            ], total: 2)),
            '*variables*page=1*' => Http::response($this->variablesPage(1, [
                ['id' => 2, 'n1' => 'cukier biały kryształ - za 1kg'],
            ], total: 2)),
            '*by-unit*' => Http::response([
                'totalRecords' => 2,
                'results' => [
                    ['id' => 1, 'values' => [['year' => '2025', 'val' => 3.76]]],
                    ['id' => 2, 'values' => [['year' => '2025', 'val' => 4.10]]],
                ],
            ]),
        ]);

        $summary = $this->app->make(ImportPrices::class)->run($this->gus());

        $this->assertSame(2, $summary->stored);
        $this->assertSame(2, $summary->matched);
        $this->assertSame(376, $this->observationFor('Mąka pszenna')?->price_minor);
    }

    /**
     * A year the office has not published yet comes back as a null value rather
     * than an absent one. Reading it as zero would put a free product on the
     * estimate.
     */
    public function test_a_year_with_no_figure_is_not_a_price_of_zero(): void
    {
        Http::fake([
            '*variables*' => Http::response($this->variablesPage(0, [
                ['id' => 1, 'n1' => 'mąka pszenna - za 1kg'],
            ])),
            '*by-unit*' => Http::response([
                'totalRecords' => 1,
                'results' => [[
                    'id' => 1,
                    'values' => [
                        ['year' => '2026', 'val' => null],
                        ['year' => '2025', 'val' => 3.76],
                    ],
                ]],
            ]),
        ]);

        $this->app->make(ImportPrices::class)->run($this->gus());

        $this->assertSame(376, $this->observationFor('Mąka pszenna')?->price_minor);
    }

    /**
     * The figure describes a year, so it is stamped with that year's last day
     * rather than the day it was read. Stamping it today would make a two-year-old
     * average look like this morning's price, and every re-run would refresh
     * the lie.
     */
    public function test_a_yearly_figure_is_dated_by_its_year_not_by_today(): void
    {
        Http::fake([
            '*variables*' => Http::response($this->variablesPage(0, [
                ['id' => 1, 'n1' => 'mąka pszenna - za 1kg'],
            ])),
            '*by-unit*' => Http::response([
                'totalRecords' => 1,
                'results' => [['id' => 1, 'values' => [['year' => '2025', 'val' => 3.76]]]],
            ]),
        ]);

        $this->app->make(ImportPrices::class)->run($this->gus());

        $this->assertSame(
            '2025-12-31',
            $this->observationFor('Mąka pszenna')?->observed_on->toDateString(),
        );
    }

    /**
     * Re-running a source on the same day corrects that day's reading. It used to
     * throw a constraint violation instead: the column is a date, but Eloquent
     * writes it through the datetime format, so the lookup never matched what was
     * stored and every re-run lost the whole refresh.
     */
    public function test_running_the_same_source_twice_in_a_day_corrects_rather_than_duplicates(): void
    {
        Http::fake([
            '*variables*' => Http::response($this->variablesPage(0, [
                ['id' => 1, 'n1' => 'mąka pszenna - za 1kg'],
            ])),
            '*by-unit*' => Http::response([
                'totalRecords' => 1,
                'results' => [['id' => 1, 'values' => [['year' => '2025', 'val' => 3.76]]]],
            ]),
        ]);

        $import = $this->app->make(ImportPrices::class);
        $import->run($this->gus());
        $second = $import->run($this->gus());

        $this->assertSame(0, $second->failed);
        $this->assertSame(1, PriceObservation::query()->count());
    }

    /**
     * A leaflet states two figures and only one of them is evidence of a normal
     * price. Averaging the promotional one would say a household pays sale prices
     * for everything all year — wrong, and wrong in the direction that quietly
     * under-promises the bill.
     */
    public function test_only_a_leaflets_regular_price_becomes_an_observation(): void
    {
        $this->promotion('Masło', price: 599, regular: 899);
        $this->promotion('Cukier', price: 299, regular: null);

        $summary = $this->app->make(ImportPrices::class)->run(new LeafletPriceSource);

        $this->assertSame(1, $summary->stored);
        $this->assertSame(899, $this->observationFor('Masło')?->price_minor);
        $this->assertNull($this->observationFor('Cukier'));
    }

    /**
     * The promotion was matched by a pipeline with a noise list and a negation
     * rule this one does not have. Matching the title a second time could
     * disagree with it — pricing butter from an offer the plan files elsewhere.
     */
    public function test_a_leaflet_reading_keeps_the_product_the_offer_was_matched_to(): void
    {
        $offer = $this->promotion('Masło', price: 599, regular: 899);

        $this->app->make(ImportPrices::class)->run(new LeafletPriceSource);

        $this->assertSame(
            $offer->ingredient_id,
            PriceObservation::query()->firstOrFail()->ingredient_id,
        );
    }

    /**
     * Nothing is dropped and nothing is invented: a reading nobody can place is
     * kept with its title and flagged, so improving the dictionary is a re-run
     * rather than a re-fetch.
     */
    public function test_a_reading_it_cannot_place_is_kept_and_flagged(): void
    {
        Http::fake([
            '*variables*' => Http::response($this->variablesPage(0, [
                ['id' => 1, 'n1' => 'usługi gastronomiczne - za 1 posiłek'],
            ])),
            '*by-unit*' => Http::response([
                'totalRecords' => 1,
                'results' => [['id' => 1, 'values' => [['year' => '2025', 'val' => 21.5]]]],
            ]),
        ]);

        $summary = $this->app->make(ImportPrices::class)->run($this->gus());

        $observation = PriceObservation::query()->firstOrFail();

        $this->assertSame(1, $summary->stored);
        $this->assertSame(0, $summary->matched);
        $this->assertNull($observation->ingredient_id);
        $this->assertTrue($observation->needs_review);
        $this->assertSame('usługi gastronomiczne - za 1 posiłek', $observation->title);
    }

    /**
     * A national average belongs to no shop. Filing it under one would let a plan
     * narrowed to that chain pick it up as if the chain had quoted it.
     */
    public function test_a_national_average_belongs_to_no_shop(): void
    {
        $this->app->make(ImportPrices::class)->run($this->fixedSource([
            new PriceDraft(
                externalId: '1',
                title: 'mąka pszenna - za 1kg',
                price: new Money(376),
                packQuantity: 1.0,
                packUnitCode: 'kg',
                observedOn: CarbonImmutable::parse('2025-12-31'),
            ),
        ]));

        $this->assertNull(PriceObservation::query()->firstOrFail()->shop_id);
    }

    private function gus(): GusBdlPriceSource
    {
        return $this->app->make(PriceSourceRegistry::class)->get('gus');
    }

    /**
     * @param  list<PriceDraft>  $drafts
     */
    private function fixedSource(array $drafts): PriceSource
    {
        return new class($drafts) implements PriceSource
        {
            /**
             * @param  list<PriceDraft>  $drafts
             */
            public function __construct(private readonly array $drafts) {}

            public function name(): string
            {
                return 'test';
            }

            public function label(): string
            {
                return 'Test';
            }

            public function fetch(): iterable
            {
                return $this->drafts;
            }
        };
    }

    /**
     * @param  list<array{id: int, n1: string}>  $results
     * @return array<string, mixed>
     */
    private function variablesPage(int $page, array $results, ?int $total = null): array
    {
        return [
            'totalRecords' => $total ?? count($results),
            'page' => $page,
            'results' => $results,
        ];
    }

    private function promotion(string $product, int $price, ?int $regular): Promotion
    {
        static $sequence = 0;
        $sequence++;

        return Promotion::query()->create([
            'shop_id' => Shop::query()->where('slug', 'lidl')->firstOrFail()->id,
            'source' => 'gazetki.pl',
            'external_id' => 'offer-'.$sequence,
            'title' => $product.' 200 g',
            'ingredient_id' => Ingredient::query()->where('name', $product)->firstOrFail()->id,
            'needs_review' => false,
            'price_minor' => $price,
            'regular_price_minor' => $regular,
            'pack_quantity' => 200,
            'pack_unit_id' => Unit::query()->where('code', 'g')->firstOrFail()->id,
            'valid_to' => now()->addDays(3),
            'url' => 'https://www.gazetki.pl/oferty/test',
        ]);
    }

    private function observationFor(string $product): ?PriceObservation
    {
        $ingredient = Ingredient::query()->where('name', $product)->first();

        return $ingredient === null
            ? null
            : PriceObservation::query()->where('ingredient_id', $ingredient->id)->first();
    }
}
