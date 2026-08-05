<?php

declare(strict_types=1);

namespace App\Importing\Sources\Beszamel;

use App\Importing\Contracts\RecipeSource;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\RecipeReference;
use App\Importing\Exceptions\RecipeNotParsable;
use App\Importing\Sources\SchemaOrg\JsonLdRecipeParser;
use App\Support\Http\PageFetcher;
use App\Support\Http\PageUnavailable;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * beszamel.se.pl adapter — a large general Polish recipe site, added to widen the
 * everyday cooking pool beyond kwestiasmaku.com.
 *
 * Its robots.txt excludes only API and CDN paths and declares no Crawl-delay; the
 * delay the injected PageFetcher enforces is our own politeness.
 *
 * The page's JSON-LD is trusted for everything except the ingredient list, which
 * the site publishes as one glued-together string — see BeszamelPageParser.
 */
final class BeszamelRecipeSource implements RecipeSource
{
    /**
     * A backstop against walking forever rather than an expected limit: the site's
     * categories run to 80-odd pages each and exhausted ones drop out on their own.
     */
    private const int MAX_LISTING_PAGES = 150;

    /**
     * @param  list<string>  $listingPaths
     */
    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly BeszamelPageParser $pages,
        private readonly JsonLdRecipeParser $recipes,
        private readonly string $name,
        private readonly string $baseUrl,
        private readonly array $listingPaths,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /**
     * Interleaves the configured category listings rather than draining them one at
     * a time, so a small limit still yields a mix instead of thirty soups.
     *
     * @return Generator<RecipeReference>
     */
    public function discover(int $limit): Generator
    {
        $seen = [];
        $yielded = 0;
        $active = $this->listingPaths;

        for ($page = 1; $page <= self::MAX_LISTING_PAGES && $active !== []; $page++) {
            $byListing = [];
            $stillActive = [];

            foreach ($active as $path) {
                $fresh = $this->unseenReferencesOnPage($path, $page, $seen);

                // A listing that returns nothing new has either run out or does not
                // paginate at all. The site's /przepisy/ hub ignores ?page entirely
                // and answers with the same 296 links forever: asking it for page
                // after page cost 200 identical requests before this guard existed.
                if ($fresh === []) {
                    continue;
                }

                $stillActive[] = $path;
                $byListing[] = $fresh;
            }

            $active = $stillActive;

            foreach ($this->roundRobin($byListing) as $reference) {
                $seen[$reference->url] = true;
                yield $reference;

                if (++$yielded >= $limit) {
                    return;
                }
            }
        }
    }

    /**
     * @param  list<list<RecipeReference>>  $byListing
     * @return list<RecipeReference>
     */
    private function roundRobin(array $byListing): array
    {
        $ordered = [];
        $longest = max(array_map('count', $byListing) ?: [0]);

        for ($index = 0; $index < $longest; $index++) {
            foreach ($byListing as $references) {
                if (isset($references[$index])) {
                    $ordered[] = $references[$index];
                }
            }
        }

        return $ordered;
    }

    public function fetch(RecipeReference $reference): RecipeDraft
    {
        $html = $this->fetcher->get($reference->url);

        $draft = $this->recipes->parseRecipe(
            html: $html,
            url: $reference->url,
            slug: $reference->slug,
        );

        $ingredientLines = $this->pages->parseIngredients($html);

        if ($ingredientLines === []) {
            // The JSON-LD list is one unusable blob, so an empty HTML list means we
            // have no ingredients at all. Importing the recipe anyway would store a
            // method with nothing to cook it from.
            throw RecipeNotParsable::missingField($reference->url, 'ingredients');
        }

        return $draft->withIngredientLines($ingredientLines);
    }

    /**
     * @param  array<string, true>  $seen
     * @return list<RecipeReference>
     */
    private function unseenReferencesOnPage(string $path, int $page, array $seen): array
    {
        $url = $this->baseUrl.$path.($page > 1 ? '?page='.$page : '');

        try {
            return array_values(array_filter(
                $this->pages->parseListing($this->fetcher->get($url)),
                static fn (RecipeReference $reference): bool => ! isset($seen[$reference->url]),
            ));
        } catch (PageUnavailable $exception) {
            Log::warning('Recipe listing unavailable, ending the walk.', [
                'source' => $this->name,
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return [];
        }
    }
}
