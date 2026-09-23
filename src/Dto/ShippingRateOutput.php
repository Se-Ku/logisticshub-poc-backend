<?php
namespace App\Dto;

use Symfony\Component\Serializer\Attribute\SerializedName;

class ShippingRateOutput
{
    public float $rate;

    #[SerializedName('calculation-id')]
    public string $calculationId;

    public function __construct(float $rate, string $calculationId)
    {
        $this->rate = $rate;
        $this->calculationId = $calculationId;
    }
}
