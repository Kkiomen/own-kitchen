<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Pricing\Contracts\PriceSource;
use App\Pricing\ImportPrices;
use App\Pricing\PriceSourceRegistry;
use Illuminate\Console\Command;
use Throwable;

class ImportPricesCommand extends Command
{
    protected $signature = 'prices:import
        {source?* : Keys of the configured price sources; every one by default}';

    protected $description = 'Refresh what things normally cost, so a shopping list can be given a rough total';

    public function handle(PriceSourceRegistry $registry, ImportPrices $import): int
    {
        $sources = $this->chosen($registry);

        if ($sources === []) {
            return self::FAILURE;
        }

        $rows = [];
        $failed = false;

        foreach ($sources as $source) {
            $this->line("Reading {$source->label()}...");

            try {
                $summary = $import->run($source);
            } catch (Throwable $exception) {
                /*
                 * One source being unreachable must not stop the other. They are
                 * independent readings of the same question, and half an answer
                 * is worth having — the statistical figures do not expire in an
                 * afternoon, so an estimate built without this week's leaflets is
                 * still an estimate.
                 */
                $failed = true;
                $this->error("  {$source->name()}: {$exception->getMessage()}");

                continue;
            }

            $rows[] = [
                $source->name(),
                $summary->stored,
                $summary->matched,
                $this->percent($summary->matchRate()),
                $summary->priced,
                $this->percent($summary->pricedRate()),
                $summary->failed,
            ];
        }

        $this->newLine();
        $this->table(
            ['Źródło', 'Odczytów', 'Dopasowanych', '%', 'Z ceną jedn.', '%', 'Błędów'],
            $rows,
        );

        $this->comment('Teraz `php artisan prices:quality` — pokaże, czego nie rozpoznało.');

        return $failed && $rows === [] ? self::FAILURE : self::SUCCESS;
    }

    /**
     * @return list<PriceSource>
     */
    private function chosen(PriceSourceRegistry $registry): array
    {
        /** @var list<string> $keys */
        $keys = $this->argument('source');

        if ($keys === []) {
            // Every source is the ordinary case here: a refresh is a couple of
            // API calls and a pass over rows we already hold, not an hours-long
            // crawl that has to be aimed.
            return $registry->all();
        }

        try {
            return array_map($registry->get(...), $keys);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return [];
        }
    }

    private function percent(float $rate): string
    {
        return number_format($rate * 100, 1).'%';
    }
}
