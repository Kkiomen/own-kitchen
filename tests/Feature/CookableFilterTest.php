<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\StoreRecipeDraft;
use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Models\Unit;
use App\Models\User;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * "Pokaż tylko te, na które mam składniki" — the chip, end to end.
 *
 * `missingCounts()` was already covered directly, but nothing exercised the
 * filter that consumes it, which is the part the screen actually offers.
 */
class CookableFilterTest extends TestCase
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

    public function test_it_shows_only_recipes_whose_products_are_all_held(): void
    {
        $this->recipe('Jajecznica', ['3 jajka', '20 g masła']);
        $this->recipe('Stek', ['400 g wołowiny', '1 cebula']);

        $this->hold('Jajko');
        $this->hold('Masło');

        $this->assertSame(['Jajecznica'], $this->titlesFor('cookable'));
    }

    public function test_almost_is_the_recipes_short_of_one_or_two(): void
    {
        $this->recipe('Jajecznica', ['3 jajka', '20 g masła']);
        $this->recipe('Omlet', ['3 jajka', '20 g masła', '50 g sera żółtego']);
        $this->recipe('Wszystkiego brak', [
            '1 cebula', '1 marchew', '200 g ryżu', '1 papryka',
        ]);

        $this->hold('Jajko');
        $this->hold('Masło');

        $this->assertSame(['Omlet'], $this->titlesFor('almost'));
    }

    /**
     * The user's own words: a dish is not blocked by the garnish on top of it.
     * Seasoning is judged by category, so it holds for products the importer
     * invented too — those never get the `is_staple` column set.
     */
    public function test_seasoning_never_blocks_a_recipe(): void
    {
        /*
         * The column is cleared on purpose. `is_staple` is written by the
         * dictionary seeder and is therefore false for all ~1300 products the
         * importer invented, which is exactly how a spice jar used to hide a
         * cookable recipe. Clearing it here puts these two in that position, so
         * the only thing that can exempt them is their category.
         */
        $spice = $this->ingredient('Przyprawa gyros');
        $herb = $this->ingredient('Natka pietruszki');

        $spice->update(['is_staple' => false]);
        $herb->update(['is_staple' => false]);

        $this->recipe('Gyros', ['3 jajka', '2 łyżki przyprawy gyros', '1 pęczek natki pietruszki']);

        $this->hold('Jajko');

        $this->assertSame(IngredientCategory::Spice, $spice->refresh()->category);
        $this->assertSame(IngredientCategory::Herb, $herb->refresh()->category);
        $this->assertSame(['Gyros'], $this->titlesFor('cookable'));
    }

    /**
     * A sauce or a sweetener is something you either have or have to buy, so
     * exempting seasoning must not quietly exempt those as well.
     */
    public function test_a_sauce_still_counts_as_missing(): void
    {
        $this->recipe('Stir fry', ['3 jajka', '2 łyżki sosu sojowego']);

        $this->hold('Jajko');

        $this->assertSame([], $this->titlesFor('cookable'));
        $this->assertSame(['Stir fry'], $this->titlesFor('almost'));
    }

    /**
     * We cannot claim to have something we cannot name, so an unresolved line
     * has to keep a recipe out of the "I have everything" list.
     */
    public function test_a_line_the_importer_never_understood_still_counts(): void
    {
        $this->recipe('Zagadka', ['3 jajka', 'coś czego nikt nie rozpozna 123']);

        $this->hold('Jajko');

        $this->assertSame([], $this->titlesFor('cookable'));
    }

    public function test_the_chip_counts_match_what_the_filter_returns(): void
    {
        $this->recipe('Jajecznica', ['3 jajka', '20 g masła']);
        $this->recipe('Omlet', ['3 jajka', '20 g masła', '50 g sera żółtego']);

        $this->hold('Jajko');
        $this->hold('Masło');

        $this->actingAs($this->user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('counts.cookable', 1)
                ->where('counts.almost', 1)
                ->where('hasPantry', true));
    }

    /**
     * A chip reading zero looks like a fault rather than an invitation, so until
     * there is a kitchen to compare against it is not offered at all.
     */
    public function test_the_chips_stay_hidden_until_the_kitchen_holds_something(): void
    {
        $this->recipe('Jajecznica', ['3 jajka']);

        $this->actingAs($this->user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('hasPantry', false));
    }

    /**
     * The card badge is what makes the chip checkable — a filter nobody can
     * verify is a filter nobody trusts.
     */
    public function test_the_card_reports_its_own_shortfall(): void
    {
        $this->recipe('Jajecznica', ['3 jajka', '20 g masła']);

        $this->hold('Jajko');

        $this->actingAs($this->user)
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('recipes.0.missing', 1));
    }

    /**
     * Opening a recipe answers for every line, in both directions: the cook wants
     * to know what to fetch as much as what is already on the shelf.
     */
    public function test_the_detail_marks_what_you_have_and_what_you_lack(): void
    {
        $this->recipe('Jajecznica', ['3 jajka', '20 g masła']);

        $this->hold('Jajko');

        $this->actingAs($this->user)
            ->get(route('recipes.show', 'jajecznica'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('recipe.hasPantry', true)
                ->where('recipe.ingredients.0.status', 'held')
                ->where('recipe.ingredients.1.status', 'absent'));
    }

    /**
     * Some of it, but not enough of it, is a third answer — and it has to be the
     * same judgement the shopping list makes, or a line can read "masz" while the
     * trolley quietly asks for more.
     */
    public function test_too_little_of_a_product_is_its_own_answer(): void
    {
        $this->recipe('Jajecznica', ['3 jajka']);

        $this->hold('Jajko', 1.0, 'piece');

        $this->actingAs($this->user)
            ->get(route('recipes.show', 'jajecznica'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('recipe.ingredients.0.status', 'not_enough'));
    }

    /**
     * Seasoning is never bought by the shopping list, so it must not be flagged
     * like something you have to fetch — but staying silent about it would leave
     * a hole in a column of answers. It gets its own, quieter one.
     */
    public function test_seasoning_nobody_wrote_down_is_assumed_rather_than_missing(): void
    {
        $this->recipe('Jajecznica', ['3 jajka', '1 łyżeczka soli']);

        $this->hold('Jajko');

        $this->actingAs($this->user)
            ->get(route('recipes.show', 'jajecznica'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->where('recipe.ingredients.1.status', 'assumed'));
    }

    /**
     * An empty kitchen has nothing to say, and saying "nie masz" forty times is
     * not information. The markers stay off until there is something to check.
     */
    public function test_an_empty_kitchen_reports_no_answers_at_all(): void
    {
        $this->recipe('Jajecznica', ['3 jajka']);

        $this->actingAs($this->user)
            ->get(route('recipes.show', 'jajecznica'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page->where('recipe.hasPantry', false));
    }

    /**
     * The aggregate spells its category list out as three SQL placeholders, so a
     * fourth seasoning category would bind silently wrong. This is that alarm.
     */
    public function test_the_seasoning_rule_matches_the_query(): void
    {
        $this->assertCount(
            3,
            IngredientCategory::assumedAtHand(),
            'RecipeAvailability::missingCounts() hardcodes three placeholders — update it with this.',
        );
    }

    /**
     * @return list<string>
     */
    private function titlesFor(string $filter): array
    {
        $titles = [];

        $this->actingAs($this->user)
            ->get(route('home', ['filter' => $filter]))
            ->assertOk()
            ->assertInertia(function ($page) use (&$titles): void {
                foreach ($page->toArray()['props']['recipes'] as $recipe) {
                    $titles[] = $recipe['title'];
                }
            });

        sort($titles);

        return $titles;
    }

    /**
     * No amount is the usual case and means "some, amount unknown" — never none.
     */
    private function hold(string $name, ?float $quantity = null, ?string $unitCode = null): void
    {
        PantryItem::query()->create([
            'user_id' => $this->user->id,
            'ingredient_id' => $this->ingredient($name)->id,
            'location' => StorageLocation::Fridge,
            'quantity' => $quantity,
            'unit_id' => $unitCode === null
                ? null
                : Unit::query()->where('code', $unitCode)->firstOrFail()->id,
        ]);
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::query()->where('name', $name)->firstOrFail();
    }

    /**
     * @param  list<string>  $lines
     */
    private function recipe(string $title, array $lines): void
    {
        $this->app->make(StoreRecipeDraft::class)->store(
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
