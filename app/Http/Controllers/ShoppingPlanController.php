<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalogue\IngredientEmoji;
use App\Models\Promotion;
use App\Models\Shop;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Shopping\Planning\Contracts\PlanStrategy;
use App\Shopping\Planning\PlannedBuy;
use App\Shopping\Planning\PlannedStop;
use App\Shopping\Planning\PlanStrategies;
use App\Shopping\Planning\ShoppingPlanner;
use App\Shopping\SelectedShops;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Gdzie kupić taniej" — the shopping list read against this week's leaflets.
 *
 * Money crosses to the browser as integer grosze and is formatted in exactly one
 * place on that side, for the same reason quantities are: three screens showing
 * the same price have to spell it identically, and a float would eventually
 * render 11,999999999 on the one screen whose whole job is a number you trust.
 */
class ShoppingPlanController extends Controller
{
    public function __construct(
        private readonly ShoppingPlanner $planner,
        private readonly PlanStrategies $strategies,
        private readonly IngredientEmoji $emoji,
        private readonly SelectedShops $shops,
    ) {}

    public function index(Request $request, ?ShoppingList $shoppingList = null): Response
    {
        // A plan is a trip, and a trip is made from one list. Without one named,
        // it is the main list — the same default every other screen writes to.
        $list = $shoppingList ?? ShoppingList::defaultFor($request->user());

        abort_unless($list->user_id === $request->user()->id, 404);

        $strategy = $this->strategies->get($request->string('plan')->value());
        $chosenShops = $this->shops->ids($request->user());
        $plan = $this->planner->plan($list, $strategy, $chosenShops);

        return Inertia::render('Shopping/Plan', [
            'list' => [
                'id' => $list->id,
                'name' => $list->name,
                'isDefault' => $list->is_default,
            ],
            'stops' => array_map($this->presentStop(...), $plan->stops),
            'withoutPromotion' => array_map($this->presentItem(...), $plan->withoutPromotion),
            'total' => $plan->total()->grosze,
            'savings' => $plan->savings()?->grosze,
            'promotedCount' => $plan->promotedCount(),
            'strategies' => array_map(
                fn (PlanStrategy $option): array => [
                    'key' => $option->key(),
                    'label' => $option->label(),
                    'active' => $option->key() === $strategy->key(),
                ],
                $this->strategies->all(),
            ),

            /*
             * Every chain with the household's choice marked, and the count of
             * offers each currently holds. The count is what makes the choice
             * checkable: ticking a chain whose leaflet we have never read gives a
             * plan that ignores it, and without the number that looks like a bug
             * rather than an empty leaflet.
             */
            'shops' => $this->shops->all($request->user())->map(fn (Shop $shop): array => [
                'id' => $shop->id,
                'slug' => $shop->slug,
                'name' => $shop->name,
                'selected' => (bool) $shop->getAttribute('selected'),
                'offerCount' => (int) $shop->getAttribute('active_promotions_count'),
            ])->all(),

            /*
             * Whether the choice is narrowing anything, so the screen can say why
             * a chain is missing from the plan. Not chosen and every-chain-ticked
             * look the same in the plan and are different statements.
             */
            'shopsNarrowed' => $chosenShops !== null,

            /*
             * Lets the screen tell the two empty states apart. "Nothing on your
             * list is on offer" and "no leaflets have been read yet" look
             * identical from the data and mean opposite things: one is a fact
             * about this week, the other is a job nobody has run. Deliberately
             * *not* narrowed by the chosen chains — it answers "have we read any
             * leaflets at all", and narrowing it would report a job nobody ran
             * when the truth is a choice that excluded them.
             */
            'hasPromotions' => Promotion::query()->active()->exists(),
            'listCount' => ShoppingListItem::query()
                ->where('shopping_list_id', $list->id)
                ->stillToBuy()
                ->count(),
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function presentStop(PlannedStop $stop): array
    {
        return [
            'slug' => $stop->shop->slug,
            'name' => $stop->shop->name,
            'subtotal' => $stop->subtotal()->grosze,
            'savings' => $stop->savings()?->grosze,
            'buys' => array_map($this->presentBuy(...), $stop->buys),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentBuy(PlannedBuy $buy): array
    {
        return $this->presentItem($buy->item) + [
            'packs' => $buy->packs,
            'cost' => $buy->cost()->grosze,
            'savings' => $buy->savings()?->grosze,
            'offer' => $this->presentOffer($buy->promotion),
            'alternatives' => array_map($this->presentOffer(...), $buy->alternatives),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentItem(ShoppingListItem $item): array
    {
        return [
            'id' => $item->id,
            'name' => $item->ingredient->name,
            'emoji' => $this->emoji->for($item->ingredient->name, $item->ingredient->category),
            'quantity' => $item->quantity,
            'unit' => $item->unit?->symbol,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function presentOffer(Promotion $promotion): array
    {
        $unitPrice = $promotion->unitPrice();

        return [
            'shop' => $promotion->shop->name,
            'title' => $promotion->title,
            'price' => $promotion->price_minor,
            'regularPrice' => $promotion->regular_price_minor,
            'discount' => $promotion->discount_percent,
            'unitPrice' => $unitPrice?->price->grosze,
            'unitLabel' => $unitPrice?->label(),
            'validTo' => $promotion->valid_to?->toDateString(),
            'url' => $promotion->url,
        ];
    }
}
