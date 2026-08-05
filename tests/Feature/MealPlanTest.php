<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\MealSlot;
use App\Enums\StorageLocation;
use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\StoreRecipeDraft;
use App\Models\Ingredient;
use App\Models\MealPlanEntry;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Models\User;
use App\Planning\PlannedShopping;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MealPlanTest extends TestCase
{
    use RefreshDatabase;

    private User $user;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);

        $this->user = User::factory()->create();
    }

    public function test_a_recipe_can_be_planned_for_a_meal(): void
    {
        $recipe = $this->recipe('Gulasz', ['500 g cukinii'], servings: 4);

        $this->actingAs($this->user)
            ->post(route('meal-plan.store'), [
                'date' => '2026-08-10',
                'slot' => MealSlot::Lunch->value,
                'recipe_id' => $recipe->id,
                'servings' => 6,
            ])
            ->assertRedirect();

        $entry = MealPlanEntry::query()->firstOrFail();

        $this->assertSame(MealSlot::Lunch, $entry->slot);
        $this->assertSame(6, $entry->servings);
    }

    /** Two people eat here; the number should be right before it is touched. */
    public function test_portions_default_to_the_household(): void
    {
        $this->actingAs($this->user)
            ->post(route('meal-plan.store'), [
                'date' => '2026-08-10',
                'slot' => MealSlot::Dinner->value,
                'recipe_id' => $this->recipe('Zupa', ['1 cebula'])->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame(2, MealPlanEntry::query()->firstOrFail()->servings);
    }

    public function test_a_meal_can_be_a_note_instead_of_a_recipe(): void
    {
        $this->actingAs($this->user)
            ->post(route('meal-plan.store'), [
                'date' => '2026-08-10',
                'slot' => MealSlot::Breakfast->value,
                'note' => 'Kanapki',
            ])
            ->assertSessionHasNoErrors();

        $entry = MealPlanEntry::query()->firstOrFail();

        $this->assertTrue($entry->isNote());
        $this->assertSame('Kanapki', $entry->label());
        // A note feeds nobody a measured portion.
        $this->assertNull($entry->servings);
    }

    public function test_a_meal_is_a_recipe_or_a_note_but_never_both(): void
    {
        $this->actingAs($this->user)
            ->post(route('meal-plan.store'), [
                'date' => '2026-08-10',
                'slot' => MealSlot::Breakfast->value,
                'recipe_id' => $this->recipe('Owsianka', ['1 szklanka płatków owsianych'])->id,
                'note' => 'Kanapki',
            ])
            ->assertSessionHasErrors('recipe_id');
    }

    public function test_a_meal_is_a_recipe_or_a_note_but_never_neither(): void
    {
        $this->actingAs($this->user)
            ->post(route('meal-plan.store'), [
                'date' => '2026-08-10',
                'slot' => MealSlot::Breakfast->value,
            ])
            ->assertSessionHasErrors('recipe_id');
    }

    public function test_another_households_meal_cannot_be_touched(): void
    {
        $entry = MealPlanEntry::query()->create([
            'user_id' => User::factory()->create()->id,
            'date' => '2026-08-10',
            'slot' => MealSlot::Lunch,
            'note' => 'Nie twoje',
        ]);

        $this->actingAs($this->user)
            ->delete(route('meal-plan.destroy', $entry))
            ->assertNotFound();

        $this->assertSame(1, MealPlanEntry::query()->count());
    }

    public function test_the_week_shows_every_day_and_the_everyday_meals(): void
    {
        $this->actingAs($this->user)
            ->get(route('meal-plan.index', ['week' => '2026-08-12']))
            ->assertInertia(
                fn ($page) => $page
                    ->component('Plan/Index')
                    // Monday, whichever day of that week was asked for.
                    ->where('weekStart', '2026-08-10')
                    ->has('days', 7)
                    ->has('days.0.slots', count(MealSlot::everyday())),
            );
    }

    /** A week's shopping is one trip, so it gets a list named after the days. */
    public function test_the_week_goes_on_its_own_list_named_after_the_days(): void
    {
        $this->plan('2026-08-10', MealSlot::Lunch, $this->recipe('Placki', ['300 g cukinii'], servings: 2));

        $result = $this->shopFor(['2026-08-10', '2026-08-11', '2026-08-16']);

        $this->assertSame('10–16 sierpnia', $result['list']);
        $this->assertSame(
            $result['list'],
            ShoppingListItem::query()->firstOrFail()->list->name,
        );
    }

    public function test_a_week_spanning_two_months_names_both(): void
    {
        $this->plan('2026-07-30', MealSlot::Lunch, $this->recipe('Placki', ['300 g cukinii'], servings: 2));

        $result = $this->shopFor(['2026-07-30', '2026-08-02']);

        $this->assertSame('30 lipca – 2 sierpnia', $result['list']);
    }

    /**
     * The button is meant to be pressed again after swapping a dish, so the same
     * days always mean the same list — rebuilt, not duplicated.
     */
    public function test_shopping_the_same_days_again_rebuilds_one_list(): void
    {
        $entry = $this->plan('2026-08-10', MealSlot::Lunch, $this->recipe('Placki', ['300 g cukinii'], servings: 2));

        $first = $this->shopFor(['2026-08-10']);

        // The plan changed: the courgette dish is now an onion one.
        $entry->update(['recipe_id' => $this->recipe('Zupa', ['2 cebule'], servings: 2)->id]);
        $second = $this->shopFor(['2026-08-10']);

        $this->assertSame($first['listId'], $second['listId']);
        $this->assertSame(1, ShoppingList::query()->where('is_default', false)->count());

        // The line for the dish that is no longer planned went with it.
        $this->assertSame(
            ['Cebula'],
            ShoppingListItem::query()->with('ingredient')->get()
                ->map(fn (ShoppingListItem $item): string => $item->ingredient->name)
                ->all(),
        );
    }

    /** What is already in the trolley is not asked for twice, or un-ticked. */
    public function test_rebuilding_leaves_what_was_already_bought(): void
    {
        $this->plan('2026-08-10', MealSlot::Lunch, $this->recipe('Placki', ['300 g cukinii'], servings: 2));

        $this->shopFor(['2026-08-10']);
        $bought = $this->listed('Cukinia');
        $bought->update(['bought_at' => now(), 'quantity' => 250.0]);

        $result = $this->shopFor(['2026-08-10']);

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['kept']);

        $bought->refresh();
        $this->assertTrue($bought->isBought());
        $this->assertSame(250.0, $bought->quantity);
    }

    /**
     * Half a recipe is half an egg and a quarter of a jar of passata. The pot is
     * made whole and the extra portions are left over — said out loud, because
     * buying more than was asked for is not something to do quietly.
     */
    public function test_fewer_portions_than_the_recipe_makes_still_buys_one_whole_cooking(): void
    {
        $recipe = $this->recipe('Duży gulasz', ['800 g cukinii'], servings: 4);
        $this->plan('2026-08-10', MealSlot::Lunch, $recipe, servings: 2);

        $result = $this->shopFor(['2026-08-10']);

        $this->assertSame(800.0, $this->listed('Cukinia')->quantity);
        $this->assertSame(['Duży gulasz (przepis na 4)'], $result['wholeBatches']);
    }

    /** Above one cooking it scales as usual — no rounding up to whole pots. */
    public function test_more_portions_than_the_recipe_makes_scale_normally(): void
    {
        $recipe = $this->recipe('Duży gulasz', ['800 g cukinii'], servings: 4);
        $this->plan('2026-08-10', MealSlot::Lunch, $recipe, servings: 6);

        $result = $this->shopFor(['2026-08-10']);

        $this->assertSame(1200.0, $this->listed('Cukinia')->quantity);
        $this->assertSame([], $result['wholeBatches']);
    }

    /** The whole point of the portions: a bigger batch buys more. */
    public function test_shopping_scales_a_recipe_to_the_portions_planned(): void
    {
        $recipe = $this->recipe('Gulasz', ['500 g cukinii'], servings: 4);
        $this->plan('2026-08-10', MealSlot::Lunch, $recipe, servings: 8);

        $this->shopFor(['2026-08-10']);

        $this->assertSame(1000.0, $this->listed('Cukinia')->quantity);
    }

    /**
     * The rule this household actually cooks by: one pot on Sunday, eaten on
     * three days. Scaling each meal on its own would buy three pots.
     */
    public function test_one_recipe_planned_on_three_days_is_cooked_once(): void
    {
        $recipe = $this->recipe('Gulasz', ['600 g cukinii'], servings: 6);

        foreach (['2026-08-10', '2026-08-11', '2026-08-12'] as $date) {
            $this->plan($date, MealSlot::Lunch, $recipe, servings: 2);
        }

        $this->shopFor(['2026-08-10', '2026-08-11', '2026-08-12']);

        // Six portions planned, six portions in the recipe: one batch.
        $this->assertSame(600.0, $this->listed('Cukinia')->quantity);
    }

    /**
     * The bug a loop over `Trolley::addMissingFor()` would have: each dish reads
     * the shelf afresh, both find themselves covered, and the week shops short.
     */
    public function test_two_dishes_share_one_shelf_rather_than_each_emptying_it(): void
    {
        $this->hold('Cukinia', 500, 'g');

        $first = $this->recipe('Placki', ['300 g cukinii'], servings: 2);
        $second = $this->recipe('Zapiekanka', ['300 g cukinii'], servings: 2);

        $this->plan('2026-08-10', MealSlot::Lunch, $first, servings: 2);
        $this->plan('2026-08-10', MealSlot::Dinner, $second, servings: 2);

        $this->shopFor(['2026-08-10']);

        // 600 wanted, 500 on the shelf.
        $this->assertSame(100.0, $this->listed('Cukinia')->quantity);
    }

    public function test_only_the_chosen_days_are_shopped_for(): void
    {
        $this->plan('2026-08-10', MealSlot::Lunch, $this->recipe('Placki', ['300 g cukinii'], servings: 2));
        $this->plan('2026-08-11', MealSlot::Lunch, $this->recipe('Zupa', ['2 cebule'], servings: 2));

        $this->shopFor(['2026-08-10']);

        $this->assertSame(
            ['Cukinia'],
            ShoppingListItem::query()->with('ingredient')->get()
                ->map(fn (ShoppingListItem $item): string => $item->ingredient->name)
                ->all(),
        );
    }

    public function test_a_note_is_planned_but_never_shopped_for(): void
    {
        MealPlanEntry::query()->create([
            'user_id' => $this->user->id,
            'date' => '2026-08-10',
            'slot' => MealSlot::Dinner,
            'note' => 'Obiad u rodziców',
        ]);

        $result = $this->shopFor(['2026-08-10']);

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['notes']);
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_seasoning_is_never_added_to_the_week(): void
    {
        $recipe = $this->recipe('Cukinia z solą', ['300 g cukinii', '1 szczypta soli'], servings: 2);
        $this->plan('2026-08-10', MealSlot::Lunch, $recipe);

        $this->shopFor(['2026-08-10']);

        $this->assertSame(
            ['Cukinia'],
            ShoppingListItem::query()->with('ingredient')->get()
                ->map(fn (ShoppingListItem $item): string => $item->ingredient->name)
                ->all(),
        );
    }

    /** No product to write down, so it is reported rather than invented. */
    public function test_an_unrecognised_line_is_counted_not_invented(): void
    {
        $recipe = $this->recipe(
            'Coś dziwnego',
            ['1 szklanka płatków (np. pszenne lub żytnie)'],
            servings: 2,
        );
        $this->plan('2026-08-10', MealSlot::Lunch, $recipe);

        $result = $this->shopFor(['2026-08-10']);

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['unknown']);
    }

    /**
     * 78 of ~10 200 recipes never say how many portions they make. Guessing one
     * would silently halve or double a week's shopping, so the amounts are used
     * as written and the screen is told.
     */
    public function test_a_recipe_without_portions_is_taken_as_written_and_reported(): void
    {
        $recipe = $this->recipe('Bez porcji', ['400 g cukinii'], servings: null);
        $this->plan('2026-08-10', MealSlot::Lunch, $recipe, servings: 8);

        $result = $this->shopFor(['2026-08-10']);

        $this->assertSame(['Bez porcji'], $result['unscaled']);
        $this->assertSame(400.0, $this->listed('Cukinia')->quantity);
    }

    public function test_a_kitchen_that_covers_the_week_leaves_the_list_empty(): void
    {
        $this->hold('Cukinia', 2, 'kg');

        $this->plan('2026-08-10', MealSlot::Lunch, $this->recipe('Placki', ['300 g cukinii'], servings: 2));

        $result = $this->shopFor(['2026-08-10']);

        $this->assertSame(0, $result['added']);
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    /**
     * @param  list<string>  $dates
     * @return array{added: int, unknown: int, notes: int, unscaled: list<string>, meals: int}
     */
    private function shopFor(array $dates): array
    {
        return $this->app->make(PlannedShopping::class)->addMissing($this->user, $dates);
    }

    private function plan(string $date, MealSlot $slot, Recipe $recipe, int $servings = 2): MealPlanEntry
    {
        return MealPlanEntry::query()->create([
            'user_id' => $this->user->id,
            'date' => $date,
            'slot' => $slot,
            'recipe_id' => $recipe->id,
            'servings' => $servings,
        ]);
    }

    private function listed(string $name): ShoppingListItem
    {
        return ShoppingListItem::query()
            ->where('ingredient_id', $this->ingredient($name)->id)
            ->firstOrFail();
    }

    private function hold(string $name, float $quantity, string $unitCode): void
    {
        PantryItem::query()->create([
            'user_id' => $this->user->id,
            'ingredient_id' => $this->ingredient($name)->id,
            'location' => StorageLocation::Fridge,
            'quantity' => $quantity,
            'unit_id' => $this->unit($unitCode)->id,
        ]);
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::query()->where('name', $name)->firstOrFail();
    }

    private function unit(string $code): Unit
    {
        return Unit::query()->where('code', $code)->firstOrFail();
    }

    /**
     * @param  list<string>  $lines
     */
    private function recipe(string $title, array $lines, ?int $servings = 2): Recipe
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
                servings: $servings,
            ),
            'example.test',
        );
    }
}
