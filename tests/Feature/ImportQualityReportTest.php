<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Enums\IngredientSource;
use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\Quality\ImportQualityReport;
use App\Importing\StoreRecipeDraft;
use App\Models\Ingredient;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ImportQualityReportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);
    }

    public function test_it_counts_what_the_importer_understood(): void
    {
        $this->storeRecipe([
            new IngredientLineDraft('300 g cukinii'),
            new IngredientLineDraft('2 jajka'),
            new IngredientLineDraft('świeżo zmielony czarny pieprz'),
        ]);

        $snapshot = $this->app->make(ImportQualityReport::class)->generate();

        $this->assertSame(1, $snapshot->recipes);
        $this->assertSame(3, $snapshot->ingredientLines);
        $this->assertSame(3, $snapshot->linesWithProduct);
        // Pepper legitimately carries no amount.
        $this->assertSame(2, $snapshot->linesWithQuantity);
        $this->assertSame(0, $snapshot->linesNeedingReview);
        $this->assertSame(0.0, $snapshot->reviewPercent());
    }

    public function test_an_unrecognised_line_lands_in_the_review_queue(): void
    {
        $this->storeRecipe([
            new IngredientLineDraft('300 g cukinii'),
            new IngredientLineDraft('20 g wynalazku kulinarnego bez nazwy'),
        ]);

        $snapshot = $this->app->make(ImportQualityReport::class)->generate();

        $this->assertSame(1, $snapshot->linesNeedingReview);
        $this->assertSame(['20 g wynalazku kulinarnego bez nazwy'], $snapshot->unresolvedLines);
        $this->assertSame(['zupa-testowa'], $snapshot->recipesNeedingReview);
    }

    /**
     * The importer registers an alias for every product it invents, so a second
     * pass "recognises" it. The review queue must not shrink because of that —
     * nobody has actually checked the product.
     */
    public function test_re_importing_does_not_quietly_clear_the_review_queue(): void
    {
        $lines = [
            new IngredientLineDraft('300 g cukinii'),
            new IngredientLineDraft('20 g wynalazku kulinarnego bez nazwy'),
        ];

        $this->storeRecipe($lines);
        $this->storeRecipe($lines);

        $snapshot = $this->app->make(ImportQualityReport::class)->generate();

        $this->assertSame(1, $snapshot->linesNeedingReview);
        $this->assertSame(1, $snapshot->productsAwaitingCuration);
    }

    public function test_a_product_added_to_the_dictionary_leaves_the_queue(): void
    {
        $this->storeRecipe([new IngredientLineDraft('20 g wynalazku kulinarnego bez nazwy')]);

        $this->assertSame(1, $this->app->make(ImportQualityReport::class)->generate()->linesNeedingReview);

        // Standing behind the product is exactly what the dictionary entry means.
        Ingredient::query()->awaitingCuration()->update(['source' => IngredientSource::Dictionary]);
        $this->storeRecipe([new IngredientLineDraft('20 g wynalazku kulinarnego bez nazwy')]);

        $snapshot = $this->app->make(ImportQualityReport::class)->generate();

        $this->assertSame(0, $snapshot->linesNeedingReview);
        $this->assertSame(0, $snapshot->productsAwaitingCuration);
    }

    public function test_percentages_survive_an_empty_database(): void
    {
        $snapshot = $this->app->make(ImportQualityReport::class)->generate();

        $this->assertSame(0.0, $snapshot->reviewPercent());
        $this->assertSame(0.0, $snapshot->percentOfSteps(0));
    }

    public function test_the_command_can_fail_a_run_whose_quality_dropped(): void
    {
        $this->storeRecipe([
            new IngredientLineDraft('300 g cukinii'),
            new IngredientLineDraft('20 g wynalazku kulinarnego bez nazwy'),
        ]);

        // Half the lines are unrecognised, which is far over the threshold.
        $this->artisan('recipes:quality --fail-over=5')->assertFailed();
        $this->artisan('recipes:quality --fail-over=90')->assertSuccessful();
    }

    /**
     * @param  list<IngredientLineDraft>  $lines
     */
    private function storeRecipe(array $lines): void
    {
        $this->app->make(StoreRecipeDraft::class)->store(
            new RecipeDraft(
                slug: 'zupa-testowa',
                title: 'Zupa testowa',
                sourceUrl: 'https://example.test/przepis/zupa-testowa',
                ingredientLines: $lines,
                steps: [new StepDraft('Cukinię pokroić i gotować przez 10 minut.')],
            ),
            'example.test',
        );
    }
}
