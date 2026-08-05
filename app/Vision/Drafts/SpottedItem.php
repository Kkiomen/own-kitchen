<?php

declare(strict_types=1);

namespace App\Vision\Drafts;

/**
 * One thing the model says it can see, still as raw text.
 *
 * The boundary DTO of this module, in the same sense as `RecipeDraft` and
 * `OfferDraft`: everything above it is OpenAI-specific, everything below it is
 * not. Nothing here has been matched to a product yet — `name` is whatever the
 * model wrote, and matching it is deliberately somebody else's job.
 */
final readonly class SpottedItem
{
    public function __construct(
        public string $name,
        public ?float $quantity = null,
        /** A code from our closed unit vocabulary, or null. Never a free-text word. */
        public ?string $unitCode = null,
    ) {}
}
