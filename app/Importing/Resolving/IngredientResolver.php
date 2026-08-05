<?php

declare(strict_types=1);

namespace App\Importing\Resolving;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSource;
use App\Importing\Parsing\PolishInflection;
use App\Importing\Parsing\PolishTextNormalizer;
use App\Models\Ingredient;
use App\Models\IngredientAlias;

/**
 * Maps a written ingredient phrase onto exactly one canonical ingredient.
 *
 * This is where the project's "no duplicated products" rule is actually enforced.
 * Lookup order is deliberate: the alias table is the authority, inflection rules
 * only widen the net, and inventing a new ingredient is the last resort.
 *
 * Aliases are only ever written for products this class had to invent. Matches
 * reached by guesswork are used but never recorded, so a wrong guess costs one
 * line rather than permanently teaching the database something false.
 */
final class IngredientResolver
{
    /**
     * Alias => ingredient id, loaded once per run. Import touches this on every
     * single line, so a per-line query would dominate the runtime.
     *
     * @var array<string, int>|null
     */
    private ?array $aliasCache = null;

    public function __construct(
        private readonly PolishTextNormalizer $normalizer,
        private readonly PolishInflection $inflection,
    ) {}

    /**
     * @param  string|null  $phraseIncludingUnit  Same phrase with the measure word kept, tried
     *                                            only if the plain one finds nothing.
     */
    public function resolve(string $phrase, ?string $phraseIncludingUnit = null): ?ResolvedIngredient
    {
        $key = $this->normalizer->normalize($phrase);

        if ($key === '') {
            return null;
        }

        $ingredient = $this->find($key);

        if ($ingredient !== null) {
            return new ResolvedIngredient($ingredient, isConfident: true);
        }

        // "1 listek laurowy" parses as one leaf of "laurowy", which is nothing.
        // Putting the measure word back turns it into a product we know.
        if ($phraseIncludingUnit !== null) {
            $fallbackKey = $this->normalizer->normalize($phraseIncludingUnit);
            $ingredient = $fallbackKey === $key ? null : $this->find($fallbackKey);

            if ($ingredient !== null) {
                return new ResolvedIngredient($ingredient, isConfident: true, unitBelongsToName: true);
            }
        }

        return new ResolvedIngredient($this->create($phrase, $key), isConfident: false);
    }

    /**
     * Look a phrase up without ever inventing a product for it.
     *
     * This is what shop leaflets must go through, and the distinction is not a
     * nicety. A chain's leaflet is mostly not food: lawnmowers, exercise books,
     * loyalty coupons. Running four thousand of those through `resolve()` would
     * create four thousand products, each permanently owning an alias, and the
     * next recipe import would resolve real ingredient lines onto them. One junk
     * row absorbing dozens of real ingredients is the worst failure this codebase
     * has had — see the "Sos:" incident. Not matching is always the better answer.
     */
    public function match(string $phrase): ?Ingredient
    {
        $key = $this->normalizer->normalize($phrase);

        return $key === '' ? null : $this->find($key);
    }

    /**
     * Deliberately does NOT record the phrase as a new alias on a hit. A match
     * found via a sub-phrase or an inflection guess is a best guess, and writing
     * it back would promote that guess to authority: every later import would
     * trust it without ever re-checking.
     */
    private function find(string $key): ?Ingredient
    {
        foreach ($this->candidates($key) as $candidate) {
            $id = $this->aliases()[$candidate] ?? null;

            if ($id === null) {
                continue;
            }

            $ingredient = Ingredient::query()->find($id);

            if ($ingredient !== null) {
                return $ingredient;
            }

            // The cache outlived the row: a later recipe in the same run rolled
            // back and took the product with it. Forget it and keep looking rather
            // than failing this recipe over a stale entry.
            unset($this->aliasCache[$candidate]);
        }

        return null;
    }

