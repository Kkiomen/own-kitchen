<?php

declare(strict_types=1);

namespace App\Importing\Parsing;

/**
 * Splits one written ingredient line into amount, unit and the thing itself.
 *
 * "150 g makaronu spaghetti"                    -> 150 g       + "makaronu spaghetti"
 * "1/2 małej cebuli"                            -> 0.5 piece   + "cebuli"          (note: małej)
 * "2 łyżki drobno posiekanej natki pietruszki"  -> 2 tbsp      + "natki pietruszki" (note: drobno posiekanej)
 * "świeżo zmielony czarny pieprz"               -> no amount   + "zmielony czarny pieprz"
 */
final class IngredientLineParser
{
    private const array VULGAR_FRACTIONS = [
        '½' => 0.5, '⅓' => 0.3333, '⅔' => 0.6667, '¼' => 0.25,
        '¾' => 0.75, '⅛' => 0.125, '⅕' => 0.2, '⅖' => 0.4,
    ];

    /**
     * Words describing how an ingredient was prepared or how big it is. They belong
     * in a note: keeping them in the name would create "onion" and "small onion" as
     * two different products.
     */
    private const array DESCRIPTOR_WORDS = [
        'drobno', 'grubo', 'swiezo', 'cienko', 'delikatnie',
        'posiekanej', 'posiekany', 'posiekana', 'posiekane', 'posiekanych',
        'pokrojonej', 'pokrojony', 'pokrojona', 'pokrojone', 'pokrojonych',
        'startego', 'starty', 'starta', 'starte', 'startej',
        'obranej', 'obrany', 'obrana', 'obrane', 'obranych',
        'umytej', 'umyty', 'umyta', 'umyte',
        'maly', 'mala', 'male', 'malej', 'malych', 'maluchny',
        'duzy', 'duza', 'duze', 'duzej', 'duzych',
        'sredni', 'srednia', 'srednie', 'sredniej', 'srednich',
    ];

    private const array OPTIONAL_MARKERS = ['opcjonalnie', 'do smaku', 'wedlug uznania', 'ewentualnie'];

    public function __construct(
        private readonly PolishTextNormalizer $normalizer,
        private readonly UnitVocabulary $units,
    ) {}

