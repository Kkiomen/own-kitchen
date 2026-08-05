<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalogue\IngredientEmoji;
use App\Catalogue\RecipeScale;
use App\Enums\IngredientCategory;
use App\Enums\ShoppingAisle;
use App\Models\Ingredient;
use App\Models\Promotion;
use App\Models\Recipe;
use App\Models\ShoppingList;
use App\Models\ShoppingListItem;
use App\Models\Unit;
use App\Shopping\CostEstimate;
use App\Shopping\EstimatedLine;
use App\Shopping\SelectedShops;
use App\Shopping\ShoppingLists;
use App\Shopping\Trolley;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Co kupić" — the contents of one list. Scoped to the account throughout, like
 * the kitchen it feeds; which of the household's lists is open comes from the
 * URL, and `/zakupy` with nothing after it means the main one.
 */
class ShoppingListController extends Controller
{
    public function __construct(
        private readonly Trolley $trolley,
        private readonly ShoppingLists $lists,
        private readonly IngredientEmoji $emoji,
        private readonly CostEstimate $estimate,
        private readonly SelectedShops $shops,
    ) {}

    public function index(Request $request, ?ShoppingList $shoppingList = null): Response
    {
        $list = $this->resolve($request, $shoppingList);

        $items = ShoppingListItem::query()
            ->where('shopping_list_id', $list->id)
            /*
             * Units are loaded whole, not as `unit:id,code,symbol`.
             *
             * A trimmed one has a null `dimension`, and `Unit::definition()`
             * throws on that — so the moment anything on this screen asked a line
             * for its `Quantity`, which the cost estimate now does, the page died
             * with a type error naming a column nobody on this screen reads.
             * There are about a dozen unit rows in total; the saving was never
             * real and the trap is.
             */
            ->with([
                'ingredient:id,name,category,default_unit_id',
                'ingredient.defaultUnit',
                'unit',
            ])
            ->get();

        /*
         * Which of these are on offer somewhere. One query for the whole list
         * rather than one per line, and only ids: the list screen says "there is
         * a promotion", the plan screen says where and for how much.
         */
        $wanted = array_values(array_unique(array_map(
            static fn (ShoppingListItem $item): int => $item->ingredient_id,
            $items->all(),
        )));

        $promoted = array_flip(array_map(
            static fn (mixed $id): int => (int) $id,
            Promotion::query()
                ->active()
                ->forIngredients($wanted)
                ->distinct()
                ->pluck('ingredient_id')
                ->all(),
        ));

        /*
         * Priced against the chains the household said it drives to, so the
         * figure on this screen and the one on the plan are answering the same
         * question. Only what is still to buy: a ticked line is money already
         * spent, and leaving it in would make the trolley look more expensive the
         * further round the shop you got.
         */
        $estimate = $this->estimate->for(
            array_values($items->filter(
                static fn (ShoppingListItem $item): bool => ! $item->isBought(),
            )->all()),
            $this->shops->ids($request->user()),
        );

        $costs = $estimate->byItem();

        $aisles = [];

        foreach (ShoppingAisle::cases() as $aisle) {
            $inAisle = $items
                ->filter(fn (ShoppingListItem $item): bool => ShoppingAisle::for($item->ingredient->category) === $aisle)
                ->sortBy(fn (ShoppingListItem $item): string => $item->ingredient->name)
                ->values();

            // An aisle with nothing in it is a heading with no list under it.
            if ($inAisle->isEmpty()) {
                continue;
            }

            $aisles[] = [
                'value' => $aisle->value,
                'label' => $aisle->label(),
                'items' => $inAisle
                    ->map(fn (ShoppingListItem $item): array => $this->present($item, $promoted, $costs))
                    ->values()
                    ->all(),
            ];
        }

        return Inertia::render('Shopping/Index', [
            'list' => ShoppingLists::present($list),
            'lists' => $this->lists->of($request->user()),
            'aisles' => $aisles,
            'boughtCount' => $items->filter(fn (ShoppingListItem $item): bool => $item->isBought())->count(),
            'promotedCount' => $items
                ->filter(fn (ShoppingListItem $item): bool => ! $item->isBought() && isset($promoted[$item->ingredient_id]))
                ->count(),

            /*
             * Grosze, formatted in exactly one place on the other side. The count
             * of unpriced lines travels with the total because they are one
             * statement: "około 84 zł" and "około 84 zł, i sześciu rzeczy nie
             * umiem wycenić" are different claims, and a screen holding only the
             * first would understate the bill invisibly.
             */
            'estimate' => [
                'total' => $estimate->total()->grosze,
                'promoted' => $estimate->promoted()->grosze,
                'priced' => $estimate->pricedCount(),
                'unpriced' => $estimate->unpricedCount(),
                'hasAnyPrice' => $estimate->hasAnyPrice(),
            ],
            'units' => Unit::query()
                ->orderBy('dimension')
                ->get(['id', 'symbol', 'name'])
                ->map(fn (Unit $unit): array => [
                    'id' => $unit->id,
                    'symbol' => $unit->symbol,
                    'name' => $unit->name,
                ])
                ->all(),
            'ingredients' => Ingredient::query()
                ->where('category', '!=', IngredientCategory::Equipment->value)
                ->orderBy('name')
                ->get(['id', 'name', 'category', 'default_unit_id'])
                ->map(fn (Ingredient $ingredient): array => [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'emoji' => $this->emoji->for($ingredient->name, $ingredient->category),
                    'defaultUnitId' => $ingredient->default_unit_id,
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'ingredient_id' => ['required', 'exists:ingredients,id'],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'note' => ['nullable', 'string', 'max:255'],
            'shopping_list_id' => ['nullable', 'integer'],
        ]);

        $data += ['quantity' => null, 'unit_id' => null, 'note' => null];

        if ($data['quantity'] !== null && $data['unit_id'] === null) {
            throw ValidationException::withMessages([
                'unit_id' => 'Podaj jednostkę albo usuń ilość.',
            ]);
        }

        $unit = $data['unit_id'] === null
            ? null
            : Unit::query()->whereKey($data['unit_id'])->firstOrFail();

        $this->trolley->add(
            $this->targetList($request),
            (int) $data['ingredient_id'],
            $unit === null || $data['quantity'] === null ? null : $unit->quantity((float) $data['quantity']),
            $data['note'],
        );

        return back();
    }

    /**
     * The two things that change about a line already on the list: it gets
     * ticked off in the shop, and its amount gets corrected at the shelf.
     */
    public function update(Request $request, ShoppingListItem $shoppingListItem): RedirectResponse
    {
        $this->authoriseOwnership($request, $shoppingListItem);

        $data = $request->validate([
            'bought' => ['sometimes', 'boolean'],
            'quantity' => ['sometimes', 'nullable', 'numeric', 'min:0'],
        ]);

        if (array_key_exists('bought', $data)) {
            $shoppingListItem->bought_at = $data['bought'] ? now() : null;
        }

        if (array_key_exists('quantity', $data)) {
            $this->setQuantity($shoppingListItem, $data['quantity'] === null ? null : (float) $data['quantity']);
        }

        $shoppingListItem->save();

        return back();
    }

    /**
     * An amount needs a unit to mean anything, so a line that never had one
     * borrows the unit the product is normally bought in. Where even that is
     * unknown there is no honest amount to write down — the line stays as it
     * was, which reads as "some, amount unknown" rather than a number nobody
     * chose. The screen hides the stepper in that case; this is the guard
     * behind it.
     */
    private function setQuantity(ShoppingListItem $item, ?float $quantity): void
    {
        if ($quantity === null || $quantity <= 0.0) {
            $item->quantity = null;

            return;
        }

        $unitId = $item->unit_id ?? $item->ingredient->default_unit_id;

        if ($unitId === null) {
            throw ValidationException::withMessages([
                'quantity' => 'Nie wiadomo, w czym mierzyć ten produkt.',
            ]);
        }

        $item->unit_id = $unitId;
        $item->quantity = $quantity;
    }

    public function destroy(Request $request, ShoppingListItem $shoppingListItem): RedirectResponse
    {
        $this->authoriseOwnership($request, $shoppingListItem);

        $shoppingListItem->delete();

        return back();
    }

    /**
     * Everything this recipe needs that the kitchen cannot supply.
     */
    public function addRecipe(Request $request, Recipe $recipe): RedirectResponse
    {
        $list = $this->targetList($request);
        $this->scale($request, $recipe);
        $result = $this->trolley->addMissingFor($list, $recipe);

        return back()->with('shopping', $result + ['list' => $list->name, 'listId' => $list->id]);
    }

    /**
     * The rest of it: what the kitchen said it had. Asked for explicitly, from
     * the screen, after the shortfall has already been written down.
     */
    public function addRecipeHeld(Request $request, Recipe $recipe): RedirectResponse
    {
        $list = $this->targetList($request);
        $this->scale($request, $recipe);
        $result = $this->trolley->addHeldFor($list, $recipe);

        return back()->with('shopping', $result + ['list' => $list->name, 'listId' => $list->id]);
    }

    /**
     * Buy for the number of portions the screen was showing.
     *
     * The modal scales what it displays; a button underneath it that quietly
     * bought the original amounts would be the worst kind of wrong — invisible,
     * and only discovered at the stove. The lines are loaded here so that the
     * factor lands on the same instances `Trolley` will read: its own
     * `loadMissing()` then finds the relation present and leaves it alone.
     */
    private function scale(Request $request, Recipe $recipe): void
    {
        $recipe->load('ingredients.ingredient', 'ingredients.unit');

        RecipeScale::for($recipe, $request->integer('porcje') ?: null)->applyTo($recipe);
    }

    /**
     * Unpack the trolley: what was ticked off lands in the kitchen.
     */
    public function stockUp(Request $request, ?ShoppingList $shoppingList = null): RedirectResponse
    {
        $stocked = $this->trolley->stockUp($this->resolve($request, $shoppingList));

        return back()->with('shopping', ['stocked' => $stocked]);
    }

    /**
     * Which list a write lands on. Named in the request when the screen asked
     * which, and the main one otherwise — every screen that writes a line down
     * has to have somewhere to put it without a decision.
     */
    private function targetList(Request $request): ShoppingList
    {
        /*
         * A recipe can start a list of its own. Creating it here rather than
         * sending the screen to the lists page and back keeps "add this dish to
         * a new list for Saturday" one tap, and the modal never has to navigate
         * away from the recipe being read.
         */
        $name = trim((string) $request->input('new_list_name', ''));

        if ($name !== '') {
            // As in `ShoppingListsController::store()`: the main list exists
            // before any other can, whichever screen makes the first one.
            ShoppingList::defaultFor($request->user());

            $request->merge(['new_list_name' => $name]);
            $request->validate([
                'new_list_name' => [
                    'string',
                    'max:60',
                    Rule::unique('shopping_lists', 'name')->where('user_id', $request->user()->id),
                ],
            ]);

            return ShoppingList::query()->create([
                'user_id' => $request->user()->id,
                'name' => $name,
                'is_default' => false,
            ]);
        }

        $id = $request->integer('shopping_list_id');

        if ($id === 0) {
            return ShoppingList::defaultFor($request->user());
        }

        $list = ShoppingList::query()->of($request->user())->whereKey($id)->first();

        // Another household's list is not a permission problem to explain, it is
        // a list that does not exist — the same answer registration gives.
        abort_if($list === null, 404);

        return $list;
    }

    private function resolve(Request $request, ?ShoppingList $list): ShoppingList
    {
        if ($list === null) {
            return ShoppingList::defaultFor($request->user());
        }

        abort_unless($list->user_id === $request->user()->id, 404);

        return $list;
    }

    private function authoriseOwnership(Request $request, ShoppingListItem $item): void
    {
        abort_unless($item->list->user_id === $request->user()->id, 404);
    }

    /**
     * @param  array<int, int>  $promoted  ingredient ids that are on offer somewhere
     * @param  array<int, EstimatedLine>  $costs  keyed by item id; a ticked line has none
     * @return array<string, mixed>
     */
    private function present(ShoppingListItem $item, array $promoted, array $costs): array
    {
        // What an amount typed on this row would be counted in: what it already
        // says, or failing that how the product is normally bought.
        $measure = $item->unit ?? $item->ingredient->defaultUnit;

        // A ticked line was never estimated — it is money already spent.
        $cost = $costs[$item->id] ?? null;

        return [
            'id' => $item->id,
            'name' => $item->ingredient->name,
            'emoji' => $this->emoji->for($item->ingredient->name, $item->ingredient->category),
            'quantity' => $item->quantity,
            'unit' => $item->unit?->symbol,
            'measureUnit' => $measure?->symbol,
            'measureCode' => $measure?->code,
            'bought' => $item->isBought(),
            'note' => $item->note,
            'promoted' => isset($promoted[$item->ingredient_id]),

            /*
             * Null on a ticked line and on one nothing could price — two
             * different silences that happen to look the same here, which is
             * fine: neither has a figure to show. `costBasis` is what tells the
             * row how much to lean on the number it does have.
             */
            'cost' => $cost?->cost?->grosze,
            'costBasis' => $cost?->basis,
        ];
    }
}
