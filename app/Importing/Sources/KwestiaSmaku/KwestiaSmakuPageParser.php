<?php

declare(strict_types=1);

namespace App\Importing\Sources\KwestiaSmaku;

use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\Exceptions\RecipeNotParsable;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Reads kwestiasmaku.com's Drupal markup.
 *
 * The site publishes no JSON-LD, so this leans on its field classes:
 * `field-name-field-skladniki` holds the ingredient list, `field-name-field-przygotowanie`
 * the steps, and a `div.wyroznione` before a list starts a new section such as
 * "Spód" or "Masa serowa". If those classes ever change, this class is the only
 * thing that breaks.
 */
final class KwestiaSmakuPageParser
{
    private const string INGREDIENTS_FIELD = 'field-name-field-skladniki';

    private const string STEPS_FIELD = 'field-name-field-przygotowanie';

    private const string SERVINGS_FIELD = 'field-name-field-ilosc-porcji';

    private const string SECTION_CLASS = 'wyroznione';

    public function parseRecipe(string $html, string $url, string $slug): RecipeDraft
    {
        $xpath = $this->xpath($html);

        $title = $this->firstText($xpath, '//h1')
            ?? $this->attribute($xpath, '//meta[@property="og:title"]', 'content');

        if ($title === null) {
            throw RecipeNotParsable::missingField($url, 'title');
        }

        $ingredientLines = array_map(
            static fn (array $item): IngredientLineDraft => new IngredientLineDraft($item['text'], $item['section']),
            $this->sectionedItems($xpath, self::INGREDIENTS_FIELD),
        );

        if ($ingredientLines === []) {
            throw RecipeNotParsable::missingField($url, 'ingredients');
        }

        $steps = array_map(
            static fn (array $item): StepDraft => new StepDraft($item['text'], $item['section']),
            $this->sectionedItems($xpath, self::STEPS_FIELD, splitSentences: true),
        );

        if ($steps === []) {
            throw RecipeNotParsable::missingField($url, 'steps');
        }

        $servingsLabel = $this->firstText($xpath, '//div[contains(@class, "'.self::SERVINGS_FIELD.'")]');

        return new RecipeDraft(
            slug: $slug,
            title: $title,
            sourceUrl: $url,
            ingredientLines: $ingredientLines,
            steps: $steps,
            description: $this->attribute($xpath, '//meta[@name="description"]', 'content'),
            servings: $this->parseServings($servingsLabel),
            servingsLabel: $servingsLabel,
            imageUrl: $this->attribute($xpath, '//meta[@property="og:image"]', 'content'),
            tags: $this->parseTags($xpath),
        );
    }

    /**
     * Recipe slugs linked from a listing page, in the order they appear.
     *
     * @return list<string>
     */
    public function parseListing(string $html): array
    {
        $xpath = $this->xpath($html);
        $slugs = [];

        foreach ($this->nodes($xpath, '//a[starts-with(@href, "/przepis/")]') as $link) {
            if (! $link instanceof DOMElement) {
                continue;
            }

            $slug = trim(str_replace('/przepis/', '', $link->getAttribute('href')));

            if ($slug !== '' && ! str_contains($slug, '/')) {
                $slugs[$slug] = true;
            }
        }

        return array_keys($slugs);
    }

    /**
     * Walks a field's children in document order, so every list item is tagged with
     * the section heading that most recently preceded it.
     *
     * @return list<array{text: string, section: string|null}>
     */
    private function sectionedItems(DOMXPath $xpath, string $fieldClass, bool $splitSentences = false): array
    {
        $field = $this->firstElement($xpath, '//div[contains(@class, "'.$fieldClass.'")]');

        if ($field === null) {
            return [];
        }

        $items = [];
        $section = null;
        $paragraphs = [];

        foreach ($this->descendants($field) as $node) {
            if (! $node instanceof DOMElement) {
                continue;
            }

            if ($node->tagName === 'div' && str_contains($node->getAttribute('class'), self::SECTION_CLASS)) {
                $section = $this->clean($node->textContent) ?: null;

                continue;
            }

            if ($node->tagName === 'p') {
                $paragraphs[] = ['text' => $this->clean($node->textContent), 'section' => $section];

                continue;
            }

            if ($node->tagName !== 'li') {
                continue;
            }

            $text = $this->clean($node->textContent);

            if ($text !== '') {
                $items[] = ['text' => $text, 'section' => $section];
            }
        }

        if ($items !== []) {
            return $items;
        }

        // Simple recipes — dressings and sauces mostly — write their method as a
        // paragraph instead of a list. Falling back keeps them out of the failure
        // pile; without it the whole recipe was thrown away.
        $fallback = [];

        foreach ($paragraphs as $paragraph) {
            if ($paragraph['text'] === '') {
                continue;
            }

            // One paragraph would become one enormous "step", which is useless for
            // cooking along. Sentences are the closest thing to the list items the
            // other recipes provide.
            $texts = $splitSentences ? $this->splitSentences($paragraph['text']) : [$paragraph['text']];

            foreach ($texts as $text) {
                $fallback[] = ['text' => $text, 'section' => $paragraph['section']];
            }
        }

        return $fallback;
    }

