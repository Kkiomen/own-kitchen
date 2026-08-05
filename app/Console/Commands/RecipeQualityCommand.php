<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Importing\Quality\ImportQualityReport;
use App\Importing\Quality\QualitySnapshot;
use Illuminate\Console\Command;

class RecipeQualityCommand extends Command
{
    protected $signature = 'recipes:quality
        {--unresolved=25 : How many unrecognised lines to list}
        {--fail-over= : Exit with a failure when more than this percent of lines need review}';

    protected $description = 'Report how well the importer understood the recipes currently in the database';

    public function handle(ImportQualityReport $report): int
    {
        $snapshot = $report->generate((int) $this->option('unresolved'));

        $this->renderVolume($snapshot);
        $this->renderParsing($snapshot);
        $this->renderSteps($snapshot);
        $this->renderReviewQueue($snapshot);

        return $this->verdict($snapshot);
    }

    private function renderVolume(QualitySnapshot $snapshot): void
    {
        $this->components->twoColumnDetail('<fg=cyan>Zawartość</>', '');
        $this->components->twoColumnDetail('przepisy', (string) $snapshot->recipes);
        $this->components->twoColumnDetail('linie składników', (string) $snapshot->ingredientLines);
        $this->components->twoColumnDetail('kroki', (string) $snapshot->steps);
        $this->components->twoColumnDetail('produkty', (string) $snapshot->products);
        $this->components->twoColumnDetail(
            'w tym niezweryfikowane',
            $this->highlight($snapshot->productsAwaitingCuration, (string) $snapshot->productsAwaitingCuration),
        );
        $this->components->twoColumnDetail(
            'ze zdjęciem',
            $snapshot->recipesWithImage.'/'.$snapshot->recipes,
        );
        $this->newLine();
    }

    private function renderParsing(QualitySnapshot $snapshot): void
    {
        $this->components->twoColumnDetail('<fg=cyan>Parsowanie składników</>', '');

        foreach ([
            'rozpoznany produkt' => $snapshot->linesWithProduct,
            'z ilością' => $snapshot->linesWithQuantity,
            'z jednostką' => $snapshot->linesWithUnit,
        ] as $label => $count) {
            $this->components->twoColumnDetail(
                $label,
                $count.'/'.$snapshot->ingredientLines.' ('.$snapshot->percentOfLines($count).'%)',
            );
        }

        $review = $snapshot->linesNeedingReview;
        $this->components->twoColumnDetail(
            'do sprawdzenia',
            $this->highlight($review, $review.' ('.$snapshot->reviewPercent().'%)'),
        );
        $this->newLine();
    }

    private function renderSteps(QualitySnapshot $snapshot): void
    {
        $this->components->twoColumnDetail('<fg=cyan>Kroki</>', '');

        foreach ([
            'z akcją' => $snapshot->stepsWithAction,
            'ze sprzętem' => $snapshot->stepsWithAppliance,
            'z temperaturą' => $snapshot->stepsWithTemperature,
            'z czasem' => $snapshot->stepsWithDuration,
        ] as $label => $count) {
            $this->components->twoColumnDetail(
                $label,
                $count.'/'.$snapshot->steps.' ('.$snapshot->percentOfSteps($count).'%)',
            );
        }

        $this->components->twoColumnDetail(
            'bez powiązanych składników',
            $snapshot->stepsWithoutIngredients.'/'.$snapshot->steps,
        );
        $this->components->twoColumnDetail('powiązania krok↔składnik', (string) $snapshot->stepIngredientLinks);
        $this->newLine();
    }

    private function renderReviewQueue(QualitySnapshot $snapshot): void
    {
        if ($snapshot->unresolvedLines === []) {
            $this->components->info('Wszystkie linie rozpoznane.');

            return;
        }

        $this->components->twoColumnDetail('<fg=yellow>Nierozpoznane linie</>', '');

        foreach ($snapshot->unresolvedLines as $line) {
            $this->line('  '.$line);
        }

        $this->newLine();
        $this->components->warn(
            'Dopisz te produkty do database/data/ingredients.php, przeseeduj i zaimportuj ponownie.'
        );
    }

    private function verdict(QualitySnapshot $snapshot): int
    {
        $threshold = $this->option('fail-over');

        if ($threshold === null) {
            return self::SUCCESS;
        }

        if ($snapshot->reviewPercent() > (float) $threshold) {
            $this->components->error(
                "Do sprawdzenia {$snapshot->reviewPercent()}% linii, próg to {$threshold}%."
            );

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function highlight(int $count, string $text): string
    {
        return $count === 0 ? "<fg=green>{$text}</>" : "<fg=yellow>{$text}</>";
    }
}
