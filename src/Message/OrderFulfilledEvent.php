<?php

namespace App\Message;

final readonly class OrderFulfilledEvent
{
    public function __construct(
        public int $orderId,
        public string $trackingNumber,
        public ?string $labelUrl = null,
        public ?string $fulfilledAt = null
    ) {
    }
}
