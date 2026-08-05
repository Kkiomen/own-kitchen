<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Importing\ImportRecipes;
use App\Importing\ImportSummary;
use App\Importing\RecipeSourceRegistry;
use App\Models\Recipe;
use App\Support\Http\PageFetcher;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePageFetcher;
use Tests\TestCase;

/**
 * The meal prep source, end to end. It shares the whole pipeline with the other two
 * sites and differs only in the adapter, so these assertions are about the wiring
 * and the meal prep flag rather than about parsing again.
 */
class ImportMealPrepRecipesTest extends TestCase
{
    use RefreshDatabase;

    private const string BASE = 'https://centrumrespo.pl';

    private const string SLUG = 'lunchbox-z-pieczonym-falafelem';

    private const string LISTING = '/przepisy/kategoria/lunchbox/';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);
    }

    public function test_it_imports_a_recipe_with_its_ingredients_and_steps(): void
    {
        $this->fakeSite([self::SLUG]);

        $summary = $this->import();

        $this->assertSame(1, $summary->imported);
        $this->assertSame(0, $summary->failed);

        $recipe = Recipe::query()->where('slug', self::SLUG)->firstOrFail();

        $this->assertSame('Lunchbox z pieczonym falafelem', $recipe->title);
        $this->assertSame('centrumrespo.pl', $recipe->source_name);
        $this->assertSame(2, $recipe->servings);
        $this->assertCount(11, $recipe->ingredients);
        $this->assertCount(5, $recipe->steps);
        // The site points at its picture by "@id" rather than repeating the URL.
        $this->assertNotNull($recipe->image_url, 'The recipe imported without its picture.');
    }

    /**
     * The reason to import this site at all: batch-cooked lunchbox dishes have to be
     * findable as such, and the flag has to come from the listing that was walked
     * rather than from a title that happens to say "lunchbox".
     */
    public function test_every_recipe_from_the_meal_prep_listings_is_marked_as_meal_prep(): void
    {
        $this->fakeSite([self::SLUG, 'lunchbox-kurczak-gyros-z-batatami-pomidorkami-i-sosem']);
        $this->import();

        $this->assertSame(2, Recipe::query()->mealPrep()->count());
    }

    public function test_recipes_from_the_other_sources_are_not_marked_as_meal_prep(): void
    {
        $this->fakeSite([self::SLUG]);
        $this->import();

        Recipe::query()->create([
            'slug' => 'gulasz',
            'title' => 'Gulasz',
            'source_name' => 'kwestiasmaku.com',
        ]);

        $this->assertSame([self::SLUG], Recipe::query()->mealPrep()->pluck('slug')->all());
    }

    public function test_it_resolves_lines_to_the_same_products_the_other_sources_use(): void
    {
        $this->fakeSite([self::SLUG]);
        $this->import();

        $line = Recipe::query()->where('slug', self::SLUG)->firstOrFail()
            ->ingredients()->where('position', 1)->firstOrFail();

        $this->assertSame(6.0, $line->quantity);
        $this->assertSame('g', $line->unit?->symbol);
        $this->assertSame('Czosnek', $line->ingredient?->name);
    }

    /**
     * The listing links to the categories it belongs to, and the search box suggests
     * recipes from anywhere on the site. Fetching either as a recipe of this listing
     * would fail, or worse, quietly mark an unrelated dish as meal prep.
     */
    public function test_it_only_follows_the_recipes_in_the_listing_grid(): void
    {
        $this->fakeSite([self::SLUG]);

        $this->import();

        $this->assertSame([self::SLUG], Recipe::query()->pluck('slug')->all());
    }

    /**
     * The "Na wynos" listing is a filter on the archive, so its pages are
     * "/przepisy/page/2/?catFilter=127". Appending the page after the query instead
     * would ask for a URL that does not exist and quietly end the walk one page in,
     * which is most of the catalogue lost without a single failure reported.
     */
    public function test_it_paginates_a_listing_that_is_a_query_string_filter(): void
    {
        $this->app->instance(PageFetcher::class, $fetcher = new FakePageFetcher([
            self::BASE.'/przepisy/?catFilter=127' => $this->grid(['pierwszy']),
            self::BASE.'/przepisy/page/2/?catFilter=127' => $this->grid(['drugi']),
            self::BASE.'/przepisy/'.'pierwszy/' => $this->fixture(self::SLUG),
            self::BASE.'/przepisy/'.'drugi/' => $this->fixture(self::SLUG),
        ]));

        $this->app->make(ImportRecipes::class)->run(
            source: $this->app->make(RecipeSourceRegistry::class)->get('centrumrespo'),
            limit: 10,
        );

        $this->assertContains(self::BASE.'/przepisy/page/2/?catFilter=127', $fetcher->requestedUrls);
        $this->assertSame(['pierwszy', 'drugi'], Recipe::query()->orderBy('id')->pluck('slug')->all());
    }

    public function test_a_page_the_fetcher_will_not_serve_does_not_abort_the_run(): void
    {
        $this->fakeSite([self::SLUG], advertisedButMissing: ['przepis-ktorego-nie-ma']);

        $summary = $this->import();

        $this->assertSame(1, $summary->imported);
        $this->assertSame(1, $summary->failed);
    }

    /**
     * @param  list<string>  $slugs
     * @param  list<string>  $advertisedButMissing
     */
    private function fakeSite(array $slugs, array $advertisedButMissing = []): void
    {
        $pages = [self::BASE.self::LISTING => $this->grid([...$slugs, ...$advertisedButMissing])];

        foreach ($slugs as $slug) {
            $pages[self::BASE.'/przepisy/'.$slug.'/'] = $this->fixture($slug);
        }

        $this->app->instance(PageFetcher::class, new FakePageFetcher($pages));
    }

    /**
     * @param  list<string>  $slugs
     */
    private function grid(array $slugs): string
    {
        $cards = implode('', array_map(
            static fn (string $slug): string => '<div class="single-przepis">'
                .'<a href="'.self::BASE.'/przepisy/'.$slug.'/" class="single-przepis__link">'
                .'<h4 class="single-przepis__title">'.$slug.'</h4></a></div>',
            $slugs,
        ));

        // What the real page wraps around the grid: the category the listing is, and
        // the search box's suggestions of recipes from elsewhere on the site.
        $noise = '<a href="'.self::BASE.'/przepisy/kategoria/piknik/">Piknik</a>'
            .'<a href="'.self::BASE.'/przepisy/owsianka-snickers/" class="search-form__suggestArticleAnchor">Owsianka</a>';

        return '<html><body>'.$noise.$cards.'</body></html>';
    }

    private function fixture(string $slug): string
    {
        return (string) file_get_contents(__DIR__."/../Fixtures/centrumrespo/{$slug}.html");
    }

    private function import(): ImportSummary
    {
        return $this->app->make(ImportRecipes::class)->run(
            source: $this->app->make(RecipeSourceRegistry::class)->get('centrumrespo'),
            limit: 10,
        );
    }
}
