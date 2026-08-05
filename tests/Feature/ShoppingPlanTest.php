<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UnitDimension;
use App\Models\Ingredient;
use App\Models\Promotion;
use App\Models\Shop;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Models\User;
use App\Shopping\Planning\Contracts\PlanStrategy;
use App\Shopping\Planning\ShoppingPlan;
use App\Shopping\Planning\ShoppingPlanner;
use App\Shopping\Planning\Strategies\CheapestOverall;
use App\Shopping\Planning\Strategies\FewestStops;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\ShopSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ShoppingPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);
        $this->seed(ShopSeeder::class);

        $this->user = User::factory()->create();
    }

    /**
     * The point of the whole feature: 12,99 zł is a bigger number than 5,99 zł
     * and the better deal, because one is half a kilo and the other is 200 g.
     */
    public function test_it_ranks_by_price_per_kilo_rather_than_by_shelf_price(): void
    {
        $this->want('Masło');
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200);
        $this->offer('lidl', 'Masło', price: 1299, packGrams: 500);

        $stops = $this->plan()->stops;

        $this->assertCount(1, $stops);
        $this->assertSame('Lidl', $stops[0]->shop->name);
    }

    public function test_a_product_on_offer_nowhere_is_listed_separately(): void
    {
        $this->want('Masło');
        $this->want('Papryka');
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200);

        $plan = $this->plan();

        $this->assertCount(1, $plan->withoutPromotion);
        $this->assertSame('Papryka', $plan->withoutPromotion[0]->ingredient->name);
    }

    /**
     * Half a kilo of butter is three 200 g packs, not one, and the plan's total
     * has to say so or it is a number nobody can shop to.
     */
    public function test_it_works_out_how_many_packs_the_amount_needs(): void
    {
        $this->want('Masło', 500, 'g');
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200);

        $buy = $this->plan()->stops[0]->buys[0];

        $this->assertSame(3, $buy->packs);
        $this->assertSame(1797, $buy->cost()->grosze);
    }

    /**
     * An amount the list never stated, or a pack whose size the leaflet never
     * printed, must fall back to one — never to a multiplication by a number
     * nobody knows.
     */
    public function test_an_unknown_amount_buys_one_pack(): void
    {
        $this->want('Masło');
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200);

        $this->assertSame(1, $this->plan()->stops[0]->buys[0]->packs);
    }

    public function test_an_offer_that_has_run_out_is_ignored(): void
    {
        $this->want('Masło');
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200, validTo: now()->subDay());

        $plan = $this->plan();

        $this->assertSame([], $plan->stops);
        $this->assertCount(1, $plan->withoutPromotion);
    }

    /**
     * The listing states a duration for most entries and stays quiet on the rest.
     * Dropping the quiet ones would hide real promotions.
     */
    public function test_an_offer_with_no_stated_end_still_counts(): void
    {
        $this->want('Masło');
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200, validTo: null);

        $this->assertCount(1, $this->plan()->stops);
    }

    public function test_the_runners_up_from_other_shops_are_carried_to_the_screen(): void
    {
        $this->want('Masło');
        $this->offer('lidl', 'Masło', price: 1299, packGrams: 500);
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200);

        $alternatives = $this->plan()->stops[0]->buys[0]->alternatives;

        $this->assertCount(1, $alternatives);
        $this->assertSame('Biedronka', $alternatives[0]->shop->name);
    }

    /**
     * Two entries for butter in the same leaflet are a choice at the shelf, not a
     * reason to drive somewhere else.
     */
    public function test_one_alternative_per_shop(): void
    {
        $this->want('Masło');
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200);
        $this->offer('lidl', 'Masło', price: 1299, packGrams: 500);
        $this->offer('lidl', 'Masło', price: 1499, packGrams: 500);

        $this->assertCount(1, $this->plan()->stops[0]->buys[0]->alternatives);
    }

    /**
     * An offer we cannot price per kilo is not a bad deal — it is one we cannot
     * show to be a good one, and a plan telling someone to drive has to be able
     * to defend itself.
     */
    public function test_an_offer_with_no_pack_size_ranks_behind_one_we_can_compare(): void
    {
        $this->want('Masło');
        $this->offer('biedronka', 'Masło', price: 499, packGrams: null);
        $this->offer('lidl', 'Masło', price: 1299, packGrams: 500);

        $this->assertSame('Lidl', $this->plan()->stops[0]->shop->name);
    }

    public function test_two_stops_is_two_stops(): void
    {
        $this->want('Masło');
        $this->want('Cukier');
        $this->want('Papryka');
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200);
        $this->offer('biedronka', 'Cukier', price: 299, packGrams: 1000);
        $this->offer('lidl', 'Papryka', price: 199, packGrams: null);
        $this->offer('aldi', 'Masło', price: 549, packGrams: 200);

        $plan = $this->plan(new FewestStops(2));

        $this->assertLessThanOrEqual(2, count($plan->stops));
        $this->assertSame(3, $plan->promotedCount());
    }

    /**
     * When the cap cannot cover everything, the products it misses are ordinary
     * shopping again — not silently dropped off the list.
     */
    public function test_a_product_only_sold_at_a_shop_we_skip_falls_back_to_no_promotion(): void
    {
        $this->want('Masło');
        $this->want('Cukier');
        $this->want('Papryka');
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200);
        $this->offer('biedronka', 'Cukier', price: 299, packGrams: 1000);
        $this->offer('aldi', 'Papryka', price: 199, packGrams: null);

        $plan = $this->plan(new FewestStops(1));

        $this->assertCount(1, $plan->stops);
        $this->assertCount(1, $plan->withoutPromotion);
        $this->assertSame('Papryka', $plan->withoutPromotion[0]->ingredient->name);
    }

    public function test_the_total_adds_up_what_the_stops_cost(): void
    {
        $this->want('Masło');
        $this->want('Cukier');
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200, regular: 799);
        $this->offer('biedronka', 'Cukier', price: 299, packGrams: 1000);

        $plan = $this->plan();

        $this->assertSame(898, $plan->total()->grosze);
        $this->assertSame(200, $plan->savings()?->grosze);
    }

    /**
     * Zero would claim we checked and found no saving. Nothing printed a "before"
     * price here, which is a different statement.
     */
    public function test_a_saving_nobody_stated_is_unknown_rather_than_zero(): void
    {
        $this->want('Masło');
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200);

        $this->assertNull($this->plan()->savings());
    }

    public function test_the_screen_renders_the_plan(): void
    {
        $this->want('Masło');
        $this->offer('biedronka', 'Masło', price: 599, packGrams: 200);

        $this->actingAs($this->user)
            ->get(route('shopping.plan'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->component('Shopping/Plan')
                ->where('promotedCount', 1)
                ->where('total', 599)
                ->where('stops.0.name', 'Biedronka')
            );
    }

    public function test_the_screen_tells_no_leaflets_apart_from_no_offers(): void
    {
        $this->want('Masło');

        $this->actingAs($this->user)
            ->get(route('shopping.plan'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('hasPromotions', false));
    }

    public function test_the_plan_is_private_to_the_account(): void
    {
        $this->get(route('shopping.plan'))->assertRedirect(route('login'));
    }

    private function plan(?object $strategy = null): ShoppingPlan
    {
        /** @var PlanStrategy $chosen */
        $chosen = $strategy ?? new CheapestOverall;

        return $this->app->make(ShoppingPlanner::class)->plan(ShoppingList::defaultFor($this->user), $chosen);
    }

    private function want(string $product, ?float $quantity = null, ?string $unit = null): ShoppingListItem
    {
        return ShoppingListItem::query()->create([
            'shopping_list_id' => ShoppingList::defaultFor($this->user)->id,
            'ingredient_id' => $this->ingredient($product)->id,
            'quantity' => $quantity,
            'unit_id' => $unit === null ? null : Unit::query()->where('code', $unit)->firstOrFail()->id,
        ]);
    }

    private function offer(
        string $shop,
        string $product,
        int $price,
        ?int $packGrams,
        ?int $regular = null,
        mixed $validTo = 'default',
    ): Promotion {
        static $sequence = 0;
        $sequence++;

        $grams = Unit::query()->where('code', 'g')->firstOrFail();

        return Promotion::query()->create([
            'shop_id' => Shop::query()->where('slug', $shop)->firstOrFail()->id,
            'source' => 'test',
            'external_id' => 'offer-'.$sequence,
            'title' => $product.($packGrams === null ? '' : " {$packGrams} g"),
            'ingredient_id' => $this->ingredient($product)->id,
            'needs_review' => false,
            'price_minor' => $price,
            'regular_price_minor' => $regular,
            'pack_quantity' => $packGrams,
            'pack_unit_id' => $packGrams === null ? null : $grams->id,
            'unit_price_minor' => $packGrams === null ? null : (int) round($price * 1000 / $packGrams),
            'unit_price_per' => $packGrams === null ? null : UnitDimension::Mass,
            'valid_to' => $validTo === 'default' ? now()->addDays(3) : $validTo,
            'url' => 'https://www.gazetki.pl/oferty/test',
        ]);
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::query()->where('name', $name)->firstOrFail();
    }
}
