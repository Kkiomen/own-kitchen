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
use App\Models\User;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

/**
 * "Co ugotuję z tego, co się psuje."
 *
 * The kitchen has recorded use-by dates from the start and done nothing with
 * them. The rules that make this a suggestion rather than a search are what
 * these tests pin: only what has a date, only what could actually be cooked
 * tonight, and the product named on the card so the answer can be checked.
 */
class ExpiringSoonTest extends TestCase
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

    public function test_a_recipe_using_something_about_to_go_off_is_offered(): void
    {
        $this->recipe('Omlet', ['2 jajka']);
        $this->hold('Jajko', expiresInDays: 1);

        $this->assertSame(['Omlet'], $this->titlesFor('expiring'));
    }

    /**
     * The rule that turns this from a search into a suggestion. A carton of milk
     * going off on Thursday appears in two thousand recipes; the ones needing a
     * shop first do not answer "co ugotuję".
     */
    public function test_a_recipe_needing_a_shopping_trip_first_is_not_offered(): void
    {
        // Three products beyond the egg, none of them in the kitchen and none
        // of them seasoning, so the shortfall is over the "brakuje 1–2" line.
        $this->recipe('Zapiekanka', ['2 jajka', '200 g makaronu', '100 g szynki', '150 g sera żółtego']);
        $this->hold('Jajko', expiresInDays: 1);

        $this->assertSame([], $this->titlesFor('expiring'));
    }

    /** Most of the kitchen has no date on it, and unknown is not urgent. */
    public function test_an_entry_with_no_use_by_date_is_not_expiring(): void
    {
        $this->recipe('Omlet', ['2 jajka']);
        $this->hold('Jajko', expiresInDays: null);

        $this->assertSame([], $this->titlesFor('expiring'));
        $this->assertSame(0, $this->counts()['expiring']);
    }

    public function test_something_going_off_next_month_is_not_expiring(): void
    {
        $this->recipe('Omlet', ['2 jajka']);
        $this->hold('Jajko', expiresInDays: 30);

        $this->assertSame([], $this->titlesFor('expiring'));
    }

    /** Already past its date is the most urgent case, not an excluded one. */
    public function test_something_already_out_of_date_still_counts(): void
    {
        $this->recipe('Omlet', ['2 jajka']);
        $this->hold('Jajko', expiresInDays: -1);

        $this->assertSame(['Omlet'], $this->titlesFor('expiring'));
    }

    /**
     * An optional line is not a reason to cook a dish, and the shortfall
     * aggregate does not count one either — the two rules have to agree.
     */
    public function test_an_optional_line_is_not_a_reason_to_cook_a_dish(): void
    {
        $this->recipe('Sałatka', ['200 g makaronu', '2 jajka (opcjonalnie)']);
        $this->hold('Jajko', expiresInDays: 1);

        $this->assertSame([], $this->titlesFor('expiring'));
    }

    /** The card has to say *what* is running out, or the badge is a puzzle. */
    public function test_the_card_names_the_product_that_is_running_out(): void
    {
        $this->recipe('Omlet', ['2 jajka']);
        $this->hold('Jajko', expiresInDays: 1);

        $this->actingAs($this->user)
            ->get(route('home', ['filter' => 'expiring']))
            ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
                ->where('recipes.0.expiring', ['Jajko']),
            );
    }

    /** A chip reading zero is a dead button; the screen hides it instead. */
    public function test_the_count_is_zero_when_nothing_is_going_off(): void
    {
        $this->recipe('Omlet', ['2 jajka']);
        $this->hold('Jajko', expiresInDays: null);

        $this->assertSame(0, $this->counts()['expiring']);
    }

    public function test_the_count_matches_what_the_filter_returns(): void
    {
        $this->recipe('Omlet', ['2 jajka']);
        $this->recipe('Jajecznica', ['3 jajka']);
        $this->recipe('Kluski', ['200 g makaronu']);
        $this->hold('Jajko', expiresInDays: 2);

        $this->assertSame(2, $this->counts()['expiring']);
        $this->assertSame(['Jajecznica', 'Omlet'], $this->titlesFor('expiring'));
    }

    /**
     * @return list<string>
     */
    private function titlesFor(string $filter): array
    {
        $titles = [];

        $this->actingAs($this->user)
            ->get(route('home', ['filter' => $filter]))
            ->assertInertia(function (AssertableInertia $page) use (&$titles): void {
                /** @var list<array{title: string}> $recipes */
                $recipes = $page->toArray()['props']['recipes'];

                foreach ($recipes as $recipe) {
                    $titles[] = $recipe['title'];
                }
            });

        return $titles;
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];

        $this->actingAs($this->user)
            ->get(route('home'))
            ->assertInertia(function (AssertableInertia $page) use (&$counts): void {
                /** @var array<string, int> $counts */
                $counts = $page->toArray()['props']['counts'];
            });

        return $counts;
    }

    private function hold(string $name, ?int $expiresInDays): void
    {
        PantryItem::query()->create([
            'user_id' => $this->user->id,
            'ingredient_id' => $this->ingredient($name)->id,
            'location' => StorageLocation::Fridge,
            'expires_at' => $expiresInDays === null ? null : now()->addDays($expiresInDays),
        ]);
    }

    private function ingredient(string $name): Ingredient
    {
        return Ingredient::query()->where('name', $name)->firstOrFail();
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
                steps: [new StepDraft('Wymieszać.')],
                servings: 2,
            ),
            'example.test',
        );
    }
}
