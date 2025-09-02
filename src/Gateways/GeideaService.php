<?php

namespace Arafa\Payments\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class GeideaService extends BasePaymentService implements PaymentGatewayInterface
{
    protected $name;
    protected $mode;
    protected $base_url;
    protected mixed $password;
    protected mixed $api_key;
    protected array $header;

    public function __construct()
    {
        $this->initConfig();
        $this->initHeaders();
    }

    private function initConfig(): void
    {
        $this->name      = 'geidea';
        $this->mode      = config("payments.{$this->name}.mode");
        $this->base_url  = config("payments.{$this->name}.base_url");
        $this->api_key   = config("payments.{$this->name}.{$this->mode}_api_key");
        $this->password  = config("payments.{$this->name}.{$this->mode}_password");
    }

    private function initHeaders(): void
    {
        $this->header = [
            'accept'        => 'application/json',
            'Content-Type'  => 'application/json',
            'Authorization' => 'Basic ' . base64_encode("$this->api_key:$this->password"),
        ];
    }

    private function preparePaymentData(array $data): array
    {
        $data["eInvoiceDetails"] = [
            "extraChargesType"   => "Amount",
            "invoiceDiscountType" => "Amount"
        ];

        return $data;
    }

    public function sendPayment(Request $request): array
    {
        $data     = $this->preparePaymentData($request->all());
        $response = $this->buildRequest('POST', '/payment-intent/api/v1/direct/eInvoice', $data);

        return $this->handlePaymentResponse($response);
    }

    private function handlePaymentResponse($response): array
    {
        $parsed = $response->getData(true);

        if (!empty($parsed['success']) && isset($parsed['data']['paymentIntent']['link'])) {
            return [
                'success' => true,
                'url'     => $parsed['data']['paymentIntent']['link']
            ];
        }

        return [
            'success' => false,
            'url'     => $response
        ];
    }

    public function callBack(Request $request): PaymentResponse
    {
        try {
            $data = $request->all();
            $success = false;
            $status = 'failed';
            $unique_id = $data['order']['merchantReferenceId'] ?? null;
            $amount = null;
            $currency = null;

            if (
                isset($data['order']['status'], $data['order']['detailedStatus'])
            ) {
                // Check if payment is successful
                $success = $data['order']['status'] === 'Success' && $data['order']['detailedStatus'] === 'Paid';
                $status = $success ? 'success' : $data['order']['detailedStatus'] ?? 'failed';

                // Extract additional information if available
                if (isset($data['order']['amount'])) {
                    $amount = (float)$data['order']['amount'];
                }

                if (isset($data['order']['currency'])) {
                    $currency = $data['order']['currency'];
                }
            }

            return new PaymentResponse(
                success: $success,
                status: $status,
                unique_id: $unique_id,
                amount: $amount,
                currency: $currency,
                gateway_name: 'geidea',
                raw: $data
            );
        } catch (\Exception $e) {
            return new PaymentResponse(
                success: false,
                status: 'error',
                unique_id: $data['order']['merchantReferenceId'] ?? null,
                amount: null,
                currency: null,
                gateway_name: 'geidea',
                raw: ['error' => $e->getMessage(), 'request_data' => $data ?? []]
            );
        }
    }
}
