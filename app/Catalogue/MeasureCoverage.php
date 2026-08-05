<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Enums\UnitDimension;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\DB;

/**
 * How much of the catalogue we can actually put a weight on.
 *
 * Measured against **recipe lines**, not against products, and that is the whole
 * point of the class. A dictionary of four hundred products where the missing
 * hundred are the ones every recipe uses is not 75% done. Sorting the gaps by how
 * often the catalogue uses them is the same rule the ingredient review queue
 * follows: attack by frequency, because the top of that list is worth a hundred
 * entries from the tail.
 */
final class MeasureCoverage
{
    /**
     * @return array{lines: int, convertible: int, byDimension: array<string, array{lines: int, convertible: int}>, missing: list<array{name: string, unit: string, lines: int}>}
     */
    public function report(int $missingLimit = 30): array
    {
        $rows = $this->lines()
            ->selectRaw('u.dimension as dimension, count(*) as lines, sum(case when '.$this->convertibleSql().' then 1 else 0 end) as convertible')
            ->groupBy('u.dimension')
            ->get();

        $byDimension = [];
        $lines = 0;
        $convertible = 0;

        foreach ($rows as $row) {
            $byDimension[(string) $row->dimension] = [
                'lines' => (int) $row->lines,
                'convertible' => (int) $row->convertible,
            ];

            $lines += (int) $row->lines;
            $convertible += (int) $row->convertible;
        }

        return [
            'lines' => $lines,
            'convertible' => $convertible,
            'byDimension' => $byDimension,
            'missing' => $this->missing($missingLimit),
        ];
    }

    /**
     * The (product, unit) pairs the catalogue leans on hardest and we still cannot
     * weigh.
     *
     * @return list<array{name: string, unit: string, lines: int}>
     */
    private function missing(int $limit): array
    {
        $rows = $this->lines()
            ->whereRaw('not ('.$this->convertibleSql().')')
            ->selectRaw('i.name as name, u.symbol as unit, count(*) as lines')
            ->groupBy('i.name', 'u.symbol')
            ->orderByDesc('lines')
            ->limit($limit)
            ->get();

        return array_values(array_map(
            static fn (object $row): array => [
                'name' => (string) $row->name,
                'unit' => (string) $row->unit,
                'lines' => (int) $row->lines,
            ],
            $rows->all(),
        ));
    }

    /**
     * Lines worth measuring: a real product, a real unit, and an amount.
     *
     * A line with no amount ("sól do smaku") is not a gap in this data — there is
     * nothing to convert — so counting it as one would make the report look worse
     * every time the importer succeeded at reading a vague line correctly.
     */
    private function lines(): Builder
    {
        return DB::table('recipe_ingredients as line')
            ->join('units as u', 'u.id', '=', 'line.unit_id')
            ->join('ingredients as i', 'i.id', '=', 'line.ingredient_id')
            ->leftJoin('ingredient_measures as m', function ($join): void {
                $join->on('m.ingredient_id', '=', 'line.ingredient_id')
                    ->on('m.unit_id', '=', 'line.unit_id');
            })
            ->whereNotNull('line.quantity');
    }

    /**
     * The same three layers `IngredientMeasures` applies, expressed once in SQL so
     * the report cannot drift from what the app will actually manage to convert.
     *
     * Declared `literal-string` so the two callers that concatenate it stay
     * literal too — that is what `selectRaw()` and `whereRaw()` require, and it
     * is the property that makes interpolating a request value here impossible.
     * Every piece of it is a constant or an enum case, never data.
     *
     * @return literal-string
     */
    private function convertibleSql(): string
    {
        return "u.dimension = '".UnitDimension::Mass->value."'"
            .' or m.grams is not null'
            ." or (u.dimension = '".UnitDimension::Volume->value."' and i.density_g_per_ml is not null)";
    }
}
