<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Importing\ImportOutcome;
use App\Importing\ReimportRecipes;
use Illuminate\Console\Command;

class ReimportRecipesCommand extends Command
{
    protected $signature = 'recipes:reimport
        {--matching=* : Text an ingredient line contains; recipes with any such line are re-imported}';

    protected $description = 'Re-import, from the page cache, only the recipes whose lines a dictionary fix can change';

    public function handle(ReimportRecipes $reimport): int
    {
        /** @var list<string> $phrases */
        $phrases = array_values(array_filter((array) $this->option('matching'), static fn (mixed $one): bool => is_string($one) && $one !== ''));

        if ($phrases === []) {
            $this->error('Podaj co najmniej jedno --matching, np. --matching=bulgur.');

            return self::FAILURE;
        }

        $summary = $reimport->matching($phrases, function (ImportOutcome $outcome): void {
            if ($outcome->status === 'failed') {
                $this->line("  <fg=red>✗</> {$outcome->slug}: {$outcome->message}");
            }
        });

        $this->table(['Imported', 'Failed'], [[$summary->imported, $summary->failed]]);
        $this->comment('Then: php artisan recipes:categorise && php artisan recipes:meal-slots');

        return $summary->imported > 0 || $summary->failed === 0 ? self::SUCCESS : self::FAILURE;
    }
}
