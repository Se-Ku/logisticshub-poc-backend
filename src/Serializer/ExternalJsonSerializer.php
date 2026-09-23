<?php

namespace App\Serializer;

use App\Message\OrderFulfilledEvent;
use Symfony\Component\Messenger\Envelope;
use Symfony\Component\Messenger\Exception\MessageDecodingFailedException;
use Symfony\Component\Messenger\Transport\Serialization\SerializerInterface;
use Symfony\Component\Serializer\SerializerInterface as SymfonySerializer;

final readonly class ExternalJsonSerializer implements SerializerInterface
{
    public function __construct(
        private SymfonySerializer $serializer
    ) {
    }

    public function decode(array $encodedEnvelope): Envelope
    {
        $body = $encodedEnvelope['body'] ?? '';

        if (empty($body)) {
            throw new MessageDecodingFailedException('Empty message body');
        }

        try {
            // Deserializes incoming plain JSON straight into your PHP DTO
            $event = $this->serializer->deserialize($body, OrderFulfilledEvent::class, 'json');
        } catch (\Throwable $e) {
            throw new MessageDecodingFailedException('Failed to decode JSON to OrderFulfilledEvent: ' . $e->getMessage(), 0,
                $e);
        }

        return new Envelope($event);
    }

    public function encode(Envelope $envelope): array
    {
        return [
            'body' => $this->serializer->serialize($envelope->getMessage(), 'json'),
            'headers' => ['content_type' => 'application/json'],
        ];
    }
}
