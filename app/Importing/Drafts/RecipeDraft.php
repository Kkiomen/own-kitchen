<?php

declare(strict_types=1);

namespace App\Importing\Drafts;

use App\Enums\Appliance;

/**
 * A recipe as the source website describes it: still raw text, not yet resolved to
 * ingredients and units. This is the hexagon's boundary — everything upstream is
 * site-specific, everything downstream is not.
 */
final readonly class RecipeDraft
{
    /**
     * @param  list<IngredientLineDraft>  $ingredientLines
     * @param  list<StepDraft>  $steps
     * @param  list<string>  $tags
     */
    public function __construct(
        public string $slug,
        public string $title,
        public string $sourceUrl,
        public array $ingredientLines,
        public array $steps,
        public ?string $description = null,
        public ?int $servings = null,
        public ?string $servingsLabel = null,
        public ?string $imageUrl = null,
        public array $tags = [],
        public ?int $totalTimeMinutes = null,
        /** The device the recipe is written for, when it is device specific at all. */
        public ?Appliance $appliance = null,
        /** Cooked ahead in a batch and eaten over the following days. */
        public bool $isMealPrep = false,
    ) {}

    /**
     * Some sites publish a perfectly good JSON-LD recipe with a broken ingredient
     * list — beszamel.se.pl glues every line into one string — while the page's own
     * markup still has them separated. This lets an adapter keep everything the
     * standard gave it and replace only the part its site got wrong.
     *
     * @param  list<IngredientLineDraft>  $ingredientLines
     */
    public function withIngredientLines(array $ingredientLines): self
    {
        return new self(
            slug: $this->slug,
            title: $this->title,
            sourceUrl: $this->sourceUrl,
            ingredientLines: $ingredientLines,
            steps: $this->steps,
            description: $this->description,
            servings: $this->servings,
            servingsLabel: $this->servingsLabel,
            imageUrl: $this->imageUrl,
            tags: $this->tags,
            totalTimeMinutes: $this->totalTimeMinutes,
            appliance: $this->appliance,
            isMealPrep: $this->isMealPrep,
        );
    }
}
