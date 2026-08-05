<?php

declare(strict_types=1);

namespace App\Importing\Exceptions;

use RuntimeException;

final class RecipeNotParsable extends RuntimeException
{
    public static function missingField(string $url, string $field): self
    {
        return new self("Recipe at {$url} has no {$field}; the page layout has probably changed.");
    }
}
