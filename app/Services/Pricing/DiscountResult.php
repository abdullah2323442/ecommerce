<?php

namespace App\Services\Pricing;

final readonly class DiscountResult
{
    public function __construct(
        public float $percent,
        public string $layer,
    ) {}
}
