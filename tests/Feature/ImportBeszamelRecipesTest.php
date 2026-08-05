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
 * The fourth source, end to end. What is worth asserting here is the one thing that
 * makes it different: its ingredients come from the page's markup because its
 * JSON-LD publishes them glued into a single string.
 */
class ImportBeszamelRecipesTest extends TestCase
{
    use RefreshDatabase;

    private const string BASE = 'https://beszamel.se.pl';

    private const string SLUG = 'fasola-z-airfryera';

    private const string URL = self::BASE.'/przepisy/warzywa-na-cieplo/'.self::SLUG.'.html';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);
    }

    public function test_it_imports_a_recipe_with_its_ingredients_and_steps(): void
    {
        $this->fakeSite();

        $summary = $this->import();

        $this->assertSame(1, $summary->imported);
        $this->assertSame(0, $summary->failed);

        $recipe = Recipe::query()->where('slug', self::SLUG)->firstOrFail();

        $this->assertSame('beszamel.se.pl', $recipe->source_name);
        $this->assertSame(15, $recipe->total_time_minutes);
        $this->assertNotEmpty($recipe->steps);
        $this->assertNull($recipe->appliance, 'A general site states no device for the recipe as a whole.');
    }

    /**
     * The site's own JSON-LD would give one ingredient a thousand characters long.
     * Anything less than several separate lines means the markup parser stopped
     * being used.
     */
    public function test_the_ingredients_are_separate_lines_not_one_glued_string(): void
    {
        $this->fakeSite();
        $this->import();

        $recipe = Recipe::query()->where('slug', self::SLUG)->firstOrFail();

        $this->assertGreaterThan(5, $recipe->ingredients->count());

        $first = $recipe->ingredients()->where('position', 0)->firstOrFail();
        $this->assertSame('2 puszki fasoli (czerwona, biała lub czarna), odsączone i opłukane', $first->raw_text);
        $this->assertSame('Fasola', $first->ingredient?->name);

        foreach ($recipe->ingredients as $line) {
            $this->assertLessThan(200, mb_strlen($line->raw_text), 'A line this long is the glued blob.');
        }
    }

    /**
     * A recipe whose ingredients cannot be recovered has a method and nothing to
     * cook it from, which is worse than not importing it.
     */
    public function test_a_recipe_without_a_readable_ingredient_list_is_rejected(): void
    {
        $withoutIngredients = preg_replace(
            '/<div class="ingredients__items".*?<\/div>/s',
            '',
            (string) file_get_contents(__DIR__.'/../Fixtures/beszamel/fasola-z-airfryera.html'),
        ) ?? '';

        $this->fakeSite(recipeHtml: $withoutIngredients);

        $summary = $this->import();

        $this->assertSame(0, $summary->imported);
        $this->assertSame(1, $summary->failed);
    }

    /**
     * The site's own hub page answers every ?page with the same links. A walk that
     * only stops on an empty page therefore asks it 150 times for nothing, which is
     * both useless to us and rude to the site.
     */
    public function test_a_listing_that_ignores_its_page_parameter_is_walked_once(): void
    {
        $listing = '<html><body><a href="'.self::URL.'">Fasola</a></body></html>';
        $fetcher = new FakePageFetcher([
            self::BASE.'/przepisy/warzywa-na-cieplo/' => $listing,
            // Every later page repeats page one, exactly as the real hub does.
            ...array_reduce(
                range(2, 20),
                static fn (array $pages, int $page): array => [
                    ...$pages,
                    self::BASE.'/przepisy/warzywa-na-cieplo/?page='.$page => $listing,
                ],
                [],
            ),
            self::URL => (string) file_get_contents(__DIR__.'/../Fixtures/beszamel/fasola-z-airfryera.html'),
        ]);

        config()->set('importing.sources.beszamel.listings', ['/przepisy/warzywa-na-cieplo/']);
        $this->app->instance(PageFetcher::class, $fetcher);

        $references = iterator_to_array($this->app->make(RecipeSourceRegistry::class)
            ->get('beszamel')
            ->discover(50));

        $this->assertCount(1, $references, 'The repeated page must not be yielded again.');
        $this->assertLessThanOrEqual(
            3,
            count($fetcher->requestedUrls),
            'A listing that stops producing new recipes must drop out of the walk.',
        );
    }

    private function fakeSite(?string $recipeHtml = null): void
    {
        $listing = '<html><body><a href="'.self::URL.'">Fasola</a>'
            .'<a href="'.self::BASE.'/przepisy/warzywa-na-cieplo/">Warzywa</a></body></html>';

        config()->set('importing.sources.beszamel.listings', ['/przepisy/warzywa-na-cieplo/']);
        $this->app->instance(PageFetcher::class, new FakePageFetcher([
            self::BASE.'/przepisy/warzywa-na-cieplo/' => $listing,
            self::URL => $recipeHtml ?? (string) file_get_contents(
                __DIR__.'/../Fixtures/beszamel/fasola-z-airfryera.html'
            ),
        ]));
    }

    private function import(): ImportSummary
    {
        return $this->app->make(ImportRecipes::class)->run(
            source: $this->app->make(RecipeSourceRegistry::class)->get('beszamel'),
            limit: 10,
        );
    }
}
