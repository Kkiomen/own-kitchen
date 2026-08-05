<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Catalogue\CategoriseRecipes;
use Illuminate\Console\Command;

class CategoriseRecipesCommand extends Command
{
    protected $signature = 'recipes:categorise';

    protected $description = 'Recompute the quick-pick categories from database/data/categories.php';

    public function handle(CategoriseRecipes $categorise): int
    {
        $this->info('Przeliczam kategorie…');

        $counts = $categorise->run(function (int $done, int $total): void {
            $this->output->write("\r  {$done} / {$total}");
        });

        $this->newLine(2);
        $this->table(
            ['Kategoria', 'Przepisów'],
            array_map(
                static fn (string $slug, int $count): array => [$slug, $count],
                array_keys($counts),
                array_values($counts),
            ),
        );

        return self::SUCCESS;
    }
}
