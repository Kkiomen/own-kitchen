<?php

declare(strict_types=1);

namespace App\Importing;

use App\Models\Recipe;

/**
 * What happened to a single recipe during an import run.
 */
final readonly class ImportOutcome
{
    private function __construct(
        public string $status,
        public string $slug,
        public ?Recipe $recipe = null,
        public ?string $message = null,
    ) {}

    public static function imported(Recipe $recipe): self
    {
        return new self('imported', $recipe->slug, $recipe);
    }

    public static function skipped(string $slug): self
    {
        return new self('skipped', $slug);
    }

    public static function failed(string $slug, string $message): self
    {
        return new self('failed', $slug, message: $message);
    }
}
