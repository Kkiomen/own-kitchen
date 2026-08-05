<?php

declare(strict_types=1);

namespace App\Importing\Sources\CentrumRespo;

use DOMDocument;
use DOMElement;
use DOMXPath;

/**
 * The only class that knows centrumrespo.pl's markup — and it only has to find
 * links, because the recipes themselves are read from JSON-LD.
 *
 * Each result in the grid is one `a.single-przepis__link`. Taking every recipe
 * link on the page instead would also pick up the search box's "recently viewed"
 * suggestions, which have nothing to do with the category being walked.
 */
final class CentrumRespoListingParser
{
    private const string RECIPE_LINKS = '//a[contains(@class, "single-przepis__link")][@href]';

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
     * A recipe lives at "/przepisy/<slug>/": one segment below the archive root.
     * Anything deeper or differently shaped ("/przepisy/kategoria/lunchbox/") is a
     * listing, and fetching it as a recipe would fail on every run.
     */
    private function slugFromUrl(string $url, string $baseUrl): ?string
    {
        $path = trim(str_replace($baseUrl, '', $url), '/');

        if (! str_starts_with($path, 'przepisy/')) {
            return null;
        }

        $slug = substr($path, strlen('przepisy/'));

        return $slug !== '' && ! str_contains($slug, '/') ? $slug : null;
    }
}
