<?php

declare(strict_types=1);

namespace App\Pricing\Sources\Gus;

use App\Offers\Parsing\PackSizeParser;

/**
 * Reads a statistical variable's label into a product and a pack.
 *
 * The office states the pack inside the label rather than beside it — "mąka
 * pszenna - za 1kg", "masło świeże o zawartości tłuszczu ok. 82,5% - za 200g",
 * "jaja kurze świeże - za 1szt." — so without this every figure is a price for
 * an unknown amount of something, which is no price at all.
 *
 * It borrows `PackSizeParser` from the leaflet module deliberately. Reading a
 * size out of a product name is the same job whether the name came from a
 * leaflet or from a spreadsheet, and a second implementation would be a second
 * place for "0,5kg" to be read wrongly. What it adds is the one thing that is
 * specific here: the label's `- za` scaffolding, which is not part of any
 * product's name and would otherwise be matched against the catalogue.
 */
final class GusVariableName
{
    /**
     * The remains of "… - za" once the size has been taken out, plus the office's
     * own disambiguation marker: it publishes a second series under the same name
     * as "(1)" when a definition changes mid-history.
     */
    private const string SCAFFOLDING = '/(?:\s*[-–]\s*za\b|\bza\s*$|\(\d+\))/u';

    public function __construct(private readonly PackSizeParser $packs) {}

    public function parse(string $label): ParsedVariableName
    {
        $pack = $this->packs->parse($label);

        // No size means no comparable price. The reading is still kept — the
        // office did state a figure — but it can never enter an estimate, and
        // the coverage report counts it as a gap rather than as coverage.
        $product = $pack === null ? $label : $pack->remainingTitle;

        return new ParsedVariableName(
            product: $this->tidy($product),
            pack: $pack,
        );
    }

    private function tidy(string $product): string
    {
        $stripped = preg_replace(self::SCAFFOLDING, ' ', $product) ?? $product;

        return trim(preg_replace('/\s+/u', ' ', $stripped) ?? $stripped, " \t\n\r,;.-–");
    }
}