    /**
     * Spellings to try, most specific first. Trying the whole phrase before its
     * parts keeps "ser pleśniowy" from collapsing into plain "ser".
     *
     * @return list<string>
     */
    private function candidates(string $key): array
    {
        $words = explode(' ', $key);
        $candidates = $this->spellingsOf($words);

        // Contiguous word groups, longest first, left-anchored before right-anchored.
        for ($length = count($words) - 1; $length >= 1; $length--) {
            for ($start = 0; $start + $length <= count($words); $start++) {
                $group = array_slice($words, $start, $length);

                foreach ($this->spellingsOf($group) as $spelling) {
                    $candidates[] = $spelling;
                }

                if ($length === 1) {
                    foreach ($this->inflection->lemmaCandidates($group[0]) as $lemma) {
                        $candidates[] = $lemma;
                    }
                }
            }
        }

        return array_values(array_unique(array_filter($candidates)));
    }

    /**
     * Ways the same phrase might be written down.
     *
     * Polish usually declines only the head noun and leaves the qualifier alone:
     * "marynaty teriyaki" is stored as "marynata teriyaki", not "marynata
     * teriyaka". Reducing every word at once produced exactly that wrong second
     * form, so each word is also tried on its own.
     *
     * @param  list<string>  $words
     * @return list<string>
     */
    private function spellingsOf(array $words): array
    {
        $spellings = [implode(' ', $words), $this->lemmatisePhrase($words)];

        foreach ($words as $index => $word) {
            foreach ($this->inflection->lemmaCandidates($word) as $lemma) {
                $variant = $words;
                $variant[$index] = $lemma;
                $spellings[] = implode(' ', $variant);
            }
        }

        return $spellings;
    }

    /**
     * @param  list<string>  $words
     */
    private function lemmatisePhrase(array $words): string
    {
        $lemmas = array_map(
            fn (string $word): string => $this->inflection->lemmaCandidates($word)[1] ?? $word,
            $words,
        );

        return implode(' ', $lemmas);
    }

    private function create(string $phrase, string $key): Ingredient
    {
        // `ingredient_aliases.alias` is unique in the database, and that constraint
        // — not this class's in-memory cache — is the authority on who owns a
        // spelling. When the two disagree the cache is wrong, and a disagreement
        // used to abort the whole recipe on the insert below. One extra query is a
        // fair price for never losing a recipe to a stale cache entry.
        $owner = IngredientAlias::query()->where('alias', $key)->first();

        if ($owner !== null) {
            $existing = Ingredient::query()->find($owner->ingredient_id);

            if ($existing !== null) {
                $this->aliasCache[$key] = $existing->id;

                return $existing;
            }
        }

        $ingredient = Ingredient::query()->create([
            'slug' => $this->uniqueSlug($key),
            // Keep the human spelling, diacritics and all; only the key is stripped.
            'name' => trim($phrase),
            'category' => IngredientCategory::Other,
            // Marks this as a guess for the review queue. Without it, the alias
            // written below would make the next import treat it as recognised.
            'source' => IngredientSource::Import,
            'is_staple' => false,
        ]);

        // The alias is required here, not optional: without it the very next recipe
        // using the same wording would create a second copy of this product.
        $this->registerAlias($ingredient, $key);

        return $ingredient;
    }

    private function uniqueSlug(string $key): string
    {
        $base = str_replace(' ', '-', $key);
        $slug = $base;
        $suffix = 2;

        while (Ingredient::query()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$suffix++;
        }

        return $slug;
    }

    private function registerAlias(Ingredient $ingredient, string $alias): void
    {
        if (isset($this->aliases()[$alias])) {
            return;
        }

        IngredientAlias::query()->create([
            'ingredient_id' => $ingredient->id,
            'alias' => $alias,
        ]);

        $this->aliasCache[$alias] = $ingredient->id;
    }

    /**
     * @return array<string, int>
     */
    private function aliases(): array
    {
        return $this->aliasCache ??= IngredientAlias::query()
            ->pluck('ingredient_id', 'alias')
            ->all();
    }
}
