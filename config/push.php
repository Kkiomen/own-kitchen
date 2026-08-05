<?php

declare(strict_types=1);

return [
    /*
     * The application server's identity to the push services. Generated once by
     * `php artisan push:keys` and then left alone for good: the public half is
     * baked into every subscription a phone has already made, so a new pair
     * silently orphans every device that subscribed under the old one — they go
     * on looking subscribed and never ring again.
     *
     * Missing keys are not an error. `PushSender` reads that as "this
     * installation does not do push" and the screen offers nothing, which is the
     * correct state for a checkout that has never been configured.
     */
    'vapid' => [
        /*
         * Who to contact about a misbehaving sender. A push service may use it
         * when our requests start failing, so a real address is worth more than
         * a URL — but the URL is the honest default for a household app.
         */
        'subject' => env('VAPID_SUBJECT', env('APP_URL', 'https://kuchnia.local')),
        'public_key' => env('VAPID_PUBLIC_KEY'),
        'private_key' => env('VAPID_PRIVATE_KEY'),
    ],

    /*
     * How long a delivery may take before it is abandoned. This runs after the
     * response has been sent, so nobody is waiting on it — but a hung connection
     * to a push service would otherwise hold a PHP worker open indefinitely.
     */
    'timeout_seconds' => (int) env('PUSH_TIMEOUT', 10),
];
