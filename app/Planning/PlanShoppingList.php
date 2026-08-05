<?php

declare(strict_types=1);

namespace App\Planning;

use App\Models\ShoppingList;
use App\Models\User;
use App\Support\PolishDate;
use Carbon\CarbonImmutable;

/**
 * The list a week of meals goes onto: "3–9 sierpnia", not the main one.
 *
 * A week's shopping is one trip with a beginning and an end, and mixing it into
 * the standing list makes both unreadable — you cannot tell what you still need
 * for Tuesday's dinner from the washing-up liquid somebody added on Thursday.
 * Naming it after the days it covers is the only name that says what it is
 * without anybody typing one.
 */
final class PlanShoppingList
{
    /**
     * The list for these days — the same one every time they are shopped for.
     *
     * One week, one list. Swapping a dish and pressing the button again is the
     * ordinary way this gets used, and a fresh "10–16 sierpnia (2)" each time
     * would leave a drawer of near-identical lists and no way to tell which one
     * to take to the shop. `PlannedShopping` rebuilds the contents instead.
     *
     * @param  list<string>  $dates
     */
    public function for(User $user, array $dates): ShoppingList
    {
        // The main list has to exist before any other, or deleting this one
        // could leave the household with nowhere to write anything down.
        ShoppingList::defaultFor($user);

        return ShoppingList::query()->firstOrCreate(
            // The column holds 60 characters; a run of days never comes close.
            ['user_id' => $user->id, 'name' => $this->nameFor($dates)],
            ['is_default' => false],
        );
    }

    /**
     * "3–9 sierpnia" for a run of days, "we wtorek 5 sierpnia" for one.
     *
     * The month is named once when both ends share it and twice when they do
     * not ("29 lipca – 4 sierpnia"), because a list called "29–4" reads as
     * nonsense on the shop floor.
     *
     * @param  list<string>  $dates
     */
    public function nameFor(array $dates): string
    {
        sort($dates);

        $first = CarbonImmutable::parse($dates[0]);
        $last = CarbonImmutable::parse($dates[count($dates) - 1]);

        if ($first->isSameDay($last)) {
            return PolishDate::dayAndMonth($first);
        }

        return $first->isSameMonth($last)
            ? $first->format('j').'–'.PolishDate::dayAndMonth($last)
            : PolishDate::dayAndMonth($first).' – '.PolishDate::dayAndMonth($last);
    }
}
