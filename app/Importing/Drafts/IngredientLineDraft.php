<?php

declare(strict_types=1);

namespace App\Importing\Drafts;

final readonly class IngredientLineDraft
{
    public function __construct(
        public string $rawText,
        public ?string $section = null,
    ) {}
}
