<?php

declare(strict_types=1);

namespace App\Planning;

/**
 * A planned stretch of days, read back and added up: what each person eats each
 * day, what it costs, and what could not be read.
 *
 * Everything here is a measurement of the plan as it stands. Nothing in it is
 * what the generator hoped for — see `WeekSummary` for why that distinction is
 * the point.
 */
final readonly class PlannedWeek
{
    /**
     * @param  array<string, float>  $kcalPerPersonByDate  date => calories one person eats
     * @param  array<string, float>  $proteinPerPersonByDate  date => grams of protein one person eats
     * @param  int  $uncounted  planned dishes with no calorie figure
     * @param  int  $notes  entries that are a note rather than a dish
     */
    public function __construct(
        public array $kcalPerPersonByDate,
        public array $proteinPerPersonByDate,
        public WeekPrice $price,
        public int $uncounted,
        public int $notes,
        public ?PlanTargets $targets = null,
    ) {}

    /**
     * The average day, or null when nothing is planned.
     *
     * An average rather than a per-day list for the screen's headline, because
     * eating a little more on Saturday and a little less on Monday is how people
     * actually eat — a plan judged day by day would look wrong every week.
     */
    public function averageKcalPerPerson(): ?float
    {
        if ($this->kcalPerPersonByDate === []) {
            return null;
        }

        return array_sum($this->kcalPerPersonByDate) / count($this->kcalPerPersonByDate);
    }

    /**
     * The average day's protein, in grams per person, or null when nothing is
     * planned.
     *
     * Beside the calories rather than instead of them: 2 500 kcal of pancakes
     * and 2 500 kcal of dinner are the same number and not the same week.
     */
    public function averageProteinPerPerson(): ?float
    {
        if ($this->proteinPerPersonByDate === []) {
            return null;
        }

        return array_sum($this->proteinPerPersonByDate) / count($this->proteinPerPersonByDate);
    }

    /**
     * How far the average day lands from the target, as a share of it.
     *
     * Signed, so the screen can say "za mało" and "za dużo" rather than only
     * "nie tyle". Null when nobody set a target to miss.
     */
    public function calorieGap(): ?float
    {
        $average = $this->averageKcalPerPerson();

        if ($average === null || $this->targets === null) {
            return null;
        }

        return ($average - $this->targets->kcalPerPerson) / $this->targets->kcalPerPerson;
    }

    /**
     * Whether the shopping fits the budget that was set.
     *
     * Null when there is no budget — which is a different answer from "no", and
     * the screen has to tell them apart or a week with no budget would read as a
     * week that broke one.
     */
    public function fitsBudget(): ?bool
    {
        return $this->targets?->budget === null
            ? null
            : $this->price->fitsWithin($this->targets->budget);
    }
}
