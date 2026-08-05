<?php

declare(strict_types=1);

namespace App\Importing\Sources\SchemaOrg;

use App\Enums\Appliance;
use App\Importing\Drafts\IngredientLineDraft;
use App\Importing\Drafts\RecipeDraft;
use App\Importing\Drafts\StepDraft;
use App\Importing\Exceptions\RecipeNotParsable;

/**
 * Reads a schema.org/Recipe out of a page's JSON-LD.
 *
 * This is not an adapter for one site: it is the published contract that recipe
 * plugins (WPRM, WPZOOM, Tasty) all emit, so any site using one of them needs only
 * a listing parser rather than a second HTML scraper. Site-specific classes stay
 * responsible for finding recipe URLs; this class turns a page into a draft.
 *
 * It reads the markup the site vouches for rather than its layout, which is why it
 * survives a redesign that would break a class-name scraper.
 */
final class JsonLdRecipeParser
{
    /**
     * @param  Appliance|null  $appliance  Set when every recipe on the site is written
     *                                     for one device, as an air fryer site is.
     * @param  bool  $isMealPrep  Set when every recipe the source walks is meant to be
     *                            cooked ahead in a batch, as a lunchbox listing is.
     */
    public function __construct(
        private readonly ?Appliance $appliance = null,
        private readonly bool $isMealPrep = false,
    ) {}

    public function parseRecipe(string $html, string $url, string $slug): RecipeDraft
    {
        $documents = $this->jsonLdDocuments($html);
        $recipe = $this->findRecipeNode($documents);

        if ($recipe === null) {
            throw RecipeNotParsable::missingField($url, 'JSON-LD Recipe');
        }

        $title = $this->string($recipe['name'] ?? null);

        if ($title === null) {
            throw RecipeNotParsable::missingField($url, 'title');
        }

        $ingredientLines = $this->ingredientLines($recipe);

        if ($ingredientLines === []) {
            throw RecipeNotParsable::missingField($url, 'ingredients');
        }

        $steps = $this->steps($recipe['recipeInstructions'] ?? null, null);

        if ($steps === []) {
            throw RecipeNotParsable::missingField($url, 'steps');
        }

        [$servings, $servingsLabel] = $this->yield($recipe['recipeYield'] ?? null);

        return new RecipeDraft(
            slug: $slug,
            title: $title,
            sourceUrl: $url,
            ingredientLines: $ingredientLines,
            steps: $steps,
            description: $this->string($recipe['description'] ?? null),
            servings: $servings,
            servingsLabel: $servingsLabel,
            imageUrl: $this->imageUrl($recipe['image'] ?? null, $this->nodesById($documents)),
            tags: $this->tags($recipe),
            totalTimeMinutes: $this->minutes($recipe['totalTime'] ?? null)
                ?? $this->sumOfPrepAndCook($recipe),
            appliance: $this->appliance,
            isMealPrep: $this->isMealPrep,
        );
    }

    /**
     * Every JSON-LD block on the page, decoded. A page routinely carries several,
     * and the one holding the recipe is not always the one holding its image.
     *
     * @return list<array<mixed>>
     */
    private function jsonLdDocuments(string $html): array
    {
        $found = preg_match_all(
            '#<script[^>]+type=["\']application/ld\+json["\'][^>]*>(.*?)</script>#si',
            $html,
            $matches,
        );

        if ($found === false || $found === 0) {
            return [];
        }

        $documents = [];

        foreach ($matches[1] as $json) {
            $decoded = $this->decode($json);

            if (is_array($decoded)) {
                $documents[] = $decoded;
            }
        }

        return $documents;
    }

    /**
     * Sites do emit invalid JSON: beszamel.se.pl leaves raw newlines inside string
     * values, which is a control-character error and used to lose the whole recipe.
     *
     * The retry only ever collapses control characters into spaces. Between the
     * tokens of well-formed JSON that changes nothing, and inside a string it turns
     * a line break into the space the text meant anyway — the alternative is
     * throwing away a recipe the site did publish.
     *
     * @return array<mixed>|null
     */
    private function decode(string $json): ?array
    {
        $decoded = json_decode(trim($json), true);

        if (is_array($decoded)) {
            return $decoded;
        }

        if (json_last_error() !== JSON_ERROR_CTRL_CHAR) {
            return null;
        }

        $decoded = json_decode(
            (string) preg_replace('/[\x00-\x1F]+/', ' ', trim($json)),
            true,
        );

        return is_array($decoded) ? $decoded : null;
    }

