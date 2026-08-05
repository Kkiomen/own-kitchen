<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Shopping\SelectedShops;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;

/**
 * "Do których sklepów jadę" — the one choice the plan and the cost estimate both
 * read.
 *
 * It has no screen of its own. The choice belongs beside the plan it changes,
 * because a chain ticked on a settings page three taps away is a chain nobody
 * remembers ticking when the plan later fails to mention Lidl.
 */
class ShopSelectionController extends Controller
{
    public function __construct(private readonly SelectedShops $shops) {}

    public function update(Request $request): RedirectResponse
    {
        $data = $request->validate([
            // Present but empty is a real answer — "I have no chain in mind" —
            // and it puts the plan back to considering all of them.
            'shops' => ['present', 'array'],
            'shops.*' => ['integer', 'exists:shops,id'],
        ]);

        $this->shops->replace($request->user(), array_values(array_map(intval(...), $data['shops'])));

        // Back rather than to a named route: the plan carries its strategy in the
        // query string, and sending them to the bare URL would silently reset it.
        return back();
    }
}