    /**
     * Abbreviations that end in a full stop but do not end a sentence. Without
     * shielding them, "gotować ok. 9 minut" would split in the middle.
     */
    private const array ABBREVIATIONS = ['ok', 'np', 'tj', 'ew', 'itp', 'itd', 'min', 'godz', 'szt', 'ub', 'ok'];

    /**
     * @return list<string>
     */
    private function splitSentences(string $text): array
    {
        $shielded = $text;

        foreach (self::ABBREVIATIONS as $abbreviation) {
            $shielded = preg_replace(
                '/\b('.preg_quote($abbreviation, '/').')\.\s/ui',
                '$1<DOT> ',
                $shielded,
            ) ?? $shielded;
        }

        // A sentence ends where a full stop is followed by a capital letter.
        $parts = preg_split('/(?<=[.!?])\s+(?=[A-ZĄĆĘŁŃÓŚŹŻ])/u', $shielded) ?: [$shielded];

        $sentences = [];

        foreach ($parts as $part) {
            $sentence = trim(str_replace('<DOT>', '.', $part));

            if ($sentence !== '') {
                $sentences[] = $sentence;
            }
        }

        return $sentences;
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

    /**
     * @return list<string>
     */
    private function parseTags(DOMXPath $xpath): array
    {
        $tags = [];

        foreach ($this->nodes($xpath, '//div[contains(@class, "field-name-field-tagi")]//a') as $link) {
            $tag = $this->clean($link->textContent);

            if ($tag !== '') {
                $tags[$tag] = true;
            }
        }

        return array_keys($tags);
    }

    /**
     * "2 porcje" -> 2. Anything unparseable keeps the label but drops the number.
     */
    private function parseServings(?string $label): ?int
    {
        if ($label === null || preg_match('/(\d+)/', $label, $match) !== 1) {
            return null;
        }

        $servings = (int) $match[1];

        return $servings > 0 && $servings <= 50 ? $servings : null;
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

    /**
     * DOMXPath::query returns false on a malformed expression and can yield
     * namespace nodes; both are filtered out here so callers get plain nodes.
     *
     * @return list<DOMNode>
     */
    private function nodes(DOMXPath $xpath, string $query): array
    {
        $result = $xpath->query($query);

        if ($result === false) {
            return [];
        }

        $nodes = [];

        foreach ($result as $node) {
            if ($node instanceof DOMNode) {
                $nodes[] = $node;
            }
        }

        return $nodes;
    }

    private function firstElement(DOMXPath $xpath, string $query): ?DOMElement
    {
        $node = $this->nodes($xpath, $query)[0] ?? null;

        return $node instanceof DOMElement ? $node : null;
    }

    private function firstText(DOMXPath $xpath, string $query): ?string
    {
        $node = $this->nodes($xpath, $query)[0] ?? null;

        if ($node === null) {
            return null;
        }

        return $this->clean($node->textContent) ?: null;
    }

    private function attribute(DOMXPath $xpath, string $query, string $attribute): ?string
    {
        $element = $this->firstElement($xpath, $query);

        if ($element === null) {
            return null;
        }

        return $this->clean($element->getAttribute($attribute)) ?: null;
    }

    private function clean(string $text): string
    {
        $text = html_entity_decode($text, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Non-breaking spaces are rife in this markup and break every regex downstream.
        $text = str_replace("\u{00A0}", ' ', $text);

        return trim(preg_replace('/\s+/u', ' ', $text) ?? $text);
    }
}
