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
use App\Shopping\Planning\ShoppingPlan;
use App\Shopping\Planning\ShoppingPlanner;
use App\Shopping\Planning\Strategies\CheapestOverall;
use App\Shopping\SelectedShops;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\ShopSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Do których sklepów jadę" — the choice, and the one rule everything reads it by.
 */
class SelectedShopsTest extends TestCase
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
     * The rule the whole feature hangs on. A fresh account has chosen nothing,
     * and reading that as "no shops" would answer the plan with an empty screen
     * — a feature that switches itself off until it is configured.
     */
    public function test_choosing_nothing_means_every_chain(): void
    {
        $this->want('Masło');
        $this->offer('lidl', 'Masło', price: 599, packGrams: 200);

        $this->assertNull($this->app->make(SelectedShops::class)->ids($this->user));
        $this->assertCount(1, $this->plan()->stops);
    }

    public function test_a_chain_i_do_not_drive_to_is_left_out_of_the_plan(): void
    {
        $this->want('Masło');
        $this->offer('lidl', 'Masło', price: 599, packGrams: 200);

        $this->choose('biedronka');

        $plan = $this->plan();

        $this->assertSame([], $plan->stops);
        $this->assertCount(1, $plan->withoutPromotion);
    }

    /**
     * The point of narrowing: the cheapest offer in the country is not the
     * cheapest offer you can actually buy.
     */
    public function test_the_cheapest_offer_in_a_chain_i_skip_does_not_win(): void
    {
        $this->want('Masło');
        $this->offer('aldi', 'Masło', price: 499, packGrams: 200);
        $this->offer('biedronka', 'Masło', price: 899, packGrams: 200);

        $this->choose('biedronka');

        $this->assertSame('Biedronka', $this->plan()->stops[0]->shop->name);
    }

    public function test_the_screen_saves_the_choice(): void
    {
        $this->actingAs($this->user)
            ->post(route('shopping.shops'), [
                'shops' => [$this->shop('lidl')->id, $this->shop('aldi')->id],
            ])
            ->assertRedirect();

        $this->assertEqualsCanonicalizing(
            [$this->shop('lidl')->id, $this->shop('aldi')->id],
            $this->app->make(SelectedShops::class)->ids($this->user),
        );
    }

    /**
     * Unticking the last chain is a real answer — "I have no shop in mind" — and
     * it has to put the plan back to considering all of them rather than being
     * refused as an empty form.
     */
    public function test_unticking_everything_goes_back_to_all_of_them(): void
    {
        $this->choose('lidl');

        $this->actingAs($this->user)
            ->post(route('shopping.shops'), ['shops' => []])
            ->assertRedirect();

        $this->assertNull($this->app->make(SelectedShops::class)->ids($this->user->refresh()));
    }

    /**
     * The screen has to be able to say why a chain is missing from the plan, so
     * it needs the choice marked and something to check it against.
     */
    public function test_the_plan_screen_lists_every_chain_with_the_choice_marked(): void
    {
        $this->want('Masło');
        $this->offer('lidl', 'Masło', price: 599, packGrams: 200);

        $this->choose('lidl');

        $this->actingAs($this->user)
            ->get(route('shopping.plan'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shopsNarrowed', true)
                ->has('shops', Shop::query()->count())
                ->where('shops.0.selected', false));
    }

    /**
     * Nothing chosen is not the same statement as every chain ticked, but the
     * plan behaves identically for both — so the screen shows every chip lit
     * rather than a row of empty boxes that misdescribes what will happen.
     */
    public function test_an_unchosen_account_sees_every_chain_marked(): void
    {
        $this->want('Masło');

        $this->actingAs($this->user)
            ->get(route('shopping.plan'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('shopsNarrowed', false)
                ->where('shops.0.selected', true));
    }

    public function test_the_choice_belongs_to_the_account(): void
    {
        $this->post(route('shopping.shops'), ['shops' => []])->assertRedirect(route('login'));
    }

    private function choose(string ...$slugs): void
    {
        $this->app->make(SelectedShops::class)->replace(
            $this->user,
            array_map(fn (string $slug): int => $this->shop($slug)->id, $slugs),
        );
    }

    private function shop(string $slug): Shop
    {
        return Shop::query()->where('slug', $slug)->firstOrFail();
    }

    private function plan(): ShoppingPlan
    {
        return $this->app->make(ShoppingPlanner::class)->plan(
            ShoppingList::defaultFor($this->user),
            new CheapestOverall,
            $this->app->make(SelectedShops::class)->ids($this->user),
        );
    }

    private function want(string $product): ShoppingListItem
    {
        return ShoppingListItem::query()->create([
            'shopping_list_id' => ShoppingList::defaultFor($this->user)->id,
            'ingredient_id' => $this->ingredient($product)->id,
        ]);
    }

    private function offer(string $shop, string $product, int $price, ?int $packGrams): Promotion
    {
        static $sequence = 0;
        $sequence++;

        return Promotion::query()->create([
            'shop_id' => $this->shop($shop)->id,
            'source' => 'test',
            'external_id' => 'offer-'.$sequence,
            'title' => $product.($packGrams === null ? '' : " {$packGrams} g"),
            'ingredient_id' => $this->ingredient($product)->id,
            'needs_review' => false,
            'price_minor' => $price,
            'pack_quantity' => $packGrams,
            'pack_unit_id' => $packGrams === null ? null : Unit::query()->where('code', 'g')->firstOrFail()->id,
            'unit_price_minor' => $packGrams === null ? null : (int) round($price * 1000 / $packGrams),
            'unit_price_per' => $packGrams === null ? null : UnitDimension::Mass,
            'valid_to' => now()->addDays(3),
            'url' => 'https://www.gazetki.pl/oferty/test',
        ]);
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::query()->where('name', $name)->firstOrFail();
    }
}