    /**
     * @param  list<array<mixed>>  $documents
     * @return array<string, mixed>|null
     */
    private function findRecipeNode(array $documents): ?array
    {
        foreach ($documents as $document) {
            $node = $this->firstRecipeIn($document);

            if ($node !== null) {
                return $node;
            }
        }

        return null;
    }

    /**
     * JSON-LD lets a node be written once and referred to by "@id" from anywhere
     * else in the document. Plugins routinely attach the recipe's picture that way,
     * so without an index of the defined nodes "image" reads as a bare reference
     * and every recipe imports without one.
     *
     * @param  list<array<mixed>>  $documents
     * @return array<string, array<mixed>>
     */
    private function nodesById(array $documents): array
    {
        $byId = [];

        foreach ($documents as $document) {
            $this->collectNodesById($document, $byId);
        }

        return $byId;
    }

    /**
     * @param  array<mixed>  $node
     * @param  array<string, array<mixed>>  $byId
     */
    private function collectNodesById(array $node, array &$byId): void
    {
        $id = $node['@id'] ?? null;

        // A node that is nothing but an "@id" is the reference, not the definition
        // it points at; indexing it would shadow the real one.
        if (is_string($id) && count($node) > 1 && ! isset($byId[$id])) {
            $byId[$id] = $node;
        }

        foreach ($node as $child) {
            if (is_array($child)) {
                $this->collectNodesById($child, $byId);
            }
        }
    }

    /**
     * A document may hold a bare Recipe, an @graph, or a plain list of nodes; all
     * three are searched rather than guessing which shape the plugin chose.
     *
     * @param  array<mixed>  $node
     * @return array<string, mixed>|null
     */
    private function firstRecipeIn(array $node): ?array
    {
        if ($this->isRecipe($node)) {
            /** @var array<string, mixed> $node */
            return $node;
        }

        foreach ($node as $child) {
            if (! is_array($child)) {
                continue;
            }

            $found = $this->firstRecipeIn($child);

            if ($found !== null) {
                return $found;
            }
        }

        return null;
    }

