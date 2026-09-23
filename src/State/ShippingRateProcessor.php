<?php

namespace App\State;

use ApiPlatform\Metadata\Operation;
use ApiPlatform\State\ProcessorInterface;
use ApiPlatform\Validator\Exception\ValidationException;
use App\Dto\ShippingRateInput;
use App\Dto\ShippingRateOutput;
use App\Service\CircuitBreaker;
use App\Service\RequestIdProvider;
use Psr\Log\LoggerInterface;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Symfony\Component\Validator\ConstraintViolation;
use Symfony\Component\Validator\ConstraintViolationList;
use Symfony\Contracts\HttpClient\Exception\ExceptionInterface;
use Symfony\Contracts\HttpClient\HttpClientInterface;

class ShippingRateProcessor implements ProcessorInterface
{
    private const SERVICE_NAME = 'shipping_node_microservice';

    public function __construct(
        private readonly HttpClientInterface $shippingClient,
        private readonly CircuitBreaker $circuitBreaker,
        private readonly RequestIdProvider $requestIdProvider,
        private readonly LoggerInterface $logger
    ) {
    }

    public function process(
        mixed $data,
        Operation $operation,
        array $uriVariables = [],
        array $context = []
    ): ShippingRateOutput {
        /** @var ShippingRateInput $data */
        $requestId = $this->requestIdProvider->getRequestId();

        // 1. Check Circuit Breaker State
        if (!$this->circuitBreaker->isAvailable(self::SERVICE_NAME)) {
            $this->logger->warning('Circuit breaker OPEN. Routing to legacy fallback.', [
                'x_request_id' => $requestId
            ]);
            return $this->legacyFallback($data);
        }

        // 2. Execute Microservice Request
        try {
            $response = $this->shippingClient->request('POST', '/shipping/calculate-rates', [
                'headers' => [
                    'X-Request-ID' => $requestId,
                ],
                'json' => [
                    'dimensions' => $data->dimensions,
                    'weight' => $data->weight,
                    'destinationCountry' => $data->destinationCountry,
                ],
            ]);

            $statusCode = $response->getStatusCode();

            // Handle standard client payload validation failures (400)
            if ($statusCode === Response::HTTP_BAD_REQUEST || $statusCode === Response::HTTP_UNPROCESSABLE_ENTITY) {
                // Pass false to toArray() to prevent it from throwing a ClientException on 4xx statuses
                $errorPayload = $response->toArray(false);
                $violations = new ConstraintViolationList();

                // Assuming Node (e.g., Zod or Fastify Ajv) returns errors in an 'errors' array
                // e.g., { "errors": [ { "path": "dimensions.length", "message": "Must be greater than 0" } ] }
                $nodeErrors = $errorPayload['errors'] ?? [];

                foreach ($nodeErrors as $error) {
                    $violations->add(new ConstraintViolation(
                        $error['message'] ?? 'Validation failed',
                        null,
                        [],
                        $data,
                        $error['path'] ?? '',
                        null
                    ));
                }

                if (count($violations) > 0) {
                    // API Platform will automatically catch this and serialize it into a standard 422 JSON-LD/Hydra response
                    throw new ValidationException($violations);
                }

                throw new HttpException(Response::HTTP_UNPROCESSABLE_ENTITY,
                    'Microservice rejected the input payload.');
            }

            // Throw on 401 Auth issue, 500 Internal error, etc.
            if ($statusCode !== Response::HTTP_OK) {
                throw new \RuntimeException(sprintf('Microservice responded with status: %d', $statusCode));
            }

            $result = $response->toArray();

            // Success: Close/Reset circuit
            $this->circuitBreaker->recordSuccess(self::SERVICE_NAME);

            return new ShippingRateOutput(
                (float)$result['rate'],
                $result['calculationId']
            );

        } catch (ExceptionInterface|\RuntimeException $e) {
            // 3. Handle Network/Timeout/Server Errors
            $this->circuitBreaker->recordFailure(self::SERVICE_NAME);

            $this->logger->error('Shipping microservice failed.', [
                'error' => $e->getMessage(),
                'x_request_id' => $requestId
            ]);

            // Phase 1: Graceful degradation to local calculation
            // Phase 2: Change this to `throw new HttpException(Response::HTTP_SERVICE_UNAVAILABLE, 'Shipping service down');`
            return $this->legacyFallback($data);
        }
    }

    /**
     * Legacy computation scheduled for removal in Phase 2.
     */
    private function legacyFallback(ShippingRateInput $data): ShippingRateOutput
    {
        $volumetricWeight = ($data->dimensions['length'] * $data->dimensions['width'] * $data->dimensions['height']) / 5000;
        $billableWeight = max($data->weight, $volumetricWeight);

        $baseRate = 12.50;
        $rate = round($baseRate + ($billableWeight * 1.75), 2);

        // Appending prefix to easily trace fallback responses in logs/UI
        $calculationId = uniqid('calc_fallback_', true);

        return new ShippingRateOutput($rate, $calculationId);
    }
}
