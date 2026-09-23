<?php
/**
 * This service extracts the X-Request-ID from the incoming request or generates a new one.
 */
namespace App\Service;

use Symfony\Component\HttpFoundation\RequestStack;
use Symfony\Component\Uid\Uuid;

class RequestIdProvider
{
    public function __construct(private RequestStack $requestStack) {}

    public function getRequestId(): string
    {
        $request = $this->requestStack->getCurrentRequest();

        if ($request && $request->headers->has('X-Request-ID')) {
            return $request->headers->get('X-Request-ID');
        }

        // Generate a new UUIDv4 if the client didn't provide one
        return (string) Uuid::v4();
    }
}
