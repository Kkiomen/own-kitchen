<?php

declare(strict_types=1);

namespace App\Importing\Sources\KwestiaSmaku;

use App\Importing\Contracts\RecipeSource;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\RecipeReference;
use App\Support\Http\PageFetcher;
use App\Support\Http\PageUnavailable;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * kwestiasmaku.com adapter.
 *
 * The site's robots.txt allows crawling with a 10 second Crawl-delay and does not
 * exclude AI agents; the delay is enforced by the injected PageFetcher.
 */
final class KwestiaSmakuRecipeSource implements RecipeSource
{
    /**
     * A backstop against walking forever, not an expected limit. Categories run to
     * a handful of pages; exhausted ones drop out of the walk on their own.
     */
    private const int MAX_LISTING_PAGES = 60;

    /**
     * @param  list<string>  $listingPaths
     */
    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly KwestiaSmakuPageParser $parser,
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
     * so a small limit still yields a varied mix instead of ten soups.
     *
     * @return Generator<RecipeReference>
     */
    public function discover(int $limit): Generator
    {
        $seen = [];
        $yielded = 0;
        $active = $this->listingPaths;

        for ($page = 0; $page < self::MAX_LISTING_PAGES && $active !== []; $page++) {
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

            // Every listing has run out of pages.
            if ($longest === 0) {
                return;
            }

            // Take one recipe from each listing in turn, so a small limit still
            // returns a varied mix rather than fifteen chicken dishes.
            for ($index = 0; $index < $longest; $index++) {
                foreach ($byListing as $slugs) {
                    $slug = $slugs[$index] ?? null;

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
        return $this->parser->parseRecipe(
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
        $url = $this->baseUrl.$path.($page > 0 ? (str_contains($path, '?') ? '&' : '?').'page='.$page : '');

        try {
            return $this->parser->parseListing($this->fetcher->get($url));
        } catch (PageUnavailable $exception) {
            // A listing that 404s must not abort an import that is otherwise
            // working — but it must not vanish either, or a mistyped path in the
            // config silently narrows what we import and nobody notices.
            Log::warning('Recipe listing unavailable, skipping it.', [
                'source' => $this->name,
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function recipeUrl(string $slug): string
    {
        return $this->baseUrl.'/przepis/'.$slug;
    }
}
