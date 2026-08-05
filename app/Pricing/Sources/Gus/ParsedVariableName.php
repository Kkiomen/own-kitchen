<?php

declare(strict_types=1);

namespace App\Pricing\Sources\Gus;

use App\Offers\Parsing\ParsedPack;

/**
 * One statistical label, split into the two things anything downstream needs:
 * what the product is, and how much of it the figure buys.
 */
final readonly class ParsedVariableName
{
    public function __construct(
        public string $product,
        public ?ParsedPack $pack,
    ) {}
}
