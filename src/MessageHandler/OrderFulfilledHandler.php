<?php

namespace App\MessageHandler;

use App\Entity\Order;
use App\Message\OrderFulfilledEvent;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Messenger\Attribute\AsMessageHandler;

#[AsMessageHandler]
final readonly class OrderFulfilledHandler
{
    public function __construct(
        private EntityManagerInterface $entityManager,
        private LoggerInterface $logger
    ) {
    }

    public function __invoke(OrderFulfilledEvent $event): void
    {
        $order = $this->entityManager->getRepository(Order::class)->find($event->orderId);

        if (!$order) {
            $this->logger->error('Order not found during fulfillment process', [
                'orderId' => $event->orderId
            ]);
            return;
        }

        // Idempotency check: Skip if already processed
        if ($order->getStatus() === 'fulfilled') {
            $this->logger->info('Order already marked as fulfilled', ['orderId' => $event->orderId]);
            return;
        }

        // Update entity status
        $order->setStatus('fulfilled');

        $this->entityManager->flush();

        $this->logger->info('Order status updated to fulfilled', [
            'orderId' => $event->orderId,
            'trackingNumber' => $event->trackingNumber
        ]);
    }
}
