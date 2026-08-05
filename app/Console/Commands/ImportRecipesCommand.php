<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Importing\ImportOutcome;
use App\Importing\ImportRecipes;
use App\Importing\RecipeSourceRegistry;
use Illuminate\Console\Command;

class ImportRecipesCommand extends Command
{
    protected $signature = 'recipes:import
        {source=kwestiasmaku : Key of the configured source adapter}
        {--limit=20 : How many recipes to import}
        {--replace : Re-import recipes already in the database}';

    protected $description = 'Import recipes from a configured source into the products, units and recipes tables';

    public function handle(RecipeSourceRegistry $registry, ImportRecipes $import): int
    {
        $source = $registry->get((string) $this->argument('source'));
        $limit = (int) $this->option('limit');

        $this->info("Importing up to {$limit} recipes from {$source->name()}...");
        $this->comment('Requests are throttled to the crawl delay declared in the site\'s robots.txt.');
        $this->newLine();

        $summary = $import->run(
            source: $source,
            limit: $limit,
            replaceExisting: (bool) $this->option('replace'),
            onProgress: fn (ImportOutcome $outcome) => $this->report($outcome),
        );

        $this->newLine();
        $this->table(
            ['Imported', 'Skipped', 'Failed'],
            [[$summary->imported, $summary->skipped, $summary->failed]],
        );

        return $summary->imported > 0 || $summary->failed === 0
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function report(ImportOutcome $outcome): void
    {
        match ($outcome->status) {
            'imported' => $this->line(sprintf(
                '  <fg=green>✓</> %s <fg=gray>(%d ingredients, %d steps)</>',
                $outcome->slug,
                $outcome->recipe?->ingredients()->count() ?? 0,
                $outcome->recipe?->steps()->count() ?? 0,
            )),
            'skipped' => $this->line("  <fg=gray>· {$outcome->slug} already imported</>"),
            default => $this->line("  <fg=red>✗</> {$outcome->slug}: {$outcome->message}"),
        };
    }
}
