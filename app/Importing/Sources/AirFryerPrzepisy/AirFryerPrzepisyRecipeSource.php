<?php

declare(strict_types=1);

namespace App\Importing\Sources\AirFryerPrzepisy;

use App\Importing\Contracts\RecipeSource;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\RecipeReference;
use App\Importing\Sources\SchemaOrg\JsonLdRecipeParser;
use App\Support\Http\PageFetcher;
use App\Support\Http\PageUnavailable;
use Generator;
use Illuminate\Support\Facades\Log;

/**
 * airfryerprzepisy.pl adapter — the source of air fryer recipes.
 *
 * Its robots.txt only excludes /wp-admin/ and declares no Crawl-delay; the delay
 * the injected PageFetcher enforces is our own politeness, not a stated rule.
 *
 * The recipes come from the page's JSON-LD rather than its HTML, so this class only
 * has to walk the archive and hand over URLs.
 */
final class AirFryerPrzepisyRecipeSource implements RecipeSource
{
    private const int MAX_LISTING_PAGES = 60;

    public function __construct(
        private readonly PageFetcher $fetcher,
        private readonly AirFryerPrzepisyListingParser $listings,
        private readonly JsonLdRecipeParser $recipes,
        private readonly string $name,
        private readonly string $baseUrl,
        private readonly string $listingPath,
    ) {}

    public function name(): string
    {
        return $this->name;
    }

    /**
     * The archive is one chronological stream of every category, so walking it in
     * order already yields a mix of breakfasts, dinners and desserts.
     *
     * @return Generator<RecipeReference>
     */
    public function discover(int $limit): Generator
    {
        $seen = [];
        $yielded = 0;

        for ($page = 1; $page <= self::MAX_LISTING_PAGES; $page++) {
            $slugs = $this->slugsOnPage($page);

            // Past the last page of the archive.
            if ($slugs === []) {
                return;
            }

            foreach ($slugs as $slug) {
                if (isset($seen[$slug])) {
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
    private function slugsOnPage(int $page): array
    {
        $url = $this->baseUrl.$this->listingPath.($page > 1 ? 'page/'.$page.'/' : '');

        try {
            return $this->listings->parseListing($this->fetcher->get($url), $this->baseUrl);
        } catch (PageUnavailable $exception) {
            // Running off the end of the archive is expected and ends the walk; a
            // failure on the first page is a broken configuration and must be seen.
            Log::warning('Recipe listing unavailable, ending the walk.', [
                'source' => $this->name,
                'url' => $url,
                'reason' => $exception->getMessage(),
            ]);

            return [];
        }
    }

    private function recipeUrl(string $slug): string
    {
        return $this->baseUrl.'/'.$slug.'/';
    }
}
