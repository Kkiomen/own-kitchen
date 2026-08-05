<?php

use App\Http\Controllers\Auth\RegisteredUserController;
use App\Http\Controllers\Auth\SessionController;
use App\Http\Controllers\CookingController;
use App\Http\Controllers\DeviceLinkController;
use App\Http\Controllers\MealPlanController;
use App\Http\Controllers\PantryController;
use App\Http\Controllers\PantryPhotoController;
use App\Http\Controllers\PriceHistoryController;
use App\Http\Controllers\PushSubscriptionController;
use App\Http\Controllers\RecipeController;
use App\Http\Controllers\ShoppingListController;
use App\Http\Controllers\ShoppingListsController;
use App\Http\Controllers\ShoppingPlanController;
use App\Http\Controllers\ShopSelectionController;
use App\Http\Controllers\TaskController;
use App\Http\Controllers\TravelController;
use Illuminate\Support\Facades\Route;

Route::middleware('guest')->group(function (): void {
    Route::get('/rejestracja', [RegisteredUserController::class, 'create'])->name('register');
    // Throttled like the login form now that anybody may reach it: an open
    // sign-up is a write endpoint a stranger can call.
    Route::post('/rejestracja', [RegisteredUserController::class, 'store'])->middleware('throttle:6,1');

    Route::get('/logowanie', [SessionController::class, 'create'])->name('login');
    // Throttled by address so the form cannot be used to guess a password.
    Route::post('/logowanie', [SessionController::class, 'store'])->middleware('throttle:6,1');

    /*
     * The camera half of the device link. It writes nothing and reads nothing —
     * the scanning happens in the browser and the code is redeemed on the route
     * below — so there is no throttle here; the rate limit that matters is on
     * the redemption.
     */
    Route::get('/logowanie/kod', [DeviceLinkController::class, 'scan'])->name('device-link.scan');
});

/*
 * Outside the guest group on purpose: scanning a code on a phone that is already
 * signed in as someone else must still work, and simply switches the account.
 */
Route::get('/dolacz/{token}', [DeviceLinkController::class, 'redeem'])
    ->middleware('throttle:10,1')
    ->name('device-link.redeem');

