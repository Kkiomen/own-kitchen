<?php

declare(strict_types=1);

namespace App\Importing\Sources\Beszamel;

use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeReference;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * The only class that knows beszamel.se.pl's markup.
 *
 * It exists for one reason: the site's JSON-LD publishes `recipeIngredient` as a
 * single string with every line glued to the next ("…opłukane1–2 łyżki oliwy"),
 * which no amount of parsing downstream can pull apart safely. Its own HTML keeps
 * the lines separated by <br> inside `div.ingredients__items`, so that is where the
 * ingredients are read from. Everything else still comes from the JSON-LD.
 */
final class BeszamelPageParser
{
    private const string INGREDIENTS = '//div[contains(@class, "ingredients__items")]';

    /**
     * Recipes live at /przepisy/<category>/<slug>.html; anything else linked from a
     * listing is navigation.
     */
    private const string RECIPE_LINK = '#^https?://[^/]+/przepisy/[a-z0-9-]+/(?<slug>[^/]+)\.html$#i';

    /**
     * Ingredient lines in the order the page lists them.
     *
     * @return list<IngredientLineDraft>
     */
    public function parseIngredients(string $html): array
    {
        $found = $this->xpath($html)->query(self::INGREDIENTS);
        // DOMXPath::query returns false on a malformed expression.
        $field = $found === false ? null : $found->item(0);

        if (! $field instanceof DOMElement) {
            return [];
        }

        $lines = [];
        $section = null;

        foreach ($this->blocksIn($field) as $block) {
            foreach ($this->splitOnBreaks($block) as $text) {
                if ($this->isSectionCaption($text)) {
                    $section = $text;

                    continue;
                }

                $lines[] = new IngredientLineDraft($text, $section);
            }
        }

        return $lines;
    }

    /**
     * Recipes linked from a listing page, in the order they appear.
     *
     * @return list<RecipeReference>
     */
    public function parseListing(string $html): array
    {
        $xpath = $this->xpath($html);
        $links = $xpath->query('//a[@href]');

        if ($links === false) {
            return [];
        }

        $references = [];

        foreach ($links as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $url = $link->getAttribute('href');

            if (preg_match(self::RECIPE_LINK, $url, $match) !== 1 || isset($references[$url])) {
                continue;
            }

            $references[$url] = new RecipeReference(url: $url, slug: $match['slug']);
        }

        return array_values($references);
    }

    /**
     * A multi-part recipe captions its parts with a plain list item — "Na ciasto",
     * "Do wykończenia" — indistinguishable in the markup from an ingredient.
     *
     * Only the "for the …" idiom is claimed here, capitalised and with no amount,
     * because that is the one shape no ingredient line takes. Deliberately narrow:
     * a caption read as a product invents junk like "Do smażenia", which then
     * swallows every real "olej do smażenia" line into itself, but a *product* read
     * as a caption loses an ingredient outright. Two-word captions such as "Farsz
     * owocowy" are left in the review queue rather than risk that.
     */
    private function isSectionCaption(string $text): bool
    {
        return preg_match('/^(Na|Do)\s+\p{L}/u', $text) === 1
            && preg_match('/\d/u', $text) !== 1;
    }

    /**
     * The site writes its ingredient list two different ways and both are live: the
     * older recipes give every line its own <li>, the newer ones put the whole list
     * in a single <li> separated by <br>. Splitting on only one of the two turns the
     * other shape into a single thousand-character "ingredient".
     *
     * @return list<DOMElement>
     */
    private function blocksIn(DOMElement $field): array
    {
        $items = [];

        foreach ($this->descendants($field) as $node) {
            if ($node instanceof DOMElement && $node->tagName === 'li') {
                $items[] = $node;
            }
        }

        return $items === [] ? [$field] : $items;
    }

    /**
     * Lines separated by <br> have to be gathered between the breaks rather than per
     * element, because they are siblings of the text rather than wrapping it.
     *
     * @return list<string>
     */
    private function splitOnBreaks(DOMElement $field): array
    {
        $lines = [];
        $current = '';

        foreach ($this->descendants($field) as $node) {
            if ($node instanceof DOMElement && $node->tagName === 'br') {
                $lines[] = $current;
                $current = '';

                continue;
            }

            if ($node->nodeType === XML_TEXT_NODE) {
                $current .= $node->textContent;
            }
        }

        $lines[] = $current;

        return array_values(array_filter(
            array_map(fn (string $line): string => $this->clean($line), $lines),
            // The list is headed by its own "SKŁADNIKI" label, which is not one.
            static fn (string $line): bool => $line !== '' && mb_strtoupper($line) !== $line,
        ));
    }

    /**
     * @return list<DOMNode>
     */
    private function descendants(DOMElement $root): array
    {
        $nodes = [];

        foreach ($root->childNodes as $child) {
            $nodes[] = $child;

            if ($child instanceof DOMElement) {
                $nodes = [...$nodes, ...$this->descendants($child)];
            }
        }

        return $nodes;
    }

    private function xpath(string $html): DOMXPath
    {
        $document = new DOMDocument;
        $previous = libxml_use_internal_errors(true);
        // Real-world markup is never valid; parse it anyway and discard the warnings.
        $document->loadHTML('<?xml encoding="UTF-8">'.$html);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        return new DOMXPath($document);
    }

    private function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
