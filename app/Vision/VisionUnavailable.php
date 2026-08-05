<?php

declare(strict_types=1);

namespace App\Vision;

use RuntimeException;

/**
 * The photo could not be read, for any reason at all.
 *
 * One exception for a refused connection, a timeout, a 429, a 500 and a body
 * that is not the JSON we asked for — exactly like `DealsUnavailable`. They all
 * mean the same thing to somebody standing in front of an open fridge: nothing
 * came back, type it in or try again. The original is kept as the previous
 * exception so the log still says which it was.
 */
final class VisionUnavailable extends RuntimeException {}
