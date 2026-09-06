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
        /*
         * Fresh is not a different product, and leaving these in made them one:
         * "świeżych drożdży", "świeżych malin" and "świeżych liści bazylii" all
         * imported under an invented product called "świeżych", 232 lines of it.
         *
         * Note what is deliberately *not* here: **suszony**. Dried is a different
         * product — "suszonych pomidorów" reduced to "pomidorów" would put fresh
         * tomatoes in a recipe that wants the jarred ones. Those get dictionary
         * entries instead, which is the slower half of the same job.
         */
        'swieze', 'swiezy', 'swieza', 'swiezej', 'swiezych', 'swiezymi', 'swiezego',
        'posiekanej', 'posiekany', 'posiekana', 'posiekane', 'posiekanych',
        'pokrojonej', 'pokrojony', 'pokrojona', 'pokrojone', 'pokrojonych',
        'startego', 'starty', 'starta', 'starte', 'startej',
        'obranej', 'obrany', 'obrana', 'obrane', 'obranych',
        'umytej', 'umyty', 'umyta', 'umyte',
        /*
         * How it was put through something. "czosnku przeciśniętego przez
         * praskę" is garlic with an instruction attached, and the instruction
         * became a product called "przeciśniętego przez praskę" that then took
         * the garlic with it — the same way "do podania" took the parmesan.
         */
        'przecisniety', 'przecisnieta', 'przecisniete', 'przecisnietego', 'przecisnietych',
        'przez', 'praske', 'prasce', 'praski', 'maszynke', 'maszynce', 'tarce', 'tarke',
        'maly', 'mala', 'male', 'malej', 'malych', 'maluchny',
        'duzy', 'duza', 'duze', 'duzej', 'duzych',
        'sredni', 'srednia', 'srednie', 'sredniej', 'srednich',
        // How full the spoon is. "1 płaska łyżeczka soli" was 19 lines of one
        // *piece* of salt on its own; the measures already assume a Polish
        // heaped spoon, so this belongs in the note either way.
        'plaska', 'plaski', 'plaskie', 'plaskiej', 'plaskich',
        'czubata', 'czubaty', 'czubate', 'czubatej', 'czubatych', 'kopiasta', 'kopiaste',
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
        $working = $this->stripTrailingMarker($this->stripLeadingMarker($working));
        [$quantity, $quantityMax, $afterQuantity] = $this->extractQuantity($working);

        /*
         * Descriptors come out **before** the measure is read, and the order is
         * the whole point. "1 płaska łyżeczka soli" and "2 duże ząbki czosnku"
         * put a describing word between the number and the measure; reading the
         * measure first found none there, so the line fell back to the bare-count
         * rule below and became "1 sztuka soli" and "2 sztuki czosnku". That is
         * not a cosmetic slip — two cloves are 10 g and two heads are 90 g. 679
         * lines were counted in pieces this way.
         */
        [$withoutDescriptors, $descriptorNote] = $this->stripDescriptors($afterQuantity);
        [$unitCode, $afterUnit] = $this->extractUnit($withoutDescriptors);

        // A bare count with no unit is a piece: "2 jajka" means two of them.
        if ($quantity !== null && $unitCode === null) {
            $unitCode = 'piece';
        }

        $phrase = $afterUnit;
        // The same words with the measure left in, for the "1 listek laurowy"
        // retry: one leaf of "laurowy" is nothing, one "listek laurowy" is a
        // product.
        $phraseIncludingUnit = $withoutDescriptors;

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
        // The separator is optional: the sites write both "opcjonalnie: 1 łyżka"
        // and "opcjonalnie 1 łyżka".
        return trim((string) preg_replace('/^\s*(?:'.$this->markerPattern().')\s*[:,-]?\s+/ui', '', $line));
    }

    /**
     * Words that label a line rather than name anything in it, as one alternation
     * shared by the leading and trailing forms — two lists would drift the first
     * time a site was seen writing one of them at the other end.
     */
    private function markerPattern(): string
    {
        return implode('|', array_map(
            static fn (string $marker): string => preg_quote($marker, '/'),
            // "oraz" joins this line to the previous one and names nothing on
            // its own; left in front it became a product 32 times, including on
            // "oraz 50 g masła" where the butter was there to be read.
            [...self::OPTIONAL_MARKERS, 'dla chętnych', 'do podania', 'do dekoracji', 'dodatkowo', 'oraz'],
        ));
    }

    /**
     * The same markers at the *end* of the line, where they read as a serving
     * note rather than a label: "parmezan do podania", "natka do dekoracji".
     *
     * Stripped for exactly the reason the leading form is. Left in place the
     * whole phrase becomes the product, and "do podania" was invented as one —
     * after which it owned that spelling and quietly took the parmesan, the
     * chives and the soured cream on 93 lines with it. That is the "Sos:"
     * mechanism again, arriving from the other end of the sentence.
     *
     * Only ever a suffix, and only when something is left in front of it: a line
     * that is *nothing but* a serving note has no ingredient to rescue, and
     * emptying it here would lose the raw text a review depends on.
     */
    private function stripTrailingMarker(string $line): string
    {
        $stripped = trim((string) preg_replace(
            '/[\s,;-]+(?:'.$this->markerPattern().')\s*$/ui',
            '',
            $line,
        ));

        return $stripped === '' ? $line : $stripped;
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
