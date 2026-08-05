<?php

declare(strict_types=1);

namespace App\Push;

/**
 * What a phone will show, and where tapping it lands.
 *
 * Deliberately small. A notification is read at arm's length on a lock screen,
 * and iOS renders no action buttons for a web push however many are sent — so a
 * title, a line of text and a destination is the whole of what can be said.
 */
final readonly class PushMessage
{
    /**
     * @param  string  $tag  Which conversation this belongs to. A second message
     *                       with the same tag replaces the first on screen rather
     *                       than stacking, which is what keeps five tasks written
     *                       in a minute from becoming five banners.
     * @param  int|null  $badge  The number to put on the app icon. Null leaves it
     *                           alone; zero clears it.
     */
    public function __construct(
        public string $title,
        public string $body,
        public string $url,
        public string $tag,
        public ?int $badge = null,
    ) {}

    /**
     * The payload as the service worker's `push` handler will read it.
     */
    public function payload(): string
    {
        return json_encode([
            'title' => $this->title,
            'body' => $this->body,
            'url' => $this->url,
            'tag' => $this->tag,
            'badge' => $this->badge,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
    }
}
