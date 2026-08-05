<?php

declare(strict_types=1);

namespace App\Offers\Parsing;

use App\Importing\Parsing\PolishTextNormalizer;
use App\Importing\Parsing\UnitVocabulary;

/**
 * Pulls the pack size out of a leaflet entry: "Piwo Garage 400 ml" → 400 ml.
 *
 * Without this, two prices for the same product cannot be ranked. "Masło 8,99"
 * and "Masło 12,49" says nothing until you know one is 200 g and the other half
 * a kilo, and picking the smaller number would confidently recommend the worse
 * deal. Most entries carry no size at all, and for those the honest answer is
 * null — a "typical pack" would be an invented number driving a real decision.
 */
final class PackSizeParser
{
    /**
     * Only the units a shop actually prints on a package. The shared vocabulary
     * also knows tablespoons and pinches, which in a product name are part of the
     * name ("Przyprawa Szczypta Smaku"), never a size.
     *
     * @var list<string>
     */
    private const array PACKAGING_CODES = ['g', 'kg', 'dag', 'ml', 'l', 'piece'];

    /**
     * A number, optionally multiplied ("2 x 250 g"), followed by a word.
     *
     * The unit has to be a word for this to fire, which is what keeps model
     * numbers out: "Smartwatch SMR11 Hero 1.39" has a number at the end and no
     * unit after it. A percentage is likewise safe — "śmietana 18%" puts a `%`
     * where the letters would be.
     */
    private const string PACK = '/(?:(\d+)\s*[x×]\s*)?(\d+(?:[.,]\d+)?)\s*([\p{L}]{1,6})\.?(?![\p{L}])/u';

    public function __construct(
        private readonly PolishTextNormalizer $normalizer,
        private readonly UnitVocabulary $units,
    ) {}

    public function parse(string $title): ?ParsedPack
    {
        if (preg_match_all(self::PACK, $title, $matches, PREG_SET_ORDER | PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        // Last match wins: a package states its contents at the end, after the
        // brand and any strength ("Brandy Napoleon Pons 36% vol., 700 ml").
        foreach (array_reverse($matches) as $match) {
            $code = $this->units->codeFor($this->normalizer->normalize($match[3][0]));

            if ($code === null || ! in_array($code, self::PACKAGING_CODES, true)) {
                continue;
            }

            $packs = $match[1][0] === '' ? 1.0 : (float) $match[1][0];
            $amount = (float) str_replace(',', '.', $match[2][0]);

            if ($amount <= 0.0) {
                continue;
            }

            return new ParsedPack(
                amount: $packs * $amount,
                unitCode: $code,
                remainingTitle: $this->withoutMatch($title, $match[0][1], strlen($match[0][0])),
            );
        }

        return null;
    }

    private function withoutMatch(string $title, int $offset, int $length): string
    {
        $remaining = substr($title, 0, $offset).substr($title, $offset + $length);

        return trim(preg_replace('/\s+/u', ' ', $remaining) ?? $remaining, " \t\n\r,;.-");
    }
}
