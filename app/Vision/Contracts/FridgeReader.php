<?php

declare(strict_types=1);

namespace App\Vision\Contracts;

use App\Vision\Drafts\SpottedItem;
use App\Vision\VisionUnavailable;

/**
 * Reads groceries out of a photograph.
 *
 * The port. It exists for the same reason `OfferSource` and `PriceSource` do:
 * everything above it is handed a list of drafts and never learns that an AI
 * was involved, so the model, the provider or the whole approach can change
 * without the pantry knowing. It is also what lets the tests pin the rules that
 * matter — never inventing a product, dropping an unknown unit — against a
 * fixed list instead of a live model that answers differently every run.
 */
interface FridgeReader
{
    /**
     * @param  string  $image  The raw bytes of the photograph.
     * @param  list<string>  $unitCodes  The only unit codes an answer may use. Units are a
     *                                   closed vocabulary, so the boundary enforces it rather
     *                                   than hoping the model behaves.
     * @return list<SpottedItem>
     *
     * @throws VisionUnavailable
     */
    public function read(string $image, string $mimeType, array $unitCodes): array;
}
