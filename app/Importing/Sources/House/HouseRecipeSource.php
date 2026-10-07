<?php

declare(strict_types=1);

namespace App\Importing\Sources\House;

use App\Importing\Contracts\RecipeSource;
use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\RecipeReference;
use App\Importing\Drafts\StepDraft;
use App\Importing\Exceptions\RecipeNotParsable;
use Generator;

/**
 * The household's own plain recipes — `database/data/house-recipes.php`.
 *
 * Not a website, so nothing is fetched and there is no crawl policy to honour;
 * it is an adapter all the same so that these recipes go through exactly the
 * pipeline every other one does, and the parser, the dictionary and the
 * nutrition book judge them by the same rules.
 *
 * The source URL is a `house:` key rather than an address: there is no page to
 * link to, and the recipe screen shows the source's name without a link.
 */
final class HouseRecipeSource implements RecipeSource
{
    public const string SCHEME = 'house:';

    /**
     * @param  list<array{slug: string, title: string, servings: int, minutes: int, ingredients: list<string>, steps: list<string>}>  $recipes
     */
    public function __construct(
        private readonly array $recipes,
        private readonly string $name,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /**
     * @return Generator<RecipeReference>
     */
    public function discover(int $limit): Generator
    {
        foreach (array_slice($this->recipes, 0, $limit) as $recipe) {
            yield new RecipeReference(self::SCHEME.$recipe['slug'], $recipe['slug'], $recipe['title']);
        }
    }

    public function fetch(RecipeReference $reference): RecipeDraft
    {
        foreach ($this->recipes as $recipe) {
            if ($recipe['slug'] !== $reference->slug) {
                continue;
            }

            return new RecipeDraft(
                slug: $recipe['slug'],
                title: $recipe['title'],
                sourceUrl: $reference->url,
                ingredientLines: array_map(static fn (string $line): IngredientLineDraft => new IngredientLineDraft($line), $recipe['ingredients']),
                steps: array_map(static fn (string $step): StepDraft => new StepDraft($step), $recipe['steps']),
                servings: $recipe['servings'],
                totalTimeMinutes: $recipe['minutes'],
            );
        }

        throw new RecipeNotParsable("No house recipe '{$reference->slug}'.");
    }
}
