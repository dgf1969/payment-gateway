<?php

namespace Arafa\Payments\Gateways;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class TelrService extends BasePaymentService implements PaymentGatewayInterface
{
    protected $name;
    protected $mode;
    protected $store_id;
    protected $auth_key;
    protected $currency;
    protected $merchant_id;

    public function __construct()
    {
        $this->name = 'telr';

        $this->mode = config("payments.{$this->name}.mode");

        $this->store_id = config("payments.{$this->name}.{$this->mode}_store_id");
        $this->auth_key = config("payments.{$this->name}.{$this->mode}_auth_key");
        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->currency = config("payments.{$this->name}.currency");
        $this->merchant_id = config("payments.{$this->name}.merchant_id");

        $this->header = $this->buildHeader();
    }


      public function buildHeader(): array
    {
        return   [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];
    }

    /**
     * Process a payment request through Telr
     *
     * @param Request $request
     * @return array
     */
    public function sendPayment(Request $request): array
    {
        try {
            // Extract data from request
            $data = $request->all();

            // Generate a unique transaction reference
            $transRef = $data['order_id'] ?? uniqid('telr_');

            // Prepare payment request payload for Telr
            $payload = [
                'method' => 'create',
                'store' => $this->store_id,
                'authkey' => $this->auth_key,
                'order' => [
                    'ref' => $transRef,
                    'amount' => $data['amount'],
                    'currency' => $data['currency'] ?? $this->currency,
                    'description' => $data['description'] ?? 'Order payment'
                ],
                'customer' => [
                    'name' => $data['customer_name'] ?? '',
                    'email' => $data['customer_email'] ?? '',
                    'phone' => $data['customer_phone'] ?? ''
                ],
                'return' => [
                    'url' => config("payments.callback_url"),
                    'params' => [
                        'ref' => $transRef
                    ]
                ],
                'cancel' => [
                    'url' => config("payments.failed_url"),
                ]
            ];

            // Add billing address if provided
            if (isset($data['billing_street'])) {
                $payload['billing'] = [
                    'address' => [
                        'line1' => $data['billing_street'] ?? '',
                        'city' => $data['billing_city'] ?? '',
                        'region' => $data['billing_state'] ?? '',
                        'country' => $data['billing_country'] ?? 'SA',
                        'zip' => $data['billing_postcode'] ?? ''
                    ]
                ];
            }

            // Send request to Telr API to create a payment session
            $response = $this->buildRequest('POST', '', $payload);
            $responseData = $response->getData(true);

            if ($response->getStatusCode() === 200 && isset($responseData['data']['url'])) {
                return [
                    'success' => true,
                    'url' => $responseData['data']['url'],
                    'session_id' => $responseData['data']['order']['ref'] ?? $transRef,
                ];
            }

            return [
                'success' => false,
                'url' => config("payments.failed_url"),
                'message' => $responseData['message'] ?? 'Failed to create Telr payment session',
            ];
        } catch (\Exception $e) {
            Log::error('Telr payment error: ' . $e->getMessage());
            return [
                'success' => false,
                'url' => config("payments.failed_url"),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle callback from Telr
     *
     * @param Request $request
     * @return PaymentResponse
     */
    public function callBack(Request $request): PaymentResponse
    {
        try {
            $data = $request->all();
            $success = false;
            $status = 'failed';
            $unique_id = $data['ref'] ?? null;
            $amount = null;
            $currency = null;

            // Verify the payment status
            if (isset($data['ref'])) {
                $paymentStatus = $this->verifyPayment($data['ref']);

                // If payment is successful
                if (isset($paymentStatus['order']['status']['code'])) {
                    $statusCode = $paymentStatus['order']['status']['code'];
                    $success = $statusCode === 3;
                    $status = $success ? 'success' : 'failed';

                    // Extract additional information if available
                    if (isset($paymentStatus['order']['amount'])) {
                        $amount = (float)$paymentStatus['order']['amount'];
                    }

                    if (isset($paymentStatus['order']['currency'])) {
                        $currency = $paymentStatus['order']['currency'];
                    }
                }
            }

            return new PaymentResponse(
                success: $success,
                status: $status,
                unique_id: $unique_id,
                amount: $amount,
                currency: $currency,
                gateway_name: 'telr',
                raw: array_merge($data, ['verification_result' => $paymentStatus ?? []])
            );
        } catch (\Exception $e) {
            Log::error('Telr callback error: ' . $e->getMessage());
            return new PaymentResponse(
                success: false,
                status: 'error',
                unique_id: $data['ref'] ?? null,
                amount: null,
                currency: null,
                gateway_name: 'telr',
                raw: ['error' => $e->getMessage(), 'request_data' => $data ?? []]
            );
        }
    }

    /**
     * Verify payment status with Telr
     *
     * @param string $transactionRef
     * @return array
     */
    public function verifyPayment(string $transactionRef): array
    {
        try {
            $payload = [
                'method' => 'check',
                'store' => $this->store_id,
                'authkey' => $this->auth_key,
                'order' => [
                    'ref' => $transactionRef
                ]
            ];

            $response = $this->buildRequest('POST', '', $payload);
            return $response->getData(true)['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('Telr verification error: ' . $e->getMessage());
            return [
                'order' => [
                    'status' => [
                        'code' => -1,
                        'message' => $e->getMessage()
                    ]
                ]
            ];
        }
    }

    /**
     * Capture an authorized payment
     *
     * @param string $transactionRef
     * @param float $amount
     * @return array
     */
    public function capturePayment(string $transactionRef, float $amount): array
    {
        try {
            $payload = [
                'method' => 'capture',
                'store' => $this->store_id,
                'authkey' => $this->auth_key,
                'order' => [
                    'ref' => $transactionRef
                ],
                'amount' => $amount
            ];

            $response = $this->buildRequest('POST', '', $payload);
            return $response->getData(true);
        } catch (\Exception $e) {
            Log::error('Telr capture error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Cancel/void an authorized payment
     *
     * @param string $transactionRef
     * @return array
     */
    public function cancelPayment(string $transactionRef): array
    {
        try {
            $payload = [
                'method' => 'cancel',
                'store' => $this->store_id,
                'authkey' => $this->auth_key,
                'order' => [
                    'ref' => $transactionRef
                ]
            ];

            $response = $this->buildRequest('POST', '', $payload);
            return $response->getData(true);
        } catch (\Exception $e) {
            Log::error('Telr cancel error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Refund a payment
     *
     * @param string $transactionRef
     * @param float $amount
     * @return array
     */
    public function refundPayment(string $transactionRef, float $amount): array
    {
        try {
            $payload = [
                'method' => 'refund',
                'store' => $this->store_id,
                'authkey' => $this->auth_key,
                'order' => [
                    'ref' => $transactionRef
                ],
                'amount' => $amount
            ];

            $response = $this->buildRequest('POST', '', $payload);
            return $response->getData(true);
        } catch (\Exception $e) {
            Log::error('Telr refund error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
