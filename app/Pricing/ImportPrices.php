<?php

declare(strict_types=1);

namespace App\Pricing;

use App\Models\PriceObservation;
use App\Pricing\Contracts\PriceSource;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Drives one refresh of what things cost.
 *
 * Source-agnostic by construction: it speaks only to the `PriceSource` port, so
 * a third source never changes this class.
 *
 * Unlike the leaflet importer it **prunes nothing**. A leaflet that stops
 * running has stopped being true; a price that held last March still held last
 * March, and it is exactly that history a median is taken over. Readings age out
 * of the estimate by being too old to quote — a decision made when the estimate
 * is built, not by deleting rows that could still answer a question later.
 */
final class ImportPrices
{
    public function __construct(private readonly StorePriceDraft $store) {}

    public function run(PriceSource $source): PriceImportSummary
    {
        $summary = new PriceImportSummary;

        foreach ($source->fetch() as $draft) {
            try {
                $observation = $this->store->store($draft, $source->name());
                $summary->stored++;

                if ($observation->ingredient_id !== null) {
                    $summary->matched++;
                }

                if ($this->canPrice($observation)) {
                    $summary->priced++;
                }
            } catch (Throwable $exception) {
                /*
                 * One malformed reading must not end a run — but it must not
                 * disappear either, or a source that changed its shape looks like
                 * a quiet month for prices.
                 */
                $summary->failed++;

                Log::warning('Could not store a price observation.', [
                    'source' => $source->name(),
                    'title' => $draft->title,
                    'reason' => $exception->getMessage(),
                ]);
            }
        }

        return $summary;
    }

    private function canPrice(PriceObservation $observation): bool
    {
        return $observation->ingredient_id !== null && $observation->unit_price_minor !== null;
    }
}
