<?php

declare(strict_types=1);

namespace App\Travel;

use RuntimeException;

/**
 * The deals app did not answer.
 *
 * A separate exception rather than letting the HTTP one escape, because the
 * screen has to tell these apart from "nothing matched your filters": an empty
 * board and an unreachable board look identical in the data and mean opposite
 * things. One is "wybierz inne daty", the other is "ta druga aplikacja nie
 * działa" — and only the second is something to go and fix.
 */
final class DealsUnavailable extends RuntimeException {}
