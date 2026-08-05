<?php

declare(strict_types=1);

return [
    /*
     * Whether anyone may create an account.
     *
     * Open unless told otherwise. It used to close itself after the first
     * account — one household, one account — and then friends were invited, so
     * the form has to be there for people who do not live here. Everything
     * personal is scoped per account already; the catalogue is what they share.
     *
     * Set ALLOW_REGISTRATION=false to close it again.
     */
    'registration_open' => env('ALLOW_REGISTRATION'),
];
