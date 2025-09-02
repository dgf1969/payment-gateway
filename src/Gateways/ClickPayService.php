<?php

namespace Arafa\Payments\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class ClickPayService extends BasePaymentService implements PaymentGatewayInterface
{
    protected $name;
    protected $mode;
    protected $server_key;
    protected $client_key;
    protected $merchant_id;

    public function __construct()
    {
        $this->name = 'clickpay';

        $this->mode = config("payments.{$this->name}.mode");

        $this->server_key = config("payments.{$this->name}.{$this->mode}_server_key");
        $this->client_key = config("payments.{$this->name}.{$this->mode}_client_key");
        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->merchant_id = config("payments.{$this->name}.merchant_id");

        $this->header            = $this->buildHeader();
    }

    /**
     * Build the header for ClickPay API requests
     *
     * @return array
     */
    public function buildHeader(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => $this->server_key
        ];
    }

    /**
     * Process a payment request through ClickPay
     *
     * @param Request $request
     * @return array
     */

    public function sendPayment(Request $request): array
    {
        try {
            // Extract data from request
            $data = $request->all();

            // Prepare payment request payload for ClickPay
            $payload = [
                'profile_id' => $this->merchant_id,
                'tran_type' => 'sale',
                'tran_class' => 'ecom',
                'cart_id' => $data['order_id'] ?? uniqid('clickpay_'),
                'cart_description' => $data['description'] ?? 'Order payment',
                'cart_currency' => $data['currency'] ?? 'SAR',
                'cart_amount' => $data['amount'],
                'callback' => config("payments.callback_url"),
                'return' =>  config("payments.callback_url"),
                'customer_details' => [
                    'name' => $data['customer_name'] ?? '',
                    'email' => $data['customer_email'] ?? '',
                    'phone' => $data['customer_phone'] ?? '',
                    'street1' => $data['billing_street'] ?? '',
                    'city' => $data['billing_city'] ?? '',
                    'state' => $data['billing_state'] ?? '',
                    'country' => $data['billing_country'] ?? 'SA',
                    'zip' => $data['billing_postcode'] ?? '',
                    'ip' => $request->ip()
                ],
                'hide_shipping' => true
            ];

            // Send request to ClickPay API to create a payment session
            $response = $this->buildRequest('POST', '/payment/request', $payload);
            $responseData = $response->getData(true);

            if ($response->getStatusCode() === 200 && isset($responseData['data']['redirect_url'])) {
                return [
                    'success' => true,
                    'url' => $responseData['data']['redirect_url'],
                    'session_id' => $responseData['data']['tran_ref'] ?? null,
                ];
            }

            return [
                'success' => false,
                'url' => config("payments.failed_url"),
                'message' => $responseData['message'] ?? 'Failed to create ClickPay payment session',
            ];
        } catch (\Exception $e) {
            Log::error('ClickPay payment error: ' . $e->getMessage());
            return [
                'success' => false,
                'url' => config("payments.failed_url"),
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle callback from ClickPay
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
            $unique_id = $data['tran_ref'] ?? null;
            $amount = null;
            $currency = null;

            // Verify the payment status
            if (isset($data['tran_ref'])) {
                $paymentStatus = $this->verifyPayment($data['tran_ref']);

                // If payment is successful
                if (isset($paymentStatus['payment_result']['response_status'])) {
                    $responseStatus = $paymentStatus['payment_result']['response_status'];
                    $success = $responseStatus === 'A';
                    $status = $success ? 'success' : $paymentStatus['payment_result']['response_message'] ?? 'failed';

                    // Extract additional information if available
                    if (isset($paymentStatus['cart_amount'])) {
                        $amount = (float)$paymentStatus['cart_amount'];
                    }

                    if (isset($paymentStatus['cart_currency'])) {
                        $currency = $paymentStatus['cart_currency'];
                    }
                }
            }

            return new PaymentResponse(
                success: $success,
                status: $status,
                unique_id: $unique_id,
                amount: $amount,
                currency: $currency,
                gateway_name: 'clickpay',
                raw: array_merge($data, ['verification_result' => $paymentStatus ?? []])
            );
        } catch (\Exception $e) {
            Log::error('ClickPay callback error: ' . $e->getMessage());
            return new PaymentResponse(
                success: false,
                status: 'error',
                unique_id: $data['tran_ref'] ?? null,
                amount: null,
                currency: null,
                gateway_name: 'clickpay',
                raw: ['error' => $e->getMessage(), 'request_data' => $data ?? []]
            );
        }
    }

    /**
     * Verify payment status with ClickPay
     *
     * @param string $transactionRef
     * @return array
     */
    public function verifyPayment(string $transactionRef): array
    {
        try {
            $payload = [
                'profile_id' => $this->merchant_id,
                'tran_ref' => $transactionRef
            ];

            $response = $this->buildRequest('POST', '/payment/query', $payload);
            return $response->getData(true)['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('ClickPay verification error: ' . $e->getMessage());
            return [
                'payment_result' => [
                    'response_status' => 'E',
                    'response_message' => $e->getMessage()
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
                'profile_id' => $this->merchant_id,
                'tran_ref' => $transactionRef,
                'tran_type' => 'capture',
                'amount' => $amount
            ];

            $response = $this->buildRequest('POST', '/payment/request', $payload);
            return $response->getData(true);
        } catch (\Exception $e) {
            Log::error('ClickPay capture error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Void/cancel an authorized payment
     *
     * @param string $transactionRef
     * @return array
     */
    public function voidPayment(string $transactionRef): array
    {
        try {
            $payload = [
                'profile_id' => $this->merchant_id,
                'tran_ref' => $transactionRef,
                'tran_type' => 'void'
            ];

            $response = $this->buildRequest('POST', '/payment/request', $payload);
            return $response->getData(true);
        } catch (\Exception $e) {
            Log::error('ClickPay void error: ' . $e->getMessage());
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
                'profile_id' => $this->merchant_id,
                'tran_ref' => $transactionRef,
                'tran_type' => 'refund',
                'amount' => $amount
            ];

            $response = $this->buildRequest('POST', '/payment/request', $payload);
            return $response->getData(true);
        } catch (\Exception $e) {
            Log::error('ClickPay refund error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
