<?php

declare(strict_types=1);

namespace App\Auth;

/**
 * Decides whether the sign-up form is available.
 *
 * It **is**, by default. This started as a two-person app whose sign-up closed
 * itself the moment the first account existed — one household, one account,
 * everyone else joins by scanning a code — and that was right for as long as
 * the only people using it lived here. It is now open to friends, so the form
 * has to stay there for a stranger who was told about it.
 *
 * The data model was always ready for this: the catalogue is shared, and every
 * personal thing — the kitchen, the shopping lists, the week's meals — is scoped
 * to `user_id` already. A new account gets an empty kitchen and the same ten
 * thousand recipes, which is exactly what a friend wants.
 *
 * `ALLOW_REGISTRATION=false` closes it again, and closing it is a **404** rather
 * than a 403: the address should not confirm what it is hiding.
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

        return true;
    }
}
