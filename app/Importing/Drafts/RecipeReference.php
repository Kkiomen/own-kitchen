<?php

declare(strict_types=1);

namespace App\Importing\Drafts;

/**
 * A recipe we know exists but have not fetched yet.
 */
final readonly class RecipeReference
{
    public function __construct(
        public string $url,
        public string $slug,
        public ?string $title = null,
    ) {}
}
