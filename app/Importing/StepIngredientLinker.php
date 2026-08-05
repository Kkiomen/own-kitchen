<?php

declare(strict_types=1);

namespace App\Importing;

use App\Importing\Parsing\PolishInflection;
use App\Importing\Parsing\PolishTextNormalizer;
use App\Models\IngredientAlias;
use App\Models\RecipeIngredient;
use App\Models\RecipeStep;
use Illuminate\Support\Collection;

/**
 * Works out which of a recipe's ingredient lines each step actually uses, by
 * looking for any known spelling of the ingredient in the step's text.
 *
 * This is what lets a guided-cooking screen say "ADD 150 g of chanterelles"
 * instead of just replaying the prose.
 */
final class StepIngredientLinker
{
    /**
     * Phrases signalling that a step consumes only part of a line, e.g.
     * "dodać 2/3 ilości sera".
     */
    private const string PARTIAL_PREFIX = '\b(\d+\/\d+|polowe|polowa|reszte|pozostal\w+)\s+(?:ilosci\s+|czesci\s+)?';

    /**
     * Below this length a stem is too generic to trust: matching on three letters
     * would let unrelated words collide once inflection strips their endings.
     * Four is the floor rather than five because short Polish staples — mąka,
     * woda, ryba — would otherwise never match at all.
     */
    private const int MIN_LEMMA_LENGTH = 4;

    public function __construct(
        private readonly PolishTextNormalizer $normalizer,
        private readonly PolishInflection $inflection,
    ) {}

    /**
     * @param  Collection<int, RecipeStep>  $steps
     * @param  Collection<int, RecipeIngredient>  $lines
     */
    public function link(Collection $steps, Collection $lines): void
    {
        $spellings = $this->spellingsByLine($lines);
        $linesById = $lines->keyBy('id');

        foreach ($steps as $step) {
            $haystack = $this->normalizer->normalize($step->raw_text);
            $stepLemmas = $this->lemmasOf($haystack);

            // A recipe can list the same product twice — butter for the soup and
            // butter for the garlic bread. The text says "butter" once, so both
            // lines match; keep only the one belonging to this step's section.
            $bestLinePerIngredient = [];

            foreach ($spellings as $lineId => $aliases) {
                $matched = $this->firstMatch($haystack, $stepLemmas, $aliases);
                $line = $linesById->get($lineId);

                if ($matched === null || $line === null || $line->ingredient_id === null) {
                    continue;
                }

                $rival = $bestLinePerIngredient[$line->ingredient_id] ?? null;

                if ($rival === null || $this->isBetterMatch($line, $rival['line'], $step)) {
                    $bestLinePerIngredient[$line->ingredient_id] = ['line' => $line, 'alias' => $matched];
                }
            }

            $attachments = [];
            $position = 0;

            foreach ($bestLinePerIngredient as $match) {
                $attachments[$match['line']->id] = [
                    'quantity' => null,
                    'portion_note' => $this->partialNote($haystack, $match['alias']),
                    'position' => $position++,
                ];
            }

            $step->ingredients()->sync($attachments);
        }
    }

    /**
     * A line from the step's own section beats one from a different section, which
     * in turn beats nothing; ties keep whichever was found first.
     */
    private function isBetterMatch(RecipeIngredient $candidate, RecipeIngredient $current, RecipeStep $step): bool
    {
        return $this->sectionScore($candidate, $step) > $this->sectionScore($current, $step);
    }

    private function sectionScore(RecipeIngredient $line, RecipeStep $step): int
    {
        if ($line->section !== null && $line->section === $step->section) {
            return 2;
        }

        return $line->section === null ? 1 : 0;
    }

