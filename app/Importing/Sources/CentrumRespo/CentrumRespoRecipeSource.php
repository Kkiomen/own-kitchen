<?php

declare(strict_types=1);

namespace App\Importing\Sources\CentrumRespo;

use App\Importing\Contracts\RecipeSource;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\RecipeReference;
use App\Importing\Sources\SchemaOrg\JsonLdRecipeParser;
use App\Support\Http\PageFetcher;
use App\Support\Http\PageUnavailable;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * centrumrespo.pl adapter — the source of meal prep recipes.
 *
 * Its robots.txt excludes only /wp-admin/ and declares no Crawl-delay, so the delay
 * the injected PageFetcher enforces is our own politeness rather than a stated rule.
 *
 * Unlike the other two sites this one is not wholly one kind of cooking, so what
 * makes its recipes meal prep is the set of category listings walked — lunchbox,
 * picnic and packed-lunch — configured alongside the flag they justify. The parser
 * marks every recipe reached through them, which is why the flag stays a stated
 * fact rather than something guessed from a title.
 *
 * The recipes come from the page's JSON-LD, so this class only walks the listings.
 */
final class CentrumRespoRecipeSource implements RecipeSource
{
    /**
     * A backstop against walking forever, not an expected limit — set above the
     * longest listing rather than near it. The named categories run to a couple of
     * pages, but the "Na wynos" filter is 54 and growing; a cap set to its current
     * length would silently stop the walk mid-catalogue, which is indistinguishable
     * from the site having run out. Exhausted listings drop out on their own.
     */
    private const int MAX_LISTING_PAGES = 120;

    /**
     * @param  list<string>  $listingPaths
     */
    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly CentrumRespoListingParser $listings,
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
     * Interleaves the configured listings rather than draining them one at a time,
     * so a small limit still yields a mix instead of twenty sandwiches.
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
                $slugs = $this->slugsOnPage($path, $page);

                // A category that has run out must drop out of the walk. Asking it
                // for page after page would cost one request each, per round, for
                // the rest of the import.
                if ($slugs === []) {
                    continue;
                }

                $byListing[] = $slugs;
                $stillActive[] = $path;
            }

            $active = $stillActive;

            $longest = max(array_map('count', $byListing) ?: [0]);

            // Every listing has run out of pages. The site answers 200 with an empty
            // grid past the last one rather than a 404, so this is the end marker.
            if ($longest === 0) {
                return;
            }

            for ($index = 0; $index < $longest; $index++) {
                foreach ($byListing as $slugs) {
                    $slug = $slugs[$index] ?? null;

                    // The categories overlap: a lunchbox dish is often a picnic one too.
                    if ($slug === null || isset($seen[$slug])) {
                        continue;
                    }

                    $seen[$slug] = true;
                    yield new RecipeReference(
                        url: $this->recipeUrl($slug),
                        slug: $slug,
                    );

                    if (++$yielded >= $limit) {
                        return;
                    }
                }
            }
        }
    }

    public function fetch(RecipeReference $reference): RecipeDraft
    {
        return $this->recipes->parseRecipe(
            html: $this->fetcher->get($reference->url),
            url: $reference->url,
            slug: $reference->slug,
        );
    }

    /**
     * @return list<string>
     */
    private function slugsOnPage(string $path, int $page): array
    {
        $url = $this->baseUrl.$this->paged($path, $page);

        try {
            return $this->listings->parseListing($this->fetcher->get($url), $this->baseUrl);
        } catch (PageUnavailable $exception) {
            // A listing that fails must not abort an import that is otherwise working
            // — but it must not vanish either, or a mistyped path in the config
            // silently narrows what we import and nobody notices.
            Log::warning('Recipe listing unavailable, skipping it.', [
                'source' => $this->name,
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    /**
     * Pagination is a path segment, so it has to go before any query string: the
     * site's own tag listings are a filter on the archive ("/przepisy/?catFilter=1"),
     * and appending "page/2/" to the end of that would ask for a page that does not
     * exist and silently end the walk after twenty recipes.
     */
    private function paged(string $path, int $page): string
    {
        [$path, $query] = array_pad(explode('?', $path, 2), 2, null);

        return $path
            .($page > 1 ? 'page/'.$page.'/' : '')
            .($query !== null ? '?'.$query : '');
    }

    private function recipeUrl(string $slug): string
    {
        return $this->baseUrl.'/przepisy/'.$slug.'/';
    }
}
