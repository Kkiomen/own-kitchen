<?php

declare(strict_types=1);

namespace App\Planning;

/**
 * A recipe the generator may reach for: its id, and the one fact that changes
 * how it is placed — whether the source calls it food cooked ahead and carried,
 * which is what earns it two days running rather than one.
 *
 * A named type rather than the query builder's `stdClass` because the generator
 * reads both fields in three places, and a typo in one of them would be a
 * runtime surprise rather than a failed check.
 */
final readonly class RecipeCandidate
{
    public function __construct(
        public int $id,
        public bool $isMealPrep,
    ) {}
}
