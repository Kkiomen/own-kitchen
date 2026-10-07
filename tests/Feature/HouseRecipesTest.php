<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IngredientSource;
use App\Importing\ImportRecipes;
use App\Importing\RecipeSourceRegistry;
use App\Importing\ReimportRecipes;
use App\Models\Ingredient;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * The plain sides no website publishes — `database/data/house-recipes.php`.
 *
 * They exist to stand beside an obiad, so a line that resolves to nothing, or
 * to a product the importer invented, is a side whose calories the planner
 * cannot count and which it will therefore never offer. Written by hand, these
 * have no excuse for a review queue.
 */
class HouseRecipesTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_house_recipe_imports_with_every_line_understood(): void
    {
        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);

        /** @var list<array{slug: string}> $recipes */
        $recipes = require database_path('data/house-recipes.php');

        $summary = $this->app->make(ImportRecipes::class)->run(
            $this->app->make(RecipeSourceRegistry::class)->get('house'),
            limit: 1000,
        );

        $this->assertSame(0, $summary->failed);
        $this->assertSame(count($recipes), Recipe::query()->where('source_url', 'like', 'house:%')->count());

        $lines = RecipeIngredient::query()->with('ingredient')->get();

        foreach ($lines as $line) {
            $this->assertFalse($line->needs_review, "Needs review: {$line->raw_text}");
            $this->assertSame(
                IngredientSource::Dictionary,
                $line->ingredient?->source,
                "Not a curated product: {$line->raw_text}",
            );
        }
    }

    /**
     * A dictionary fix reaches only the recipes re-imported after it, so the
     * re-import has to find them by what their lines say — and leave the rest.
     */
    public function test_a_targeted_reimport_reaches_exactly_the_recipes_whose_lines_match(): void
    {
        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);

        $this->app->make(ImportRecipes::class)->run($this->app->make(RecipeSourceRegistry::class)->get('house'), limit: 1000);

        $bulgur = RecipeIngredient::query()->where('raw_text', 'like', '%bulgur%')->firstOrFail();
        $rice = RecipeIngredient::query()->where('raw_text', '200 g ryżu')->firstOrFail();
        $wrong = Ingredient::query()->where('name', 'Kasza gryczana')->value('id');

        // As if both had been resolved before the dictionary learnt better.
        RecipeIngredient::query()->whereKey([$bulgur->id, $rice->id])->update(['ingredient_id' => $wrong]);

        $summary = $this->app->make(ReimportRecipes::class)->matching(['BULGUR']);

        $this->assertSame(1, $summary->imported);
        $this->assertSame('Kasza bulgur', RecipeIngredient::query()->where('raw_text', 'like', '%bulgur%')->firstOrFail()->ingredient?->name);
        $this->assertSame($wrong, RecipeIngredient::query()->whereKey($rice->id)->value('ingredient_id'));
    }
}
