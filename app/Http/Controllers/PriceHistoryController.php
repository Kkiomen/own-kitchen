<?php

declare(strict_types=1);

namespace App\Http\Controllers;

use App\Catalogue\IngredientEmoji;
use App\Enums\IngredientCategory;
use App\Models\Ingredient;
use App\Pricing\PriceBook;
use App\Pricing\PriceHistory;
use Inertia\Inertia;
use Inertia\Response;

/**
 * "Czy masło jest teraz drogie."
 *
 * The readings have been accumulating since prices were imported and nothing
 * read them back — `price_observations` is append-only precisely so this
 * question can be asked, and the estimate only ever consumed the median. Every
 * figure on these screens is a regular price; see `PriceHistory` for why that
 * is a property of the table rather than a filter applied here.
 */
class PriceHistoryController extends Controller
{
    public function __construct(
        private readonly PriceHistory $history,
        private readonly PriceBook $prices,
        private readonly IngredientEmoji $emoji,
    ) {}

    public function index(): Response
    {
        $products = array_map(
            fn (array $product): array => [
                ...$product,
                'emoji' => $this->emoji->for(
                    $product['name'],
                    IngredientCategory::from($product['category']),
                ),
            ],
            $this->history->pricedProducts(),
        );

        return Inertia::render('Prices/Index', [
            // Shipped whole and filtered in the browser, like the product list
            // the kitchen searches: it is a couple of hundred rows, and a search
            // endpoint would be slower and would not work offline.
            'products' => $products,
        ]);
    }

    public function show(Ingredient $ingredient): Response
    {
        $days = $this->history->of($ingredient);

        return Inertia::render('Prices/Show', [
            'product' => [
                'slug' => $ingredient->slug,
                'name' => $ingredient->name,
                'emoji' => $this->emoji->for($ingredient->name, $ingredient->category),
            ],
            'days' => $days,
            'change' => $this->history->change($days),
            'typical' => $this->history->typical($ingredient, $this->prices),
        ]);
    }
}
