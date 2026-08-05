<?php

declare(strict_types=1);

namespace App\Support\Http;

use RuntimeException;

final class PageUnavailable extends RuntimeException
{
    public static function forUrl(string $url, int $status): self
    {
        return new self("Could not fetch {$url}: HTTP {$status}.");
    }

    /**
     * The request never reached a server — DNS, TLS or a dropped connection.
     */
    public static function unreachable(string $url, string $reason): self
    {
        return new self("Could not reach {$url}: {$reason}");
    }
}
