<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\StoreRecipeDraft;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Models\User;
use App\Shopping\Trolley;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Several lists per household: the weekly shop, the barbecue, the Asian grocer.
 *
 * The rules that matter are about what a list guarantees — there is always one,
 * it is always the household's own, and what is on it is only on it.
 */
class ShoppingListsTest extends TestCase
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

    /**
     * `DatabaseSeeder` creates no account, so nothing can have created a list in
     * advance. Every screen still needs somewhere to write to.
     */
    public function test_the_main_list_appears_the_first_time_it_is_needed(): void
    {
        $this->assertSame(0, ShoppingList::query()->count());

        $this->actingAs($this->user)
            ->get(route('shopping.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('list.name', 'Lista główna')
                ->where('list.isDefault', true)
                // Nothing to write to means nothing to switch between.
                ->has('lists', 1));
    }

    public function test_a_second_list_can_be_created_and_opens_straight_away(): void
    {
        $this->actingAs($this->user)
            ->post(route('shopping.lists.store'), ['name' => 'Grill'])
            ->assertRedirect(route('shopping.show', ShoppingList::query()->where('name', 'Grill')->firstOrFail()));

        $this->assertSame(2, ShoppingList::query()->count());
    }

    public function test_two_lists_cannot_share_a_name(): void
    {
        $this->actingAs($this->user)->post(route('shopping.lists.store'), ['name' => 'Grill']);

        $this->actingAs($this->user)
            ->post(route('shopping.lists.store'), ['name' => 'Grill'])
            ->assertSessionHasErrors('name');

        $this->assertSame(2, ShoppingList::query()->count());
    }

    /**
     * The whole point of separate lists: the same butter can honestly belong to
     * two different trips, and neither one merges into the other.
     */
    public function test_the_same_product_on_two_lists_is_two_lines(): void
    {
        $grill = $this->list('Grill');
        $butter = $this->ingredient('Masło');

        $this->trolley()->add(ShoppingList::defaultFor($this->user), $butter->id, $this->unit('g')->quantity(200));
        $this->trolley()->add($grill, $butter->id, $this->unit('g')->quantity(500));

        $this->assertSame(2, ShoppingListItem::query()->count());
        $this->assertSame(500.0, $grill->items()->firstOrFail()->quantity);
    }

    public function test_a_list_shows_only_its_own_products(): void
    {
        $grill = $this->list('Grill');
        $this->trolley()->add(ShoppingList::defaultFor($this->user), $this->ingredient('Cukinia')->id);
        $this->trolley()->add($grill, $this->ingredient('Masło')->id);

        $this->actingAs($this->user)
            ->get(route('shopping.show', $grill))
            ->assertInertia(fn ($page) => $page
                ->where('list.name', 'Grill')
                ->has('aisles', 1)
                ->where('aisles.0.items.0.name', 'Masło'));
    }

    public function test_a_product_is_written_to_the_open_list(): void
    {
        $grill = $this->list('Grill');

        $this->actingAs($this->user)
            ->post(route('shopping.store'), [
                'ingredient_id' => $this->ingredient('Masło')->id,
                'shopping_list_id' => $grill->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertSame($grill->id, ShoppingListItem::query()->firstOrFail()->shopping_list_id);
    }

    /**
     * Everything that writes without being asked which list — the kitchen form,
     * a recipe with nothing chosen — lands on the main one rather than the last
     * one somebody happened to open.
     */
    public function test_a_write_with_no_list_named_goes_to_the_main_one(): void
    {
        $this->list('Grill');

        $this->actingAs($this->user)
            ->post(route('shopping.store'), ['ingredient_id' => $this->ingredient('Masło')->id]);

        $this->assertTrue(ShoppingListItem::query()->firstOrFail()->list->is_default);
    }

    public function test_a_recipe_can_be_added_to_a_chosen_list(): void
    {
        $grill = $this->list('Grill');
        $recipe = $this->recipe('Cukinia z cebulą', ['300 g cukinii', '1 cebula']);

        $this->actingAs($this->user)
            ->post(route('shopping.recipe', $recipe), ['shopping_list_id' => $grill->id])
            ->assertSessionHas('shopping', fn (array $result): bool => $result['list'] === 'Grill');

        $this->assertSame(2, $grill->items()->count());
    }

    /**
     * "Add this dish to a new list for Saturday" is one tap: the modal never
     * navigates away from the recipe being read.
     */
    public function test_a_recipe_can_start_a_list_of_its_own(): void
    {
        $recipe = $this->recipe('Cukinia', ['300 g cukinii']);

        $this->actingAs($this->user)
            ->post(route('shopping.recipe', $recipe), ['new_list_name' => 'Sobota'])
            ->assertSessionHasNoErrors();

        $list = ShoppingList::query()->where('name', 'Sobota')->firstOrFail();

        $this->assertFalse($list->is_default);
        $this->assertSame(1, $list->items()->count());
    }

    public function test_a_new_list_from_a_recipe_cannot_take_a_name_already_used(): void
    {
        $this->list('Grill');
        $recipe = $this->recipe('Cukinia', ['300 g cukinii']);

        $this->actingAs($this->user)
            ->post(route('shopping.recipe', $recipe), ['new_list_name' => 'Grill'])
            ->assertSessionHasErrors('new_list_name');

        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_a_list_can_be_renamed(): void
    {
        $grill = $this->list('Grill');

        $this->actingAs($this->user)
            ->patch(route('shopping.lists.update', $grill), ['name' => 'Grill u Kaśki'])
            ->assertSessionHasNoErrors();

        $this->assertSame('Grill u Kaśki', $grill->refresh()->name);
    }

    public function test_deleting_a_list_takes_what_was_on_it(): void
    {
        $grill = $this->list('Grill');
        $this->trolley()->add($grill, $this->ingredient('Masło')->id);

        $this->actingAs($this->user)
            ->delete(route('shopping.lists.destroy', $grill))
            ->assertRedirect(route('shopping.index'));

        $this->assertSame(0, ShoppingListItem::query()->count());
        $this->assertSame(1, ShoppingList::query()->count());
    }

    /**
     * Refused rather than hidden: an account with no list at all would break the
     * very screen that would have made a new one.
     */
    public function test_the_main_list_cannot_be_deleted(): void
    {
        $this->actingAs($this->user)
            ->delete(route('shopping.lists.destroy', ShoppingList::defaultFor($this->user)))
            ->assertForbidden();

        $this->assertSame(1, ShoppingList::query()->count());
    }

    public function test_another_household_cannot_see_or_touch_a_list(): void
    {
        $grill = $this->list('Grill');
        $other = User::factory()->create();

        $this->actingAs($other)->get(route('shopping.show', $grill))->assertNotFound();
        $this->actingAs($other)->get(route('shopping.list-plan', $grill))->assertNotFound();
        $this->actingAs($other)->patch(route('shopping.lists.update', $grill), ['name' => 'Moje'])->assertNotFound();
        $this->actingAs($other)->delete(route('shopping.lists.destroy', $grill))->assertNotFound();

        $this->assertSame('Grill', $grill->refresh()->name);
    }

    public function test_a_line_cannot_be_changed_from_another_household(): void
    {
        $item = $this->trolley()->add($this->list('Grill'), $this->ingredient('Masło')->id);

        $this->actingAs(User::factory()->create())
            ->patch(route('shopping.update', $item), ['bought' => true])
            ->assertNotFound();

        $this->assertFalse($item->refresh()->isBought());
    }

    /**
     * A plan is a trip, and a trip is made from one list — otherwise the weekly
     * shop would drag the barbecue along with it.
     */
    public function test_the_plan_counts_only_the_list_it_was_asked_about(): void
    {
        $grill = $this->list('Grill');
        $this->trolley()->add(ShoppingList::defaultFor($this->user), $this->ingredient('Cukinia')->id);
        $this->trolley()->add($grill, $this->ingredient('Masło')->id);

        $this->actingAs($this->user)
            ->get(route('shopping.list-plan', $grill))
            ->assertInertia(fn ($page) => $page
                ->where('list.name', 'Grill')
                ->where('listCount', 1));
    }

    /**
     * The modal cannot ask which list without being told what there is.
     */
    public function test_a_recipe_page_carries_the_households_lists(): void
    {
        $this->list('Grill');
        $recipe = $this->recipe('Cukinia', ['300 g cukinii']);

        $this->actingAs($this->user)
            ->get(route('recipes.show', $recipe))
            ->assertInertia(fn ($page) => $page->has('shoppingLists', 2));
    }

    private function trolley(): Trolley
    {
        return $this->app->make(Trolley::class);
    }

    private function list(string $name): ShoppingList
    {
        // Through the endpoint, so the main list exists exactly as it would in
        // the app rather than only in the test's head.
        $this->actingAs($this->user)->post(route('shopping.lists.store'), ['name' => $name]);

        return ShoppingList::query()->where('name', $name)->firstOrFail();
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
}
