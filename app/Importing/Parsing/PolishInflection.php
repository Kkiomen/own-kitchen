<?php

declare(strict_types=1);

namespace App\Importing\Parsing;

/**
 * Best-effort reduction of a declined Polish word back to its dictionary form.
 *
 * Recipe lines are written in whatever case the sentence needs — "150 g makaronu",
 * "1/2 cebuli", "2 ząbki czosnku" — so the literal text almost never matches the
 * nominative form we store. These rules are deliberately shallow heuristics: the
 * alias table is the authority, and this only widens the net when no alias matches.
 * Wrong guesses are harmless because every candidate is checked against existing
 * ingredients rather than trusted on its own.
 */
final class PolishInflection
{
    /**
     * Irregular stems that no suffix rule recovers, because the stem itself changes.
     */
    private const array IRREGULAR = [
        'cukru' => 'cukier',
        'czosnku' => 'czosnek',
        'pieprzu' => 'pieprz',
        'soli' => 'sol',
        'marchwi' => 'marchew',
        'masla' => 'maslo',
        'mleka' => 'mleko',
        'jajek' => 'jajko',
        'jajka' => 'jajko',
        'jaj' => 'jajko',
        'oleju' => 'olej',
        'octu' => 'ocet',
        'ryzu' => 'ryz',
        'chleba' => 'chleb',
        'miodu' => 'miod',
        'ziemniakow' => 'ziemniak',
        'pomidorow' => 'pomidor',
        'kurek' => 'kurki',
        'orzechow' => 'orzech',
        'sera' => 'ser',
        'serka' => 'serek',
        'boczku' => 'boczek',
        'makaronu' => 'makaron',
    ];

    /**
     * Suffix rewrites, ordered from most to least specific. `null` means "drop it".
     */
    private const array SUFFIX_RULES = [
        'ami' => '',
        'ach' => '',
        'om' => '',
        'ow' => '',
        'ki' => 'ka',
        'ce' => 'ka',
        'gi' => 'ga',
        'i' => 'a',
        'y' => 'a',
        'e' => 'a',
        'u' => '',
        'a' => '',
        'ie' => '',
    ];

    /**
     * Ordered dictionary-form candidates for a normalised word, most likely first.
     * The original word is always included, since it may already be nominative.
     *
     * @return list<string>
     */
    public function lemmaCandidates(string $word): array
    {
        if ($word === '') {
            return [];
        }

        $candidates = [$word];

        if (isset(self::IRREGULAR[$word])) {
            $candidates[] = self::IRREGULAR[$word];
        }

        foreach (self::SUFFIX_RULES as $suffix => $replacement) {
            if (! str_ends_with($word, $suffix)) {
                continue;
            }

            $stem = mb_substr($word, 0, mb_strlen($word) - mb_strlen($suffix));

            // Anything shorter than three letters is noise rather than a stem.
            if (mb_strlen($stem) < 3) {
                continue;
            }

            $candidates[] = $stem.$replacement;
            $candidates[] = $this->restoreFleetingE($stem.$replacement);
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Reverses the vowel that Polish drops when declining: "cukier" -> "cukru",
     * "czosnek" -> "czosnku". Only fires on a trailing consonant pair, which is
     * where the vowel disappeared from.
     */
    private function restoreFleetingE(string $stem): string
    {
        if (mb_strlen($stem) < 3 || preg_match('/[aeiouy]{1}[bcdfghjklmnprstwzq]{2}$/u', $stem) !== 1) {
            return $stem;
        }

        $head = mb_substr($stem, 0, mb_strlen($stem) - 1);
        $last = mb_substr($stem, -1);

        return $head.'e'.$last;
    }
}
