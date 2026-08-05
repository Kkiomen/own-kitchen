<?php

declare(strict_types=1);

namespace App\Shopping;

use App\Models\ShoppingList;
use App\Models\User;

/**
 * The household's lists as a screen needs to see them.
 *
 * Two screens ask the same question — the list page draws a switcher, the recipe
 * modal asks which list to write to — so the answer, including the guarantee
 * that a main list exists at all, lives in one place rather than in whichever
 * controller needed it first.
 */
final class ShoppingLists
{
    /**
     * @return list<array<string, mixed>>
     */
    public function of(User $user): array
    {
        // Created here if this account has never had one, so every caller can
        // assume there is somewhere to write to.
        ShoppingList::defaultFor($user);

        $lists = ShoppingList::query()
            ->of($user)
            ->withCount(['items as to_buy_count' => fn ($query) => $query->stillToBuy()])
            // The main one first, then by name: a switcher whose order changes
            // as things are ticked off moves the target under the thumb.
            ->orderByDesc('is_default')
            ->orderBy('name')
            ->get()
            ->map(static fn (ShoppingList $list): array => self::present($list))
            ->all();

        /*
         * `map()` keeps the collection's keys. The prop crosses to the browser
         * as JSON, where a gap in them arrives as an object rather than an
         * array — and the switcher, which iterates, would then render nothing.
         */
        return array_values($lists);
    }

    /**
     * @return array<string, mixed>
     */
    public static function present(ShoppingList $list): array
    {
        return [
            'id' => $list->id,
            'name' => $list->name,
            'isDefault' => $list->is_default,
            'deletable' => $list->isDeletable(),
            'toBuyCount' => $list->to_buy_count ?? null,
        ];
    }
}
