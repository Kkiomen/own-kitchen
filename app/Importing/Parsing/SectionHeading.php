<?php

declare(strict_types=1);

namespace App\Importing\Parsing;

/**
 * Tells a heading apart from an ingredient.
 *
 * Some sites put "Sos:" or "Przyprawy" in the ingredient list as ordinary items
 * rather than marking them up as headings. Imported literally they become
 * products that nobody can buy, and they were the single largest group in the
 * review queue.
 */
final class SectionHeading
{
    /**
     * Words that head a group of ingredients. Only ever consulted for a line that
     * carries no amount, so a real ingredient called "sos sojowy" is unaffected.
     */
    private const array HEADING_WORDS = [
        'przyprawy', 'przyprawa', 'dodatki', 'dodatek', 'sos', 'sosy', 'pasta',
        'marynata', 'farsz', 'nadzienie', 'ciasto', 'spod', 'krem', 'polewa',
        'dekoracja', 'do podania', 'podanie', 'skladniki', 'reszta', 'posypka',
        'masa', 'baza', 'zalewa', 'panierka', 'do dekoracji',
    ];

    /**
     * Group names that are not headings but are still far too broad to stand for a
     * product. "płatki" could be oats, almonds or chilli.
     */
    private const array GENERIC_WORDS = [
        // "płatków" reduces to the singular stem, so both forms are listed.
        'platki', 'platek', 'zielenina', 'tluszcz', 'owoce', 'warzywa',
    ];

    public function __construct(
        private readonly PolishTextNormalizer $normalizer,
        private readonly PolishInflection $inflection,
    ) {}

    /**
     * Whether a phrase names a group rather than a product.
     *
     * Creating a product from one of these is what poisons the database: a line
     * reduced to "sosu" invents a product owning the alias "sos", and from then on
     * every sauce in every recipe resolves to it. Such a line is better left
     * unresolved and flagged than silently attached to an invention.
     */
    public function isGenericGroupName(string $phrase): bool
    {
        $key = $this->normalizer->normalize($phrase);

        // Only bare words are suspect; a qualifier makes a phrase specific enough.
        if ($key === '' || str_contains($key, ' ')) {
            return false;
        }

        foreach ($this->inflection->lemmaCandidates($key) as $candidate) {
            if (in_array($candidate, [...self::HEADING_WORDS, ...self::GENERIC_WORDS], true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The heading's label, or null when the line is an ingredient after all.
     */
    public function detect(string $rawText): ?string
    {
        $text = trim($rawText);

        if ($text === '') {
            return null;
        }

        // An amount means an ingredient, whatever the wording.
        if (preg_match('/\d/u', $text) === 1) {
            return null;
        }

        $withoutColon = rtrim($text, ": \t");

        // "Sos:" — the colon is the site's own way of marking a heading.
        if (str_ends_with($text, ':') && $withoutColon !== '') {
            return $withoutColon;
        }

        return in_array($this->normalizer->normalize($withoutColon), self::HEADING_WORDS, true)
            ? $withoutColon
            : null;
    }

    /**
     * Removes a heading that shares its line with the ingredients under it, as in
     * "sos: 4 łyżki jogurtu + 1 łyżka majonezu".
     *
     * Left in place the word becomes part of the product's name, and — worse — a
     * bare "sos:" line registers the alias "sos", after which every sauce in the
     * database resolves to that one invented product.
     *
     * @return array{0: string, 1: string|null} The remaining text and the heading, if any.
     */
    public function stripPrefix(string $rawText): array
    {
        $text = trim($rawText);

        if (preg_match('/^(\p{L}[\p{L}\s]{1,24}?)\s*:\s*(.+)$/u', $text, $match) !== 1) {
            return [$text, null];
        }

        $heading = trim($match[1]);

        if (! in_array($this->normalizer->normalize($heading), self::HEADING_WORDS, true)) {
            return [$text, null];
        }

        return [trim($match[2]), $heading];
    }
}
