<?php

declare(strict_types=1);

namespace App\Planning;

use App\Enums\MealSlot;
use App\Models\MealPlanEntry;
use App\Models\Shop;
use App\Models\User;
use App\Support\Money\Money;
use Illuminate\Support\Facades\DB;

/**
 * "Jadę do jednego z tych sklepów — który da najtańszy tydzień?"
 *
 * The household drives to **one** shop for the week's shopping and does not
 * mind which. So the question is not "the cheapest butter anywhere" — that is a
 * plan for four trips — but "which shop's leaflet feeds the whole week for
 * least". And that cannot be answered by comparing leaflets: Aldi's cheap
 * chicken is worth nothing to a week that would not have eaten chicken. It is
 * answered by planning the week **for each shop** and pricing each plan at that
 * shop's till.
 *
 * **And then every week is priced in every shop**, because the first live run
 * showed that the dishes matter more than the leaflet: the week planned around
 * Biedronka's offers came to 173 zł there and 145 zł in Aldi. Each trial week
 * is a different draw, so the answer is the cheapest (week, shop) pair — "this
 * week, bought in Aldi" — not the cheapest shop's own week.
 *
 * Every candidate week is written inside a transaction that is then rolled
 * back, so the planner runs exactly as it does for real — the same rules, the
 * same sides, the same "never overwrite" — and is measured by `WeekSummary`
 * reading back what it wrote, as every other week report is. The winner's rows
 * are kept and written for real. Re-running the planner for the winner instead
 * would hand back a *different* week (it shuffles on purpose), priced at a
 * number nobody measured.
 */
