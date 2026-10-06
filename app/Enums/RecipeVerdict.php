<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * What the household thinks of a recipe.
 *
 * Two cases and no scale. A five-star rating asks a question nobody answers at
 * the hob; "more of this" and "never again" are the two things somebody looking
 * at a planned week actually wants to say.
 */
enum RecipeVerdict: string
{
    /** Plan it again, and sooner than the month the variety rule waits. */
    case Like = 'like';

    /** Never suggest it. Still in the catalogue, still searchable by hand. */
    case Dislike = 'dislike';
}
