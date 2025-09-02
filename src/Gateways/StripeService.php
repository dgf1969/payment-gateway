<?php

namespace Arafa\Payments\Gateways;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class StripeService extends BasePaymentService implements PaymentGatewayInterface
{
    protected $name;
    protected $mode;
    protected $api_key;


    public function __construct()
    {
        $this->name = 'stripe';
        $this->mode = config("payments.{$this->name}.mode");
        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->api_key = config("payments.{$this->name}.{$this->mode}_api_key");

        $this->header = $this->buildHeader();
    }


      public function buildHeader(): array
    {
        return   [
            'Accept' => 'application/json',
            'Content-Type' => 'application/x-www-form-urlencoded',
            'Authorization' => 'Bearer ' . $this->api_key,
        ];
    }

    protected function formatData(Request $request): array
    {
        return [
            "success_url" => config("payments.success_url"),
            "line_items" => [[
                "price_data" => [
                    "unit_amount" => $request->input('amount') * 100,
                    "currency" => $request->input("currency"),
                    "product_data" => [
                        "name" => "product name",
                        "description" => "description of product",
                    ],
                ],
                "quantity" => 1,
            ]],
            "mode" => "payment",
        ];
    }

    public function sendPayment(Request $request): array
    {
        $responseData = $this->buildRequest('POST', '/v1/checkout/sessions', $this->formatData($request), 'form_params')
            ->getData(true);

        return ($responseData['success'] ?? false)
            ? ['success' => true, 'url' => $responseData['data']['url'] ?? config("payments.failed_url")]
            : ['success' => false, 'url' => config("payments.failed_url")];
    }

    public function callBack(Request $request): PaymentResponse
    {
        $session_id = $request->get('session_id');
        $raw = $this->buildRequest('GET', "/v1/checkout/sessions/{$session_id}")->getData(true);

        return new PaymentResponse(
            success: ($raw['success'] ?? false) && (($raw['data']['payment_status'] ?? '') === 'paid'),
            status: $raw['data']['status'] ?? 'unknown',
            unique_id: $raw['data']['id'] ?? null,
            amount: isset($raw['data']['amount_total']) ? ((float)$raw['data']['amount_total'] / 100) : null,
            currency: $raw['data']['currency'] ?? null,
            gateway_name: 'stripe',
            raw: $raw
        );
    }
}
