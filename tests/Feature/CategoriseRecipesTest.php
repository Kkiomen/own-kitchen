<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Catalogue\CategoriseRecipes;
use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\StoreRecipeDraft;
use App\Models\Category;
use App\Models\Recipe;
use App\Models\User;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The quick-pick row is derived, not imported, so these rules are the only thing
 * standing between "tap Kurczak" and a list of desserts.
 */
class CategoriseRecipesTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);
    }

    public function test_a_recipe_is_placed_by_its_ingredients(): void
    {
        $this->store('Cokolwiek na obiad', ['400 g piersi z kurczaka', '1 cebula']);

        $this->categorise();

        $this->assertContains('kurczak', $this->categoriesOf('cokolwiek-na-obiad'));
    }

    public function test_a_recipe_is_placed_by_its_title_when_the_ingredients_are_silent(): void
    {
        $this->store('Zupa krem z dyni', ['500 g dyni', '1 cebula']);

        $this->categorise();

        $this->assertContains('zupy', $this->categoriesOf('zupa-krem-z-dyni'));
    }

    /**
     * A chicken soup belongs in both, and forcing a single home would make one of
     * the two buttons lie.
     */
    public function test_a_recipe_may_belong_to_several_categories(): void
    {
        $this->store('Rosół z kurczaka', ['1 kg piersi z kurczaka', '2 marchewki']);

        $this->categorise();

        $categories = $this->categoriesOf('rosol-z-kurczaka');

        $this->assertContains('kurczak', $categories);
        $this->assertContains('zupy', $categories);
    }

    /**
     * "zupełnie" contains "zupe". A plain substring search put this recipe in
     * Soups, and "łodyga" would have put a celery salad in Desserts — hence the
     * whole-word matching this pins down.
     */
    public function test_a_word_hiding_inside_another_word_does_not_match(): void
    {
        $this->store('Coś zupełnie osobliwego', ['1 szczypta soli']);

        $this->categorise();

        $this->assertNotContains('zupy', $this->categoriesOf('cos-zupelnie-osobliwego'));
    }

    /**
     * Claiming a dish is meat-free while holding an ingredient nobody has
     * curated is the one mistake here with real consequences.
     */
    public function test_an_unrecognised_ingredient_blocks_the_vegetarian_claim(): void
    {
        $this->store('Danie z czymś nieznanym', ['200 g wynalazku bez nazwy']);
        $this->store('Danie ze znanych rzeczy', ['200 g cukinii', '1 cebula']);

        $this->categorise();

        $this->assertNotContains('wege', $this->categoriesOf('danie-z-czyms-nieznanym'));
        $this->assertContains('wege', $this->categoriesOf('danie-ze-znanych-rzeczy'));
    }

    public function test_meat_keeps_a_recipe_out_of_the_vegetarian_category(): void
    {
        $this->store('Obiad z mięsem', ['400 g piersi z kurczaka', '1 cebula']);

        $this->categorise();

        $this->assertNotContains('wege', $this->categoriesOf('obiad-z-miesem'));
    }

    /**
     * Some animal products do not live in an animal category, and the category
     * check alone let them through: lard is a Fat and gelatine is a Baking
     * ingredient. Eighteen recipes were claiming to be vegetarian on the
     * strength of that.
     */
    public function test_an_animal_product_outside_the_meat_categories_still_blocks_it(): void
    {
        $this->store('Pierogi na smalcu', ['2 łyżki smalcu', '1 cebula']);
        $this->store('Galaretka', ['20 g żelatyny', '200 g malin']);
        $this->store('Sałatka', ['1 ogórek', '1 cebula']);

        $this->categorise();

        $this->assertNotContains('wege', $this->categoriesOf('pierogi-na-smalcu'));
        $this->assertNotContains('wege', $this->categoriesOf('galaretka'));
        $this->assertContains('wege', $this->categoriesOf('salatka'));
    }

    /**
     * Re-running must replace, not accumulate: a recipe that no longer matches a
     * rule has to be released, or the counts drift upward for ever.
     */
    public function test_running_it_again_replaces_rather_than_accumulates(): void
    {
        $this->store('Zupa krem z dyni', ['500 g dyni']);

        $this->categorise();
        $first = $this->categoriesOf('zupa-krem-z-dyni');

        $this->categorise();

        $this->assertSame($first, $this->categoriesOf('zupa-krem-z-dyni'));
    }

    public function test_the_list_page_offers_the_categories_with_their_counts(): void
    {
        $this->store('Kurczak w sosie', ['400 g piersi z kurczaka']);
        $this->categorise();

        $this->actingAs(User::factory()->create())
            ->get(route('home'))
            ->assertOk()
            ->assertInertia(fn ($page) => $page
                ->has('categories')
                ->where('recipes.0.categories', ['kurczak']));
    }

    /**
     * @return array<string, int>
     */
    private function categorise(): array
    {
        return $this->app->make(CategoriseRecipes::class)->run();
    }

    /**
     * @return list<string>
     */
    private function categoriesOf(string $slug): array
    {
        $recipe = Recipe::query()->where('slug', $slug)->firstOrFail();

        return $recipe->categories()
            ->orderBy('slug')
            ->pluck('slug')
            ->all();
    }

    /**
     * @param  list<string>  $lines
     */
    private function store(string $title, array $lines): void
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

    protected function tearDown(): void
    {
        Category::query()->delete();

        parent::tearDown();
    }
}
