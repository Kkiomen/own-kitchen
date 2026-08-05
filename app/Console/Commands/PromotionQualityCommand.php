<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Offers\Quality\PromotionQualityReport;
use App\Offers\Quality\PromotionQualitySnapshot;
use Illuminate\Console\Command;

class PromotionQualityCommand extends Command
{
    protected $signature = 'promotions:quality
        {--unmatched=25 : How many unrecognised leaflet entries to list}
        {--matches=15 : How many top matched products to list}';

    protected $description = 'Report how well the leaflet import matched offers to products';

    public function handle(PromotionQualityReport $report): int
    {
        $snapshot = $report->generate(
            (int) $this->option('unmatched'),
            (int) $this->option('matches'),
        );

        if ($snapshot->promotions === 0) {
            $this->components->warn('Brak promocji w bazie. Uruchom `php artisan promotions:import`.');

            return self::SUCCESS;
        }

        $this->renderVolume($snapshot);
        $this->renderMatching($snapshot);
        $this->renderTopMatches($snapshot);
        $this->renderUnmatched($snapshot);

        return self::SUCCESS;
    }

    private function renderVolume(PromotionQualitySnapshot $snapshot): void
    {
        $this->components->twoColumnDetail('<fg=cyan>Zawartość</>', '');
        $this->components->twoColumnDetail('promocje', (string) $snapshot->promotions);
        $this->components->twoColumnDetail('sklepy', (string) $snapshot->shops);
        $this->components->twoColumnDetail('po terminie', (string) $snapshot->expired);

        foreach ($snapshot->perShop as $shop => $total) {
            $this->components->twoColumnDetail("  {$shop}", (string) $total);
        }

        $this->newLine();
    }

    private function renderMatching(PromotionQualitySnapshot $snapshot): void
    {
        $this->components->twoColumnDetail('<fg=cyan>Dopasowanie</>', '');

        foreach ([
            'przypisane do produktu' => $snapshot->matched,
            'z gramaturą' => $snapshot->withPackSize,
            'z ceną za kg/l/szt.' => $snapshot->withUnitPrice,
            'z ceną przed promocją' => $snapshot->withRegularPrice,
        ] as $label => $count) {
            $this->components->twoColumnDetail(
                $label,
                $count.'/'.$snapshot->promotions.' ('.$snapshot->percentOf($count).'%)',
            );
        }

        $this->newLine();
    }

    /**
     * Read this list looking for something too popular. A leaflet does not carry
     * forty offers on one product; a product at the top of this list is usually
     * swallowing entries that belong somewhere else.
     */
    private function renderTopMatches(PromotionQualitySnapshot $snapshot): void
    {
        if ($snapshot->topMatches === []) {
            return;
        }

        $this->components->twoColumnDetail('<fg=cyan>Najczęściej dopasowane</>', '');

        foreach ($snapshot->topMatches as $name => $total) {
            $this->components->twoColumnDetail("  {$name}", (string) $total);
        }

        $this->newLine();
    }

    private function renderUnmatched(PromotionQualitySnapshot $snapshot): void
    {
        if ($snapshot->unmatched === []) {
            $this->components->info('Wszystkie oferty przypisane do produktu.');

            return;
        }

        $this->components->twoColumnDetail('<fg=yellow>Bez produktu</>', '');

        foreach ($snapshot->unmatched as $title) {
            $this->line('  '.$title);
        }

        $this->newLine();
        $this->components->warn(
            'Jedzenie z tej listy dopisz do database/data/ingredients.php i przeseeduj; '
            .'nie-jedzenie do database/data/promotion-noise.php. Potem uruchom import ponownie.'
        );
    }
}
