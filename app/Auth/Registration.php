<?php

declare(strict_types=1);

namespace App\Auth;

use App\Models\User;

/**
 * Decides whether the sign-up form is still available.
 *
 * A two-person kitchen app needs exactly one account. Leaving registration open
 * on a public URL would let a stranger in for no benefit, so it closes itself as
 * soon as the first account exists.
 */
final class Registration
{
    public function isOpen(): bool
    {
        $override = config('household.registration_open');

        if (is_bool($override)) {
            return $override;
        }

        if (is_string($override) && $override !== '') {
            return filter_var($override, FILTER_VALIDATE_BOOLEAN);
        }

        return User::query()->doesntExist();
    }
}
