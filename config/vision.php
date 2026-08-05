<?php

declare(strict_types=1);

return [
    /*
     * The OpenAI key. Empty means the feature is simply not there: the camera
     * button never renders and the endpoints answer 404. A household that has
     * not bought a key should not be shown a button that always fails.
     */
    'key' => (string) env('OPENAI_API_KEY', ''),

    'url' => rtrim((string) env('OPENAI_API_URL', 'https://api.openai.com/v1'), '/'),

    /*
     * A full vision model rather than a mini one, on purpose. The picture is a
     * cluttered shelf photographed at an angle in bad light — that is the hard
     * case for reading, and the cheaper models answer it with plausible
     * groceries rather than the ones in the photo. A wrong product on a shelf
     * is worse than a missing one: the shopping list acts on it.
     */
    'model' => (string) env('VISION_MODEL', 'gpt-4o'),

    /*
     * Long, because this runs while somebody is holding a phone and waiting —
     * but it is one request over a photo, not a page load. Under ~20 s the
     * model regularly has not finished a full shelf and the whole trip is lost.
     */
    'timeout_seconds' => (int) env('VISION_TIMEOUT_SECONDS', 45),
];
