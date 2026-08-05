<?php

declare(strict_types=1);

namespace App\Importing\Parsing;

/**
 * Maps the Polish unit words that appear in recipe text onto our unit codes.
 * Units are a closed set, so a static table beats any clever inference here.
 */
final class UnitVocabulary
{
    /**
     * Normalised word => unit code. Longest match wins, so multi-word entries
     * must be looked up before single words.
     *
     * @var array<string, string>
     */
    private const array TOKENS = [
        // "gr" and "sztuk" come from shop packaging rather than recipe text, but
        // units are one closed vocabulary for the whole app — a second table for
        // labels would be the same knowledge written twice.
        'g' => 'g', 'gr' => 'g', 'gram' => 'g', 'grama' => 'g', 'gramy' => 'g', 'gramow' => 'g',
        'kg' => 'kg', 'kilogram' => 'kg', 'kilograma' => 'kg', 'kilogramy' => 'kg',
        'dag' => 'dag', 'dkg' => 'dag', 'deka' => 'dag',
        'ml' => 'ml', 'mililitr' => 'ml', 'mililitry' => 'ml', 'mililitrow' => 'ml',
        'l' => 'l', 'litr' => 'l', 'litra' => 'l', 'litry' => 'l', 'litrow' => 'l',
        'lyzka' => 'tbsp', 'lyzki' => 'tbsp', 'lyzek' => 'tbsp', 'lyzke' => 'tbsp',
        'lyzeczka' => 'tsp', 'lyzeczki' => 'tsp', 'lyzeczek' => 'tsp', 'lyzeczke' => 'tsp',
        'szklanka' => 'cup', 'szklanki' => 'cup', 'szklanek' => 'cup', 'szklanke' => 'cup',
        'szczypta' => 'pinch', 'szczypty' => 'pinch', 'szczypte' => 'pinch',
        'zabek' => 'clove', 'zabki' => 'clove', 'zabka' => 'clove', 'zabkow' => 'clove',
        'opakowanie' => 'package', 'opakowania' => 'package', 'paczka' => 'package', 'paczki' => 'package',
        'puszka' => 'can', 'puszki' => 'can', 'puszek' => 'can',
        'sloik' => 'jar', 'sloika' => 'jar', 'sloiki' => 'jar', 'sloiczek' => 'jar', 'sloiczka' => 'jar',
        'peczek' => 'bunch', 'peczka' => 'bunch', 'peczki' => 'bunch',
        'plaster' => 'slice', 'plastry' => 'slice', 'plastrow' => 'slice',
        'plasterek' => 'slice', 'plasterki' => 'slice',
        'garsc' => 'handful', 'garsci' => 'handful',
        'kropla' => 'drop', 'krople' => 'drop', 'kropli' => 'drop',
        // "listek bazylii" counts leaves, but in "liść laurowy" the noun is the
        // product itself, so those forms deliberately stay out of the vocabulary.
        'listek' => 'leaf', 'listki' => 'leaf',
        'galazka' => 'sprig', 'galazki' => 'sprig',
        'kostka' => 'cube', 'kostki' => 'cube',
        'sztuka' => 'piece', 'sztuki' => 'piece', 'sztuk' => 'piece', 'szt' => 'piece',
    ];

    public function codeFor(string $normalisedWord): ?string
    {
        return self::TOKENS[$normalisedWord] ?? null;
    }
}
