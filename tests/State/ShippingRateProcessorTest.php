<?php

namespace App\Tests\State;

use ApiPlatform\Metadata\Post;
use App\Dto\ShippingRateInput;
use App\Service\CircuitBreaker;
use App\Service\RequestIdProvider;
use App\State\ShippingRateProcessor;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\Stub;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\Component\HttpClient\MockHttpClient;
use Symfony\Component\HttpClient\Response\MockResponse;
use Symfony\Component\HttpFoundation\Response;

class ShippingRateProcessorTest extends TestCase
{
    private const SERVICE_NAME = 'shipping_node_microservice';
    private const DEFAULT_FIXTURE = [
        'dimensions' => ['length' => 10, 'width' => 20, 'height' => 30],
        'weight' => 5.0,
        'country' => 'US',
        'expectedRate' => 21.25,
    ];

    private CircuitBreaker $circuitBreaker;
    private RequestIdProvider&Stub $requestIdProvider;
    private LoggerInterface $logger;

    protected function setUp(): void
    {
        $this->circuitBreaker = $this->createMock(CircuitBreaker::class);
        $this->requestIdProvider = $this->createStub(RequestIdProvider::class);
        $this->logger = new NullLogger();
    }

    #[DataProvider('shippingInputProvider')]
    public function testProcessDelegatesToNodeMicroserviceSuccessfully(
        array $dimensions,
        float $weight,
        string $country,
        float $expectedRate
    ): void {
        $input = $this->createInput($dimensions, $weight, $country);

        $this->requestIdProvider
            ->method('getRequestId')
            ->willReturn('trace-12345');

        $mockResponse = new MockResponse(
            json_encode(['rate' => $expectedRate, 'calculationId' => 'calc_node_999']),
            ['http_code' => Response::HTTP_OK, 'response_headers' => ['content-type' => 'application/json']]
        );

        $httpClient = new MockHttpClient(function (string $method, string $url, array $options) use (
            $mockResponse,
            $input
        ) {
            $this->assertSame('POST', $method);
            $this->assertStringContainsString('/shipping/calculate-rates', $url);

            $headersJson = strtolower(json_encode($options['headers'] ?? []));
            $this->assertStringContainsString('x-request-id: trace-12345', $headersJson);

            $body = json_decode($options['body'], true);
            $this->assertSame([
                'dimensions' => $input->dimensions,
                'weight' => $input->weight,
                'destinationCountry' => $input->destinationCountry,
            ], $body);

            return $mockResponse;
        });

        $this->circuitBreaker->expects($this->once())
            ->method('isAvailable')
            ->with(self::SERVICE_NAME)
            ->willReturn(true);

        $this->circuitBreaker->expects($this->once())
            ->method('recordSuccess')
            ->with(self::SERVICE_NAME);

        $processor = new ShippingRateProcessor(
            $httpClient,
            $this->circuitBreaker,
            $this->requestIdProvider,
            $this->logger
        );

        $output = $processor->process($input, new Post());

        $this->assertSame($expectedRate, $output->rate);
        $this->assertSame('calc_node_999', $output->calculationId);
    }

    #[DataProvider('shippingInputProvider')]
    public function testProcessTriggersLegacyFallbackWhenCircuitIsOpen(
        array $dimensions,
        float $weight,
        string $country,
        float $expectedRate
    ): void {
        $input = $this->createInput($dimensions, $weight, $country);

        $this->circuitBreaker->expects($this->once())
            ->method('isAvailable')
            ->with(self::SERVICE_NAME)
            ->willReturn(false);

        $httpClient = new MockHttpClient();

        $processor = new ShippingRateProcessor(
            $httpClient,
            $this->circuitBreaker,
            $this->requestIdProvider,
            $this->logger
        );

        $output = $processor->process($input, new Post());

        $this->assertSame($expectedRate, $output->rate);
        $this->assertStringStartsWith('calc_fallback_', $output->calculationId);
    }

    #[DataProvider('microserviceErrorProvider')]
    public function testProcessRecordsFailureAndReturnsFallbackOnMicroserviceError(
        int $httpCode,
        array $responseBody
    ): void {
        // Use standard ground-truth fixture: 10x20x30 @ 5kg = 21.25 rate
        $fixture = self::DEFAULT_FIXTURE;
        $input = $this->createInput($fixture['dimensions'], $fixture['weight'], $fixture['country']);

        $this->circuitBreaker->expects($this->once())
            ->method('isAvailable')
            ->with(self::SERVICE_NAME)
            ->willReturn(true);

        $this->circuitBreaker->expects($this->once())
            ->method('recordFailure')
            ->with(self::SERVICE_NAME);

        $mockResponse = new MockResponse(
            json_encode($responseBody),
            ['http_code' => $httpCode]
        );

        $httpClient = new MockHttpClient($mockResponse);

        $processor = new ShippingRateProcessor(
            $httpClient,
            $this->circuitBreaker,
            $this->requestIdProvider,
            $this->logger
        );

        $output = $processor->process($input, new Post());

        $this->assertSame($fixture['expectedRate'], $output->rate);
        $this->assertStringStartsWith('calc_fallback_', $output->calculationId);
    }

    public static function shippingInputProvider(): array
    {
        return [
            'actual weight higher than volumetric weight' => [
                'dimensions' => ['length' => 10, 'width' => 20, 'height' => 30], // Volumetric: 1.2 kg
                'weight' => 5.0,                                                 // Billable: 5.0 kg
                'country' => 'US',
                'expectedRate' => 21.25,                                         // 12.50 + (5.0 * 1.75)
            ],
            'volumetric weight higher than actual weight' => [
                'dimensions' => ['length' => 50, 'width' => 40, 'height' => 30], // Volumetric: 12.0 kg
                'weight' => 2.0,                                                 // Billable: 12.0 kg
                'country' => 'DE',
                'expectedRate' => 33.50,                                         // 12.50 + (12.0 * 1.75)
            ],
        ];
    }

    public static function microserviceErrorProvider(): array
    {
        return [
            'structured 400 validation error' => [
                'httpCode' => Response::HTTP_BAD_REQUEST,
                'responseBody' => [
                    'errors' => [
                        ['path' => 'dimensions.length', 'message' => 'Must be greater than 0']
                    ]
                ],
            ],
            'unstructured 422 validation error' => [
                'httpCode' => Response::HTTP_UNPROCESSABLE_ENTITY,
                'responseBody' => [],
            ],
            '500 internal server error' => [
                'httpCode' => Response::HTTP_INTERNAL_SERVER_ERROR,
                'responseBody' => [],
            ],
        ];
    }

    private function createInput(array $dimensions, float $weight, string $country): ShippingRateInput
    {
        $input = new ShippingRateInput();
        $input->dimensions = $dimensions;
        $input->weight = $weight;
        $input->destinationCountry = $country;

        return $input;
    }
}