Route::middleware('auth')->group(function (): void {
    Route::get('/', [RecipeController::class, 'index'])->name('home');
    Route::get('/przepis/{recipe:slug}', [RecipeController::class, 'show'])->name('recipes.show');
    /*
     * A page of its own, not a mode of the modal: it has to survive a reload and
     * be shareable to the other phone, and a timer that outlives the app must
     * have somewhere to come back to.
     */
    Route::get('/przepis/{recipe:slug}/gotowanie', [CookingController::class, 'show'])->name('cooking.show');

    /*
     * `zrobione` before `{task}`, or "zrobione" is read as a task id — the same
     * reason `lista/` is in the path of the shopping routes.
     */
    Route::get('/zadania', [TaskController::class, 'index'])->name('tasks.index');
    Route::post('/zadania', [TaskController::class, 'store'])->name('tasks.store');
    Route::delete('/zadania/zrobione', [TaskController::class, 'clearDone'])->name('tasks.clear-done');
    Route::patch('/zadania/{task}', [TaskController::class, 'update'])->name('tasks.update');
    Route::delete('/zadania/{task}', [TaskController::class, 'destroy'])->name('tasks.destroy');

    /*
     * This phone's permission to be told about a new task. Not under /zadania:
     * it is a property of the device rather than of the list, and the next thing
     * worth announcing will not be a task.
     */
    Route::post('/powiadomienia', [PushSubscriptionController::class, 'store'])->name('push.subscribe');
    Route::delete('/powiadomienia', [PushSubscriptionController::class, 'destroy'])->name('push.unsubscribe');

    Route::get('/lodowka', [PantryController::class, 'index'])->name('pantry.index');
    Route::post('/lodowka', [PantryController::class, 'store'])->name('pantry.store');
    /*
     * Declared before `/lodowka/{pantryItem}` for the same reason
     * `/zakupy/sklepy` is: "zdjecie" must never be read as an item id.
     *
     * Throttled because reading one costs a call to a paid model — a stuck
     * finger on the shutter is the only way this app can spend real money.
     */
    Route::post('/lodowka/zdjecie', [PantryPhotoController::class, 'read'])
        ->middleware('throttle:12,1')
        ->name('pantry.photo.read');
    Route::post('/lodowka/zdjecie/zapisz', [PantryPhotoController::class, 'confirm'])
        ->name('pantry.photo.confirm');
    Route::patch('/lodowka/{pantryItem}', [PantryController::class, 'update'])->name('pantry.update');
    Route::delete('/lodowka/{pantryItem}', [PantryController::class, 'destroy'])->name('pantry.destroy');

    /*
     * Named `meal-plan.*`, not `plan.*`: `shopping.plan` below is a different
     * plan entirely — which shop to drive to — and two things called "plan" in
     * one route file is how the wrong one gets linked.
     */
    Route::get('/jadlospis', [MealPlanController::class, 'index'])->name('meal-plan.index');
    Route::post('/jadlospis', [MealPlanController::class, 'store'])->name('meal-plan.store');
    Route::post('/jadlospis/zakupy', [MealPlanController::class, 'shop'])->name('meal-plan.shop');
    Route::post('/jadlospis/generuj', [MealPlanController::class, 'generate'])->name('meal-plan.generate');
    Route::post('/jadlospis/{mealPlanEntry}/inne-danie', [MealPlanController::class, 'swap'])->name('meal-plan.swap');
    Route::patch('/jadlospis/{mealPlanEntry}', [MealPlanController::class, 'update'])->name('meal-plan.update');
    Route::delete('/jadlospis/{mealPlanEntry}', [MealPlanController::class, 'destroy'])->name('meal-plan.destroy');

    /*
     * A household keeps several lists and always has a main one, so every route
     * here comes in two forms: without a list (the main one, and the URL
     * somebody bookmarks) and with one. `lista/` sits in the path so a list can
     * never be mistaken for an item id.
     */
    Route::get('/zakupy', [ShoppingListController::class, 'index'])->name('shopping.index');
    Route::get('/zakupy/lista/{shoppingList}', [ShoppingListController::class, 'index'])->name('shopping.show');
    Route::get('/zakupy/plan', [ShoppingPlanController::class, 'index'])->name('shopping.plan');
    Route::get('/zakupy/lista/{shoppingList}/plan', [ShoppingPlanController::class, 'index'])->name('shopping.list-plan');
    Route::post('/zakupy', [ShoppingListController::class, 'store'])->name('shopping.store');
    Route::post('/zakupy/z-przepisu/{recipe:slug}', [ShoppingListController::class, 'addRecipe'])->name('shopping.recipe');
    Route::post('/zakupy/z-przepisu/{recipe:slug}/reszta', [ShoppingListController::class, 'addRecipeHeld'])->name('shopping.recipe-held');
    Route::post('/zakupy/do-kuchni', [ShoppingListController::class, 'stockUp'])->name('shopping.stock-up');
    Route::post('/zakupy/lista/{shoppingList}/do-kuchni', [ShoppingListController::class, 'stockUp'])->name('shopping.list-stock-up');

    /*
     * Before `/zakupy/{shoppingListItem}` below, or "sklepy" is read as an item
     * id. The same reason `lista/` is in the path of the routes above.
     */
    Route::post('/zakupy/sklepy', [ShopSelectionController::class, 'update'])->name('shopping.shops');

    Route::post('/zakupy/listy', [ShoppingListsController::class, 'store'])->name('shopping.lists.store');
    Route::patch('/zakupy/listy/{shoppingList}', [ShoppingListsController::class, 'update'])->name('shopping.lists.update');
    Route::delete('/zakupy/listy/{shoppingList}', [ShoppingListsController::class, 'destroy'])->name('shopping.lists.destroy');
    Route::patch('/zakupy/{shoppingListItem}', [ShoppingListController::class, 'update'])->name('shopping.update');
    Route::delete('/zakupy/{shoppingListItem}', [ShoppingListController::class, 'destroy'])->name('shopping.destroy');

    /*
     * A window onto the flight-deals app. One route and no writes: every change
     * of filter is a fresh GET with a new query string, which is also what keeps
     * a filtered board linkable to the other phone.
     */
    Route::get('/podroz', [TravelController::class, 'index'])->name('travel.index');

    /*
     * What things normally cost, read back out of `price_observations`. Regular
     * prices only — see App\Pricing\PriceHistory.
     */
    Route::get('/ceny', [PriceHistoryController::class, 'index'])->name('prices.index');
    Route::get('/ceny/{ingredient:slug}', [PriceHistoryController::class, 'show'])->name('prices.show');

    Route::get('/konto/urzadzenia', [DeviceLinkController::class, 'create'])->name('device-link.create');
    Route::post('/wyloguj', [SessionController::class, 'destroy'])->name('logout');
});
