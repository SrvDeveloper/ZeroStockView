<?php

namespace App\Services;

use Carbon\CarbonImmutable;

final readonly class AmazonInventoryReport
{
    /**
     * @param  list<array{asin: string, sku: string, quantity: string}>  $rows
     */
    public function __construct(
        public string $reportType,
        public string $reportId,
        public CarbonImmutable $retrievedAt,
        public array $rows,
    ) {}
}
