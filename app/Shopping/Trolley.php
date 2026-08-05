<?php

declare(strict_types=1);

namespace App\Shopping;

use App\Enums\StorageLocation;
use App\Models\PantryItem;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Pantry\Pantry;
use App\Pantry\RecipeAvailability;
use App\Support\Measurement\MeasureBook;
use App\Support\Measurement\Quantity;
use Illuminate\Support\Facades\DB;

/**
 * Filling and emptying a shopping list.
 *
 * Named for the trolley rather than the list because a household now keeps
 * several lists (`App\Models\ShoppingList` is one of them) and this is the
 * behaviour that acts on whichever one is open: put things in, take them out,
 * unpack them into the kitchen.
 *
 * One product is one line however many recipes asked for it — the trolley does
 * not care that the courgette is wanted twice — so everything here merges rather
 * than appends. That is per list: the same butter on the weekly shop and on the
 * barbecue list is two honest lines, not a duplicate.
 */
final class Trolley
{
    public function __construct(
        private readonly RecipeAvailability $availability,
        private readonly MeasureBook $measures,
    ) {}

    /**
     * Put a product on the list, adding to whatever is already written there.
     */
    public function add(ShoppingList $list, int $ingredientId, ?Quantity $quantity = null, ?string $note = null): ShoppingListItem
    {
        return DB::transaction(function () use ($list, $ingredientId, $quantity, $note): ShoppingListItem {
            $item = ShoppingListItem::query()
                ->where('shopping_list_id', $list->id)
                ->where('ingredient_id', $ingredientId)
                ->lockForUpdate()
                ->first();

            if ($item === null) {
                return ShoppingListItem::query()->create([
                    'shopping_list_id' => $list->id,
                    'ingredient_id' => $ingredientId,
                    'quantity' => $quantity?->amount,
                    'unit_id' => $quantity === null ? null : $this->unitId($quantity),
                    'note' => $note,
                ]);
            }

            /*
             * Adding to a line already ticked off means it is wanted again, so
             * the tick goes and the amount starts from what was just asked for
             * rather than from what was bought on the last trip.
             */
            $running = $item->isBought() ? null : $item->toQuantity();
            $total = $item->isBought()
                ? $quantity
                : $this->measures->for($ingredientId)->sum($running, $quantity);

            $item->update([
                'quantity' => $total?->amount,
                'unit_id' => $total === null ? null : $this->unitId($total),
                'bought_at' => null,
                'note' => $note ?? $item->note,
            ]);

            return $item;
        });
    }

    /**
     * Write down everything this recipe needs that the kitchen cannot supply.
     *
     * Lines the importer never recognised are counted but not added: we have no
     * product to put on the list, and a line of raw text would be a duplicate
     * waiting to happen — exactly what the whole catalogue is built to avoid.
     *
     * `skipped` is what the kitchen claimed to cover, and it is reported rather
     * than assumed away: an entry with no amount means "some, unknown", which is
     * honestly not a shortage but is also not a promise that 500 g of flour is
     * on the shelf. The screen offers those as a second, deliberate tap — see
     * `addHeldFor()`.
     *
     * The kitchen is the household's, whichever list is being written to: what
     * you own does not change with the trip you are planning.
     *
     * @return array{added: int, unknown: int, skipped: int}
     */
    public function addMissingFor(ShoppingList $list, Recipe $recipe): array
    {
        $recipe->loadMissing('ingredients.ingredient', 'ingredients.unit');

        $pantry = Pantry::of($list->user, $this->measures);
        $added = 0;
        $unknown = 0;

        foreach ($this->availability->missingFor($recipe, $pantry) as $missing) {
            $ingredient = $missing['line']->ingredient;

            if ($ingredient === null) {
                $unknown++;

                continue;
            }

            $this->add($list, $ingredient->id, $missing['quantity']);
            $added++;
        }

        return [
            'added' => $added,
            'unknown' => $unknown,
            'skipped' => count($this->availability->heldFor($recipe, $pantry)),
        ];
    }

    /**
     * Write down the rest: the lines left off because the kitchen says it has
     * them.
     *
     * The other half of `addMissingFor()`, and never the default. A shelf
     * recorded without an amount, or a product nobody has weighed, silently
     * removes a line from the list — right, in that we will not invent a
     * shortage, but wrong for the cook who knows the packet is nearly empty.
     * This is how that person gets those lines, without the app guessing on
     * everybody's behalf.
     *
     * @return array{added: int}
     */
    public function addHeldFor(ShoppingList $list, Recipe $recipe): array
    {
        $recipe->loadMissing('ingredients.ingredient', 'ingredients.unit');

        $added = 0;

        foreach ($this->availability->heldFor($recipe, Pantry::of($list->user, $this->measures)) as $held) {
            $ingredient = $held['line']->ingredient;

            if ($ingredient === null) {
                continue;
            }

            $this->add($list, $ingredient->id, $held['quantity']);
            $added++;
        }

        return ['added' => $added];
    }

    /**
     * Move everything ticked off into the kitchen and clear it from the list.
     *
     * This is the point of writing the list down here rather than on paper: the
     * shopping you just did becomes the fridge you can cook from, without
     * anybody typing it a second time. The shelf is the usual one for that kind
     * of product and can be corrected on the kitchen screen.
     *
     * @return int how many products were put away
     */
    public function stockUp(ShoppingList $list): int
    {
        $userId = $list->user_id;

        $bought = ShoppingListItem::query()
            ->where('shopping_list_id', $list->id)
            ->whereNotNull('bought_at')
            ->with(['ingredient', 'unit'])
            ->get();

        foreach ($bought as $item) {
            $location = StorageLocation::suggestFor($item->ingredient->category);

            $existing = PantryItem::query()
                ->where('user_id', $userId)
                ->where('ingredient_id', $item->ingredient_id)
                ->where('location', $location)
                ->with('unit')
                ->first();

            // Shopping adds to the shelf; it never replaces what is on it.
            $total = $existing === null
                ? $item->toQuantity()
                : $this->measures->for($item->ingredient_id)->sum(
                    $existing->toQuantity(),
                    $item->toQuantity(),
                );

            PantryItem::query()->updateOrCreate(
                [
                    'user_id' => $userId,
                    'ingredient_id' => $item->ingredient_id,
                    'location' => $location,
                ],
                [
                    'quantity' => $total?->amount,
                    'unit_id' => $total === null ? null : $this->unitId($total),
                ],
            );

            $item->delete();
        }

        return $bought->count();
    }

    /**
     * A unit row's id for a quantity that came back from the value object, which
     * knows units by code rather than by primary key. Adding a whole recipe asks
     * this a dozen times over a closed vocabulary, so it is looked up once.
     *
     * @var array<string, int>|null
     */
    private ?array $unitIds = null;

    private function unitId(Quantity $quantity): ?int
    {
        $this->unitIds ??= Unit::query()->pluck('id', 'code')->all();

        return $this->unitIds[$quantity->unit->code] ?? null;
    }
}
