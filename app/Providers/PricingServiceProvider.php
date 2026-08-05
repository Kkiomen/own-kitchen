<?php

declare(strict_types=1);

namespace App\Providers;

use App\Pricing\PriceBook;
use App\Pricing\PriceSourceRegistry;
use App\Pricing\Sources\Gus\GusBdlClient;
use App\Pricing\Sources\Gus\GusBdlPriceSource;
use App\Pricing\Sources\Gus\GusVariableName;
use App\Pricing\Sources\Leaflets\LeafletPriceSource;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the pricing module: the one place that knows which
 * concrete adapters back the `PriceSource` port.
 */
class PricingServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(PriceSourceRegistry::class, function (): PriceSourceRegistry {
            return new PriceSourceRegistry([
                'gus' => fn (): GusBdlPriceSource => new GusBdlPriceSource(
                    client: new GusBdlClient(
                        http: $this->app->make(Http::class),
                        /*
                         * The file store in development, like the crawlers', so a
                         * `migrate:fresh` does not spend the API's rate limit
                         * again. Configurable because the test suite must not be
                         * answered out of it — see `phpunit.xml`.
                         */
                        cache: $this->app->make(CacheFactory::class)
                            ->store((string) config('pricing.sources.gus.cache_store', 'file')),
                    ),
                    names: $this->app->make(GusVariableName::class),
                ),
                'leaflets' => fn (): LeafletPriceSource => new LeafletPriceSource,
            ]);
        });

        /*
         * A singleton for the same reason `MeasureBook` is one: a single shopping
         * list asks it once per line, and separate copies would read the same
         * table twenty times to answer one screen.
         */
        $this->app->singleton(PriceBook::class);
    }
}
