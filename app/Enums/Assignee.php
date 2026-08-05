<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Who a task is for.
 *
 * Deliberately not a `user_id`. One household is one account here — that is the
 * whole point of the QR device link — so there is nobody to point at, and there
 * never will be unless the account model changes.
 *
 * It is also why the cases are "him" and "her" rather than "me" and "you": both
 * phones are signed in as the same account, so a first-person label would mean
 * the opposite thing depending on who happened to be holding the phone. These
 * three read the same from either side of the kitchen.
 */
enum Assignee: string
{
    case Him = 'him';
    case Her = 'her';
    case Both = 'both';

    public function label(): string
    {
        return match ($this) {
            self::Him => 'Dla niego',
            self::Her => 'Dla niej',
            self::Both => 'Dla obojga',
        };
    }

    /**
     * For a chip in a row of chips, where "Dla " three times over is noise.
     */
    public function shortLabel(): string
    {
        return match ($this) {
            self::Him => 'On',
            self::Her => 'Ona',
            self::Both => 'Oboje',
        };
    }
}
