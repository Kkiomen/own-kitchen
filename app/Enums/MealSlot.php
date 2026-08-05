<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * The meals of one day, in the order they are eaten.
 *
 * A closed vocabulary rather than free text, for the same reason units are: a
 * plan whose slots are typed in would hold "obiad", "Obiad" and "obiadek", and
 * nothing could line one day up against the next.
 *
 * Nothing is required to be filled in — most days will have two or three of
 * these — but the five are always shown, because an empty slot is what an
 * unplanned meal looks like.
 */
enum MealSlot: string
{
    case Breakfast = 'breakfast';
    case SecondBreakfast = 'second_breakfast';
    case Lunch = 'lunch';
    case Snack = 'snack';
    case Dinner = 'dinner';

    public function label(): string
    {
        return match ($this) {
            self::Breakfast => 'Śniadanie',
            self::SecondBreakfast => 'Drugie śniadanie',
            self::Lunch => 'Obiad',
            self::Snack => 'Podwieczorek',
            self::Dinner => 'Kolacja',
        };
    }

    /** The order they are eaten in, which is the order the day is read in. */
    public function position(): int
    {
        return match ($this) {
            self::Breakfast => 0,
            self::SecondBreakfast => 1,
            self::Lunch => 2,
            self::Snack => 3,
            self::Dinner => 4,
        };
    }

    /**
     * The slots a day opens with when nothing has been planned yet.
     *
     * Second breakfast and an afternoon snack are real meals here, but showing
     * five empty rows for every one of seven days is a wall rather than a plan.
     * The other two appear on the day they are asked for.
     *
     * @return list<self>
     */
    public static function everyday(): array
    {
        return [self::Breakfast, self::Lunch, self::Dinner];
    }

    /**
     * @return list<self>
     */
    public static function ordered(): array
    {
        $slots = self::cases();

        usort($slots, static fn (self $a, self $b): int => $a->position() <=> $b->position());

        return $slots;
    }
}
