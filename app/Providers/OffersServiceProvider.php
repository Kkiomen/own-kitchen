<?php

declare(strict_types=1);

namespace App\Providers;

use App\Offers\OfferSourceRegistry;
use App\Offers\Sources\GazetkiPl\GazetkiPlOfferParser;
use App\Offers\Sources\GazetkiPl\GazetkiPlOfferSource;
use App\Support\Http\PageFetcher;
use App\Support\Http\ThrottledPageFetcher;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the promotions module: the one place that knows which
 * concrete adapter backs the OfferSource port.
 */
class OffersServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(OfferSourceRegistry::class, function (): OfferSourceRegistry {
            return new OfferSourceRegistry([
                'gazetki' => fn (): GazetkiPlOfferSource => $this->makeGazetkiPl(),
            ]);
        });
    }

    private function makeGazetkiPl(): GazetkiPlOfferSource
    {
        /** @var array{name: string, base_url: string, crawl_delay_seconds: int} $config */
        $config = config('offers.sources.gazetki');

        /** @var array<string, string> $shops */
        $shops = config('offers.shops', []);

        return new GazetkiPlOfferSource(
            fetcher: $this->makeFetcher($config['crawl_delay_seconds']),
            parser: new GazetkiPlOfferParser,
            name: $config['name'],
            baseUrl: $config['base_url'],
            shopSlugs: array_keys($shops),
        );
    }

    private function makeFetcher(int $crawlDelaySeconds): PageFetcher
    {
        // Tests bind a fake fetcher; honour it rather than reaching for the network.
        if ($this->app->bound(PageFetcher::class)) {
            return $this->app->make(PageFetcher::class);
        }

        return new ThrottledPageFetcher(
            // The file store, like the recipe crawler, so a `migrate:fresh` does
            // not send us back to the site. The TTL is the difference: six hours
            // rather than a fortnight, because a leaflet page IS its prices.
            cache: $this->app->make(CacheFactory::class)->store('file'),
            userAgent: (string) config('offers.user_agent'),
            crawlDelaySeconds: $crawlDelaySeconds,
            cacheTtlHours: (int) config('offers.page_cache_ttl_hours'),
        );
    }
}
