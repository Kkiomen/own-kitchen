<?php

declare(strict_types=1);

namespace App\Importing;

use App\Importing\Drafts\RecipeReference;
use App\Models\Recipe;
use Closure;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Re-import only the recipes a dictionary fix can change.
 *
 * Growing `ingredients.php` is the house rule, and a fix does nothing to lines
 * already resolved until their recipes are imported again. Walking every
 * listing with `--replace` re-imports ten thousand recipes to reach the forty
 * that say "bulgur", takes the better part of an hour, and still misses the ones
 * a listing no longer offers. The lines that a fix can touch are found by what
 * they say instead, and each recipe is fetched back by its own address — out of
 * the page cache, so it costs no requests.
 */
final class ReimportRecipes
{
    public function __construct(
        private readonly RecipeSourceRegistry $sources,
        private readonly StoreRecipeDraft $store,
    ) {}

    /**
     * @param  non-empty-list<string>  $phrases  text any ingredient line contains, matched case-insensitively
     * @param  Closure(ImportOutcome): void|null  $onProgress
     */
    public function matching(array $phrases, ?Closure $onProgress = null): ImportSummary
    {
        $report = $onProgress ?? static fn (ImportOutcome $outcome): null => null;
        $keys = $this->sourceKeysByName();

        $ids = DB::table('recipe_ingredients')
            ->where(static function ($query) use ($phrases): void {
                foreach ($phrases as $phrase) {
                    $query->orWhereRaw('LOWER(raw_text) LIKE ?', ['%'.mb_strtolower($phrase).'%']);
                }
            })
            ->distinct()
            ->pluck('recipe_id');

        $imported = 0;
        $failed = 0;

        foreach (Recipe::query()->whereIn('id', $ids)->get(['id', 'slug', 'source_url', 'source_name']) as $recipe) {
            $key = $keys[$recipe->source_name] ?? null;

            if ($key === null || $recipe->source_url === null) {
                $failed++;
                $report(ImportOutcome::failed($recipe->slug, "no configured source called '{$recipe->source_name}'"));

                continue;
            }

            try {
                $source = $this->sources->get($key);
                $fresh = $this->store->store(
                    $source->fetch(new RecipeReference($recipe->source_url, $recipe->slug)),
                    $source->name(),
                );
                $imported++;
                $report(ImportOutcome::imported($fresh));
            } catch (Throwable $exception) {
                // A page gone since it was imported keeps its old data; the run goes on.
                $failed++;
                $report(ImportOutcome::failed($recipe->slug, $exception->getMessage()));
            }
        }

        return new ImportSummary($imported, 0, $failed);
    }

    /**
     * The registry knows sources by key, a recipe by the name it was stored
     * under — `config('importing.sources')` holds both.
     *
     * @return array<string, string>
     */
    private function sourceKeysByName(): array
    {
        $keys = [];

        /** @var array<string, array{name: string}> $sources */
        $sources = config('importing.sources');

        foreach ($sources as $key => $config) {
            $keys[$config['name']] = $key;
        }

        return $keys;
    }
}