    public function parse(string $rawLine, ?string $section = null): ParsedIngredientLine
    {
        $raw = trim(preg_replace('/\s+/u', ' ', $rawLine) ?? '');

        [$working, $parentheticalNote] = $this->extractParentheticals($raw);
        // Footnote markers such as the trailing asterisk in "cream cheese*".
        $working = trim((string) preg_replace('/[*†]+/u', '', $working));

        /*
         * The raw line, not `$working`: `extractParentheticals()` has just moved
         * "(opcjonalnie)" into the note, so asking the remainder found nothing
         * and the commonest spelling of an optional ingredient — a bracket at
         * the end of the line — was imported as a required one. "opcjonalnie 2
         * jajka" and "2 jajka, opcjonalnie" were detected all along, which is
         * why this went unnoticed.
         */
        $isOptional = $this->detectOptional($raw);
        $working = $this->stripLeadingMarker($working);
        [$quantity, $quantityMax, $afterQuantity] = $this->extractQuantity($working);
        [$unitCode, $afterUnit] = $this->extractUnit($afterQuantity);

        // A bare count with no unit is a piece: "2 jajka" means two of them.
        if ($quantity !== null && $unitCode === null) {
            $unitCode = 'piece';
        }

        [$phrase, $descriptorNote] = $this->stripDescriptors($afterUnit);
        [$phraseIncludingUnit] = $this->stripDescriptors($afterQuantity);

        return new ParsedIngredientLine(
            rawText: $raw,
            quantity: $quantity,
            quantityMax: $quantityMax,
            unitCode: $unitCode,
            ingredientPhrase: $phrase,
            phraseIncludingUnit: $phraseIncludingUnit,
            note: $this->mergeNotes($descriptorNote, $parentheticalNote),
            isOptional: $isOptional,
        );
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function extractParentheticals(string $line): array
    {
        $notes = [];
        $stripped = preg_replace_callback(
            '/\(([^)]*)\)/u',
            function (array $match) use (&$notes): string {
                $notes[] = trim($match[1]);

                return ' ';
            },
            $line,
        ) ?? $line;

        return [
            trim(preg_replace('/\s+/u', ' ', $stripped) ?? $stripped),
            $notes === [] ? null : implode('; ', array_filter($notes)),
        ];
    }

    /**
     * "opcjonalnie: 1 łyżeczka sosu" opens with a label, not an amount. Left in
     * place it defeats quantity extraction and the whole line ends up being
     * treated as the product's name.
     */
    private function stripLeadingMarker(string $line): string
    {
        $markers = implode('|', array_map(
            static fn (string $marker): string => preg_quote($marker, '/'),
            [...self::OPTIONAL_MARKERS, 'dla chętnych', 'do podania', 'do dekoracji', 'dodatkowo'],
        ));

        // The separator is optional: the sites write both "opcjonalnie: 1 łyżka"
        // and "opcjonalnie 1 łyżka".
        return trim((string) preg_replace('/^\s*(?:'.$markers.')\s*[:,-]?\s+/ui', '', $line));
    }

    private function detectOptional(string $line): bool
    {
        $normalised = $this->normalizer->normalize($line);

        foreach (self::OPTIONAL_MARKERS as $marker) {
            if (str_contains($normalised, $marker)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array{0: float|null, 1: float|null, 2: string}
     */
    private function extractQuantity(string $line): array
    {
        $number = '(?:\d+\s+\d+\/\d+|\d+\/\d+|\d+(?:[.,]\d+)?|['.implode('', array_keys(self::VULGAR_FRACTIONS)).'])';

        // Ranges first, so "1 - 2 łyżki" is not read as a bare "1".
        if (preg_match('/^('.$number.')\s*(?:-|–|do)\s*('.$number.')\s*(.*)$/u', $line, $match) === 1) {
            return [$this->toFloat($match[1]), $this->toFloat($match[2]), trim($match[3])];
        }

        if (preg_match('/^('.$number.')\s*(.*)$/u', $line, $match) === 1) {
            return [$this->toFloat($match[1]), null, trim($match[2])];
        }

        return [null, null, $line];
    }

    private function toFloat(string $token): float
    {
        $token = trim($token);

        if (isset(self::VULGAR_FRACTIONS[$token])) {
            return self::VULGAR_FRACTIONS[$token];
        }

        // Mixed numbers such as "1 1/2".
        if (preg_match('#^(\d+)\s+(\d+)/(\d+)$#u', $token, $match) === 1) {
            return (float) $match[1] + ((float) $match[2] / (float) $match[3]);
        }

        if (preg_match('#^(\d+)/(\d+)$#u', $token, $match) === 1) {
            return (float) $match[1] / (float) $match[2];
        }

        return (float) str_replace(',', '.', $token);
    }

    /**
     * @return array{0: string|null, 1: string}
     */
    private function extractUnit(string $line): array
    {
        $words = $line === '' ? [] : explode(' ', $line);

        if ($words === []) {
            return [null, $line];
        }

        $code = $this->units->codeFor($this->normalizer->normalize($words[0]));

        if ($code === null) {
            return [null, $line];
        }

        array_shift($words);

        return [$code, trim(implode(' ', $words))];
    }

    /**
     * @return array{0: string, 1: string|null}
     */
    private function stripDescriptors(string $phrase): array
    {
        $kept = [];
        $removed = [];

        foreach ($phrase === '' ? [] : explode(' ', $phrase) as $word) {
            if (in_array($this->normalizer->normalize($word), self::DESCRIPTOR_WORDS, true)) {
                $removed[] = $word;

                continue;
            }

            $kept[] = $word;
        }

        // Descriptors only: keep the words, since dropping them leaves nothing.
        if ($kept === []) {
            return [trim($phrase), null];
        }

        return [
            trim(implode(' ', $kept)),
            $removed === [] ? null : implode(' ', $removed),
        ];
    }

    private function mergeNotes(?string ...$notes): ?string
    {
        $present = array_filter($notes, static fn (?string $note): bool => $note !== null && $note !== '');

        return $present === [] ? null : implode('; ', $present);
    }
}
