<?php

declare(strict_types=1);

namespace App\Importing\Parsing;

use App\Enums\Appliance;
use App\Enums\StepAction;

final readonly class ParsedStep
{
    public function __construct(
        public string $instruction,
        public ?StepAction $action = null,
        public ?Appliance $appliance = null,
        public ?int $temperatureCelsius = null,
        public ?int $durationSeconds = null,
    ) {}

    /**
     * Without a recognised verb the guided-cooking screen has no headline to show,
     * so the step is worth a human glance.
     */
    public function needsReview(): bool
    {
        return $this->action === null || $this->startsMidSentence();
    }

    /**
     * airfryerprzepisy.pl publishes some steps with their first word cut off
     * ("łuższy czas pieczenia…"), in its own HTML as well as its JSON-LD. The text
     * is imported verbatim — we do not invent the missing letters — but a step that
     * begins lower-case has lost its beginning and must not pass as understood.
     */
    private function startsMidSentence(): bool
    {
        return preg_match('/^\p{Ll}/u', $this->instruction) === 1;
    }
}
