<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Models\Ingredient;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Gives every product the unit it is normally measured in.
 *
 * The curated dictionary states a unit for its 344 entries; the ~1300 products
 * the importer invented have none, so picking one of those in the kitchen or on
 * the shopping list left the unit blank — and an amount without a unit is
 * refused, which turns a one-tap job into three taps and a guess.
 *
 * The catalogue already knows the answer: ~80 000 recipe lines say how each
 * product is actually measured. The modal unit of those lines is the one to
 * offer. Nothing is invented — a product no recipe measures keeps no default.
 */
class DefaultUnits
{
    /**
     * Fills in `ingredients.default_unit_id` from recipe usage.
     *
     * Only empty ones by default: the dictionary is the authority for what it
     * covers, and derived usage must never quietly overrule a curated entry.
     *
     * @return int how many products were given a unit
     */
    public function fill(bool $overwrite = false): int
    {
        $targets = Ingredient::query()
            ->unless($overwrite, fn ($query) => $query->whereNull('default_unit_id'))
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->values()
            ->all();

        $byUnit = [];

        foreach ($this->pickPerIngredient($targets) as $ingredientId => $unitId) {
            $byUnit[$unitId][] = $ingredientId;
        }

        $filled = 0;

        foreach ($byUnit as $unitId => $ingredientIds) {
            foreach (array_chunk($ingredientIds, 500) as $chunk) {
                $filled += Ingredient::query()
                    ->whereIn('id', $chunk)
                    ->update(['default_unit_id' => $unitId]);
            }
        }

        return $filled;
    }

    /**
     * @param  array<int, int>  $targets
     * @return array<int, int> ingredient id => unit id
     */
    private function pickPerIngredient(array $targets): array
    {
        /** @var array<int, array{unit: int, uses: int, approximate: bool}> $best */
        $best = [];

        foreach (array_chunk($targets, 500) as $chunk) {
            foreach ($this->usage($chunk) as $row) {
                $ingredientId = (int) $row->ingredient_id;
                $candidate = [
                    'unit' => (int) $row->unit_id,
                    'uses' => (int) $row->uses,
                    'approximate' => (bool) $row->is_approximate,
                ];

                if (! isset($best[$ingredientId]) || $this->beats($candidate, $best[$ingredientId])) {
                    $best[$ingredientId] = $candidate;
                }
            }
        }

        return array_map(static fn (array $pick): int => $pick['unit'], $best);
    }

    /**
     * How often each product is measured in each unit.
     *
     * @param  list<int>  $ingredientIds
     * @return Collection<int, \stdClass>
     */
    private function usage(array $ingredientIds): Collection
    {
        return DB::table('recipe_ingredients')
            ->join('units', 'units.id', '=', 'recipe_ingredients.unit_id')
            ->select('recipe_ingredients.ingredient_id', 'recipe_ingredients.unit_id', 'units.is_approximate')
            ->selectRaw('count(*) as uses')
            ->whereIn('recipe_ingredients.ingredient_id', $ingredientIds)
            ->whereNotNull('recipe_ingredients.unit_id')
            ->groupBy('recipe_ingredients.ingredient_id', 'recipe_ingredients.unit_id', 'units.is_approximate')
            ->get();
    }

    /**
     * A pinch, a drop and a handful describe cooking, never a shelf: nobody
     * stocks "1 szczypta" of salt. So an exact unit wins however rare it is,
     * and an approximate one is kept only when it is all the catalogue has.
     *
     * @param  array{unit: int, uses: int, approximate: bool}  $candidate
     * @param  array{unit: int, uses: int, approximate: bool}  $incumbent
     */
    private function beats(array $candidate, array $incumbent): bool
    {
        if ($candidate['approximate'] !== $incumbent['approximate']) {
            return ! $candidate['approximate'];
        }

        if ($candidate['uses'] !== $incumbent['uses']) {
            return $candidate['uses'] > $incumbent['uses'];
        }

        // A tie must not depend on the order rows came back in, or two runs of
        // the same command would disagree.
        return $candidate['unit'] < $incumbent['unit'];
    }
}
