<?php

declare(strict_types=1);

namespace App\Importing\Drafts;

final readonly class StepDraft
{
    public function __construct(
        public string $rawText,
        public ?string $section = null,
    ) {}
}
