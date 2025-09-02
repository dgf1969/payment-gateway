<?php

namespace Arafa\Payments\Gateways;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class MyFatoorahService extends BasePaymentService implements PaymentGatewayInterface
{

    protected $name;
    protected $mode;
    protected $api_key;
    protected array $header;
    protected $base_url;
    public function __construct()
    {

        $this->name = 'myfatoorah';

        $this->mode = config("payments.{$this->name}.mode");

        $this->api_key = config("payments.{$this->name}.{$this->mode}_api_key");
        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");

        $this->header =     $this->buildHeader();
    }


    public function buildHeader(): array
    {
        return [
            'accept' => 'application/json',
            "Content-Type" => "application/json",
            "Authorization" => "Bearer " . $this->api_key,
        ];
    }

    public function sendPayment(Request $request): array
    {

        $data = $request->all();
        $data['NotificationOption'] = "LNK";
        $data['Language'] = "en";
        $data['CallBackUrl'] = config("payments.callback_url");

        $response = $this->buildRequest('POST', '/v2/SendPayment', $data);

        if ($response->getData(true)['success']) {
            return ['success' => true, 'url' => $response->getData(true)['data']['Data']['InvoiceURL']];
        }
        return ['success' => false, 'url' => config("payments.failed_url")];
    }

    public function callBack(Request $request): PaymentResponse
    {
        $data = [
            'KeyType' => 'paymentId',
            'Key' => $request->input('paymentId'),
        ];

        $response = $this->buildRequest('POST', '/v2/getPaymentStatus', $data);
        $raw = $response->getData(true);

        return new PaymentResponse(
            success: ($raw['data']['IsSuccess'] ?? false) && (($raw['data']['Data']['InvoiceStatus'] ?? '') === 'Paid'),
            status: $raw['data']['Data']['InvoiceStatus'] ?? 'unknown',
            unique_id: $raw['data']['Data']['InvoiceId'] ?? null,
            amount: isset($raw['data']['Data']['InvoiceValue']) ? (float)$raw['data']['Data']['InvoiceValue'] : null,
            currency: $raw['data']['Data']['InvoiceTransactions'][0]['Currency'] ?? $raw['data']['Data']['Currency'] ?? null,
            gateway_name: 'myfatoorah',
            raw: $raw
        );
    }
}
