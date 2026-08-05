<?php

declare(strict_types=1);

namespace App\Providers;

use App\Travel\DealsApi;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Http\Client\Factory as Http;
use Illuminate\Support\ServiceProvider;

/**
 * Composition root for the travel module — one class to build, but the cache
 * store it reads is a decision rather than a default, exactly as it is for the
 * price client.
 */
class TravelServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(DealsApi::class, fn (): DealsApi => new DealsApi(
            http: $this->app->make(Http::class),
            cache: $this->app->make(CacheFactory::class)
                ->store((string) config('travel.cache_store', 'file')),
        ));
    }
}
