<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\Appliance;
use App\Enums\StepAction;
use App\Importing\ImportRecipes;
use App\Importing\ImportSummary;
use App\Importing\RecipeSourceRegistry;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Support\Http\PageFetcher;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\FakePageFetcher;
use Tests\TestCase;

class ImportRecipesTest extends TestCase
{
    use RefreshDatabase;

    private const string BASE = 'https://www.kwestiasmaku.com';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);
    }

    public function test_it_imports_a_recipe_with_its_ingredients_and_steps(): void
    {
        $this->fakeSite(['carbonara-z-kurkami']);

        $summary = $this->import();

        $this->assertSame(1, $summary->imported);
        $this->assertSame(0, $summary->failed);

        $recipe = Recipe::query()->where('slug', 'carbonara-z-kurkami')->firstOrFail();

        $this->assertSame('Carbonara z kurkami', $recipe->title);
        $this->assertSame('kwestiasmaku.com', $recipe->source_name);
        $this->assertSame(2, $recipe->servings);
        $this->assertCount(9, $recipe->ingredients);
        $this->assertCount(10, $recipe->steps);
    }

    public function test_it_splits_a_line_into_an_amount_a_unit_and_a_canonical_product(): void
    {
        $this->fakeSite(['carbonara-z-kurkami']);
        $this->import();

        $line = Recipe::query()->where('slug', 'carbonara-z-kurkami')->firstOrFail()
            ->ingredients()->where('position', 0)->firstOrFail();

        $this->assertSame(150.0, $line->quantity);
        $this->assertSame('g', $line->unit?->code);
        $this->assertSame('Makaron spaghetti', $line->ingredient?->name);
        // The original wording survives, so parsing can be redone later.
        $this->assertSame('150 g makaronu spaghetti', $line->raw_text);
    }

    public function test_declined_spellings_resolve_to_one_product(): void
    {
        $this->fakeSite(['carbonara-z-kurkami', 'sernik-lotus']);
        $this->import();

        // "2 jajka" in one recipe and "4 jajka" in the other are the same product.
        $this->assertSame(1, Ingredient::query()->where('name', 'Jajko')->count());

        $eggs = Ingredient::query()->where('name', 'Jajko')->firstOrFail();
        $this->assertSame(2, RecipeIngredient::query()->where('ingredient_id', $eggs->id)->count());
    }

    public function test_it_records_what_a_step_needs_so_it_can_be_shown_on_its_own_screen(): void
    {
        $this->fakeSite(['carbonara-z-kurkami']);
        $this->import();

        $recipe = Recipe::query()->where('slug', 'carbonara-z-kurkami')->firstOrFail();
        $fryingStep = $recipe->steps()->where('position', 4)->firstOrFail();

        $this->assertSame(StepAction::Add, $fryingStep->action);
        $this->assertSame(Appliance::Pan, $fryingStep->appliance);

        $names = $fryingStep->ingredients->map(fn ($line) => $line->ingredient?->name)->all();
        $this->assertContains('Boczek', $names);
    }

    public function test_it_captures_temperature_and_duration_for_the_timer(): void
    {
        $this->fakeSite(['sernik-lotus']);
        $this->import();

        $recipe = Recipe::query()->where('slug', 'sernik-lotus')->firstOrFail();

        $this->assertSame(160, $recipe->steps()->whereNotNull('temperature_celsius')->first()?->temperature_celsius);
        $this->assertSame(3600, $recipe->steps()->where('position', 6)->firstOrFail()->duration_seconds);
    }

    public function test_it_keeps_the_sections_of_a_multi_part_recipe(): void
    {
        $this->fakeSite(['sernik-lotus']);
        $this->import();

        $recipe = Recipe::query()->where('slug', 'sernik-lotus')->firstOrFail();

        $this->assertSame('Spód', $recipe->ingredients()->where('position', 0)->firstOrFail()->section);
        $this->assertSame(
            ['Spód', 'Masa serowa', 'Polewa', 'Dekoracja'],
            $recipe->ingredients->pluck('section')->unique()->values()->all(),
        );
    }

    public function test_a_second_run_does_not_duplicate_anything(): void
    {
        $this->fakeSite(['carbonara-z-kurkami']);

        $this->import();
        $summary = $this->import();

        $this->assertSame(0, $summary->imported);
        $this->assertSame(1, $summary->skipped);
        $this->assertSame(1, Recipe::query()->count());
    }

    public function test_an_unreachable_page_does_not_abort_the_run(): void
    {
        // The listing advertises a recipe the fetcher will not serve.
        $this->fakeSite(['carbonara-z-kurkami'], advertisedButMissing: ['przepis-ktorego-nie-ma']);

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
        $listed = [...$slugs, ...$advertisedButMissing];
        $links = implode('', array_map(
            static fn (string $slug): string => '<a href="/przepis/'.$slug.'">'.$slug.'</a>',
            $listed,
        ));

        $pages = [self::BASE.'/dania_dla_dwojga/przepisy.html' => '<html><body>'.$links.'</body></html>'];

        foreach ($slugs as $slug) {
            $pages[self::BASE.'/przepis/'.$slug] = (string) file_get_contents(
                __DIR__."/../Fixtures/kwestiasmaku/{$slug}.html"
            );
        }

        config()->set('importing.sources.kwestiasmaku.listings', ['/dania_dla_dwojga/przepisy.html']);
        $this->app->instance(PageFetcher::class, new FakePageFetcher($pages));
    }

    private function import(): ImportSummary
    {
        return $this->app->make(ImportRecipes::class)->run(
            source: $this->app->make(RecipeSourceRegistry::class)->get('kwestiasmaku'),
            limit: 10,
        );
    }
}