    /**
     * @param  array<mixed>  $node
     */
    private function isRecipe(array $node): bool
    {
        $type = $node['@type'] ?? null;

        return $type === 'Recipe' || (is_array($type) && in_array('Recipe', $type, true));
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return list<IngredientLineDraft>
     */
    private function ingredientLines(array $recipe): array
    {
        $lines = [];
        $section = null;

        foreach ($this->listOf($recipe['recipeIngredient'] ?? null) as $entry) {
            $text = $this->string(is_array($entry) ? ($entry['name'] ?? null) : $entry);

            if ($text === null) {
                continue;
            }

            $heading = $this->sectionHeading($text);

            if ($heading !== null) {
                $section = $heading;

                continue;
            }

            $lines[] = new IngredientLineDraft($text, $section);
        }

        return $lines;
    }

    /**
     * schema.org has no field for the parts of a multi-part recipe, so plugins
     * smuggle them into the ingredient list as an amount-less entry ending in a
     * colon: "0 g Sos czosnkowo-jogurtowy:".
     *
     * Reading them back as the section of the lines that follow is what stops them
     * being stored as nonsense products, and is what later tells the step linker
     * which "salt" a step means when a recipe lists it twice.
     */
    private function sectionHeading(string $text): ?string
    {
        if (! str_ends_with($text, ':')) {
            return null;
        }

        // A heading carries no amount, or the zero the plugin had to invent to fit
        // the field. Anything with a real amount is a mislabelled ingredient line,
        // and dropping it would lose a product the recipe actually uses.
        if (preg_match('/^\s*(\d+(?:[.,]\d+)?)/u', $text, $amount) === 1
            && (float) str_replace(',', '.', $amount[1]) > 0.0) {
            return null;
        }

        $heading = trim(rtrim(preg_replace('/^\s*\d+(?:[.,]\d+)?\s*\p{L}{0,4}\.?\s+/u', '', $text) ?? $text, ':'));

        return $heading !== '' ? $heading : null;
    }

    /**
     * HowToSection nests its own steps and names the part of the recipe they belong
     * to, which is where a multi-part recipe's sections come from.
     *
     * @return list<StepDraft>
     */
    private function steps(mixed $instructions, ?string $section): array
    {
        $steps = [];

        foreach ($this->listOf($instructions) as $entry) {
            if (! is_array($entry)) {
                $text = $this->string($entry);

                if ($text !== null) {
                    $steps[] = new StepDraft($text, $section);
                }

                continue;
            }

            if (($entry['@type'] ?? null) === 'HowToSection') {
                $steps = [
                    ...$steps,
                    ...$this->steps(
                        $entry['itemListElement'] ?? null,
                        $this->string($entry['name'] ?? null) ?? $section,
                    ),
                ];

                continue;
            }

            // "text" is the instruction; some plugins repeat it in "name", others put
            // a short headline there, so text wins when both are present.
            $text = $this->string($entry['text'] ?? null) ?? $this->string($entry['name'] ?? null);

            if ($text !== null) {
                $steps[] = new StepDraft($text, $section);
            }
        }

        return $steps;
    }

    /**
     * recipeYield is famously inconsistent: "4", "4 porcji", or both in one array.
     * The number and the wording are taken from whichever entry carries them.
     *
     * @return array{0: int|null, 1: string|null}
     */
    private function yield(mixed $value): array
    {
        $servings = null;
        $label = null;

        foreach ($this->listOf($value) as $entry) {
            $text = $this->string($entry);

            if ($text === null) {
                continue;
            }

            if ($servings === null && preg_match('/(\d+)/', $text, $match) === 1) {
                $number = (int) $match[1];
                $servings = $number > 0 && $number <= 50 ? $number : null;
            }

            // Prefer the human wording ("4 porcji") over the bare number.
            if ($label === null || preg_match('/\p{L}/u', $text) === 1) {
                $label = $text;
            }
        }

        return [$servings, $label];
    }

    /**
     * @param  array<string, array<mixed>>  $nodesById
     */
    private function imageUrl(mixed $value, array $nodesById): ?string
    {
        foreach ($this->listOf($value) as $entry) {
            if (! is_array($entry)) {
                $url = $this->string($entry);

                if ($url !== null) {
                    return $url;
                }

                continue;
            }

            $url = $this->string($entry['url'] ?? null);

            if ($url !== null) {
                return $url;
            }

            // An ImageObject the document defines elsewhere and points at here.
            $id = $entry['@id'] ?? null;

            if (is_string($id) && isset($nodesById[$id])) {
                $url = $this->string($nodesById[$id]['url'] ?? null);

                if ($url !== null) {
                    return $url;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $recipe
     * @return list<string>
     */
    private function tags(array $recipe): array
    {
        $tags = [];

        foreach (['recipeCategory', 'recipeCuisine', 'keywords'] as $field) {
            foreach ($this->listOf($recipe[$field] ?? null) as $entry) {
                $text = $this->string($entry);

                if ($text === null) {
                    continue;
                }

                // "keywords" is one comma-separated string as often as it is a list.
                foreach (explode(',', $text) as $part) {
                    $tag = trim($part);

                    if ($tag !== '') {
                        $tags[$tag] = true;
                    }
                }
            }
        }

        return array_keys($tags);
    }

    /**
     * @param  array<string, mixed>  $recipe
     */
    private function sumOfPrepAndCook(array $recipe): ?int
    {
        $prep = $this->minutes($recipe['prepTime'] ?? null);
        $cook = $this->minutes($recipe['cookTime'] ?? null);

        if ($prep === null && $cook === null) {
            return null;
        }

        return ($prep ?? 0) + ($cook ?? 0);
    }

    /**
     * ISO 8601 duration, e.g. "PT1H20M" -> 80.
     */
    private function minutes(mixed $value): ?int
    {
        $text = $this->string($value);

        if ($text === null || preg_match('/^P(?:(\d+)D)?T?(?:(\d+)H)?(?:(\d+)M)?/i', $text, $match) !== 1) {
            return null;
        }

        $minutes = ((int) ($match[1] ?? 0)) * 1440
            + ((int) ($match[2] ?? 0)) * 60
            + (int) ($match[3] ?? 0);

        return $minutes > 0 ? $minutes : null;
    }

    /**
     * Wraps a single value so callers can treat "one or many" uniformly.
     *
     * @return list<mixed>
     */
    private function listOf(mixed $value): array
    {
        if ($value === null) {
            return [];
        }

        return array_values(is_array($value) && ! $this->isAssociative($value) ? $value : [$value]);
    }

    /**
     * @param  array<mixed>  $value
     */
    private function isAssociative(array $value): bool
    {
        return array_keys($value) !== range(0, count($value) - 1);
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $text = html_entity_decode((string) $value, ENT_QUOTES | ENT_HTML5, 'UTF-8');
        // Plugins routinely leave markup inside a step's text.
        $text = strip_tags($text);
        $text = str_replace("\u{00A0}", ' ', $text);
        $text = trim(preg_replace('/\s+/u', ' ', $text) ?? $text);

        return $text !== '' ? $text : null;
    }
}
