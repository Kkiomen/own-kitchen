<?php

declare(strict_types=1);

return [
    /*
     * Whether anyone may still create an account.
     *
     * Left unset (the default) it means "only until the first account exists".
     * This is a household app: one person signs up, everyone else joins that
     * account by scanning a code. An open sign-up form on a public URL would be
     * an invitation with no upside.
     *
     * Set ALLOW_REGISTRATION=true to reopen it, or =false to close it for good.
     */
    'registration_open' => env('ALLOW_REGISTRATION'),
];
