<?php

declare(strict_types=1);

namespace App\Planning;

use App\Enums\MealSlot;
use App\Support\Money\Money;
use InvalidArgumentException;

/**
 * What a generated week is being asked to hit: how many people, how much they
 * eat, across which meals, for how much money.
 *
 * **The shares map is also the meal structure**, and that is deliberate rather
 * than clever. Asking separately "which meals?" and "how do the calories split?"
 * lets the two disagree — a day with a podwieczorek in one list and not the
 * other — and there is no sensible thing to do when they do. One map cannot
 * contradict itself.
 *
 * The budget is optional and separate from the calories on purpose: they are
 * different kinds of constraint. Calories are a target the week aims at, and
 * missing by 5% is a normal Tuesday. Money is a ceiling somebody actually has,
 * and a plan that quietly goes over it has failed at the one thing it was asked.
 */
final readonly class PlanTargets
{
    /**
     * What the form opens with when nobody has said otherwise.
     *
     * A round figure for an adult day rather than anything computed: this app
     * knows nothing about anybody's height, weight or activity, and a number
     * derived from data it does not have would look authoritative for no reason.
     * It is a starting point on a form somebody types over.
     */
    public const int DEFAULT_KCAL = 2500;

    /**
     * How much of the daily calories each meal carries.
     *
     * Only offered as a default. The screen asks, because a household that eats
     * a large breakfast and a small supper is not planning the same week as one
     * that does the opposite, and neither is wrong.
     *
     * @return array<string, float>
     */
    public static function everydayShares(): array
    {
        return [
            MealSlot::Breakfast->value => 0.30,
            MealSlot::Lunch->value => 0.45,
            MealSlot::Dinner->value => 0.25,
        ];
    }

    /**
     * @param  array<string, float>  $shares  slot => share of the day's calories
     */
    public function __construct(
        public int $people,
        public int $kcalPerPerson,
        public array $shares,
        public ?Money $budget = null,
    ) {
        if ($people < 1) {
            throw new InvalidArgumentException('A week has to be for at least one person.');
        }

        if ($kcalPerPerson < 1) {
            throw new InvalidArgumentException('A calorie target has to be a number of calories.');
        }

        if ($shares === []) {
            throw new InvalidArgumentException('A day has to hold at least one meal.');
        }

        $total = array_sum($shares);

        /*
         * Not normalised silently. Shares that do not add up mean somebody
         * intended something this class cannot guess — and quietly scaling them
         * would hand back a week that hits a target nobody set, which is exactly
         * the kind of confident wrongness the calorie count is built to avoid.
         */
        if (abs($total - 1.0) > 0.001) {
            throw new InvalidArgumentException(
                'The meal shares have to add up to the whole day, got '.round($total * 100).'%.'
            );
        }
    }

    /**
     * The meals a day holds, in the order they are eaten.
     *
     * @return list<MealSlot>
     */
    public function slots(): array
    {
        $slots = array_map(static fn (string $value): MealSlot => MealSlot::from($value), array_keys($this->shares));

        usort($slots, static fn (MealSlot $a, MealSlot $b): int => $a->position() <=> $b->position());

        return $slots;
    }

    /**
     * How many calories one person's share of this meal is.
     *
     * Per person rather than per meal, because that is the figure a recipe's
     * portion is compared against — and comparing a portion to a whole
     * household's dinner is how a plan ends up proposing one pancake for two.
     */
    public function kcalFor(MealSlot $slot): float
    {
        return $this->kcalPerPerson * ($this->shares[$slot->value] ?? 0.0);
    }

    public function kcalPerDay(): int
    {
        return $this->kcalPerPerson * $this->people;
    }
}
