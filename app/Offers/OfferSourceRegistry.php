<?php

declare(strict_types=1);

namespace App\Offers;

use App\Offers\Contracts\OfferSource;
use Closure;
use InvalidArgumentException;

/**
 * Lookup of the configured leaflet sources by key. Adding a second aggregator
 * means one adapter and one entry here; nothing else in the pipeline changes.
 */
final class OfferSourceRegistry
{
    /**
     * @param  array<string, Closure(): OfferSource>  $factories  Deferred so an unused source is never built.
     */
    public function __construct(private readonly array $factories) {}

    public function get(string $key): OfferSource
    {
        $factory = $this->factories[$key] ?? null;

        if ($factory === null) {
            throw new InvalidArgumentException(
                "Unknown offer source '{$key}'. Available: ".implode(', ', $this->keys()).'.'
            );
        }

        return $factory();
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->factories);
    }
}
