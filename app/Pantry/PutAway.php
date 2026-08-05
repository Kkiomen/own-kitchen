<?php

declare(strict_types=1);

namespace App\Pantry;

use App\Enums\StorageLocation;
use App\Models\PantryItem;

/**
 * Puts one product on one shelf.
 *
 * Extracted when the fridge photo became a second way in. The rule it holds —
 * **the same product in the same place is one row, never two** — is the one the
 * unique key exists for, and two copies of it would drift the first time one of
 * them learned something the other did not. A second "ser in the fridge" row
 * makes every amount comparison in the app wrong.
 *
 * It *states* what is on the shelf rather than adding to it, which is what the
 * kitchen form and a photograph both mean. (`Trolley::stockUp()` deliberately
 * does the opposite: unpacking shopping adds to what is already there.)
 */
final class PutAway
{
    /**
     * @param  array{ingredient_id: int, location: StorageLocation|string, quantity?: float|null, unit_id?: int|null, expires_at?: string|null, note?: string|null}  $item
     */
    public function put(int $userId, array $item): PantryItem
    {
        return PantryItem::query()->updateOrCreate(
            [
                'user_id' => $userId,
                'ingredient_id' => $item['ingredient_id'],
                'location' => $item['location'],
            ],
            [
                'quantity' => $item['quantity'] ?? null,
                'unit_id' => $item['unit_id'] ?? null,
                'expires_at' => $item['expires_at'] ?? null,
                'note' => $item['note'] ?? null,
            ],
        );
    }
}
