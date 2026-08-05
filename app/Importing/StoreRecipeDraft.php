<?php

declare(strict_types=1);

namespace App\Importing;

use App\Importing\Drafts\RecipeDraft;
use App\Importing\Parsing\IngredientLineParser;
use App\Importing\Parsing\PolishTextNormalizer;
use App\Importing\Parsing\SectionHeading;
use App\Importing\Parsing\StepInstructionParser;
use App\Importing\Resolving\IngredientResolver;
use App\Importing\Resolving\UnitResolver;
use App\Models\Recipe;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use App\Models\Tag;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Turns a source-agnostic draft into clean, related rows.
 *
 * Nothing is ever dropped: a line whose ingredient could not be recognised is
 * still stored, with its original wording and a review flag, so a better parser
 * can be re-run later without touching the source site again.
 */
final class StoreRecipeDraft
{
    public function __construct(
        private readonly IngredientLineParser $lineParser,
        private readonly StepInstructionParser $stepParser,
        private readonly IngredientResolver $ingredients,
        private readonly UnitResolver $units,
        private readonly StepIngredientLinker $linker,
        private readonly PolishTextNormalizer $normalizer,
        private readonly SectionHeading $headings,
    ) {}

    public function store(RecipeDraft $draft, string $sourceName): Recipe
    {
        return DB::transaction(function () use ($draft, $sourceName): Recipe {
            $recipe = Recipe::query()->updateOrCreate(
                ['source_url' => $draft->sourceUrl],
                [
                    'slug' => $this->availableSlug($draft),
                    'title' => $draft->title,
                    'description' => $draft->description,
                    'servings' => $draft->servings,
                    'servings_label' => $draft->servingsLabel,
                    'total_time_minutes' => $draft->totalTimeMinutes,
                    'appliance' => $draft->appliance,
                    'is_meal_prep' => $draft->isMealPrep,
                    'image_url' => $draft->imageUrl,
                    'source_name' => $sourceName,
                    'imported_at' => now(),
                ],
            );

            // A re-import is authoritative over the source's own content.
            $recipe->ingredients()->delete();
            $recipe->steps()->delete();

            $lines = $this->storeIngredientLines($recipe, $draft);
            $steps = $this->storeSteps($recipe, $draft);

            $this->linker->link($steps, $lines);
            $this->syncTags($recipe, $draft);

            $recipe->update([
                'needs_review' => $lines->contains('needs_review', true)
                    || $steps->contains('needs_review', true),
            ]);

            return $recipe->fresh() ?? $recipe;
        });
    }

    /**
     * Two sites can publish the same dish under the same slug ("gulasz-wieprzowy"),
     * and the column is unique across every source. Rather than losing the second
     * recipe to a constraint violation, it gets a numbered slug.
     */
    private function availableSlug(RecipeDraft $draft): string
    {
        $takenByAnotherRecipe = static fn (string $candidate): bool => Recipe::query()
            ->where('slug', $candidate)
            ->where(static fn ($query) => $query
                ->whereNull('source_url')
                ->orWhere('source_url', '!=', $draft->sourceUrl))
            ->exists();

        if (! $takenByAnotherRecipe($draft->slug)) {
            return $draft->slug;
        }

        $suffix = 2;

        while ($takenByAnotherRecipe($draft->slug.'-'.$suffix)) {
            $suffix++;
        }

        return $draft->slug.'-'.$suffix;
    }

    /**
     * @return Collection<int, RecipeIngredient>
     */
    private function storeIngredientLines(Recipe $recipe, RecipeDraft $draft): Collection
    {
        $stored = collect();
        $position = 0;
        $runningSection = null;

        foreach ($draft->ingredientLines as $line) {
            // Sites that do not mark their headings up put "Sos:" or "Przyprawy"
            // straight into the list. Stored as ingredients they become products
            // nobody can buy; they belong to the lines that follow them instead.
            $heading = $this->headings->detect($line->rawText);

            if ($heading !== null) {
                $runningSection = $heading;

                continue;
            }

            // The heading can also share a line with the ingredients under it.
            [$text, $inlineHeading] = $this->headings->stripPrefix($line->rawText);

            if ($inlineHeading !== null) {
                $runningSection = $inlineHeading;
            }

            $section = $line->section ?? $runningSection;
            $parsed = $this->lineParser->parse($text, $section);
            // A line naming only a group ("sosu", "przyprawy") must not become a
            // product: its alias would swallow every real member of that group.
            $resolved = $parsed->isEmpty() || $this->headings->isGenericGroupName($parsed->ingredientPhrase)
                ? null
                : $this->ingredients->resolve($parsed->ingredientPhrase, $parsed->phraseIncludingUnit);

            // The measure word turned out to be part of the product's name, so the
            // line has no unit and the amount is a plain count.
            $unit = $resolved?->unitBelongsToName === true
                ? $this->units->byCode('piece')
                : $this->units->byCode($parsed->unitCode);

            $stored->push(RecipeIngredient::query()->create([
                'recipe_id' => $recipe->id,
                'ingredient_id' => $resolved?->ingredient->id,
                'unit_id' => $unit?->id,
                'quantity' => $parsed->quantity,
                'quantity_max' => $parsed->quantityMax,
                'note' => $parsed->note,
                'section' => $section,
                'raw_text' => $parsed->rawText,
                'position' => $position++,
                'is_optional' => $parsed->isOptional,
                // Judged by whether the product is one we vouch for, not by whether
                // it happened to be invented during this particular run. Otherwise
                // re-importing would clear the queue without anything being fixed.
                'needs_review' => $resolved === null || ! $resolved->ingredient->source->isTrusted(),
            ]));
        }

        return $stored;
    }

    /**
     * @return Collection<int, RecipeStep>
     */
    private function storeSteps(Recipe $recipe, RecipeDraft $draft): Collection
    {
        $stored = collect();

        foreach ($draft->steps as $position => $step) {
            $parsed = $this->stepParser->parse($step->rawText);

            $stored->push(RecipeStep::query()->create([
                'recipe_id' => $recipe->id,
                'position' => $position,
                'section' => $step->section,
                'instruction' => $parsed->instruction,
                'raw_text' => $step->rawText,
                'action' => $parsed->action,
                'appliance' => $parsed->appliance,
                'temperature_celsius' => $parsed->temperatureCelsius,
                'duration_seconds' => $parsed->durationSeconds,
                'needs_review' => $parsed->needsReview(),
            ]));
        }

        return $stored;
    }

    private function syncTags(Recipe $recipe, RecipeDraft $draft): void
    {
        $ids = [];

        foreach ($draft->tags as $name) {
            $slug = $this->normalizer->slug($name);

            if ($slug === '') {
                continue;
            }

            $ids[] = Tag::query()->firstOrCreate(['slug' => $slug], ['name' => $name])->id;
        }

        $recipe->tags()->sync($ids);
    }
}
