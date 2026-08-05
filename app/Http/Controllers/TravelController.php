<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Travel\DealFilters;
use App\Travel\TravelBoard;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Gdzie polecieć na wakacje, żeby wyszło tanio."
 *
 * The deals themselves live in another app of ours that watches airline and blog
 * prices; this screen is a window onto it, so that looking for a trip is one tap
 * from the shopping list rather than a second app to remember. Nothing is stored
 * here and nothing is written there — the scanning stays that app's job.
 */
class TravelController extends Controller
{
    public function __construct(private readonly TravelBoard $board) {}

    public function index(Request $request): Response
    {
        return Inertia::render('Travel/Index', [
            /*
             * Eager, unlike the catalogue's closures: every partial visit this
             * screen makes *is* a filter change, so the board is exactly what
             * was asked for. One request answers the whole screen and it is
             * cached for a couple of minutes anyway.
             */
            ...$this->board->for(DealFilters::fromRequest($request)),

            /*
             * A closure, and this one earns it: the vocabulary the controls are
             * built from cannot change when a filter does, so a partial visit
             * that did not ask for it must not spend a second HTTP call on it.
             */
            'meta' => fn (): array => $this->board->meta(),
        ]);
    }
}
