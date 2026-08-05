<?php

declare(strict_types=1);

namespace App\Importing\Sources\AirFryerPrzepisy;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The only class that knows airfryerprzepisy.pl's markup — and it only has to find
 * links, because the recipes themselves are read from JSON-LD.
 *
 * The archive is a WordPress loop: one `article.loop-entry` per recipe, whose
 * `entry-title` holds the single link to the recipe. Taking every link inside the
 * article instead would also pick up its category links.
 */
final class AirFryerPrzepisyListingParser
{
    private const string RECIPE_LINKS = '//article[contains(@class, "loop-entry")]'
        .'//*[contains(@class, "entry-title")]//a[@href]';

    /**
     * Recipe slugs linked from a listing page, in the order they appear.
     *
     * @return list<string>
     */
    public function parseListing(string $html, string $baseUrl): array
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // Real-world markup is never valid; parse it anyway and discard the warnings.
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        $links = (new DOMXPath($document))->query(self::RECIPE_LINKS);

        if ($links === false) {
            return [];
        }

        $slugs = [];

        foreach ($links as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $slug = $this->slugFromUrl($link->getAttribute('href'), $baseUrl);

            if ($slug !== null) {
                $slugs[$slug] = true;
            }
        }

        return array_keys($slugs);
    }

    /**
     * Recipes live at the site root, so a slug is the whole path: one segment, no
     * trailing slash. Anything nested ("/tag/keto/") is not a recipe.
     */
    private function slugFromUrl(string $url, string $baseUrl): ?string
    {
        $path = trim(str_replace($baseUrl, '', $url), '/');

        if ($path === '' || str_contains($path, '/') || str_starts_with($path, '#')) {
            return null;
        }

        return $path;
    }
}
