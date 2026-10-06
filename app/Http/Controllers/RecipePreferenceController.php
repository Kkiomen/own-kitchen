<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Enums\RecipeVerdict;
use App\Models\Recipe;
use App\Models\RecipePreference;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * "Lubimy to" / "nie proponuj więcej". The planner reads these; this only
 * writes them down.
 */
class RecipePreferenceController extends Controller
{
    public function update(Request $request, Recipe $recipe): RedirectResponse
    {
        $data = $request->validate([
            'verdict' => ['required', Rule::enum(RecipeVerdict::class)],
        ]);

        // One verdict per recipe: changing your mind replaces it.
        RecipePreference::query()->updateOrCreate(
            ['user_id' => $request->user()->id, 'recipe_id' => $recipe->id],
            ['verdict' => $data['verdict']],
        );

        return back();
    }

    public function destroy(Request $request, Recipe $recipe): RedirectResponse
    {
        RecipePreference::query()
            ->of($request->user())
            ->where('recipe_id', $recipe->id)
            ->delete();

        return back();
    }
}
