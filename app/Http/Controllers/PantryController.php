<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalogue\IngredientEmoji;
use App\Enums\IngredientCategory;
use App\Enums\StorageLocation;
use App\Models\Ingredient;
use App\Models\PantryItem;
use App\Models\Unit;
use App\Pantry\PutAway;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

/**
 * What this kitchen holds. Scoped to the account throughout: the couple share
 * one account and therefore one fridge, and nobody can read or touch another
 * account's shelves.
 */
class PantryController extends Controller
{
    public function __construct(
        private readonly IngredientEmoji $emoji,
        private readonly PutAway $putAway,
    ) {}

    public function index(Request $request): Response
    {
        $items = PantryItem::query()
            ->where('user_id', $request->user()->id)
            ->with(['ingredient:id,name,category,default_unit_id', 'unit:id,code,symbol'])
            ->get()
            ->sortBy(fn (PantryItem $item): string => $item->ingredient->name)
            ->values();

        $sections = [];

        foreach (StorageLocation::cases() as $location) {
            $sections[] = [
                'value' => $location->value,
                'label' => $location->label(),
                'tracksExpiry' => $location->tracksExpiry(),
                'items' => $items
                    ->where('location', $location)
                    ->map($this->present(...))
                    ->values()
                    ->all(),
            ];
        }

        return Inertia::render('Pantry/Index', [
            'sections' => $sections,
            /*
             * No OpenAI key, no camera button. A button that always answers 404
             * is worse than no button — and the endpoints behind it answer 404
             * too, so the two cannot disagree.
             */
            'photoEnabled' => config('vision.key') !== '',
            'units' => Unit::query()
                ->orderBy('dimension')
                ->get(['id', 'code', 'symbol', 'name'])
                ->map(fn (Unit $unit): array => [
                    'id' => $unit->id,
                    'symbol' => $unit->symbol,
                    'name' => $unit->name,
                ])
                ->all(),
            'expiringSoon' => PantryItem::query()
                ->where('user_id', $request->user()->id)
                ->expiringWithin(3)
                ->count(),
            /*
             * The whole product list, filtered in the browser. There are ~900 of
             * them — a few dozen kilobytes — and sending them once buys instant
             * type-ahead that also works with the phone offline, which a search
             * endpoint would not.
             */
            'ingredients' => Ingredient::query()
                ->where('category', '!=', IngredientCategory::Equipment->value)
                ->orderBy('name')
                ->get(['id', 'name', 'category', 'default_unit_id'])
                ->map(fn (Ingredient $ingredient): array => [
                    'id' => $ingredient->id,
                    'name' => $ingredient->name,
                    'emoji' => $this->emoji->for($ingredient->name, $ingredient->category),
                    'defaultUnitId' => $ingredient->default_unit_id,
                    // Both only suggestions — the sheet lets you change either.
                    'location' => StorageLocation::suggestFor($ingredient->category)->value,
                ])
                ->all(),
        ]);
    }

    public function store(Request $request): RedirectResponse
    {
        $data = $this->validated($request);

        // One product in one place is one row. The rule lives in `PutAway`,
        // because a photograph of the shelf is a second way to state it.
        $this->putAway->put($request->user()->id, $data);

        return back();
    }

    public function update(Request $request, PantryItem $pantryItem): RedirectResponse
    {
        $this->authoriseOwnership($request, $pantryItem);

        $data = $this->validated($request);

        /*
         * Moving a product onto a shelf that already holds it would break the
         * one-row-per-product-per-place rule the unique key exists to keep. An
         * edit states what is on that shelf, so it replaces what was there.
         */
        PantryItem::query()
            ->where('user_id', $request->user()->id)
            ->where('ingredient_id', $data['ingredient_id'])
            ->where('location', $data['location'])
            ->whereKeyNot($pantryItem->id)
            ->delete();

        $pantryItem->update($data);

        return back();
    }

    public function destroy(Request $request, PantryItem $pantryItem): RedirectResponse
    {
        $this->authoriseOwnership($request, $pantryItem);

        $pantryItem->delete();

        return back();
    }

    /**
     * @return array{ingredient_id: int, location: string, quantity: float|null, unit_id: int|null, expires_at: string|null, note: string|null}
     */
    private function validated(Request $request): array
    {
        $data = $request->validate([
            'ingredient_id' => ['required', 'exists:ingredients,id'],
            'location' => ['required', Rule::enum(StorageLocation::class)],
            'quantity' => ['nullable', 'numeric', 'min:0'],
            'unit_id' => ['nullable', 'exists:units,id'],
            'expires_at' => ['nullable', 'date'],
            'note' => ['nullable', 'string', 'max:255'],
        ]);

        /*
         * `validate()` returns only the keys that were sent, and the optional
         * ones are written straight into the row — so they have to be filled in
         * here, otherwise a save with no expiry date would blow up.
         */
        $data += ['quantity' => null, 'unit_id' => null, 'expires_at' => null, 'note' => null];

        // An amount without a unit is meaningless, and a unit without an amount
        // is noise; both empty is fine and means "I have some".
        if ($data['quantity'] !== null && $data['unit_id'] === null) {
            throw ValidationException::withMessages([
                'unit_id' => 'Podaj jednostkę albo usuń ilość.',
            ]);
        }

        return [
            'ingredient_id' => (int) $data['ingredient_id'],
            'location' => (string) $data['location'],
            'quantity' => $data['quantity'] === null ? null : (float) $data['quantity'],
            'unit_id' => $data['unit_id'] === null ? null : (int) $data['unit_id'],
            'expires_at' => $data['expires_at'] === null ? null : (string) $data['expires_at'],
            'note' => $data['note'] === null ? null : (string) $data['note'],
        ];
    }

    private function authoriseOwnership(Request $request, PantryItem $item): void
    {
        abort_unless($item->user_id === $request->user()->id, 404);
    }

    /**
     * @return array<string, mixed>
     */
    private function present(PantryItem $item): array
    {
        return [
            'id' => $item->id,
            'ingredientId' => $item->ingredient_id,
            'name' => $item->ingredient->name,
            'emoji' => $this->emoji->for($item->ingredient->name, $item->ingredient->category),
            'quantity' => $item->quantity,
            'unitId' => $item->unit_id,
            'unit' => $item->unit?->symbol,
            'expiresAt' => $item->expires_at?->toDateString(),
            'expired' => $item->isExpired(),
            'note' => $item->note,
        ];
    }
}
