<?php

declare(strict_types=1);

namespace App\Importing\Resolving;

use App\Models\Unit;

/**
 * Units are a fixed, seeded vocabulary. An unknown code means the seeder and the
 * parser disagree, which is a bug rather than a data problem, so this never
 * invents rows the way the ingredient resolver does.
 */
final class UnitResolver
{
    /**
     * @var array<string, Unit>|null
     */
    private ?array $cache = null;

    public function byCode(?string $code): ?Unit
    {
        if ($code === null) {
            return null;
        }

        return $this->units()[$code] ?? null;
    }

    /**
     * @return array<string, Unit>
     */
    private function units(): array
    {
        return $this->cache ??= Unit::query()->get()->keyBy('code')->all();
    }
}
