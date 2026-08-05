<?php

declare(strict_types=1);

namespace App\Pricing\Resolving;

use App\Importing\Parsing\PolishTextNormalizer;
use App\Importing\Resolving\IngredientResolver;
use App\Models\Ingredient;

/**
 * Decides which canonical product, if any, a price reading is about.
 *
 * It never invents one. `IngredientResolver::match()` is used, never
 * `resolve()` — the same rule leaflets follow and for the same reason: an
 * invented product owns an alias permanently, after which recipe import resolves
 * real ingredient lines onto it. That failure has happened in this codebase and
 * cost 222 lines; a price source is no place to risk it again.
 *
 * There is deliberately no noise list here, unlike the leaflet resolver. This
 * only ever sees a statistical office's own product labels, which are food by
 * construction — the entries that make a noise list necessary are the ones a
 * shop puts in a leaflet next to the lawnmowers. A source that does need one is
 * a reason to add it there, not to widen this.
 *
 * A reading it cannot place is stored unmatched and flagged, never dropped, so
 * improving the matcher is a re-run rather than a re-fetch.
 */
final class PriceIngredientResolver
{
    public function __construct(
        private readonly IngredientResolver $ingredients,
        private readonly PolishTextNormalizer $normalizer,
    ) {}

    public function resolve(string $title): ?Ingredient
    {
        $normalised = $this->normalizer->normalize($title);

        if ($normalised === '') {
            return null;
        }

        return $this->ingredients->match($normalised);
    }
}
