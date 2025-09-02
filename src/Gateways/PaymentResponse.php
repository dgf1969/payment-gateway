<?php

namespace Arafa\Payments\Gateways;


class PaymentResponse
{
    public bool $success;
    public string $status;
    public ?string $unique_id;
    public ?float $amount;
    public ?string $currency;
    public string $gateway_name;
    public array $raw;

    public function __construct(
        bool $success,
        string $status,
        ?string $unique_id,
        ?float $amount,
        ?string $currency,
        string $gateway_name,
        array $raw
    ) {
        $this->success      = $success;
        $this->status       = $status;
        $this->unique_id    = $unique_id;
        $this->amount       = $amount;
        $this->currency     = $currency;
        $this->gateway_name = $gateway_name;
        $this->raw          = $raw;
    }
}
