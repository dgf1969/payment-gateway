<?php

namespace Arafa\Payments\Gateways;

use Illuminate\Http\Request;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class TapService extends BasePaymentService implements PaymentGatewayInterface
{
    protected $name;
    protected $mode;
    protected $api_key;
    protected $base_url;

    public function __construct()
    {
        $this->name = 'tap';
        $this->mode = config("payments.{$this->name}.mode");
        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->api_key = config("payments.{$this->name}.{$this->mode}_api_key");

        $this->header = $this->buildHeader();
    }

    public function buildHeader(): array
    {
        return [
            'accept' => 'application/json',
            "Content-Type" => "application/json",
            "Authorization" => "Bearer " . $this->api_key,
        ];
    }
    protected function preparePaymentData(Request $request): array
    {
        $data = $request->all();
        $data['source'] = ['id' => 'src_all'];
        $data['redirect'] = ['url' => config("payments.callback_url")];
        return $data;
    }

    public function sendPayment(Request $request): array
    {
        $data = $this->preparePaymentData($request);
        $response = $this->buildRequest('POST', '/v2/charges/', $data);

        return $this->handlePaymentResponse($response);
    }

    protected function handlePaymentResponse($response): array
    {
        $responseData = $response->getData(true);

        if ($responseData['success']) {
            return [
                'success' => true,
                'url' => $responseData['data']['transaction']['url']
            ];
        }

        return [
            'success' => false,
            'url' => $response
        ];
    }

    public function callBack(Request $request): PaymentResponse
    {
        $chargeId = $request->input('tap_id');
        $response = $this->buildRequest('GET', "/v2/charges/$chargeId");
        $responseData = $response->getData(true);

        $raw = $responseData;

        return new PaymentResponse(
            success: ($raw['success'] ?? false) && (($raw['data']['status'] ?? '') === 'CAPTURED'),
            status: $raw['data']['response']['message'] ?? ($raw['data']['status'] ?? 'unknown'),
            unique_id: $raw['data']['id'] ?? null,
            amount: isset($raw['data']['amount']) ? (float)$raw['data']['amount'] : null,
            currency: $raw['data']['currency'] ?? null,
            gateway_name: 'tap',
            raw: $raw
        );
    }

    protected function isPaymentCaptured($response): bool
    {
        $responseData = $response->getData(true);
        return $responseData['success'] && $responseData['data']['status'] === 'CAPTURED';
    }
}
