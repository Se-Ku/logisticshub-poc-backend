<?php

namespace App\Dto;

use ApiPlatform\Metadata\ApiResource;
use ApiPlatform\Metadata\Post;
use App\State\ShippingRateProcessor;
use Symfony\Component\Validator\Constraints as Assert;

#[ApiResource(
    shortName: 'ShippingRate',
    operations: [
        new Post(
            uriTemplate: '/shipping/calculate-rates',
            security: "is_granted('ROLE_CLIENT') or is_granted('ROLE_ADMIN')",
            output: ShippingRateOutput::class,
            processor: ShippingRateProcessor::class
        )
    ]
)]
class ShippingRateInput
{
    #[Assert\NotNull]
    #[Assert\Collection([
        'length' => [new Assert\NotNull(), new Assert\GreaterThan(0)],
        'width' => [new Assert\NotNull(), new Assert\GreaterThan(0)],
        'height' => [new Assert\NotNull(), new Assert\GreaterThan(0)]
    ])]
    public array $dimensions = [];

    #[Assert\NotNull]
    #[Assert\GreaterThan(0)]
    public float $weight = 0.0;

    #[Assert\NotBlank]
    public string $destinationCountry = '';
}
