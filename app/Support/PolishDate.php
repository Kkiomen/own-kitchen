<?php

declare(strict_types=1);

namespace App\Support;

use Carbon\CarbonImmutable;

/**
 * Dates written the way a Polish kitchen calendar writes them.
 *
 * Spelled out here rather than left to `translatedFormat()`, which follows
 * `app.locale` — and that is `en`, because the identifiers, the validation
 * messages and the framework's own strings are English by house rule while only
 * the user-facing copy is Polish. Switching the whole application's locale to
 * get one month name would move every framework string with it.
 *
 * Note the **genitive**: a Polish date says "3 sierpnia", not "3 sierpień" —
 * the nominative reads like a headline, not like a date.
 */
final class PolishDate
{
    /** Short enough for a column heading on a phone. */
    public static function weekdayShort(CarbonImmutable $date): string
    {
        return self::WEEKDAYS[$date->dayOfWeekIso - 1];
    }

    /** "3 sierpnia" — the ordinary way to say a date out loud. */
    public static function dayAndMonth(CarbonImmutable $date): string
    {
        return $date->format('j').' '.self::MONTHS[(int) $date->format('n') - 1];
    }

    public static function month(CarbonImmutable $date): string
    {
        return self::MONTHS[(int) $date->format('n') - 1];
    }

    /** @var list<string> */
    private const array WEEKDAYS = ['pon', 'wt', 'śr', 'czw', 'pt', 'sob', 'niedz'];

    /** @var list<string> */
    private const array MONTHS = [
        'stycznia', 'lutego', 'marca', 'kwietnia', 'maja', 'czerwca',
        'lipca', 'sierpnia', 'września', 'października', 'listopada', 'grudnia',
    ];
}
