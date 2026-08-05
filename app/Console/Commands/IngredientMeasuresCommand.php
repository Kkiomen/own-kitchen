<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Catalogue\MeasureCoverage;
use Illuminate\Console\Command;

class IngredientMeasuresCommand extends Command
{
    protected $signature = 'ingredients:measures
        {--missing=30 : How many uncovered product/unit pairs to list}
        {--fail-under= : Exit with a failure when coverage drops below this percent}';

    protected $description = 'Report how many recipe lines can be converted to grams, and what is still missing';

    public function handle(MeasureCoverage $coverage): int
    {
        $report = $coverage->report((int) $this->option('missing'));

        if ($report['lines'] === 0) {
            $this->components->warn('Brak linii składników z ilością. Zaimportuj przepisy.');

            return self::SUCCESS;
        }

        $this->renderCoverage($report);
        $this->renderMissing($report['missing']);

        return $this->verdict($this->percent($report['convertible'], $report['lines']));
    }

    /**
     * @param  array{lines: int, convertible: int, byDimension: array<string, array{lines: int, convertible: int}>, missing: list<array{name: string, unit: string, lines: int}>}  $report
     */
    private function renderCoverage(array $report): void
    {
        $this->components->twoColumnDetail('<fg=cyan>Przeliczalne na gramy</>', '');
        $this->components->twoColumnDetail(
            'razem',
            $report['convertible'].'/'.$report['lines'].' ('.$this->percent($report['convertible'], $report['lines']).'%)',
        );

        foreach ($report['byDimension'] as $dimension => $counts) {
            $this->components->twoColumnDetail(
                '  '.$this->label($dimension),
                $counts['convertible'].'/'.$counts['lines'].' ('.$this->percent($counts['convertible'], $counts['lines']).'%)',
            );
        }

        $this->newLine();
    }

    /**
     * @param  list<array{name: string, unit: string, lines: int}>  $missing
     */
    private function renderMissing(array $missing): void
    {
        if ($missing === []) {
            $this->components->info('Każda linia z ilością da się przeliczyć na gramy.');

            return;
        }

        $this->components->twoColumnDetail('<fg=yellow>Brakujące gramatury</>', '');

        foreach ($missing as $gap) {
            $this->components->twoColumnDetail(
                '  '.$gap['name'].' <fg=gray>('.$gap['unit'].')</>',
                $gap['lines'].' linii',
            );
        }

        $this->newLine();
        $this->components->warn(
            'Dopisz je do database/data/ingredient-measures.php i uruchom '
            .'`php artisan db:seed --class=IngredientMeasureSeeder`. '
            .'Jeśli czegoś nie da się uczciwie zważyć — zostaw puste.'
        );
    }

    private function verdict(float $percent): int
    {
        $threshold = $this->option('fail-under');

        if ($threshold === null) {
            return self::SUCCESS;
        }

        if ($percent < (float) $threshold) {
            $this->components->error("Pokrycie {$percent}%, próg to {$threshold}%.");

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function percent(int $part, int $whole): float
    {
        return $whole === 0 ? 0.0 : round($part / $whole * 100, 1);
    }

    private function label(string $dimension): string
    {
        return match ($dimension) {
            'mass' => 'masa',
            'volume' => 'objętość',
            'count' => 'sztuki',
            default => $dimension,
        };
    }
}
