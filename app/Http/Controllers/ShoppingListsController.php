<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Models\ShoppingList;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Unique;

/**
 * The lists themselves — making one, renaming it, throwing it away.
 *
 * Separate from `ShoppingListController`, which is about what is *on* a list:
 * one screen, two nouns, and mixing them would put "delete the barbecue list"
 * next to "delete the butter".
 */
class ShoppingListsController extends Controller
{
    public function store(Request $request): RedirectResponse
    {
        /*
         * The main list exists before any other can. Somebody whose very first
         * action is naming a list would otherwise own one list that is not the
         * default, and deleting it would leave them with none.
         */
        ShoppingList::defaultFor($request->user());

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', $this->uniquePerHousehold($request)],
        ]);

        $list = ShoppingList::query()->create([
            'user_id' => $request->user()->id,
            'name' => $data['name'],
            'is_default' => false,
        ]);

        /*
         * Straight to the new list. Somebody who just named one is about to put
         * something on it, and leaving them on the old one is how things land in
         * the wrong trolley.
         */
        return to_route('shopping.show', $list);
    }

    public function update(Request $request, ShoppingList $shoppingList): RedirectResponse
    {
        $this->authoriseOwnership($request, $shoppingList);

        $data = $request->validate([
            'name' => ['required', 'string', 'max:60', $this->uniquePerHousehold($request, $shoppingList)],
        ]);

        // The main list can be renamed — it just cannot be deleted.
        $shoppingList->update(['name' => $data['name']]);

        return back();
    }

    public function destroy(Request $request, ShoppingList $shoppingList): RedirectResponse
    {
        $this->authoriseOwnership($request, $shoppingList);

        /*
         * Refused rather than hidden: every screen that writes a line down needs
         * somewhere to put it, and an account with no list at all would break
         * the very screen that would have made a new one.
         */
        abort_unless($shoppingList->isDeletable(), 403);

        // Whatever was on it goes with it — that is what deleting a list means,
        // and the rows cascade in the database for the same reason.
        $shoppingList->delete();

        return to_route('shopping.index');
    }

    private function uniquePerHousehold(Request $request, ?ShoppingList $except = null): Unique
    {
        return Rule::unique('shopping_lists', 'name')
            ->where('user_id', $request->user()->id)
            ->ignore($except);
    }

    private function authoriseOwnership(Request $request, ShoppingList $list): void
    {
        abort_unless($list->user_id === $request->user()->id, 404);
    }
}
