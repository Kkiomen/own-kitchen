<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\StoreRecipeDraft;
use App\Models\Recipe;
use Database\Seeders\IngredientSeeder;
use Database\Seeders\UnitSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Covers which ingredient lines a step is credited with, which is what the
 * guided-cooking screen renders as "ADD 150 g of X".
 */
class StepIngredientLinkingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(UnitSeeder::class);
        $this->seed(IngredientSeeder::class);
    }

    public function test_a_step_is_credited_with_the_products_it_names(): void
    {
        $recipe = $this->store(
            lines: [new IngredientLineDraft('2 cebule'), new IngredientLineDraft('300 g cukinii')],
            steps: [new StepDraft('Cebulę zeszklić na patelni.')],
        );

        $this->assertSame(['Cebula'], $this->productsOfStep($recipe, 0));
    }

    /**
     * A recipe can list butter twice — for the soup and for the garlic bread. The
     * step text says "butter" once, so the section decides which line is meant.
     */
    public function test_the_line_from_the_steps_own_section_wins(): void
    {
        $recipe = $this->store(
            lines: [
                new IngredientLineDraft('50 g masła', 'Zupa'),
                new IngredientLineDraft('30 g masła', 'Grzanki'),
            ],
            steps: [new StepDraft('Roztopić masło.', 'Grzanki')],
        );

        $line = Recipe::query()->findOrFail($recipe->id)->steps()->firstOrFail()->ingredients->firstOrFail();

        $this->assertSame('Grzanki', $line->section);
        $this->assertSame(30.0, $line->quantity);
    }

    public function test_the_same_product_is_never_credited_twice_to_one_step(): void
    {
        $recipe = $this->store(
            lines: [
                new IngredientLineDraft('50 g masła', 'Zupa'),
                new IngredientLineDraft('30 g masła', 'Grzanki'),
            ],
            steps: [new StepDraft('Roztopić masło.', 'Zupa')],
        );

        $this->assertCount(1, Recipe::query()->findOrFail($recipe->id)->steps()->firstOrFail()->ingredients);
    }

    /**
     * The line says "makaron orzo", the step just says "makaron".
     */
    public function test_a_general_word_reaches_the_specific_product(): void
    {
        $recipe = $this->store(
            lines: [new IngredientLineDraft('200 g makaronu orzo')],
            steps: [new StepDraft('Wsypać suchy makaron i smażyć minutę.')],
        );

        $this->assertSame(['Makaron orzo'], $this->productsOfStep($recipe, 0));
    }

    public function test_a_general_word_is_ignored_when_it_could_mean_two_products(): void
    {
        $recipe = $this->store(
            lines: [
                new IngredientLineDraft('200 g makaronu orzo'),
                new IngredientLineDraft('100 g makaronu ryżowego'),
            ],
            steps: [new StepDraft('Wsypać suchy makaron i smażyć minutę.')],
        );

        $this->assertSame([], $this->productsOfStep($recipe, 0));
    }

    /**
     * "listek" measures basil leaves but is part of the name of a bay leaf. The
     * resolver has to be able to put the word back and try again.
     */
    public function test_a_measure_word_that_is_really_part_of_the_name_still_resolves(): void
    {
        $recipe = $this->store(
            lines: [new IngredientLineDraft('1 listek laurowy')],
            steps: [new StepDraft('Dodać listek laurowy.')],
        );

        $line = Recipe::query()->findOrFail($recipe->id)->ingredients()->firstOrFail();

        $this->assertSame('Liść laurowy', $line->ingredient?->name);
        $this->assertFalse($line->needs_review);
        // Not "one leaf of laurowy": the amount is a plain count of the product.
        $this->assertSame('piece', $line->unit?->code);
        $this->assertSame(1.0, $line->quantity);
    }

    public function test_a_measure_word_is_still_a_measure_when_the_name_stands_alone(): void
    {
        $recipe = $this->store(
            lines: [new IngredientLineDraft('2 ząbki czosnku')],
            steps: [new StepDraft('Dodać czosnek.')],
        );

        $line = Recipe::query()->findOrFail($recipe->id)->ingredients()->firstOrFail();

        $this->assertSame('Czosnek', $line->ingredient?->name);
        $this->assertSame('clove', $line->unit?->code);
        $this->assertSame(2.0, $line->quantity);
    }

    /**
     * The line reads "śmietanki 30%", the step says "wlać śmietankę". Both sides
     * have to be reduced to a dictionary form before they can meet.
     */
    public function test_a_product_declined_differently_in_the_step_still_matches(): void
    {
        $recipe = $this->store(
            lines: [new IngredientLineDraft('125 ml śmietanki 30%')],
            steps: [new StepDraft('Wlać śmietankę, wymieszać i zagotować.')],
        );

        $this->assertSame(['Śmietanka 30%'], $this->productsOfStep($recipe, 0));
    }

    public function test_flour_in_the_accusative_still_matches(): void
    {
        $recipe = $this->store(
            lines: [new IngredientLineDraft('3 łyżki mąki pszennej')],
            steps: [new StepDraft('Dodać mąkę i miksować przez pół minuty.')],
        );

        $this->assertSame(['Mąka pszenna'], $this->productsOfStep($recipe, 0));
    }

    /**
     * Inflection must not blur products into each other: "mąka" and "makaron"
     * share a beginning but are different things.
     */
    public function test_similar_looking_products_are_not_confused(): void
    {
        $recipe = $this->store(
            lines: [new IngredientLineDraft('200 g makaronu orzo')],
            steps: [new StepDraft('Dodać mąkę i wymieszać.')],
        );

        $this->assertSame([], $this->productsOfStep($recipe, 0));
    }

    public function test_a_step_naming_nothing_is_credited_with_nothing(): void
    {
        $recipe = $this->store(
            lines: [new IngredientLineDraft('2 cebule')],
            steps: [new StepDraft('Piekarnik nagrzać do 180 stopni C.')],
        );

        $this->assertSame([], $this->productsOfStep($recipe, 0));
    }

    /**
     * @return list<string>
     */
    private function productsOfStep(Recipe $recipe, int $position): array
    {
        $step = Recipe::query()->findOrFail($recipe->id)->steps()->where('position', $position)->firstOrFail();

        $names = [];

        foreach ($step->ingredients as $line) {
            $names[] = (string) $line->ingredient?->name;
        }

        sort($names);

        return $names;
    }

    /**
     * @param  list<IngredientLineDraft>  $lines
     * @param  list<StepDraft>  $steps
     */
    private function store(array $lines, array $steps): Recipe
    {
        return $this->app->make(StoreRecipeDraft::class)->store(
            new RecipeDraft(
                slug: 'test-'.count($lines).'-'.count($steps),
                title: 'Testowy przepis',
                sourceUrl: 'https://example.test/'.uniqid(),
                ingredientLines: $lines,
                steps: $steps,
            ),
            'example.test',
        );
    }
}
