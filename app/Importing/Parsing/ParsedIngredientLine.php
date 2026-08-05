<?php

declare(strict_types=1);

namespace App\Importing\Parsing;

final readonly class ParsedIngredientLine
{
    public function __construct(
        public string $rawText,
        public ?float $quantity,
        public ?float $quantityMax,
        public ?string $unitCode,
        public string $ingredientPhrase,
        /**
         * The same phrase with the measure word left in. Some words are a unit in
         * one recipe and part of the product name in another — "listek bazylii"
         * counts leaves, "listek laurowy" is the spice. Keeping both spellings
         * lets the resolver fall back instead of guessing at parse time.
         */
        public string $phraseIncludingUnit,
        public ?string $note = null,
        public bool $isOptional = false,
    ) {}

    /**
     * A line with no ingredient phrase left is meaningless and must be flagged
     * rather than stored as a silent blank.
     */
    public function isEmpty(): bool
    {
        return $this->ingredientPhrase === '';
    }
}
