<?php

namespace Arafa\Payments\Gateways;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class PaypalService extends BasePaymentService implements PaymentGatewayInterface
{
    protected $name;
    protected $mode;
    protected $client_id;
    protected $client_secret;

    public function __construct()
    {
        $this->name = 'paypal';
        $this->mode = config("payments.{$this->name}.mode");
        $this->client_id = config("payments.{$this->name}.{$this->mode}_client_id");
        $this->client_secret = config("payments.{$this->name}.{$this->mode}_client_secret");
        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");

        $this->header = $this->buildHeader();
    }


    public function buildHeader(): array
    {
       return  [
            "Accept" => "application/json",
            "Content-Type" => "application/json",
            "Authorization" => "Basic " . base64_encode("{$this->client_id}:{$this->client_secret}"),
        ];
    }

    protected function formatData(Request $request): array
    {
        return [
            "intent" => "CAPTURE",
            "purchase_units" => [
                ["amount" => $request->input("amount")]
            ],
            "payment_source" => [
                "paypal" => [
                    "experience_context" => [
                        "return_url" => config("payments.callback_url"),
                        "cancel_url" => config("payments.failed_url"),
                    ]
                ]
            ]
        ];
    }

    public function sendPayment(Request $request): array
    {
        $responseData = $this->buildRequest("POST", "/v2/checkout/orders", $this->formatData($request))
            ->getData(true);

        return ($responseData['success'] ?? false)
            ? ['success' => true, 'url' => $responseData['data']['links'][1]['href']]
            : ['success' => false, 'url' => $responseData];
    }

    public function callBack(Request $request): PaymentResponse
    {
        $token = $request->get('token');
        $response = $this->buildRequest('POST', "/v2/checkout/orders/$token/capture");
        $raw = $response->getData(true);

        $capture = $raw['data']['purchase_units'][0]['payments']['captures'][0] ?? null;
        $amount  = $capture['amount']['value'] ?? null;
        $currency = $capture['amount']['currency_code'] ?? null;

        return new PaymentResponse(
            success: ($raw['success'] ?? false) && (($raw['data']['status'] ?? '') === 'COMPLETED'),
            status: $raw['status']  == 201 ?? 'unknown',
            unique_id: $raw['data']['id'] ?? null,
            amount: $amount ? (float)$amount : null,
            currency: $currency ?? null,
            gateway_name: 'paypal',
            raw: $raw
        );
    }
}
