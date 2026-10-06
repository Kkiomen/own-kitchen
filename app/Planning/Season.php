<?php

declare(strict_types=1);

namespace App\Planning;

use App\Catalogue\TitleNeedle;
use App\Importing\Parsing\PolishTextNormalizer;
use Carbon\CarbonImmutable;

/**
 * What a given day is the season for — `database/data/seasons.php` asked about
 * one date.
 *
 * Two questions, answered very differently on purpose. **An occasion is a
 * gate**: an Easter breakfast is not offered in October at all, because a dish
 * belonging to a date is the one kind of suggestion that reads as a mistake
 * rather than a choice. **Produce is a nudge**: a pumpkin soup in October
 * should rise, and strawberries in December sink, but neither is forbidden — a
 * frozen raspberry is a perfectly good raspberry.
 */
final class Season
{
    /** @var array<string, array{months: list<int>, strict: bool, titles?: list<string>}> */
    private array $produce;

    /** @var array<string, array{titles: list<string>, from?: array{int, int}, to?: array{int, int}, easter?: array{int, int}}> */
    private array $occasions;

    public function __construct(private readonly PolishTextNormalizer $normalizer)
    {
        /** @var array{produce: array<string, array{months: list<int>, strict: bool, titles?: list<string>}>, occasions: array<string, array{titles: list<string>, from?: array{int, int}, to?: array{int, int}, easter?: array{int, int}}>} $data */
        $data = require database_path('data/seasons.php');

        $this->produce = $data['produce'];
        $this->occasions = $data['occasions'];
    }

    /** The occasion a title belongs to, or null for a dish that suits any day. */
    public function occasionOf(string $title): ?string
    {
        $normalised = $this->normalizer->normalize($title);

        foreach ($this->occasions as $key => $occasion) {
            if (TitleNeedle::matchesAny($normalised, $occasion['titles'])) {
                return $key;
            }
        }

        return null;
    }

    public function allows(?string $occasion, CarbonImmutable $date): bool
    {
        if ($occasion === null || ! isset($this->occasions[$occasion])) {
            return true;
        }

        $rule = $this->occasions[$occasion];

        if (isset($rule['easter'])) {
            $easter = $this->easterSunday($date->year);
            [$before, $after] = $rule['easter'];

            return $date->betweenIncluded($easter->addDays($before), $easter->addDays($after));
        }

        [$fromMonth, $fromDay] = $rule['from'] ?? [1, 1];
        [$toMonth, $toDay] = $rule['to'] ?? [12, 31];

        $day = $date->month * 100 + $date->day;
        $from = $fromMonth * 100 + $fromDay;
        $to = $toMonth * 100 + $toDay;

        // A window may wrap the year: 1 December to 6 January.
        return $from <= $to ? $day >= $from && $day <= $to : $day >= $from || $day <= $to;
    }

    /**
     * How in-season a dish's produce is on this date: positive when it is made
     * of what the month is good for, negative when it leans on a product that
     * is close to absent.
     *
     * @param  list<string>  $products  canonical product names
     */
    public function fitOf(array $products, CarbonImmutable $date): int
    {
        $fit = 0;

        foreach ($products as $name) {
            $rule = $this->produce[$name] ?? null;

            if ($rule === null) {
                continue;
            }

            if (in_array($date->month, $rule['months'], true)) {
                $fit++;
            } elseif ($rule['strict']) {
                $fit -= 2;
            }
        }

        return $fit;
    }

    /**
     * Whether a dish leans on a product that is close to absent on this date.
     *
     * A gate, not a nudge: even a penalty twice the size of a calorie miss let
     * "jajecznica ze szparagami, zanim sezon się skończy" through on the last
     * day of October, because the breakfast pool had little else that fitted.
     *
     * @param  list<string>  $products
     */
    public function isOutOfSeason(array $products, CarbonImmutable $date): bool
    {
        foreach ($products as $name) {
            $rule = $this->produce[$name] ?? null;

            if ($rule !== null && $rule['strict'] && ! in_array($date->month, $rule['months'], true)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> the products this file has a season for */
    public function seasonalProducts(): array
    {
        return array_keys($this->produce);
    }

    /**
     * The seasonal products a title names.
     *
     * The second witness to what a dish is made of, and not a redundant one:
     * "truskawki, sok z cytryny, syrop klonowy" is one ingredient line that
     * resolves to the lemon, so "Owsianka z truskawkami" had no strawberries
     * in it as far as the lines could tell — and was planned in October.
     *
     * @return list<string>
     */
    public function produceNamedIn(string $title): array
    {
        $normalised = $this->normalizer->normalize($title);
        $found = [];

        foreach ($this->produce as $name => $rule) {
            if (TitleNeedle::matchesAny($normalised, $rule['titles'] ?? [])) {
                $found[] = $name;
            }
        }

        return $found;
    }

    /**
     * Easter Sunday by the anonymous Gregorian algorithm — `easter_date()` needs
     * the calendar extension, which nothing else here does.
     */
    private function easterSunday(int $year): CarbonImmutable
    {
        $a = $year % 19;
        $b = intdiv($year, 100);
        $c = $year % 100;
        $d = intdiv($b, 4);
        $e = $b % 4;
        $f = intdiv($b + 8, 25);
        $g = intdiv($b - $f + 1, 3);
        $h = (19 * $a + $b - $d - $g + 15) % 30;
        $i = intdiv($c, 4);
        $k = $c % 4;
        $l = (32 + 2 * $e + 2 * $i - $h - $k) % 7;
        $m = intdiv($a + 11 * $h + 22 * $l, 451);
        $month = intdiv($h + $l - 7 * $m + 114, 31);
        $day = (($h + $l - 7 * $m + 114) % 31) + 1;

        return CarbonImmutable::create($year, $month, $day)->startOfDay();
    }
}
