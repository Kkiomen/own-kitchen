<?php

declare(strict_types=1);

namespace App\Importing;

use App\Importing\Contracts\RecipeSource;
use App\Models\Recipe;
use Closure;
use Throwable;

/**
 * Drives one import run. Source-agnostic by construction: it only speaks to the
 * RecipeSource port, so a new site never changes this class.
 */
final class ImportRecipes
{
    public function __construct(private readonly StoreRecipeDraft $store) {}

    /**
     * @param  Closure(ImportOutcome): void|null  $onProgress  Reports each recipe as it lands, for CLI output.
     */
    public function run(RecipeSource $source, int $limit, bool $replaceExisting = false, ?Closure $onProgress = null): ImportSummary
    {
        $report = $onProgress ?? static fn (ImportOutcome $outcome): null => null;

        $imported = 0;
        $skipped = 0;
        $failed = 0;

        foreach ($source->discover($limit) as $reference) {
            if (! $replaceExisting && Recipe::query()->where('source_url', $reference->url)->exists()) {
                $skipped++;
                $report(ImportOutcome::skipped($reference->slug));

                continue;
            }

            try {
                $recipe = $this->store->store($source->fetch($reference), $source->name());
                $imported++;
                $report(ImportOutcome::imported($recipe));
            } catch (Throwable $exception) {
                // One unreachable page or changed layout must not end a run that is
                // otherwise working.
                $failed++;
                $report(ImportOutcome::failed($reference->slug, $exception->getMessage()));
            }
        }

        return new ImportSummary($imported, $skipped, $failed);
    }
}
