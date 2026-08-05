<?php

declare(strict_types=1);

namespace App\Enums;

/**
 * Where a product came from. This is what separates a name we vouch for from one
 * the importer invented because it did not recognise a line.
 *
 * Without it the review queue erases itself: an invented product registers an
 * alias, so the next import "recognises" it and the line stops being flagged,
 * even though nobody ever checked it.
 */
enum IngredientSource: string
{
    /** Curated in database/data/ingredients.php. */
    case Dictionary = 'dictionary';

    /** Invented by the importer from an unrecognised phrase. Needs a human. */
    case Import = 'import';

    /** Entered by hand in the app. */
    case Manual = 'manual';

    public function isTrusted(): bool
    {
        return $this !== self::Import;
    }
}
