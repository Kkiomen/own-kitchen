<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Pricing\Contracts\PriceSource;
use Closure;
use InvalidArgumentException;

/**
 * Lookup of the configured price sources by key. Adding a third source means one
 * adapter and one entry here; nothing else in the pipeline changes.
 */
final class PriceSourceRegistry
{
    /**
     * @param  array<string, Closure(): PriceSource>  $factories  Deferred so an unused source is never built.
     */
    public function __construct(private readonly array $factories) {}

    public function get(string $key): PriceSource
    {
        $factory = $this->factories[$key] ?? null;

        if ($factory === null) {
            throw new InvalidArgumentException(
                "Unknown price source '{$key}'. Available: ".implode(', ', $this->keys()).'.'
            );
        }

        return $factory();
    }

    /**
     * Every source, built. Used by a refresh that was given no source to run:
     * "all of them" is the ordinary case here, unlike leaflets where a run is
     * hours long and always aimed at one aggregator.
     *
     * @return list<PriceSource>
     */
    public function all(): array
    {
        return array_values(array_map(
            static fn (Closure $factory): PriceSource => $factory(),
            $this->factories,
        ));
    }

    /**
     * @return list<string>
     */
    public function keys(): array
    {
        return array_keys($this->factories);
    }
}
