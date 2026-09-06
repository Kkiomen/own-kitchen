<?php

declare(strict_types=1);

namespace App\Catalogue;

use App\Enums\UnitDimension;
use App\Models\Unit;
use App\Support\Measurement\MeasureBook;
use App\Support\Measurement\Quantity;
use App\Support\Measurement\UnitDefinition;
use Illuminate\Support\Facades\DB;

/**
 * Which measures make sense for one product.
 *
 * `DefaultUnits` answers "what is this normally measured in" with a single unit.
 * This is the same question asked of a *list*: the kitchen form and the fridge
 * photo both offer a unit picker, and offering all twenty-one measures to every
 * product is how a tub of yoghurt gets written down in ząbki. A vocabulary that
 * is closed on the database side is still wide open on the screen unless
 * somebody narrows it, and the catalogue already knows how to — ~98 000 recipe
 * lines say how each product is actually measured.
 *
 * Nothing is *forbidden*: the shortlist is the first group of the picker and the
 * rest of the vocabulary is the second. A product this app has never seen
 * measured has no opinion to offer, so it is absent here and its picker shows
 * everything, exactly as it did before.
 *
 * Three things are added to what the recipes say, and each is a different kind
 * of truth:
 *
 * - **Pack sizes.** A fridge holds a kilo of what recipes count in grams and a
 *   litre of what they count in millilitres. Those convert exactly, so offering
 *   them costs nothing and saves typing "1000".
 * - **What the product has been weighed in** (`ingredient_measures`). A slice of
 *   cheese and a ząbek of garlic are things on a shelf, and they convert by
 *   definition.
 * - **The product's own default**, which is what the form prefills; a picker
 *   whose first group excluded the value already in the field would be absurd.
 *
 * Approximate measures are dropped outright: a szczypta, a kropla and a garść
 * describe cooking, never a shelf. That is the rule `DefaultUnits` already
 * applies when picking one.
 */
class IngredientUnits
{
    /** How many measures a product is offered before the list stops being a shortlist. */
    public const int MOST = 6;

    /**
     * Below this share of the product's commonest measure, a unit is one
     * recipe's oddity rather than a way this product is measured. Cheese is
     * written down in `ml` exactly once in ~1 400 lines.
     */
    private const float FRINGE = 0.05;

    /** @var array<int, Unit>|null */
    private ?array $units = null;

    /** @var array<string, int>|null */
    private ?array $byCode = null;

    /** @var array<int, UnitDefinition>|null */
    private ?array $definitions = null;

    /** @var array<int, int>|null */
    private ?array $defaults = null;

    public function __construct(private readonly MeasureBook $measures) {}

    /**
     * Every product that has an opinion about its measures.
     *
     * Products with no opinion are absent rather than present with an empty
     * list: "we know of nothing" and "we know of none" read the same in JSON,
     * and only the first is ever true here.
     *
     * @return array<int, array{units: list<int>, unconvertible: list<int>}>
     */
    public function all(): array
    {
        $shortlists = [];

        foreach ($this->usage() as $ingredientId => $uses) {
            $shortlist = $this->shortlist($ingredientId, $uses);

            if ($shortlist === []) {
                continue;
            }

            $shortlists[$ingredientId] = [
                'units' => $shortlist,
                'unconvertible' => $this->unconvertible($ingredientId, $shortlist),
            ];
        }

        return $shortlists;
    }

    /**
     * @param  array<int, int>  $uses  unit id => how many recipe lines
     * @return list<int>
     */
    private function shortlist(int $ingredientId, array $uses): array
    {
        arsort($uses);

        $threshold = max(1.0, (float) reset($uses) * self::FRINGE);
        $kept = [];

        foreach ($uses as $unitId => $count) {
            if (count($kept) >= self::MOST || $count < $threshold) {
                break;
            }

            $kept[] = $unitId;
        }

        foreach ($this->alsoOffer($ingredientId, $kept) as $unitId) {
            if (! in_array($unitId, $kept, true)) {
                $kept[] = $unitId;
            }
        }

        return $kept;
    }

