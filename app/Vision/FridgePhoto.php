<?php

declare(strict_types=1);

namespace App\Vision;

use App\Catalogue\IngredientEmoji;
use App\Enums\StorageLocation;
use App\Importing\Resolving\IngredientResolver;
use App\Models\Ingredient;
use App\Models\Unit;
use App\Vision\Contracts\FridgeReader;
use App\Vision\Drafts\SpottedItem;
use Illuminate\Support\Collection;

/**
 * Turns a photograph of a shelf into a list of *proposals* for the kitchen.
 *
 * Proposals, not entries. Nothing here writes: what comes back is shown on
 * screen, corrected by whoever took the photo and only then saved. A model that
 * reads "śmietana" off a tub of yoghurt is a normal Tuesday, and a kitchen that
 * silently gained a product nobody owns is a shopping list that quietly stops
 * buying it.
 *
 * **A photo may never invent a product.** This calls `IngredientResolver::match()`
 * and never `resolve()` — the same rule, for the same reason, as shop leaflets.
 * A fridge photograph is full of things the catalogue has no name for: a foreign
 * label, a leftovers box, a bottle of something. Through `resolve()` each of
 * those would create a product that permanently owns its spelling as an alias,
 * and the next recipe import would resolve real ingredient lines onto it. That
 * is the "Sos:" incident with a camera attached. An unmatched line is kept and
 * shown with its raw text, so the cook can point it at the right product by
 * hand; nothing is dropped and nothing is guessed.
 */
final class FridgePhoto
{
    public function __construct(
        private readonly FridgeReader $reader,
        private readonly IngredientResolver $resolver,
        private readonly IngredientEmoji $emoji,
    ) {}

    /**
     * @return list<array<string, mixed>>
     *
     * @throws VisionUnavailable
     */
    public function read(string $image, string $mimeType): array
    {
        $units = Unit::query()->get(['id', 'code', 'symbol'])->keyBy('code');

        /** @var list<string> $codes */
        $codes = $units->keys()->all();

        $proposals = [];

        foreach ($this->reader->read($image, $mimeType, $codes) as $item) {
            $proposals[] = $this->propose($item, $units);
        }

        return $this->merged($proposals);
    }

    /**
     * @param  Collection<string, Unit>  $units
     * @return array<string, mixed>
     */
    private function propose(SpottedItem $item, Collection $units): array
    {
        $ingredient = $this->resolver->match($item->name);
        $unit = $item->unitCode === null ? null : $units->get($item->unitCode);

        return [
            /*
             * What the model actually wrote, kept whether or not it matched —
             * the evidence, exactly like a recipe line's `raw_text`. It is what
             * lets somebody see that "śmietana 18%" was read off the tub before
             * deciding it is soured cream.
             */
            'spotted' => $item->name,
            'ingredientId' => $ingredient?->id,
            'name' => $ingredient?->name,
            'emoji' => $ingredient === null
                ? null
                : $this->emoji->for($ingredient->name, $ingredient->category),
            // No unit means no amount: an amount with nothing to count it in is
            // refused by the pantry's own validation.
            'quantity' => $unit === null ? null : $item->quantity,
            'unitId' => $unit?->id,
            'unit' => $unit?->symbol,
            'location' => $this->shelfFor($ingredient)->value,
        ];
    }

    /**
     * The shelf this kind of thing usually lives on — the same guess the "Dodaj
     * zakupy" sheet makes, and changeable in exactly the same way. An unmatched
     * line has no category to guess from, so it starts in the fridge, which is
     * what was being photographed.
     */
    private function shelfFor(?Ingredient $ingredient): StorageLocation
    {
        return $ingredient === null
            ? StorageLocation::Fridge
            : StorageLocation::suggestFor($ingredient->category);
    }

    /**
     * One product is one row, here as everywhere else.
     *
     * A shelf holds two tubs of yoghurt and the model dutifully lists both. Two
     * rows would race each other on save — the pantry keeps one row per product
     * per place — and the second would silently overwrite the first. So they are
     * folded here, while the amounts can still be added up honestly: identical
     * units add, anything else becomes "some, amount unknown" rather than a
     * number nobody stated. Unmatched lines are never folded: two things we
     * could not name are not evidence of being the same thing.
     *
     * @param  list<array<string, mixed>>  $proposals
     * @return list<array<string, mixed>>
     */
    private function merged(array $proposals): array
    {
        $byProduct = [];
        $merged = [];

        foreach ($proposals as $proposal) {
            $id = $proposal['ingredientId'];

            if ($id === null) {
                $merged[] = $proposal;

                continue;
            }

            $seen = $byProduct[$id] ?? null;

            if ($seen === null) {
                $byProduct[$id] = count($merged);
                $merged[] = $proposal;

                continue;
            }

            $merged[$seen]['quantity'] = $this->combined($merged[$seen], $proposal);
        }

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $first
     * @param  array<string, mixed>  $second
     */
    private function combined(array $first, array $second): ?float
    {
        $sameUnit = $first['unitId'] !== null && $first['unitId'] === $second['unitId'];

        if (! $sameUnit || $first['quantity'] === null || $second['quantity'] === null) {
            return null;
        }

        return (float) $first['quantity'] + (float) $second['quantity'];
    }
}
