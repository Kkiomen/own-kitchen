<?php

declare(strict_types=1);

namespace App\Providers;

use App\Vision\Contracts\FridgeReader;
use App\Vision\OpenAiFridgeReader;
use Illuminate\Support\ServiceProvider;

/**
 * The composition root of the photo module. Swapping the provider — or faking
 * it in a test — is this one binding and nothing else.
 */
class VisionServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(FridgeReader::class, OpenAiFridgeReader::class);
    }
}
