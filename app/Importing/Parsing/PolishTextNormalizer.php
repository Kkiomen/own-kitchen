<?php

declare(strict_types=1);

namespace App\Importing\Parsing;

/**
 * Reduces a phrase to the key used for ingredient lookup: lowercase, no diacritics,
 * no punctuation, single spaces. "Natki pietruszki," and "natki  pietruszki" must
 * produce the same key or deduplication silently fails.
 */
final class PolishTextNormalizer
{
    /**
     * Polish first, then the other Latin marks that turn up in borrowed names.
     * Without them "jalapeño" and "purée" lose a letter to the punctuation strip
     * and break into fragments that match nothing.
     */
    private const array DIACRITICS = [
        'ą' => 'a', 'ć' => 'c', 'ę' => 'e', 'ł' => 'l', 'ń' => 'n',
        'ó' => 'o', 'ś' => 's', 'ź' => 'z', 'ż' => 'z',

        'á' => 'a', 'à' => 'a', 'â' => 'a', 'ä' => 'a', 'ã' => 'a', 'å' => 'a',
        'é' => 'e', 'è' => 'e', 'ê' => 'e', 'ë' => 'e',
        'í' => 'i', 'ì' => 'i', 'î' => 'i', 'ï' => 'i',
        'ñ' => 'n',
        'ò' => 'o', 'ô' => 'o', 'ö' => 'o', 'õ' => 'o', 'ø' => 'o',
        'ú' => 'u', 'ù' => 'u', 'û' => 'u', 'ü' => 'u',
        'ý' => 'y', 'ÿ' => 'y',
        'ç' => 'c', 'č' => 'c', 'š' => 's', 'ž' => 'z', 'ß' => 'ss',
    ];

    public function normalize(string $text): string
    {
        $text = mb_strtolower(trim($text), 'UTF-8');
        $text = strtr($text, self::DIACRITICS);
        // Percentages carry meaning for dairy ("śmietana 18%"), so they survive.
        $text = preg_replace('/[^a-z0-9%\s]+/u', ' ', $text) ?? '';
        $text = preg_replace('/\s+/u', ' ', $text) ?? '';

        return trim($text);
    }

    /**
     * A URL- and filename-safe key.
     */
    public function slug(string $text): string
    {
        return str_replace(' ', '-', $this->normalize($text));
    }
}
