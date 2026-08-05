<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Enums\IngredientCategory;
use App\Enums\IngredientSource;
use App\Importing\Parsing\PolishTextNormalizer;
use App\Models\Ingredient;
use App\Models\IngredientAlias;
use App\Models\RecipeIngredient;
use App\Models\Unit;
use Illuminate\Database\Seeder;
use RuntimeException;

/**
 * Loads the curated product dictionary. Idempotent, so it can be re-run after the
 * dictionary grows without disturbing recipes that already point at these rows.
 */
class IngredientSeeder extends Seeder
{
    public function __construct(private readonly PolishTextNormalizer $normalizer) {}

    public function run(): void
    {
        /** @var list<array{name: string, category: string, unit?: string, staple?: bool, aliases?: list<string>}> $entries */
        $entries = require database_path('data/ingredients.php');

        $unitIds = Unit::query()->pluck('id', 'code');

        foreach ($entries as $entry) {
            $ingredient = Ingredient::query()->updateOrCreate(
                ['slug' => $this->normalizer->slug($entry['name'])],
                [
                    'name' => $entry['name'],
                    'category' => IngredientCategory::from($entry['category']),
                    // Promotes a product the importer previously invented: adding
                    // it to the dictionary is exactly the act of vouching for it.
                    'source' => IngredientSource::Dictionary,
                    'default_unit_id' => isset($entry['unit']) ? $unitIds[$entry['unit']] ?? null : null,
                    'is_staple' => $entry['staple'] ?? false,
                ],
            );

            // The canonical name is itself a valid spelling and must be findable.
            $this->claimCanonicalName($ingredient);
            $this->attachAliases($ingredient, $entry['aliases'] ?? []);
        }
    }

    /**
     * A product must own its own name. If another entry already claimed it as an
     * alias, the dictionary describes the same thing twice — exactly the
     * duplication this project exists to prevent — so fail loudly instead of
     * quietly pointing recipe lines at the wrong product.
     */
    private function claimCanonicalName(Ingredient $ingredient): void
    {
        $alias = $this->normalizer->normalize($ingredient->name);
        $owner = IngredientAlias::query()->firstOrCreate(
            ['alias' => $alias],
            ['ingredient_id' => $ingredient->id],
        );

        if ($owner->ingredient_id !== $ingredient->id) {
            $conflicting = Ingredient::query()->findOrFail($owner->ingredient_id)->name;

            throw new RuntimeException(
                "Ingredient '{$ingredient->name}' cannot claim its own name: alias '{$alias}' "
                ."already belongs to '{$conflicting}'. Remove the duplicate from database/data/ingredients.php."
            );
        }
    }

    /**
     * @param  list<string>  $aliases
     */
    private function attachAliases(Ingredient $ingredient, array $aliases): void
    {
        foreach ($aliases as $alias) {
            $normalised = $this->normalizer->normalize($alias);

            if ($normalised === '') {
                continue;
            }

            // First writer wins between two curated products: an alias claimed by
            // both is ambiguous, and silently repointing it would corrupt existing
            // recipes.
            $owner = IngredientAlias::query()->firstOrCreate(
                ['alias' => $normalised],
                ['ingredient_id' => $ingredient->id],
            );

            if ($owner->ingredient_id !== $ingredient->id) {
                $this->supersedeInventedProduct($owner, $ingredient);
            }
        }
    }

    /**
     * Curating a phrase is how the review queue is meant to be cleared, so a
     * dictionary entry outranks a product the importer invented from that same
     * phrase. Without this the invention keeps the alias for good and the entry
     * added to curate it never takes effect — the queue could never shrink.
     *
     * Two curated products claiming one alias is a different thing: that is a real
     * duplicate, and it is still left to the first writer rather than resolved here.
     */
    private function supersedeInventedProduct(IngredientAlias $alias, Ingredient $curated): void
    {
        $invented = Ingredient::query()->find($alias->ingredient_id);

        if ($invented === null || $invented->source !== IngredientSource::Import) {
            return;
        }

        // The lines that pointed at the invention meant the curated product all
        // along, and their review flag was only ever about it not being vouched for.
        RecipeIngredient::query()
            ->where('ingredient_id', $invented->id)
            ->update(['ingredient_id' => $curated->id, 'needs_review' => false]);

        $alias->update(['ingredient_id' => $curated->id]);

        // It existed only to hold the phrases now curated; holding none, it is a
        // duplicate of the product that took them over.
        if (! $invented->aliases()->exists()) {
            $invented->delete();
        }
    }
}
