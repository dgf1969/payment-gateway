<?php

namespace Arafa\Payments;

use Illuminate\Contracts\Container\Container;
use Arafa\Payments\Contracts\PaymentGatewayInterface;
use InvalidArgumentException;

class PaymentManager
{
    public function __construct(protected Container $app) {}

    public function gateway(string $name): PaymentGatewayInterface
    {
        $map = config('payments.gateways', []);
        $class = $map[$name] ?? null;

        if (!$class) {
            throw new InvalidArgumentException("Gateway [$name] not supported.");
        }

        $resolved = $this->app->make($class);

        if (!$resolved instanceof PaymentGatewayInterface) {
            throw new InvalidArgumentException("Gateway [$name] must implement PaymentGatewayInterface.");
        }

        return $resolved;
    }

    public function available(): array
    {
        return array_keys(config('payments.gateways', []));
    }
}
