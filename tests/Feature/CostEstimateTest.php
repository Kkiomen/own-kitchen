<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\UnitDimension;
use App\Models\Ingredient;
use App\Models\PriceObservation;
use App\Models\Promotion;
use App\Models\Shop;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Models\User;
use App\Shopping\CostEstimate;
use App\Shopping\EstimatedLine;
use App\Shopping\EstimatedList;
use App\Shopping\SelectedShops;
use Database\Seeders\IngredientMeasureSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\ShopSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Ile mniej więcej zapłacę za te zakupy" — the layering, and what it refuses to
 * make up.
 */
class CostEstimateTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);
        $this->seed(IngredientMeasureSeeder::class);
        $this->seed(ShopSeeder::class);

        $this->user = User::factory()->create();
    }

    /**
     * The strongest layer. An offer in a shop being driven to is not an estimate
     * at all — it is what the till will charge.
     */
    public function test_an_offer_in_a_chosen_shop_beats_a_typical_price(): void
    {
        $this->want('Masło');
        $this->observed('Masło', price: 899, packGrams: 200);
        $this->offer('lidl', 'Masło', price: 599, packGrams: 200);

        $line = $this->estimate()->lines[0];

        $this->assertSame(EstimatedLine::PROMOTION, $line->basis);
        $this->assertSame(599, $line->cost?->grosze);
    }

    /**
     * The point of narrowing: an offer in a shop nobody is driving to is not a
     * price this trip will pay, so the estimate falls back to a typical one.
     */
    public function test_an_offer_in_a_shop_i_skip_does_not_price_the_line(): void
    {
        $this->want('Masło');
        $this->observed('Masło', price: 899, packGrams: 200);
        $this->offer('aldi', 'Masło', price: 599, packGrams: 200);

        $this->choose('lidl');

        $line = $this->estimate()->lines[0];

        $this->assertSame(EstimatedLine::PACK, $line->basis);
        $this->assertSame(899, $line->cost?->grosze);
    }

    /**
     * An amount on the line and a price per kilo is the one case where the
     * estimate can do arithmetic rather than quote a pack.
     */
    public function test_an_amount_is_priced_per_kilo(): void
    {
        $this->want('Masło', 500, 'g');
        // 200 g for 8,99 zł is 44,95 zł/kg, so half a kilo is 22,48 zł.
        $this->observed('Masło', price: 899, packGrams: 200);

        $line = $this->estimate()->lines[0];

        $this->assertSame(EstimatedLine::UNIT, $line->basis);
        $this->assertSame(2248, $line->cost?->grosze);
    }

    /**
     * Most lines name no amount and most leaflets print no size. A typical pack
     * price is not a worse answer for those — it is the right one.
     */
    public function test_a_line_with_no_amount_is_priced_by_the_pack(): void
    {
        $this->want('Masło');
        $this->observed('Masło', price: 899, packGrams: 200);

        $this->assertSame(EstimatedLine::PACK, $this->estimate()->lines[0]->basis);
    }

    /**
     * The layer that makes the rest worth trusting. Treating an unpriced line as
     * free would understate the bill invisibly.
     */
    public function test_a_product_nobody_priced_is_reported_rather_than_guessed(): void
    {
        $this->want('Masło');
        $this->want('Szpinak');
        $this->observed('Masło', price: 899, packGrams: 200);

        $estimate = $this->estimate();

        $this->assertSame(899, $estimate->total()->grosze);
        $this->assertSame(1, $estimate->unpricedCount());
        $this->assertSame(EstimatedLine::UNKNOWN, $estimate->lines[1]->basis);
        $this->assertNull($estimate->lines[1]->cost);
    }

    /**
     * A median, not a mean: one premium reading must not drag the figure the
     * whole trolley is judged by.
     */
    public function test_one_expensive_reading_does_not_move_the_estimate(): void
    {
        $this->want('Masło');
        $this->observed('Masło', price: 800, packGrams: 200, id: 'a');
        $this->observed('Masło', price: 900, packGrams: 200, id: 'b');
        $this->observed('Masło', price: 9000, packGrams: 200, id: 'c');

        $this->assertSame(900, $this->estimate()->lines[0]->cost?->grosze);
    }

    /**
     * An old reading is worse than no reading: quoting last decade's butter as
     * "mniej więcej tyle zapłacisz" is a confident wrong number.
     */
    public function test_a_reading_too_old_to_quote_is_not_quoted(): void
    {
        $this->want('Masło');
        $this->observed('Masło', price: 899, packGrams: 200, observedOn: now()->subYears(4));

        $estimate = $this->estimate();

        $this->assertSame(EstimatedLine::UNKNOWN, $estimate->lines[0]->basis);
        $this->assertFalse($estimate->hasAnyPrice());
    }

    /**
     * Ticked lines are money already spent. Leaving them in would make the
     * trolley look more expensive the further round the shop you got.
     */
    public function test_the_screen_prices_only_what_is_still_to_buy(): void
    {
        $this->want('Masło');
        $bought = $this->want('Cukier');
        $bought->update(['bought_at' => now()]);

        $this->observed('Masło', price: 899, packGrams: 200);
        $this->observed('Cukier', price: 400, packGrams: 1000);

        $this->actingAs($this->user)
            ->get(route('shopping.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('estimate.total', 899)
                ->where('estimate.unpriced', 0)
                ->where('estimate.hasAnyPrice', true));
    }

    /**
     * Through the controller, with an amount on the line — which is the only way
     * this bug was reachable.
     *
     * The screen eager-loaded units as `unit:id,code,symbol`, and a trimmed unit
     * has a null `dimension`, which `Unit::definition()` refuses. Nothing on the
     * screen had ever asked a line for its `Quantity` before, so the moment the
     * estimate did, the page died with a type error naming a column nobody there
     * reads. Every unit test passed throughout: they build their own query and
     * load the relation whole.
     */
    public function test_the_screen_prices_a_line_that_states_an_amount(): void
    {
        $this->want('Masło', 500, 'g');
        $this->observed('Masło', price: 899, packGrams: 200);

        $this->actingAs($this->user)
            ->get(route('shopping.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('estimate.total', 2248)
                ->where('aisles.0.items.0.costBasis', 'unit'));
    }

    /**
     * A total of "0 zł" over a full list is not a smaller number, it is a wrong
     * one — so the screen has to be able to hide the whole block.
     */
    public function test_the_screen_says_when_it_can_price_nothing(): void
    {
        $this->want('Masło');

        $this->actingAs($this->user)
            ->get(route('shopping.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('estimate.hasAnyPrice', false)
                ->where('estimate.unpriced', 1));
    }

    private function estimate(): EstimatedList
    {
        $items = ShoppingListItem::query()
            ->where('shopping_list_id', ShoppingList::defaultFor($this->user)->id)
            ->stillToBuy()
            ->with(['ingredient', 'unit'])
            ->orderBy('id')
            ->get()
            ->all();

        return $this->app->make(CostEstimate::class)->for(
            array_values($items),
            $this->app->make(SelectedShops::class)->ids($this->user),
        );
    }

    private function choose(string ...$slugs): void
    {
        $this->app->make(SelectedShops::class)->replace(
            $this->user,
            array_map(fn (string $slug): int => $this->shop($slug)->id, $slugs),
        );
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

    private function observed(
        string $product,
        int $price,
        ?int $packGrams,
        string $id = 'x',
        mixed $observedOn = null,
    ): PriceObservation {
        $grams = Unit::query()->where('code', 'g')->firstOrFail();

        return PriceObservation::query()->create([
            'source' => 'test',
            'external_id' => $product.'-'.$id,
            'title' => $product,
            'ingredient_id' => $this->ingredient($product)->id,
            'needs_review' => false,
            'price_minor' => $price,
            'pack_quantity' => $packGrams,
            'pack_unit_id' => $packGrams === null ? null : $grams->id,
            'unit_price_minor' => $packGrams === null ? null : (int) round($price * 1000 / $packGrams),
            'unit_price_per' => $packGrams === null ? null : UnitDimension::Mass,
            'observed_on' => $observedOn ?? now(),
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
            'title' => $product,
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

    private function shop(string $slug): Shop
    {
        return Shop::query()->where('slug', $slug)->firstOrFail();
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::query()->where('name', $name)->firstOrFail();
    }
}
