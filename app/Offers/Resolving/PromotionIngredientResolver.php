<?php

declare(strict_types=1);

namespace App\Offers\Resolving;

use App\Importing\Parsing\PolishTextNormalizer;
use App\Importing\Resolving\IngredientResolver;
use App\Models\Ingredient;

/**
 * Decides which canonical product, if any, a leaflet entry is about.
 *
 * Two rules, both of them load-bearing:
 *
 * 1. It never invents a product. `IngredientResolver::match()` is used, not
 *    `resolve()`. A leaflet is mostly not food, and inventing products from it
 *    would hand thousands of aliases to rows nobody vouched for — after which
 *    recipe import would resolve real ingredient lines onto them.
 *
 * 2. It rejects known non-food outright before matching, because the dangerous
 *    entries are exactly the ones that would match: dog food naming a meat,
 *    shower gel naming milk and honey. See `database/data/promotion-noise.php`.
 *
 * An entry it cannot place is stored unmatched and flagged, never dropped —
 * improving the matcher is then a re-run over cached pages rather than a
 * re-crawl of every shop.
 */
final class PromotionIngredientResolver
{
    /**
     * @var list<list<string>>|null Noise phrases, split into words.
     */
    private ?array $noise = null;

    public function __construct(
        private readonly IngredientResolver $ingredients,
        private readonly PolishTextNormalizer $normalizer,
    ) {}

    public function resolve(string $title): ?Ingredient
    {
        $normalised = $this->normalizer->normalize($title);

        if ($normalised === '' || $this->isNoise($normalised)) {
            return null;
        }

        return $this->ingredients->match($this->withoutNegations($normalised));
    }

    /**
     * A negated ingredient is not that ingredient.
     *
     * Found on the first live run: "Napój gazowany Coca-Cola Zero Cukru" and
     * "Napój energetyczny zero cukru" both matched **Cukier**, so the app would
     * have offered a sugar-free drink as the cheapest sugar in town. Dropping the
     * whole entry would be wrong too — "Jogurt naturalny bez cukru" is real food
     * that should still resolve to yoghurt. So only the negated word goes, and
     * whatever the product actually is stays.
     *
     * Narrow on purpose: "bez" is also an elderberry, and "bez czarny" would be
     * read as a negation. That is a rare word on a leaflet and the cost is one
     * unmatched entry, against a wrong match on every zero-sugar drink there is.
     */
    private function withoutNegations(string $normalised): string
    {
        $stripped = preg_replace('/\b(bez|zero)\s+\S+/u', ' ', $normalised) ?? $normalised;

        return trim(preg_replace('/\s+/u', ' ', $stripped) ?? $stripped);
    }

    private function isNoise(string $normalisedTitle): bool
    {
        $words = explode(' ', $normalisedTitle);

        foreach ($this->phrases() as $phrase) {
            if ($this->containsPhrase($words, $phrase)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whole-word matching, with `*` opting into a prefix.
     *
     * A plain substring search would be wrong in both directions here: "mydla"
     * appears inside nothing useful, but "karm" appears inside "karmelowy" — and
     * caramel biscuits are food.
     *
     * @param  list<string>  $words
     * @param  list<string>  $phrase
     */
    private function containsPhrase(array $words, array $phrase): bool
    {
        $length = count($phrase);

        for ($start = 0; $start + $length <= count($words); $start++) {
            $matched = true;

            foreach ($phrase as $index => $part) {
                $word = $words[$start + $index];
                $isPrefix = str_ends_with($part, '*');

                if ($isPrefix ? ! str_starts_with($word, rtrim($part, '*')) : $word !== $part) {
                    $matched = false;

                    break;
                }
            }

            if ($matched) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<list<string>>
     */
    private function phrases(): array
    {
        if ($this->noise !== null) {
            return $this->noise;
        }

        /** @var list<string> $raw */
        $raw = require database_path('data/promotion-noise.php');

        return $this->noise = array_map(
            static fn (string $phrase): array => explode(' ', $phrase),
            $raw,
        );
    }
}
