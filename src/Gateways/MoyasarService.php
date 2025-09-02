<?php

namespace Arafa\Payments\Gateways;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class MoyasarService extends BasePaymentService implements PaymentGatewayInterface
{
    protected  $api_key;
    protected  $base_url;
    protected array  $header;
    protected  $name;
    protected  $mode;

    public function __construct()
    {
        $this->name = 'moyasar';
        $this->mode = config("payments.{$this->name}.mode");

        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->api_key = config("payments.{$this->name}.{$this->mode}_api_key");

        $this->header = $this->buildHeader();
    }


    public function buildHeader(): array
    {
        return  [
            'accept' => 'application/json',
            "Content-Type" => "application/json",
            "Authorization" => "Basic " . base64_encode("$this->api_key:''"),
        ];
    }

    public function sendPayment(Request $request)
    {

        $data = $request->all();
        $data['success_url'] = config("payments.callback_url");
        $response = $this->buildRequest('POST', '/v1/invoices', $data);

        if ($response->getData(true)['success']) {
            return ['success' => true, 'url' => $response->getData(true)['data']['url']];
        }
        return ['success' => false, 'url' => $response];
    }
   /**
    * Handle callback from Moyasar
    *
    * @param Request $request
    * @return PaymentResponse
    */
   public function callBack(Request $request): PaymentResponse
   {
       try {
           // Extract data from request
           $raw = $request->all();

           // Determine success status
           $success = ($raw['status'] ?? '') === 'paid';

           // Extract payment status
           $status = $raw['message'] ?? ($raw['status'] ?? 'unknown');

           // Extract transaction ID
           $transactionId = $raw['id'] ?? null;

           // Extract amount and currency
           // Moyasar typically includes amount in the callback
           $amount = isset($raw['amount']) ? (float)($raw['amount'] / 100) : null; // Convert from cents if needed
           $currency = $raw['currency'] ?? 'SAR'; // Default to SAR if not provided

           // Return standardized PaymentResponse
           return new PaymentResponse(
               success: $success,
               status: $status,
               unique_id: $transactionId,
               amount: $amount,
               currency: $currency,
               gateway_name: $this->name,
               raw: $raw
           );
       } catch (\Exception $e) {
           // Handle exceptions gracefully
           \Illuminate\Support\Facades\Log::error("Moyasar callback error: " . $e->getMessage());

           return new PaymentResponse(
               success: false,
               status: 'error',
               unique_id: null,
               amount: null,
               currency: null,
               gateway_name: $this->name,
               raw: ['error' => $e->getMessage(), 'request_data' => $request->all()]
           );
       }
   }

}
