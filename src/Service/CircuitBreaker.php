<?php

namespace App\Service;

use Psr\Cache\CacheItemPoolInterface;

/**
 * A simple PSR-6 Cache implementation of a circuit breaker.
 */
class CircuitBreaker
{
    private const THRESHOLD = 3;     // Max failures before opening the circuit
    private const COOLDOWN = 10;     // Seconds to wait before attempting again

    public function __construct(private CacheItemPoolInterface $cache)
    {
    }

    public function isAvailable(string $serviceName): bool
    {
        $item = $this->cache->getItem(sprintf('cb_failures_%s', $serviceName));

        if ($item->isHit() && $item->get() >= self::THRESHOLD) {
            return false; // Circuit is OPEN
        }

        return true; // Circuit is CLOSED or HALF-OPEN
    }

    public function recordFailure(string $serviceName): void
    {
        $item = $this->cache->getItem(sprintf('cb_failures_%s', $serviceName));
        $failures = $item->isHit() ? $item->get() + 1 : 1;

        $item->set($failures);
        $item->expiresAfter(self::COOLDOWN);
        $this->cache->save($item);
    }

    public function recordSuccess(string $serviceName): void
    {
        $this->cache->deleteItem(sprintf('cb_failures_%s', $serviceName));
    }
}
