<?php

declare(strict_types=1);

namespace App\Offers\Parsing;

/**
 * A pack size read off a leaflet entry, plus the entry with that size removed.
 *
 * The remainder matters as much as the number: "Masło Ekstra 200 g" resolves to
 * a product through the words, and leaving "200 g" among them gives the matcher
 * two tokens that can only ever be noise.
 */
final readonly class ParsedPack
{
    public function __construct(
        public float $amount,
        public string $unitCode,
        public string $remainingTitle,
    ) {}
}
