<?php

declare(strict_types=1);

namespace App\Catalogue;

/**
 * Matching a word against a recipe title.
 *
 * Its own class because two rule sets depend on getting this exactly right —
 * the quick-pick categories and the meal slots — and the rule is not the obvious
 * one. **A plain substring search is unsafe in Polish**: "zupełnie" contains
 * "zupe", so an innocent title landed in Soups, and "łodyga" contains "lody",
 * which would have put a celery salad in Desserts. Needles are therefore whole
 * words, with a trailing `*` opting into prefix matching for the stems that
 * genuinely need it ("wieprzow*", "kanapk*").
 */
final class TitleNeedle
{
    /** The title must already be normalised — lower case, no diacritics. */
    public static function matches(string $title, string $needle): bool
    {
        $isPrefix = str_ends_with($needle, '*');
        $word = preg_quote($isPrefix ? rtrim($needle, '*') : $needle, '/');

        return preg_match('/\b'.$word.($isPrefix ? '' : '\b').'/u', $title) === 1;
    }

    /**
     * @param  list<string>  $needles
     */
    public static function matchesAny(string $title, array $needles): bool
    {
        foreach ($needles as $needle) {
            if (self::matches($title, $needle)) {
                return true;
            }
        }

        return false;
    }

    /*
     * There was briefly an `opensWithAny()` here, on the theory that the dish a
     * Polish title names comes at the front — "Surówka z młodej kapusty" is a
     * side, "Kotlety z łososia z surówką" is dinner beside one. It does not
     * survive the real catalogue: beszamel.se.pl writes headlines, so the dish
     * turns up in the second sentence ("Pieczone buraki mieszam z ogórkiem
     * małosolnym. Ta surówka znika…"). `TagMealSlots` tells the two apart by
     * corroboration instead — see `sideWords` in `data/meal-slots.php`.
     */
}
