<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\StorageLocation;
use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\StoreRecipeDraft;
use App\Models\Ingredient;
use App\Models\PantryItem;
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

class ShoppingListTest extends TestCase
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

    public function test_a_product_can_be_written_down(): void
    {
        $this->actingAs($this->user)
            ->post(route('shopping.store'), [
                'ingredient_id' => $this->ingredient('Cukinia')->id,
                'quantity' => 500,
                'unit_id' => $this->unit('g')->id,
            ])
            ->assertRedirect();

        $this->assertSame(1, ShoppingListItem::query()->count());
    }

    public function test_an_amount_is_optional(): void
    {
        $this->actingAs($this->user)
            ->post(route('shopping.store'), [
                'ingredient_id' => $this->ingredient('Chleb')->id,
            ])
            ->assertSessionHasNoErrors();

        $this->assertNull(ShoppingListItem::query()->firstOrFail()->quantity);
    }

    public function test_an_amount_without_a_unit_is_refused(): void
    {
        $this->actingAs($this->user)
            ->post(route('shopping.store'), [
                'ingredient_id' => $this->ingredient('Cukinia')->id,
                'quantity' => 500,
            ])
            ->assertSessionHasErrors('unit_id');
    }

    /**
     * The trolley does not care that two recipes both want a courgette.
     */
    public function test_the_same_product_asked_for_twice_is_one_line(): void
    {
        $list = $this->list();
        $list->add($this->mainList(), $this->ingredient('Cukinia')->id, $this->unit('g')->quantity(300));
        $list->add($this->mainList(), $this->ingredient('Cukinia')->id, $this->unit('g')->quantity(200));

        $this->assertSame(1, ShoppingListItem::query()->count());
        $this->assertSame(500.0, ShoppingListItem::query()->firstOrFail()->quantity);
    }

    public function test_amounts_in_different_units_still_add_up(): void
    {
        $list = $this->list();
        $list->add($this->mainList(), $this->ingredient('Mąka pszenna')->id, $this->unit('kg')->quantity(1));
        $list->add($this->mainList(), $this->ingredient('Mąka pszenna')->id, $this->unit('g')->quantity(500));

        $this->assertSame(1.5, ShoppingListItem::query()->firstOrFail()->quantity);
    }

    /**
     * A total that includes an unknown part is unknown — better a product with no
     * number on it than a number that is wrong.
     */
    public function test_an_amountless_line_makes_the_total_unknown(): void
    {
        $list = $this->list();
        $list->add($this->mainList(), $this->ingredient('Cukinia')->id, $this->unit('g')->quantity(300));
        $list->add($this->mainList(), $this->ingredient('Cukinia')->id, null);

        $item = ShoppingListItem::query()->firstOrFail();

        $this->assertNull($item->quantity);
        $this->assertNull($item->unit_id);
    }

    public function test_a_recipe_writes_down_only_what_the_kitchen_lacks(): void
    {
        $recipe = $this->recipe('Cukinia z cebulą', ['300 g cukinii', '1 cebula']);
        $this->hold('Cukinia', 500, 'g');

        $result = $this->list()->addMissingFor($this->mainList(), $recipe);

        $this->assertSame(['added' => 1, 'unknown' => 0, 'skipped' => 1], $result);
        $this->assertSame('Cebula', ShoppingListItem::query()->firstOrFail()->ingredient->name);
    }

    /**
     * "Mam trochę, nie wiem ile" is honestly not a shortage — but it is not a
     * promise of 300 g either, and the line would otherwise vanish from the list
     * without anybody being told. So it is skipped *and* counted.
     */
    public function test_an_entry_with_no_amount_is_skipped_but_reported(): void
    {
        $recipe = $this->recipe('Cukinia', ['300 g cukinii']);
        $this->holdSome('Cukinia');

        $result = $this->list()->addMissingFor($this->mainList(), $recipe);

        $this->assertSame(['added' => 0, 'unknown' => 0, 'skipped' => 1], $result);
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    /**
     * The other half of the shortfall rule, and never the default: the cook who
     * knows the packet is nearly empty asks for it, at the full amount.
     */
    public function test_the_rest_can_be_asked_for_explicitly(): void
    {
        $recipe = $this->recipe('Cukinia z cebulą', ['300 g cukinii', '1 cebula']);
        $this->hold('Cukinia', 500, 'g');
        $this->list()->addMissingFor($this->mainList(), $recipe);

        $result = $this->list()->addHeldFor($this->mainList(), $recipe);

        $this->assertSame(['added' => 1], $result);
        $item = ShoppingListItem::query()->whereRelation('ingredient', 'name', 'Cukinia')->firstOrFail();
        $this->assertSame(300.0, $item->quantity);
    }

    /**
     * A line left off by policy is not something the kitchen covered, so asking
     * for "the rest" must not put a jar of paprika in the trolley.
     */
    public function test_the_rest_never_reaches_for_seasoning(): void
    {
        $recipe = $this->recipe('Cukinia z solą', ['300 g cukinii', '1 szczypta soli']);
        $this->hold('Cukinia', 500, 'g');

        $this->assertSame(['added' => 1], $this->list()->addHeldFor($this->mainList(), $recipe));
        $this->assertSame(
            ['Cukinia'],
            ShoppingListItem::query()->with('ingredient')->get()
                ->map(fn (ShoppingListItem $item): string => $item->ingredient->name)
                ->all(),
        );
    }

    public function test_the_rest_is_asked_for_through_its_own_endpoint(): void
    {
        $recipe = $this->recipe('Cukinia', ['300 g cukinii']);
        $this->hold('Cukinia', 500, 'g');

        $this->actingAs($this->user)
            ->post(route('shopping.recipe-held', $recipe))
            ->assertRedirect()
            // Which list it landed on rides along in the same flash, so this
            // reads the one thing it is about rather than the whole message.
            ->assertSessionHas('shopping.added', 1);
    }

    /**
     * Having some is not having enough — but you only buy the difference.
     */
    public function test_only_the_shortfall_is_written_down(): void
    {
        $recipe = $this->recipe('Dużo cukinii', ['800 g cukinii']);
        $this->hold('Cukinia', 200, 'g');

        $this->list()->addMissingFor($this->mainList(), $recipe);

        $this->assertSame(600.0, ShoppingListItem::query()->firstOrFail()->quantity);
    }

    /**
     * A line that reduces to a bare group word ("płatków") deliberately resolves
     * to no product at all — attaching it to an invention is the worst failure
     * this codebase has had. There is therefore nothing to write down, so the
     * line is reported back instead of guessed at.
     */
    public function test_a_line_with_no_product_is_reported_not_invented(): void
    {
        $recipe = $this->recipe('Coś dziwnego', ['1 szklanka płatków (np. pszenne lub żytnie)']);

        $result = $this->list()->addMissingFor($this->mainList(), $recipe);

        $this->assertSame(0, $result['added']);
        $this->assertSame(1, $result['unknown']);
        $this->assertSame(0, ShoppingListItem::query()->count());
    }

    public function test_staples_are_not_written_down(): void
    {
        $recipe = $this->recipe('Cukinia z solą', ['300 g cukinii', '1 szczypta soli']);

        $this->list()->addMissingFor($this->mainList(), $recipe);

        $this->assertSame(
            ['Cukinia'],
            ShoppingListItem::query()->with('ingredient')->get()
                ->map(fn (ShoppingListItem $item): string => $item->ingredient->name)
                ->all(),
        );
    }

    public function test_an_item_can_be_ticked_off_and_back_on(): void
    {
        $item = $this->list()->add($this->mainList(), $this->ingredient('Cukinia')->id);

        $this->actingAs($this->user)
            ->patch(route('shopping.update', $item), ['bought' => true]);

        $this->assertTrue($item->refresh()->isBought());

        $this->actingAs($this->user)
            ->patch(route('shopping.update', $item), ['bought' => false]);

        $this->assertFalse($item->refresh()->isBought());
    }

    /**
     * Asking for something again means it is wanted again, whatever was bought
     * on the last trip.
     */
    public function test_adding_to_a_ticked_off_line_starts_it_again(): void
    {
        $list = $this->list();
        $item = $list->add($this->mainList(), $this->ingredient('Cukinia')->id, $this->unit('g')->quantity(300));
        $item->update(['bought_at' => now()]);

        $list->add($this->mainList(), $this->ingredient('Cukinia')->id, $this->unit('g')->quantity(200));

        $item->refresh();

        $this->assertFalse($item->isBought());
        $this->assertSame(200.0, $item->quantity);
    }

    public function test_an_amount_can_be_corrected_on_the_list(): void
    {
        $item = $this->list()->add($this->mainList(), $this->ingredient('Cukinia')->id, $this->unit('g')->quantity(300));

        $this->actingAs($this->user)
            ->patch(route('shopping.update', $item), ['quantity' => 500])
            ->assertSessionHasNoErrors();

        $this->assertSame(500.0, $item->refresh()->quantity);
        // Correcting the amount is not the same as putting it in the trolley.
        $this->assertFalse($item->isBought());
    }

    /**
     * A line written down as "some, amount unknown" gets its number from the
     * unit the product is normally bought in — otherwise the stepper on the
     * screen would have nothing to count in.
     */
    public function test_a_line_with_no_unit_borrows_the_products_own(): void
    {
        $item = $this->list()->add($this->mainList(), $this->ingredient('Cebula')->id);

        $this->assertNull($item->unit_id);

        $this->actingAs($this->user)
            ->patch(route('shopping.update', $item), ['quantity' => 3])
            ->assertSessionHasNoErrors();

        $item->refresh();

        $this->assertSame(3.0, $item->quantity);
        $this->assertSame($this->ingredient('Cebula')->default_unit_id, $item->unit_id);
    }

    /**
     * Nothing knows what to count it in, so there is no honest amount to write
     * down — better no number than one nobody chose.
     */
    public function test_an_amount_is_refused_when_no_unit_is_known(): void
    {
        $ingredient = $this->ingredient('Cukinia');
        $ingredient->update(['default_unit_id' => null]);

        $item = $this->list()->add($this->mainList(), $ingredient->id);

        $this->actingAs($this->user)
            ->patch(route('shopping.update', $item), ['quantity' => 2])
            ->assertSessionHasErrors('quantity');

        $this->assertNull($item->refresh()->quantity);
    }

    /**
     * Emptying the box means "some, amount unknown" again, which is a real
     * answer and not zero.
     */
    public function test_clearing_the_amount_leaves_the_product_on_the_list(): void
    {
        $item = $this->list()->add($this->mainList(), $this->ingredient('Cukinia')->id, $this->unit('g')->quantity(300));

        $this->actingAs($this->user)
            ->patch(route('shopping.update', $item), ['quantity' => null]);

        $this->assertNull($item->refresh()->quantity);
        $this->assertSame(1, ShoppingListItem::query()->count());
    }

    public function test_another_account_cannot_change_an_amount(): void
    {
        $item = $this->list()->add($this->mainList(), $this->ingredient('Cukinia')->id, $this->unit('g')->quantity(300));

        $this->actingAs(User::factory()->create())
            ->patch(route('shopping.update', $item), ['quantity' => 900])
            ->assertNotFound();

        $this->assertSame(300.0, $item->refresh()->quantity);
    }

    /**
     * The row needs to know what a typed amount would be counted in before one
     * has ever been given.
     */
    public function test_the_list_says_what_each_line_is_measured_in(): void
    {
        $this->list()->add($this->mainList(), $this->ingredient('Cebula')->id);

        $this->actingAs($this->user)
            ->get(route('shopping.index'))
            ->assertInertia(fn ($page) => $page
                ->where('aisles.0.items.0.quantity', null)
                ->where('aisles.0.items.0.measureCode', 'piece'));
    }

    public function test_another_account_cannot_touch_this_list(): void
    {
        $item = $this->list()->add($this->mainList(), $this->ingredient('Cukinia')->id);
        $other = User::factory()->create();

        $this->actingAs($other)
            ->delete(route('shopping.destroy', $item))
            ->assertNotFound();

        $this->assertSame(1, ShoppingListItem::query()->count());
    }

    /**
     * The loop the app exists for: what you bought becomes what you can cook.
     */
    public function test_ticked_off_shopping_moves_into_the_kitchen(): void
    {
        $list = $this->list();
        $bought = $list->add($this->mainList(), $this->ingredient('Cukinia')->id, $this->unit('g')->quantity(500));
        $bought->update(['bought_at' => now()]);
        $list->add($this->mainList(), $this->ingredient('Cebula')->id, $this->unit('piece')->quantity(2));

        $this->actingAs($this->user)->post(route('shopping.stock-up'));

        $item = PantryItem::query()->with('ingredient')->firstOrFail();

        $this->assertSame('Cukinia', $item->ingredient->name);
        $this->assertSame(500.0, $item->quantity);
        // Vegetables go in the fridge unless somebody says otherwise.
        $this->assertSame(StorageLocation::Fridge, $item->location);
        // What was not ticked off is still to buy.
        $this->assertSame(1, ShoppingListItem::query()->count());
    }

    public function test_unpacking_adds_to_the_shelf_rather_than_replacing_it(): void
    {
        $this->hold('Cukinia', 200, 'g');

        $bought = $this->list()->add($this->mainList(), $this->ingredient('Cukinia')->id, $this->unit('g')->quantity(500));
        $bought->update(['bought_at' => now()]);

        $this->actingAs($this->user)->post(route('shopping.stock-up'));

        $this->assertSame(1, PantryItem::query()->count());
        $this->assertSame(700.0, PantryItem::query()->firstOrFail()->quantity);
    }

    public function test_the_list_is_grouped_by_aisle(): void
    {
        $list = $this->list();
        $list->add($this->mainList(), $this->ingredient('Cukinia')->id);
        $list->add($this->mainList(), $this->ingredient('Mąka pszenna')->id);

        $this->actingAs($this->user)
            ->get(route('shopping.index'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('aisles.0.label', 'Warzywa i owoce')
                ->where('aisles.1.label', 'Sypkie i pieczywo'));
    }

    private function list(): Trolley
    {
        return $this->app->make(Trolley::class);
    }

    /**
     * The list every write lands on unless a screen says otherwise.
     */
    private function mainList(): ShoppingList
    {
        return ShoppingList::defaultFor($this->user);
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::query()->where('name', $name)->firstOrFail();
    }

    private function unit(string $code): Unit
    {
        return Unit::query()->where('code', $code)->firstOrFail();
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

    /** "Some, amount unknown" — what a kitchen nobody measured actually says. */
    private function holdSome(string $name): void
    {
        PantryItem::query()->create([
            'user_id' => $this->user->id,
            'ingredient_id' => $this->ingredient($name)->id,
            'location' => StorageLocation::Fridge,
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
}
