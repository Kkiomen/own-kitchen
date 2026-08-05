<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Pricing\Quality\PriceCoverageReport;
use App\Pricing\Quality\PriceCoverageSnapshot;
use Illuminate\Console\Command;

class PriceQualityCommand extends Command
{
    protected $signature = 'prices:quality
        {--unmatched=25 : How many unrecognised readings to list}
        {--gaps=20 : How many unpriced products from the shopping lists to list}';

    protected $description = 'Report how much of a shopping list the price data can put a number on';

    public function handle(PriceCoverageReport $report): int
    {
        $snapshot = $report->generate(
            (int) $this->option('unmatched'),
            (int) $this->option('gaps'),
        );

        if ($snapshot->observations === 0) {
            $this->components->warn('Brak odczytów cen. Uruchom `php artisan prices:import`.');

            return self::SUCCESS;
        }

        $this->renderVolume($snapshot);
        $this->renderCoverage($snapshot);
        $this->renderGaps($snapshot);
        $this->renderUnmatched($snapshot);

        return self::SUCCESS;
    }

    private function renderVolume(PriceCoverageSnapshot $snapshot): void
    {
        $this->components->twoColumnDetail('<fg=cyan>Odczyty</>', '');
        $this->components->twoColumnDetail('razem', (string) $snapshot->observations);

        foreach ($snapshot->perSource as $source => $total) {
            $this->components->twoColumnDetail("  {$source}", (string) $total);
        }

        foreach ([
            'przypisane do produktu' => $snapshot->matched,
            'przypisane i aktualne' => $snapshot->fresh,
            'z ceną za kg/l/szt.' => $snapshot->priced,
        ] as $label => $count) {
            $this->components->twoColumnDetail(
                $label,
                $count.'/'.$snapshot->observations.' ('.$snapshot->percentOf($count).'%)',
            );
        }

        $this->newLine();
    }

    /**
     * The line to read first. Everything else is diagnosis of this one.
     */
    private function renderCoverage(PriceCoverageSnapshot $snapshot): void
    {
        $this->components->twoColumnDetail('<fg=cyan>Pokrycie</>', '');
        $this->components->twoColumnDetail('produkty z ceną', (string) $snapshot->pricedProducts);
        $this->components->twoColumnDetail(
            'pozycje na listach zakupów',
            $snapshot->listCoverage['priced'].'/'.$snapshot->listCoverage['items']
            .' ('.$snapshot->listPercent().'%)',
        );

        $this->newLine();
    }

    /**
     * Sorted by how often a list asks for it, not alphabetically: the top of this
     * is where one dictionary entry buys the most coverage, and the tail is
     * genuinely rare shopping.
     */
    private function renderGaps(PriceCoverageSnapshot $snapshot): void
    {
        if ($snapshot->gaps === []) {
            return;
        }

        $this->components->twoColumnDetail('<fg=yellow>Bez ceny, a na liście</>', '');

        foreach ($snapshot->gaps as $name => $total) {
            $this->components->twoColumnDetail("  {$name}", (string) $total);
        }

        $this->newLine();
    }

    private function renderUnmatched(PriceCoverageSnapshot $snapshot): void
    {
        if ($snapshot->unmatched === []) {
            $this->components->info('Wszystkie odczyty przypisane do produktu.');

            return;
        }

        $this->components->twoColumnDetail('<fg=yellow>Bez produktu</>', '');

        foreach ($snapshot->unmatched as $title) {
            $this->line('  '.$title);
        }

        $this->newLine();
        $this->components->warn(
            'Jedzenie z tej listy dopisz do database/data/ingredients.php, przeseeduj '
            .'i uruchom import ponownie — odczyty są w bazie, nic nie trzeba pobierać jeszcze raz.'
        );
    }
}