final readonly class CheapestShopPlan
{
    /**
     * Past this many shops the wait stops being worth it — each one is a whole
     * week planned and thrown away.
     */
    public const int MAX_SHOPS = 4;

    public function __construct(
        private PlanGenerator $generator,
        private LeafletShops $leaflets,
        private WeekSummary $summary,
        private SideDishes $sides,
    ) {}

    /**
     * @param  array<string, list<MealSlot>>  $wanted
     * @param  list<int>  $shopIds
     * @return array{result: array{added: int, skipped: int, empty: list<string>, overspent: int}, offers: ShopOffers, week: PlannedWeek, compared: list<array{name: string, buys: int, onOffer: int, unpriced: int, chosen: bool}>}
     */
    public function fill(User $user, array $wanted, int $servings, ?PlanTargets $targets, array $shopIds): array
    {
        $shops = Shop::query()->whereIn('id', $shopIds)->get();
        $dates = array_map(strval(...), array_keys($wanted));

        if ($shops->count() === 1) {
            $offers = $this->leaflets->offersAt($shops->first());
            $result = $this->generator->fill($user, $wanted, $servings, $targets, $offers);
            $week = $this->summary->of($user, $dates, $targets, $offers);

            return ['result' => $result, 'offers' => $offers, 'week' => $week, 'compared' => []];
        }

        /*
         * Warmed outside the trials. The cache lives in the database here, so
         * an admission computed inside a rolled-back trial would be rolled back
         * with it and every shop would pay the seconds again.
         */
        $this->sides->of(SideDishes::STARCH);

        $everyShop = array_values($shops->map(fn (Shop $shop): ShopOffers => $this->leaflets->offersAt($shop))->all());
        $trials = [];

        foreach ($everyShop as $offers) {
            $trials[] = $this->trial($user, $wanted, $servings, $targets, $dates, $offers, $everyShop);
        }

        [$bestTrial, $bestShop] = self::cheapest($trials);
        $best = $trials[$bestTrial];

        DB::transaction(static function () use ($best): void {
            foreach ($best['rows'] as $row) {
                MealPlanEntry::query()->create($row);
            }
        });

        $offers = $everyShop[$bestShop];

        return [
            'result' => $best['result'],
            'offers' => $offers,
            'week' => $this->summary->of($user, $dates, $targets, $offers),
            'compared' => $this->compared($best, $everyShop, $bestShop),
        ];
    }

    /**
     * The trial and the shop of the cheapest pair.
     *
     * @param  list<array{weeks: list<PlannedWeek>}>  $trials
     * @return array{int, int}
     */
    private static function cheapest(array $trials): array
    {
        $best = [0, 0];
        $lowest = INF;

        foreach ($trials as $t => $trial) {
            foreach ($trial['weeks'] as $s => $week) {
                $fair = self::fairBuys($week->price);

                if ($fair < $lowest) {
                    $lowest = $fair;
                    $best = [$t, $s];
                }
            }
        }

        return $best;
    }

    /**
     * The week that was kept, as it would cost in each shop — cheapest first.
     * One week across the shops is the comparison somebody can act on: "the
     * same shopping costs 20 zł more in Lidl".
     *
     * @param  array{weeks: list<PlannedWeek>}  $best
     * @param  list<ShopOffers>  $everyShop
     * @return list<array{name: string, buys: int, onOffer: int, unpriced: int, chosen: bool}>
     */
    private function compared(array $best, array $everyShop, int $bestShop): array
    {
        $rows = [];

        foreach ($best['weeks'] as $s => $week) {
            $rows[] = [
                'name' => $everyShop[$s]->shopName,
                'buys' => $week->price->buys->grosze,
                'onOffer' => $week->price->onOffer,
                'unpriced' => $week->price->unpricedProducts,
                'chosen' => $s === $bestShop,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => [! $a['chosen'], $a['buys']] <=> [! $b['chosen'], $b['buys']]);

        return $rows;
    }

    /**
     * One shop's week, planned, priced in every shop and taken back.
     *
     * @param  array<string, list<MealSlot>>  $wanted
     * @param  list<string>  $dates
     * @param  list<ShopOffers>  $everyShop
     * @return array{result: array{added: int, skipped: int, empty: list<string>, overspent: int}, weeks: list<PlannedWeek>, rows: list<array<string, mixed>>}
     */
    private function trial(User $user, array $wanted, int $servings, ?PlanTargets $targets, array $dates, ShopOffers $offers, array $everyShop): array
    {
        $before = (int) MealPlanEntry::query()->max('id');

        DB::beginTransaction();

        try {
            $result = $this->generator->fill($user, $wanted, $servings, $targets, $offers);
            $weeks = array_map(
                fn (ShopOffers $shop): PlannedWeek => $this->summary->of($user, $dates, $targets, $shop),
                $everyShop,
            );

            $rows = MealPlanEntry::query()
                ->where('user_id', $user->id)
                ->where('id', '>', $before)
                ->orderBy('id')
                ->get()
                ->map(static fn (MealPlanEntry $entry): array => [
                    'user_id' => $entry->user_id,
                    'date' => $entry->date->toDateString(),
                    'slot' => $entry->slot->value,
                    'recipe_id' => $entry->recipe_id,
                    'note' => $entry->note,
                    'servings' => $entry->servings,
                    'position' => $entry->position,
                ])
                ->all();
        } finally {
            DB::rollBack();
        }

        return [
            'result' => $result,
            'weeks' => $weeks,
            'rows' => array_values($rows),
        ];
    }

    /**
     * The shopping total, grossed up for what could not be priced.
     *
     * Two weeks are compared on this rather than on the bare total, because the
     * bare total rewards ignorance: a week whose dear products happen to have no
     * price behind them would come out "cheapest" for exactly that reason. It
     * is a comparison only — the screen quotes the real total.
     */
    private static function fairBuys(WeekPrice $price): float
    {
        return $price->buys->grosze / max($price->confidence(), 0.5);
    }

    /** What the leaflet says the chosen week saves, for the screen. */
    public static function savingsOf(PlannedWeek $week): ?Money
    {
        $savings = $week->price->savings;

        return $savings === null || $savings->grosze === 0 ? null : $savings;
    }
}
