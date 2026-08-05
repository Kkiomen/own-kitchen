<?php

declare(strict_types=1);

namespace App\Providers;

use App\Push\Contracts\PushGateway;
use App\Push\NullPushGateway;
use App\Push\Vapid;
use App\Push\WebPushGateway;
use Illuminate\Support\ServiceProvider;

/**
 * The composition root for notifications, following the pattern the importing,
 * offers and pricing modules already use: one place decides which adapter is
 * behind the port, and nothing downstream branches on it.
 */
class PushServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Vapid::class, static fn (): Vapid => Vapid::fromConfig());

        $this->app->singleton(PushGateway::class, function (): PushGateway {
            $vapid = $this->app->make(Vapid::class);

            if (! $vapid->isConfigured()) {
                return new NullPushGateway;
            }

            return new WebPushGateway(
                $vapid->forLibrary(),
                (int) config('push.timeout_seconds'),
            );
        });
    }
}
