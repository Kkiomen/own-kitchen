<?php

declare(strict_types=1);

namespace App\Support\Money;

/**
 * The middle of a set of readings, in grosze.
 *
 * A median and never a mean, for the reason `PriceBook` sets out: the readings
 * come from sources that disagree by design, and one 90 zł/kg parmesan among
 * ten ordinary cheeses moves a mean enough to be noticed on a bill and moves a
 * median not at all.
 *
 * It lives here rather than inside either caller because two of them now answer
 * questions about the same prices — what a thing typically costs, and what it
 * cost on each day somebody read it — and a screen showing a history that
 * disagreed with the estimate below it would be worse than either alone.
 */
final class Median
{
    /**
     * @param  list<int>  $figures  in any order; a copy is sorted
     */
    public static function of(array $figures): int
    {
        sort($figures);

        $count = count($figures);
        $middle = intdiv($count, 2);

        /*
         * An even count takes the mean of the two middle readings. That is still
         * a median — it is the standard definition — and it keeps two
         * disagreeing sources from having their tie broken by row order.
         */
        return $count % 2 === 1
            ? $figures[$middle]
            : (int) round(($figures[$middle - 1] + $figures[$middle]) / 2);
    }
}
