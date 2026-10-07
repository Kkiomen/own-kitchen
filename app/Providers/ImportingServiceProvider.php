<?php

declare(strict_types=1);

namespace App\Providers;

use App\Enums\Appliance;
use App\Importing\RecipeSourceRegistry;
use App\Importing\Sources\AirFryerPrzepisy\AirFryerPrzepisyListingParser;
use App\Importing\Sources\AirFryerPrzepisy\AirFryerPrzepisyRecipeSource;
use App\Importing\Sources\Beszamel\BeszamelPageParser;
use App\Importing\Sources\Beszamel\BeszamelRecipeSource;
use App\Importing\Sources\CentrumRespo\CentrumRespoListingParser;
use App\Importing\Sources\CentrumRespo\CentrumRespoRecipeSource;
use App\Importing\Sources\House\HouseRecipeSource;
use App\Importing\Sources\KwestiaSmaku\KwestiaSmakuPageParser;
use App\Importing\Sources\KwestiaSmaku\KwestiaSmakuRecipeSource;
use App\Importing\Sources\SchemaOrg\JsonLdRecipeParser;
use App\Support\Http\PageFetcher;
use App\Support\Http\ThrottledPageFetcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the import module: the one place that knows which concrete
 * adapter backs each port.
 */
class ImportingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(RecipeSourceRegistry::class, function (): RecipeSourceRegistry {
            return new RecipeSourceRegistry([
                'kwestiasmaku' => fn (): KwestiaSmakuRecipeSource => $this->makeKwestiaSmaku(),
                'airfryerprzepisy' => fn (): AirFryerPrzepisyRecipeSource => $this->makeAirFryerPrzepisy(),
                'centrumrespo' => fn (): CentrumRespoRecipeSource => $this->makeCentrumRespo(),
                'beszamel' => fn (): BeszamelRecipeSource => $this->makeBeszamel(),
                'house' => fn (): HouseRecipeSource => $this->makeHouse(),
            ]);
        });
    }

    private function makeHouse(): HouseRecipeSource
    {
        /** @var list<array{slug: string, title: string, servings: int, minutes: int, ingredients: list<string>, steps: list<string>}> $recipes */
        $recipes = require database_path('data/house-recipes.php');

        return new HouseRecipeSource($recipes, (string) config('importing.sources.house.name'));
    }

    private function makeBeszamel(): BeszamelRecipeSource
    {
        /** @var array{name: string, base_url: string, crawl_delay_seconds: int, listings: list<string>} $config */
        $config = config('importing.sources.beszamel');

        return new BeszamelRecipeSource(
            fetcher: $this->makeFetcher($config['crawl_delay_seconds']),
            pages: new BeszamelPageParser,
            recipes: new JsonLdRecipeParser,
            name: $config['name'],
            baseUrl: $config['base_url'],
            listingPaths: $config['listings'],
        );
    }

    private function makeCentrumRespo(): CentrumRespoRecipeSource
    {
        /** @var array{name: string, base_url: string, crawl_delay_seconds: int, meal_prep: bool, listings: list<string>} $config */
        $config = config('importing.sources.centrumrespo');

        return new CentrumRespoRecipeSource(
            fetcher: $this->makeFetcher($config['crawl_delay_seconds']),
            listings: new CentrumRespoListingParser,
            // The listings this source walks are all batch cooking, so every recipe
            // it reaches is meal prep by construction rather than by guesswork.
            recipes: new JsonLdRecipeParser(isMealPrep: $config['meal_prep']),
            name: $config['name'],
            baseUrl: $config['base_url'],
            listingPaths: $config['listings'],
        );
    }

    private function makeAirFryerPrzepisy(): AirFryerPrzepisyRecipeSource
    {
        /** @var array{name: string, base_url: string, crawl_delay_seconds: int, listing: string} $config */
        $config = config('importing.sources.airfryerprzepisy');

        return new AirFryerPrzepisyRecipeSource(
            fetcher: $this->makeFetcher($config['crawl_delay_seconds']),
            listings: new AirFryerPrzepisyListingParser,
            // Every recipe on the site is written for an air fryer, so the device is
            // a fact about the source rather than something to guess from step text.
            recipes: new JsonLdRecipeParser(Appliance::AirFryer),
            name: $config['name'],
            baseUrl: $config['base_url'],
            listingPath: $config['listing'],
        );
    }

    private function makeKwestiaSmaku(): KwestiaSmakuRecipeSource
    {
        /** @var array{name: string, base_url: string, crawl_delay_seconds: int, listings: list<string>} $config */
        $config = config('importing.sources.kwestiasmaku');

        return new KwestiaSmakuRecipeSource(
            fetcher: $this->makeFetcher($config['crawl_delay_seconds']),
            parser: new KwestiaSmakuPageParser,
            name: $config['name'],
            baseUrl: $config['base_url'],
            listingPaths: $config['listings'],
        );
    }

    /**
     * Each source gets its own fetcher because the crawl delay it must honour is
     * that site's rule, not a global setting.
     */
    private function makeFetcher(int $crawlDelaySeconds): PageFetcher
    {
        // Tests bind a fake fetcher; honour it rather than reaching for the network.
        if ($this->app->bound(PageFetcher::class)) {
            return $this->app->make(PageFetcher::class);
        }

        return new ThrottledPageFetcher(
            // Deliberately the file store rather than the default: fetched pages
            // must survive a `migrate:fresh`, or every schema reset costs the
            // source site a full re-crawl.
            cache: $this->app->make(CacheFactory::class)->store('file'),
            userAgent: (string) config('importing.user_agent'),
            crawlDelaySeconds: $crawlDelaySeconds,
            cacheTtlHours: (int) config('importing.page_cache_ttl_hours'),
            // A recipe page does not change, so reading one is a reason to keep
            // it. This is what makes "a parser fix is a re-run, not a re-crawl"
            // stay true past the fortnight the pages were first fetched in.
            refreshOnHit: true,
        );
    }
}
