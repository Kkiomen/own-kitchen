<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Nutrition\NutritionCoverage;
use App\Nutrition\RecipeEnergy;
use Illuminate\Console\Command;

class IngredientNutritionCommand extends Command
{
    protected $signature = 'ingredients:nutrition
        {--missing=30 : How many uncovered products to list}
        {--fail-under= : Exit with a failure when usable recipes drop below this percent}';

    protected $description = 'Report how many recipes can be given a calorie figure, and which products are still missing one';

    public function handle(NutritionCoverage $coverage): int
    {
        $report = $coverage->report((int) $this->option('missing'));

        if ($report['lines'] === 0) {
            $this->components->warn('Brak linii składników z ilością. Zaimportuj przepisy.');

            return self::SUCCESS;
        }

        $this->renderCoverage($report);
        $this->renderMissing($report['missing']);

        return $this->verdict($this->percent($report['usable'], $report['recipes']));
    }

    /**
     * @param  array{lines: int, readable: int, recipes: int, usable: int, withoutServings: int, missing: list<array{name: string, lines: int}>}  $report
     */
    private function renderCoverage(array $report): void
    {
        $this->components->twoColumnDetail('<fg=cyan>Kalorie</>', '');
        $this->components->twoColumnDetail(
            'linie policzalne',
            $report['readable'].'/'.$report['lines'].' ('.$this->percent($report['readable'], $report['lines']).'%)',
        );

        /*
         * The number that decides whether the feature works. Line coverage can sit
         * at 96% while most recipes still fail, because a recipe needs nearly all
         * of its lines and one common gap is spread across thousands of dishes.
         */
        $this->components->twoColumnDetail(
            'przepisy do planowania',
            $report['usable'].'/'.$report['recipes'].' ('.$this->percent($report['usable'], $report['recipes']).'%)',
        );
        $this->components->twoColumnDetail(
            '<fg=gray>  próg pokrycia przepisu</>',
            (int) round(RecipeEnergy::MINIMUM_COVERAGE * 100).'%',
        );
        $this->components->twoColumnDetail(
            '<fg=gray>  bez podanych porcji</>',
            (string) $report['withoutServings'],
        );

        $this->newLine();
    }

    /**
     * @param  list<array{name: string, lines: int}>  $missing
     */
    private function renderMissing(array $missing): void
    {
        if ($missing === []) {
            $this->components->info('Każdy produkt z ilością ma wartość kaloryczną.');

            return;
        }

        $this->components->twoColumnDetail('<fg=yellow>Bez kalorii</>', '');

        foreach ($missing as $gap) {
            $this->components->twoColumnDetail('  '.$gap['name'], $gap['lines'].' linii');
        }

        $this->newLine();
        $this->components->warn(
            'Dopisz je do database/data/ingredient-nutrition.php i uruchom '
            .'`php artisan db:seed --class=IngredientNutritionSeeder`. '
            .'Czego nie da się uczciwie policzyć — zostaw puste.'
        );
    }

    private function verdict(float $percent): int
    {
        $floor = $this->option('fail-under');

        if ($floor !== null && $percent < (float) $floor) {
            $this->components->error("Przepisów do planowania {$percent}%, próg to {$floor}%.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function percent(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round(100 * $part / $whole, 1);
    }
}
