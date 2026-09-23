<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use App\Entity\Order;
use App\Message\OrderDispatchedEvent;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\Component\Messenger\MessageBusInterface;

final readonly class OrderDispatchProcessor implements ProcessorInterface
{
    public function __construct(
        private MessageBusInterface $bus,
        private EntityManagerInterface $entityManager
    ) {
    }

    /**
     * @param Order $data
     */
    public function process(mixed $data, Operation $operation, array $uriVariables = [], array $context = []): Order
    {
        // Retrieve the managed entity from Doctrine
        if (isset($context['previous_data']) && $context['previous_data'] instanceof Order) {
            $order = $this->entityManager->find(Order::class, $context['previous_data']->getId());
            $order->setStatus('processing');
            $this->entityManager->flush();
        }

        // Dispatch typed event to RabbitMQ
        $this->bus->dispatch(new OrderDispatchedEvent(
            orderId: $data->getId(),
            clientEmail: $data->getClient()?->getUserIdentifier() ?? '',
            shippingAddress: $data->getShippingAddress(),
            items: $data->getItems()
        ));

        return $data;
    }
}
