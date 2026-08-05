<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StorageLocation;
use App\Enums\UnitDimension;
use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\StoreRecipeDraft;
use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Models\Promotion;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\Shop;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Models\User;
use App\Pantry\Pantry;
use App\Pantry\RecipeAvailability;
use App\Shopping\Planning\ShoppingPlanner;
use App\Shopping\Planning\Strategies\CheapestOverall;
use App\Shopping\Trolley;
use App\Support\Measurement\MeasureBook;
use App\Support\Measurement\Quantity;
use Database\Seeders\IngredientMeasureSeeder;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\ShopSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The seeded weights doing their job in the places that decide things: the
 * kitchen, the shopping list and the plan.
 *
 * These use the real dictionary rather than made-up products, because the point
 * is that the shipped data works. An onion is 150 g in
 * `database/data/ingredient-measures.php` and every number below follows from it.
 */
class MeasureConversionTest extends TestCase
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
     * The question this whole feature exists for. Before there were weights, a
     * fridge counted in grams and a recipe counted in onions were two facts that
     * could not be compared, and the app said nothing.
     */
    public function test_grams_in_the_fridge_cover_a_recipe_counted_in_pieces(): void
    {
        $recipe = $this->recipe('Zupa cebulowa', ['2 cebule']);
        $this->hold('Cebula', 400, 'g');

        $this->assertSame([], $this->missingFor($recipe));
    }

    public function test_too_few_grams_are_still_too_few(): void
    {
        $recipe = $this->recipe('Zupa cebulowa', ['4 cebule']);
        $this->hold('Cebula', 300, 'g');

        $missing = $this->missingFor($recipe);

        $this->assertCount(1, $missing);
        $this->assertSame('not_enough', $missing[0]['reason']);
    }

    /**
     * The shortfall comes back in the unit the recipe asked in, because that is
     * what lands on the shopping list. Four onions wanted, 300 g (two) held,
     * two to buy.
     */
    public function test_the_shortfall_is_reported_in_the_recipes_own_unit(): void
    {
        $recipe = $this->recipe('Zupa cebulowa', ['4 cebule']);
        $this->hold('Cebula', 300, 'g');

        $shortfall = $this->missingFor($recipe)[0]['quantity'];

        $this->assertSame(2.0, $shortfall?->amount);
        $this->assertSame('piece', $shortfall?->unit->code);
    }

    /**
     * A clove and a bulb are both "czosnek". Two cloves is 10 g, not two bulbs,
     * and 40 g in the kitchen is plenty.
     */
    public function test_a_clove_is_not_a_bulb(): void
    {
        $recipe = $this->recipe('Aromatyczny sos', ['2 ząbki czosnku']);
        $this->hold('Czosnek', 40, 'g');

        $this->assertSame([], $this->missingFor($recipe));
    }

    /**
     * A spoon of oil is grams of oil, through the density — no explicit spoon
     * weight needed for something that genuinely pours.
     */
    public function test_a_spoonful_of_a_liquid_converts_through_its_density(): void
    {
        $recipe = $this->recipe('Sałatka', ['3 łyżki oliwy z oliwek']);
        $this->hold('Oliwa z oliwek', 500, 'ml');

        $this->assertSame([], $this->missingFor($recipe));
    }

    /**
     * Nothing weighs a "sztuka" of minced meat, and the app must go on saying
     * nothing about it rather than inventing an average.
     */
    public function test_a_product_nobody_weighed_produces_no_shortage(): void
    {
        $recipe = $this->recipe('Pulpety', ['2 szt. mięsa mielonego wieprzowego']);
        $this->hold('Mięso mielone wieprzowe', 100, 'g');

        $this->assertSame([], $this->missingFor($recipe));
    }

    public function test_two_shelves_in_different_units_add_up(): void
    {
        $this->hold('Cebula', 2, 'piece', StorageLocation::Fridge);
        $this->hold('Cebula', 300, 'g', StorageLocation::Pantry);

        $total = Pantry::of($this->user, $this->app->make(MeasureBook::class))
            ->amountOf($this->ingredient('Cebula')->id);

        $this->assertSame(4.0, $total?->amount);
        $this->assertSame('piece', $total?->unit->code);
    }

    public function test_the_shopping_list_merges_across_units(): void
    {
        $list = $this->app->make(Trolley::class);
        $target = ShoppingList::defaultFor($this->user);
        $onion = $this->ingredient('Cebula');

        $list->add($target, $onion->id, $this->unit('piece')->quantity(2));
        $list->add($target, $onion->id, $this->unit('g')->quantity(300));

        $item = ShoppingListItem::query()->where('ingredient_id', $onion->id)->firstOrFail();

        $this->assertSame(4.0, $item->quantity);
        $this->assertSame('piece', $item->unit?->code);
    }

    /**
     * The case that made this necessary: a list counted in pieces against a
     * leaflet selling by the kilo. It used to fall back to one pack whatever the
     * amount, so "10 cebul" and "1 cebula" cost the same.
     */
    public function test_the_plan_works_out_packs_across_units(): void
    {
        $onion = $this->ingredient('Cebula');

        ShoppingListItem::query()->create([
            'shopping_list_id' => ShoppingList::defaultFor($this->user)->id,
            'ingredient_id' => $onion->id,
            'quantity' => 10,
            'unit_id' => $this->unit('piece')->id,
        ]);

        $this->offer($onion, packGrams: 1000, price: 499);

        $buy = $this->app->make(ShoppingPlanner::class)
            ->plan(ShoppingList::defaultFor($this->user), new CheapestOverall)
            ->stops[0]->buys[0];

        // Ten onions is 1500 g, so one kilo bag is not enough.
        $this->assertSame(2, $buy->packs);
        $this->assertSame(998, $buy->cost()->grosze);
    }

    /**
     * @return list<array{line: RecipeIngredient, reason: string, quantity: Quantity|null}>
     */
    private function missingFor(Recipe $recipe): array
    {
        return $this->app->make(RecipeAvailability::class)->missingFor(
            $recipe->load('ingredients.ingredient', 'ingredients.unit'),
            Pantry::of($this->user, $this->app->make(MeasureBook::class)),
        );
    }

    private function offer(Ingredient $ingredient, int $packGrams, int $price): Promotion
    {
        return Promotion::query()->create([
            'shop_id' => Shop::query()->where('slug', 'biedronka')->firstOrFail()->id,
            'source' => 'test',
            'external_id' => 'offer-1',
            'title' => $ingredient->name.' '.$packGrams.' g',
            'ingredient_id' => $ingredient->id,
            'needs_review' => false,
            'price_minor' => $price,
            'pack_quantity' => $packGrams,
            'pack_unit_id' => $this->unit('g')->id,
            'unit_price_minor' => (int) round($price * 1000 / $packGrams),
            'unit_price_per' => UnitDimension::Mass,
            'valid_to' => now()->addDays(3),
            'url' => 'https://www.gazetki.pl/oferty/test',
        ]);
    }

    private function hold(
        string $name,
        ?float $quantity,
        ?string $unitCode,
        StorageLocation $location = StorageLocation::Fridge,
    ): PantryItem {
        return PantryItem::query()->create([
            'user_id' => $this->user->id,
            'ingredient_id' => $this->ingredient($name)->id,
            'location' => $location,
            'quantity' => $quantity,
            'unit_id' => $unitCode === null ? null : $this->unit($unitCode)->id,
        ]);
    }

    /**
     * @param  list<string>  $lines
     */
    private function recipe(string $title, array $lines): Recipe
    {
        return $this->app->make(StoreRecipeDraft::class)->store(
            new RecipeDraft(
                slug: str($title)->slug()->value(),
                title: $title,
                sourceUrl: 'https://example.test/'.str($title)->slug()->value(),
                ingredientLines: array_map(
                    static fn (string $line): IngredientLineDraft => new IngredientLineDraft($line),
                    $lines,
                ),
                steps: [new StepDraft('Wymieszać i gotować przez 10 minut.')],
            ),
            'example.test',
        );
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::query()->where('name', $name)->firstOrFail();
    }

    private function unit(string $code): Unit
    {
        return Unit::query()->where('code', $code)->firstOrFail();
    }
}