    /**
     * Every spelling that identifies a line, longest first so "ser pleśniowy" is
     * preferred over the "ser" it contains.
     *
     * @param  Collection<int, RecipeIngredient>  $lines
     * @return array<int, list<string>>
     */
    private function spellingsByLine(Collection $lines): array
    {
        $aliasesByIngredient = IngredientAlias::query()
            ->whereIn('ingredient_id', $lines->pluck('ingredient_id')->filter()->all())
            ->get()
            ->groupBy('ingredient_id');

        $spellings = [];

        foreach ($lines as $line) {
            if ($line->ingredient_id === null) {
                continue;
            }

            $aliases = [];

            foreach ($aliasesByIngredient->get($line->ingredient_id, collect()) as $alias) {
                // Two-letter aliases match far too much prose to be safe here.
                if (mb_strlen($alias->alias) >= 3) {
                    $aliases[] = $alias->alias;
                }
            }

            usort($aliases, static fn (string $a, string $b): int => mb_strlen($b) <=> mb_strlen($a));

            if ($aliases !== []) {
                $spellings[$line->id] = $aliases;
            }
        }

        return $this->withUnambiguousHeadWords($spellings, $lines);
    }

    /**
     * Steps refer to products loosely: a line reads "makaron orzo" but the step
     * just says "wsypać suchy makaron". Adding the head word of the product name
     * catches those — but only when exactly one line in the recipe starts with it,
     * so a recipe using two kinds of pasta never guesses which one is meant.
     *
     * @param  array<int, list<string>>  $spellings
     * @param  Collection<int, RecipeIngredient>  $lines
     * @return array<int, list<string>>
     */
    private function withUnambiguousHeadWords(array $spellings, Collection $lines): array
    {
        $headWords = [];

        foreach ($lines as $line) {
            $head = $this->headWord($line);

            if ($head !== null) {
                $headWords[$head][] = $line->id;
            }
        }

        foreach ($headWords as $head => $lineIds) {
            if (count($lineIds) !== 1) {
                continue;
            }

            $lineId = $lineIds[0];

            if (isset($spellings[$lineId]) && ! in_array($head, $spellings[$lineId], true)) {
                $spellings[$lineId][] = $head;
            }
        }

        return $spellings;
    }

    private function headWord(RecipeIngredient $line): ?string
    {
        if ($line->ingredient === null) {
            return null;
        }

        $head = explode(' ', $this->normalizer->normalize($line->ingredient->name))[0];

        return mb_strlen($head) >= 4 ? $head : null;
    }

    /**
     * @param  array<string, true>  $stepLemmas
     * @param  list<string>  $aliases
     */
    private function firstMatch(string $haystack, array $stepLemmas, array $aliases): ?string
    {
        foreach ($aliases as $alias) {
            // Word boundaries stop "sol" matching inside "rosol".
            if (preg_match('/\b'.preg_quote($alias, '/').'\b/u', $haystack) === 1) {
                return $alias;
            }

            if ($this->matchesByLemma($alias, $stepLemmas)) {
                return $alias;
            }
        }

        return null;
    }

    /**
     * Steps decline the product too: the line reads "śmietanki 30%" while the step
     * says "wlać śmietankę". Reducing both sides to dictionary forms catches that,
     * where a literal comparison never could.
     *
     * Only single words are treated this way — a multi-word alias is specific
     * enough that a literal match is both sufficient and safer.
     *
     * @param  array<string, true>  $stepLemmas
     */
    private function matchesByLemma(string $alias, array $stepLemmas): bool
    {
        if (mb_strlen($alias) < self::MIN_LEMMA_LENGTH || str_contains($alias, ' ')) {
            return false;
        }

        foreach ($this->inflection->lemmaCandidates($alias) as $candidate) {
            if (mb_strlen($candidate) >= self::MIN_LEMMA_LENGTH && isset($stepLemmas[$candidate])) {
                return true;
            }
        }

        return false;
    }

    /**
     * Every dictionary form the words of a step could reduce to.
     *
     * @return array<string, true>
     */
    private function lemmasOf(string $haystack): array
    {
        $lemmas = [];

        foreach ($haystack === '' ? [] : explode(' ', $haystack) as $word) {
            foreach ($this->inflection->lemmaCandidates($word) as $candidate) {
                $lemmas[$candidate] = true;
            }
        }

        return $lemmas;
    }

    private function partialNote(string $haystack, string $alias): ?string
    {
        $pattern = '/'.self::PARTIAL_PREFIX.preg_quote($alias, '/').'/u';

        return preg_match($pattern, $haystack, $match) === 1 ? trim($match[1]) : null;
    }
}
