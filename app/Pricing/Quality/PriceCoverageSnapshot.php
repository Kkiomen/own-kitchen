<?php

declare(strict_types=1);

namespace App\Pricing\Quality;

/**
 * One reading of how good the price data is. A value object so two runs can be
 * compared, which is the point of having a report rather than a query somebody
 * writes by hand each time.
 */
final readonly class PriceCoverageSnapshot
{
    /**
     * @param  array<string, int>  $perSource
     * @param  array{items: int, priced: int}  $listCoverage
     * @param  array<string, int>  $gaps  product name => how many list lines want it
     * @param  list<string>  $unmatched
     */
    public function __construct(
        public int $observations,
        public int $matched,
        public int $priced,
        public int $fresh,
        public array $perSource,
        public int $pricedProducts,
        public array $listCoverage,
        public array $gaps,
        public array $unmatched,
    ) {}

    public function percentOf(int $count): string
    {
        return $this->observations === 0
            ? '0.0'
            : number_format($count / $this->observations * 100, 1);
    }

    /**
     * The number that actually matters: the share of what is on a list that can
     * be given a price. Everything above it is diagnosis.
     */
    public function listPercent(): string
    {
        return $this->listCoverage['items'] === 0
            ? '0.0'
            : number_format($this->listCoverage['priced'] / $this->listCoverage['items'] * 100, 1);
    }
}
