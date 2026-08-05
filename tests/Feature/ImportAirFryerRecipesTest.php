<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Appliance;
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
 * The second source, end to end. It shares the whole pipeline with kwestiasmaku.com
 * and differs only in the adapter, which is the point of the ports-and-adapters
 * layout — these assertions are about the wiring, not about parsing again.
 */
class ImportAirFryerRecipesTest extends TestCase
{
    use RefreshDatabase;

    private const string BASE = 'https://airfryerprzepisy.pl';

    private const string SLUG = 'gulasz-wieprzowy-z-airfryera';

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

        $this->assertSame('Gulasz wieprzowy z AirFryera', $recipe->title);
        $this->assertSame('airfryerprzepisy.pl', $recipe->source_name);
        $this->assertSame(4, $recipe->servings);
        $this->assertSame(38, $recipe->total_time_minutes);
        $this->assertCount(18, $recipe->ingredients);
        $this->assertCount(15, $recipe->steps);
    }

    /**
     * The device is the reason to import this site at all: an air fryer recipe is
     * not an oven recipe with a shorter time, so it has to be findable as such.
     */
    public function test_every_recipe_from_the_site_is_marked_as_an_air_fryer_recipe(): void
    {
        $this->fakeSite([self::SLUG]);
        $this->import();

        $this->assertSame(
            Appliance::AirFryer,
            Recipe::query()->where('slug', self::SLUG)->firstOrFail()->appliance,
        );
    }

    public function test_it_resolves_lines_to_the_same_products_the_other_source_uses(): void
    {
        $this->fakeSite([self::SLUG]);
        $this->import();

        $line = Recipe::query()->where('slug', self::SLUG)->firstOrFail()
            ->ingredients()->where('position', 4)->firstOrFail();

        $this->assertSame(2.0, $line->quantity);
        $this->assertSame('Czosnek', $line->ingredient?->name);
        $this->assertSame('2 ząbki czosnku', $line->raw_text);
    }

    public function test_a_step_names_the_air_fryer_so_the_guided_screen_shows_it(): void
    {
        $this->fakeSite([self::SLUG]);
        $this->import();

        $steps = Recipe::query()->where('slug', self::SLUG)->firstOrFail()->steps;

        $this->assertTrue(
            $steps->contains(fn ($step) => $step->appliance === Appliance::AirFryer),
            'No step was recognised as happening in the air fryer.',
        );
    }

    /**
     * The archive links to category pages as well as recipes; fetching one as a
     * recipe would fail on every run.
     */
    public function test_it_does_not_try_to_import_the_category_pages(): void
    {
        $this->fakeSite([self::SLUG]);

        $this->assertSame(0, $this->import()->failed);
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
        $articles = implode('', array_map(
            static fn (string $slug): string => '<article class="entry loop-entry post">'
                .'<h2 class="entry-title"><a href="'.self::BASE.'/'.$slug.'/">'.$slug.'</a></h2>'
                // The real loop links to the recipe's categories too.
                .'<div class="entry-taxonomies"><a href="'.self::BASE.'/obiady/">Obiady</a></div>'
                .'</article>',
            [...$slugs, ...$advertisedButMissing],
        ));

        $pages = [self::BASE.'/przepisy/' => '<html><body>'.$articles.'</body></html>'];

        foreach ($slugs as $slug) {
            $pages[self::BASE.'/'.$slug.'/'] = (string) file_get_contents(
                __DIR__."/../Fixtures/airfryerprzepisy/{$slug}.html"
            );
        }

        $this->app->instance(PageFetcher::class, new FakePageFetcher($pages));
    }

    private function import(): ImportSummary
    {
        return $this->app->make(ImportRecipes::class)->run(
            source: $this->app->make(RecipeSourceRegistry::class)->get('airfryerprzepisy'),
            limit: 10,
        );
    }
}
