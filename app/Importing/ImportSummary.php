<?php

declare(strict_types=1);

namespace App\Importing;

final readonly class ImportSummary
{
    public function __construct(
        public int $imported,
        public int $skipped,
        public int $failed,
    ) {}
}
