<?php

namespace Arafa\Payments\Gateways;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class HyperpayService extends BasePaymentService implements PaymentGatewayInterface
{
    protected $name;
    protected $mode;
    protected $api_key;
    protected $entity_id;

    public function __construct()
    {
        $this->name = 'hyperpay';

        $this->mode = config("payments.{$this->name}.mode");

        $this->api_key = config("payments.{$this->name}.{$this->mode}_api_key");
        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->entity_id = config("payments.{$this->name}.entity_id");

        $this->header = $this->buildHeader();
    }


      public function buildHeader(): array
    {
        return  [
            'Authorization' => 'Bearer ' . $this->api_key,
            'Content-Type'  => 'application/x-www-form-urlencoded',
        ];
    }

    /**
     * Process a payment request through Hyperpay
     *
     * @param Request $request
     * @return array
     */
    public function sendPayment(Request $request): array
    {


        try {
            // Extract data from request
            $data = $request->all();

            // Prepare payment request payload for Hyperpay
            // Prepare payment request payload for Hyperpay

            // $payload = [
            //     'entityId' => $this->entity_id,
            //     'amount' => $data['amount'],
            //     'currency' => $data['currency'] ?? 'SAR',
            //     'merchantTransactionId' => $data['order_id'] ?? uniqid('hyperpay_'),
            //     'customer' => [
            //         'email' => $data['customer_email'] ?? '',
            //         'givenName' => $data['customer_name'] ?? '',
            //         'surname' => $data['customer_surname'] ?? '',
            //     ],
            //     'billing' => [
            //         'street1' => $data['billing_street'] ?? '',
            //         'city' => $data['billing_city'] ?? '',
            //         'state' => $data['billing_state'] ?? '',
            //         'country' => $data['billing_country'] ?? 'SA',
            //         'postcode' => $data['billing_postcode'] ?? '',
            //     ],
            //     'paymentType' => 'DB',
            //     'testMode' => 'INTERNAL', //! remove this key in production
            // ];   

            // this payload for fast test

            $payload = [
                'entityId'   => $this->entity_id,
                'amount'     => '10.00',
                'currency'   => 'SAR',
                'paymentType' => 'DB',
                'testMode'   => 'INTERNAL', //! remove this key in production
            ];
            // Send request to Hyperpay API to create a payment session
            // $response = $this->buildRequest('POST', 'v1/checkouts', $payload);

            $response = $this->buildRequest('POST', '/v1/checkouts', $payload, 'form_params');

            $responseData = $response->getData(true);

            if ($response->getStatusCode() === 200 && isset($responseData['data']['id'])) {
                return [
                    'success' => true,
                    'url' => config("payments.{$this->name}.checkout_url") . '?id=' . $responseData['data']['id'],
                    'session_id' => $responseData['data']['id'] ?? null,
                ];
            }

            return [
                'success' => false,
                'url' => config("payments.failed_url"),
                'message' => $responseData['message'] ?? 'Failed to create Hyperpay payment session',
            ];
        } catch (\Exception $e) {
            Log::error('Hyperpay payment error: ' . $e->getMessage());
            return [
                'success' => false,
                'url' => config("payments.failed_url"),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle callback from Hyperpay
     *
     * @param Request $request
     * @return bool
     */
    public function callBack(Request $request): PaymentResponse
    {
        try {
            $data = $request->all();
            $raw = [];

            if (isset($data['resourcePath'])) {
                $paymentStatus = $this->verifyPayment($data['resourcePath']);
                $raw = $paymentStatus;

                $isSuccess = isset($paymentStatus['result']['code']) &&
                    $this->isSuccessful($paymentStatus['result']['code']);

                return new PaymentResponse(
                    success: $isSuccess,
                    status: $paymentStatus['result']['description'] ?? 'unknown',
                    unique_id: $paymentStatus['id'] ?? null,
                    amount: isset($paymentStatus['amount']) ? (float)$paymentStatus['amount'] : null,
                    currency: $paymentStatus['currency'] ?? null,
                    gateway_name: 'hyperpay',
                    raw: $raw
                );
            }

            return new PaymentResponse( 
                success: false,
                status: 'invalid_request',
                unique_id: null,
                amount: null,
                currency: null,
                gateway_name: 'hyperpay',
                raw: $raw
            );
        } catch (\Exception $e) {
            Log::error('Hyperpay callback error: ' . $e->getMessage());

            return new PaymentResponse(
                success: false,
                status: 'error',
                unique_id: null,
                amount: null,
                currency: null,
                gateway_name: 'hyperpay',
                raw: ['exception' => $e->getMessage()]
            );
        }
    }


    /**
     * Verify payment status with Hyperpay
     *
     * @param string $resourcePath
     * @return array
     */
    public function verifyPayment(string $resourcePath): array
    {
        try {
            $urlWithParams = $resourcePath . '?' . http_build_query([
                'entityId' => $this->entity_id
            ]);

            $response = $this->buildRequest('GET', $urlWithParams);

            return $response->getData(true)['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('Hyperpay verification error: ' . $e->getMessage());
            return [
                'result' => [
                    'code' => 'ERROR',
                    'message' => $e->getMessage()
                ]
            ];
        }
    }

    /**
     * Check if the payment result code indicates success
     *
     * @param string $code
     * @return bool
     */
    private function isSuccessful(string $code): bool
    {
        // Hyperpay success code patterns
        return preg_match("/^(000\.000\.|000\.100\.1|000\.[36]|000\.400\.[1][12]0|000\.400\.0[^3]|000\.400\.100)/", $code) === 1;
    }

    /**
     * Capture an authorized payment
     *
     * @param string $paymentId
     * @param float $amount
     * @return array
     */
    public function capturePayment(string $paymentId, float $amount): array
    {
        try {
            $payload = [
                'entityId' => $this->entity_id,
                'amount' => $amount,
                'currency' => 'SAR',
                'paymentType' => 'CP'
            ];

            $response = $this->buildRequest('POST', "v1/payments/{$paymentId}", $payload);
            return $response->getData(true);
        } catch (\Exception $e) {
            Log::error('Hyperpay capture error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Refund a payment
     *
     * @param string $paymentId
     * @param float $amount
     * @return array
     */
    public function refundPayment(string $paymentId, float $amount): array
    {
        try {
            $payload = [
                'entityId' => $this->entity_id,
                'amount' => $amount,
                'currency' => 'SAR',
                'paymentType' => 'RF'
            ];

            $response = $this->buildRequest('POST', "v1/payments/{$paymentId}", $payload);
            return $response->getData(true);
        } catch (\Exception $e) {
            Log::error('Hyperpay refund error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
