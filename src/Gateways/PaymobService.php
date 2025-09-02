<?php

namespace Arafa\Payments\Gateways;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;


class PaymobService extends BasePaymentService implements PaymentGatewayInterface
{
    protected string $name;
    protected string $mode;
    protected string $api_key;
    protected array $integrations_id;

    public function __construct()
    {
        $this->name = 'paymob';
        $this->mode = config("payments.{$this->name}.mode");
        $this->api_key = config("payments.{$this->name}.{$this->mode}_api_key");
        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->integrations_id = config("payments.{$this->name}.integrations_id");
        $this->header = $this->buildHeader();
    }

    public function buildHeader(): array
    {
        return  [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    protected function generateToken(): string
    {
        return $this->buildRequest('POST', '/api/auth/tokens', ['api_key' => $this->api_key])
            ->getData(true)['data']['token'];
    }

    protected function buildPaymentData(array $requestData): array
    {
        $requestData['api_source'] = 'INVOICE';
        $requestData['integrations'] = $this->integrations_id;
        return $requestData;
    }


    public function sendPayment(Request $request): array
    {
        $this->header['Authorization'] = 'Bearer ' . $this->generateToken();

        $responseData = $this->buildRequest(
            'POST',
            '/api/ecommerce/orders',
            $this->buildPaymentData($request->all())
        )->getData(true);

        return ($responseData['success'] ?? false)
            ? ['success' => true, 'url' => $responseData['data']['url'] ??config("payments.failed_url")]
            : ['success' => false, 'url' =>config("payments.failed_url")];
    }

    public function callBack(Request $request): PaymentResponse
    {
        $raw = $request->all();
        return new PaymentResponse(
            success: ($raw['success'] ?? 'false') === 'true',
            status: $raw['data_message'] ?? ($raw['txn_response_code'] ?? 'unknown'),
            unique_id: $raw['id'] ?? null,
            amount: isset($raw['amount_cents']) ? ((float)$raw['amount_cents'] / 100) : null,
            currency: $raw['currency'] ?? null,
            gateway_name: 'paymob',
            raw: $raw
        );
    }
}