    /**
     * The pack sizes, the weighed measures and the product's own default.
     *
     * @param  list<int>  $kept
     * @return list<int>
     */
    private function alsoOffer(int $ingredientId, array $kept): array
    {
        $units = $this->units();
        $byCode = $this->byCode();
        $dimensions = [];

        foreach ($kept as $unitId) {
            $dimensions[$units[$unitId]->dimension->value] = true;
        }

        $extra = [];

        if (isset($dimensions[UnitDimension::Mass->value])) {
            $extra[] = $byCode['g'] ?? null;
            $extra[] = $byCode['kg'] ?? null;
        }

        if (isset($dimensions[UnitDimension::Volume->value])) {
            $extra[] = $byCode['ml'] ?? null;
            $extra[] = $byCode['l'] ?? null;
        }

        foreach (array_keys($this->measures->for($ingredientId)->gramsPerUnit) as $code) {
            $extra[] = $byCode[$code] ?? null;
        }

        $extra[] = $this->defaults()[$ingredientId] ?? null;

        return array_values(array_filter(
            $extra,
            fn (?int $unitId): bool => $unitId !== null
                && isset($units[$unitId])
                && ! $units[$unitId]->is_approximate,
        ));
    }

    /**
     * The offered measures this product has never been weighed in.
     *
     * They are still perfectly good things to hold — a slice of something is a
     * slice — but nothing can compare them with a recipe's grams, so the screen
     * says so rather than letting the kitchen look as though it answers a
     * question it will then go quiet on. A mass always converts; a volume needs
     * a density and a count needs a weight.
     *
     * @param  list<int>  $shortlist
     * @return list<int>
     */
    private function unconvertible(int $ingredientId, array $shortlist): array
    {
        $measures = $this->measures->for($ingredientId);
        $definitions = $this->definitions();
        $unknown = [];

        foreach ($shortlist as $unitId) {
            if ($measures->toGrams(new Quantity(1.0, $definitions[$unitId])) === null) {
                $unknown[] = $unitId;
            }
        }

        return $unknown;
    }

    /**
     * How often each product is measured in each unit, approximate measures
     * left out. One grouped query over the whole catalogue: this is asked once
     * per page that offers a picker, and per-product queries would be ~1 600 of
     * them for the same answer.
     *
     * @return array<int, array<int, int>> ingredient id => unit id => uses
     */
    private function usage(): array
    {
        $rows = DB::table('recipe_ingredients')
            ->join('units', 'units.id', '=', 'recipe_ingredients.unit_id')
            ->select('recipe_ingredients.ingredient_id', 'recipe_ingredients.unit_id')
            ->selectRaw('count(*) as uses')
            ->whereNotNull('recipe_ingredients.ingredient_id')
            ->where('units.is_approximate', false)
            ->groupBy('recipe_ingredients.ingredient_id', 'recipe_ingredients.unit_id')
            ->get();

        $usage = [];

        foreach ($rows as $row) {
            $usage[(int) $row->ingredient_id][(int) $row->unit_id] = (int) $row->uses;
        }

        return $usage;
    }

    /**
     * @return array<int, int>
     */
    private function defaults(): array
    {
        if ($this->defaults !== null) {
            return $this->defaults;
        }

        $defaults = [];

        foreach (DB::table('ingredients')->whereNotNull('default_unit_id')->select('id', 'default_unit_id')->get() as $row) {
            $defaults[(int) $row->id] = (int) $row->default_unit_id;
        }

        return $this->defaults = $defaults;
    }

    /**
     * @return array<int, Unit>
     */
    private function units(): array
    {
        /** @var array<int, Unit> $units */
        $units = $this->units ??= Unit::query()->get()->keyBy('id')->all();

        return $units;
    }

    /**
     * @return array<string, int>
     */
    private function byCode(): array
    {
        if ($this->byCode !== null) {
            return $this->byCode;
        }

        $byCode = [];

        foreach ($this->units() as $unit) {
            $byCode[$unit->code] = $unit->id;
        }

        return $this->byCode = $byCode;
    }

    /**
     * @return array<int, UnitDefinition>
     */
    private function definitions(): array
    {
        if ($this->definitions !== null) {
            return $this->definitions;
        }

        $definitions = [];

        foreach ($this->units() as $id => $unit) {
            $definitions[$id] = $unit->definition();
        }

        return $this->definitions = $definitions;
    }
}
