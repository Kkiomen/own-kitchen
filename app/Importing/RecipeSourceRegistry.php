<?php

declare(strict_types=1);

namespace App\Importing;

use App\Importing\Contracts\RecipeSource;
use Closure;
use InvalidArgumentException;

/**
 * Lookup of the configured source adapters by key. Registering a new site means
 * adding one entry here; nothing else in the pipeline changes.
 */
final class RecipeSourceRegistry
{
    /**
     * @param  array<string, Closure(): RecipeSource>  $factories  Deferred so an unused source is never built.
     */
    public function __construct(private readonly array $factories) {}

    public function get(string $key): RecipeSource
    {
        $factory = $this->factories[$key] ?? null;

        if ($factory === null) {
            throw new InvalidArgumentException(
                "Unknown recipe source '{$key}'. Available: ".implode(', ', $this->keys()).'.'
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
