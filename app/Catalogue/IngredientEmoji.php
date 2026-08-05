<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Enums\IngredientCategory;
use App\Importing\Parsing\PolishTextNormalizer;

/**
 * The picture shown beside a product's name, everywhere a product is named.
 *
 * Two layers, and the order matters: the product's own name first
 * (`database/data/ingredient-emoji.php`), its category second. The category is
 * always available, so this can only ever improve on "some vegetable" — a name
 * nothing recognises is not a gap, it just keeps the broader picture.
 *
 * Deliberately not stored on the row. It is derived from data the catalogue
 * already holds, so a better needle takes effect on the next page load rather
 * than needing a migration and a backfill.
 */
class IngredientEmoji
{
    /**
     * Needles grouped by their first letter, each group still longest-first.
     *
     * @var array<string, array<string, string>>
     */
    private array $byInitial = [];

    /** @var array<string, string> resolved name => emoji, for one request */
    private array $resolved = [];

    public function __construct(private readonly PolishTextNormalizer $normalizer)
    {
        /** @var array<string, string> $needles */
        $needles = require database_path('data/ingredient-emoji.php');

        /*
         * Longest first, once, so the lookup itself can stop at its first hit.
         * This is what lets a specific needle override a broader one — without
         * it, "serdelki" would be cheese and "kaszanka" would be groats.
         */
        uksort($needles, static fn (string $a, string $b): int => strlen($b) <=> strlen($a));

        /*
         * A needle only ever matches at a word boundary, so one that does not
         * start with a letter the name has a word starting with cannot match at
         * all. Bucketing by that letter turns "scan all 430" into "scan the two
         * or three buckets this name can reach" — the file is going to keep
         * growing, and a linear scan on every product of the catalogue does not.
         */
        foreach ($needles as $needle => $emoji) {
            $this->byInitial[$needle[0]][$needle] = $emoji;
        }
    }

    public function for(?string $name, ?IngredientCategory $category): string
    {
        $fallback = ($category ?? IngredientCategory::Other)->emoji();

        if ($name === null) {
            return $fallback;
        }

        return $this->byName($name) ?? $fallback;
    }

    /**
     * The longest needle that matches wins; if two of equal length match, the
     * alphabetically first one does. That second rule exists only so the answer
     * cannot depend on which word of the name came first — two products spelled
     * differently must not resolve differently for a reason nobody can see.
     */
    private function byName(string $name): ?string
    {
        if (array_key_exists($name, $this->resolved)) {
            return $this->resolved[$name] === '' ? null : $this->resolved[$name];
        }

        $normalised = $this->normalizer->normalize($name);
        $hit = null;
        $bestNeedle = null;
        $best = -1;

        foreach ($this->initialsOf($normalised) as $initial) {
            foreach ($this->byInitial[$initial] ?? [] as $needle => $emoji) {
                $length = strlen($needle);

                // Buckets are each longest-first, so once this one can no longer
                // beat what another bucket produced, it is exhausted. Equal
                // lengths are still examined — see the tie-break below.
                if ($length < $best) {
                    break;
                }

                if ($length === $best && $needle >= $bestNeedle) {
                    continue;
                }

                $isPrefix = str_ends_with($needle, '*');

                if ($this->hasWord($normalised, $isPrefix ? rtrim($needle, '*') : $needle, $isPrefix)) {
                    $hit = $emoji;
                    $bestNeedle = $needle;
                    $best = $length;
                }
            }
        }

        $this->resolved[$name] = $hit ?? '';

        return $hit;
    }

    /**
     * The distinct letters this name has a word starting with.
     *
     * @return list<string>
     */
    private function initialsOf(string $normalised): array
    {
        $initials = [];

        foreach (explode(' ', $normalised) as $word) {
            if ($word !== '') {
                $initials[$word[0]] = true;
            }
        }

        return array_keys($initials);
    }

    /**
     * Whole-word (or whole-prefix) containment.
     *
     * Plain string work rather than a regex: this runs against every product in
     * the catalogue on the kitchen and shopping pages, and `normalize()` has
     * already reduced the text to letters, digits and single spaces, so a space
     * either side is the whole of what a word boundary means here.
     */
    private function hasWord(string $haystack, string $needle, bool $prefix): bool
    {
        $length = strlen($needle);
        $offset = 0;

        while (($at = strpos($haystack, $needle, $offset)) !== false) {
            $startsWord = $at === 0 || $haystack[$at - 1] === ' ';
            $end = $at + $length;
            $endsWord = $prefix || $end === strlen($haystack) || $haystack[$end] === ' ';

            if ($startsWord && $endsWord) {
                return true;
            }

            $offset = $at + 1;
        }

        return false;
    }
}
