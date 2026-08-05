<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Offers\ImportPromotions;
use App\Offers\OfferSourceRegistry;
use App\Offers\PromotionImportSummary;
use Illuminate\Console\Command;

class ImportPromotionsCommand extends Command
{
    protected $signature = 'promotions:import
        {source=gazetki : Key of the configured offer source}
        {--shop=* : Limit to these shop slugs; every configured shop by default}
        {--pages= : Listing pages per shop}';

    protected $description = 'Refresh the shop leaflets so the shopping list can be planned around what is on offer';

    public function handle(OfferSourceRegistry $registry, ImportPromotions $import): int
    {
        $source = $registry->get((string) $this->argument('source'));

        /** @var list<string> $shops */
        $shops = $this->option('shop');
        $pages = (int) ($this->option('pages') ?? config('offers.default_pages'));

        $this->info("Reading {$source->name()}, up to {$pages} listing pages per shop...");
        $this->comment('Requests are throttled to the delay the site declares. A full refresh of every chain takes hours.');
        $this->newLine();

        $summary = $import->run(
            source: $source,
            shopSlugs: $shops === [] ? null : $shops,
            maxPages: $pages,
            onShopFinished: fn (string $slug, PromotionImportSummary $shop) => $this->report($slug, $shop),
        );

        $this->newLine();
        $this->table(
            ['Offers', 'Matched', 'Match rate', 'Pruned', 'Failed'],
            [[
                $summary->stored,
                $summary->matched,
                $summary->matchRate().'%',
                $summary->pruned,
                $summary->failed,
            ]],
        );

        $this->comment('Now run `php artisan promotions:quality` to see what it failed to understand.');

        return $summary->stored > 0 || $summary->failed === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function report(string $slug, PromotionImportSummary $summary): void
    {
        $this->line(sprintf(
            '  <fg=green>✓</> %s <fg=gray>(%d ofert, %d dopasowanych — %s%%)</>',
            $slug,
            $summary->stored,
            $summary->matched,
            $summary->matchRate(),
        ));
    }
}
