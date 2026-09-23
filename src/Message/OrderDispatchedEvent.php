<?php

namespace App\Message;

final readonly class OrderDispatchedEvent
{
    public function __construct(
        public int $orderId,
        public string $clientEmail,
        public array $shippingAddress,
        public array $items
    ) {
    }
}
