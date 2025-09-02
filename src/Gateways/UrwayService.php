<?php

namespace Arafa\Payments\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class UrwayService extends BasePaymentService implements PaymentGatewayInterface
{
    protected $name;
    protected $mode;
    protected $terminal_id;
    protected $merchant_key;
    protected $password;
    protected $merchant_id;
    protected $currency;

    public function __construct()
    {
        $this->name = 'urway';

        $this->mode = config("payments.{$this->name}.mode");

        $this->terminal_id = config("payments.{$this->name}.{$this->mode}_terminal_id");
        $this->merchant_key = config("payments.{$this->name}.{$this->mode}_merchant_key");
        $this->password = config("payments.{$this->name}.{$this->mode}_password");
        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->merchant_id = config("payments.{$this->name}.merchant_id");
        $this->currency = config("payments.{$this->name}.currency");

        $this->header =  $this->buildHeader();
    } 

      public function buildHeader(): array
    {
        return  [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json'
        ];
    }

    /**
     * Process a payment request through Urway
     *
     * @param Request $request
     * @param string $paymentMethod Default is '1' for cards (Visa, Mastercard, Mada). Use '13' for STC Pay.
     * @return array
     */
    public function sendPayment(Request $request, string $paymentMethod = '1'): array
    {
        try {
            // Extract data from request
            $data = $request->all();

            // Validate required fields
            if (!isset($data['amount']) || empty($data['amount'])) {
                Log::error('Urway payment error: Missing required field - amount');
                return [
                    'success' => false,
                    'error_code' => 'MISSING_AMOUNT',
                    'url' => config("payments.failed_url"),
                    'message' => 'Amount is required for payment processing',
                ];
            }

            // Generate a unique transaction reference
            $transRef = $data['order_id'] ?? uniqid('urway_');

            // Generate checksum
            $checksum = $this->generateChecksum($transRef, $data['amount'], $data['currency'] ?? $this->currency);

            // Prepare payment request payload for Urway
            $payload = [
                'trackid' => $transRef,
                'terminalId' => $this->terminal_id,
                'customerEmail' => $data['customer_email'] ?? '',
                'action' => $paymentMethod, // 1 for cards, 13 for STC Pay
                'merchantIp' => $request->ip(),
                'password' => $this->password,
                'currency' => $data['currency'] ?? $this->currency,
                'country' => $data['billing_country'] ?? 'SA',
                'amount' => $data['amount'],
                'udf1' => $data['udf1'] ?? '',
                'udf2' => $data['udf2'] ?? config("payments.callback_url"),
                'udf3' => $data['udf3'] ?? '',
                'udf4' => $data['udf4'] ?? '',
                'udf5' => $data['udf5'] ?? '',
                'requestHash' => $checksum
            ];

            // Send request to Urway API to create a payment session
            $response = $this->buildRequest('POST', 'paymentRequest', $payload);
            $responseData = $response->getData(true);

            if ($response->getStatusCode() === 200 && isset($responseData['data']['payid']) && isset($responseData['data']['targetUrl'])) {
                Log::info('Urway payment session created successfully', [
                    'track_id' => $transRef,
                    'payment_id' => $responseData['data']['payid']
                ]);

                return [
                    'success' => true,
                    'url' => $responseData['data']['targetUrl'] . '?paymentid=' . $responseData['data']['payid'],
                    'session_id' => $responseData['data']['payid'] ?? null,
                    'track_id' => $transRef
                ];
            }

            // Log specific error details
            $errorCode = $responseData['data']['responseCode'] ?? 'UNKNOWN_ERROR';
            $errorMessage = $responseData['data']['responseMessage'] ?? $responseData['message'] ?? 'Failed to create Urway payment session';

            Log::error('Urway payment session creation failed', [
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'track_id' => $transRef
            ]);

            return [
                'success' => false,
                'error_code' => $errorCode,
                'url' => config("payments.failed_url"),
                'message' => $errorMessage,
                'track_id' => $transRef
            ];
        } catch (\Exception $e) {
            Log::error('Urway payment error: ' . $e->getMessage(), [
                'exception' => get_class($e),
                'file' => $e->getFile(),
                'line' => $e->getLine()
            ]);

            return [
                'success' => false,
                'error_code' => 'EXCEPTION',
                'url' => config("payments.failed_url"),
                'message' => 'An unexpected error occurred: ' . $e->getMessage(),
            ];
        }
    }

    /**
     * Handle callback from Urway
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
            $unique_id = null;
            $amount = null;
            $currency = $this->currency;

            // Validate response hash for security
            if (isset($data['TranId']) && isset($data['ResponseCode']) && isset($data['amount']) && isset($data['responseHash'])) {
                $amount = (float)$data['amount'];
                $status = $data['ResponseCode'];
                $tranId = $data['TranId'];
                $unique_id = $tranId;
                $responseHash = $data['responseHash'];

                // Generate hash for validation
                $requestHash = "$tranId|$this->merchant_key|$status|$amount";
                $hash = hash('sha256', $requestHash);

                // Verify hash and check result
                if ($hash === $responseHash && (isset($data['Result']) && ($data['Result'] === 'Successful' || $data['Result'] === 'Success'))) {
                    Log::info('Urway payment verified successfully', ['transaction_id' => $tranId]);
                    $success = true;
                    $status = 'success';
                } else {
                    Log::warning('Urway hash validation failed or payment unsuccessful', [
                        'expected_hash' => $hash,
                        'received_hash' => $responseHash,
                        'result' => $data['Result'] ?? 'not set'
                    ]);
                    $status = $data['Result'] ?? 'failed';
                }
            } else if (isset($data['paymentId'])) {
                // Fallback to the old verification method
                $paymentStatus = $this->verifyPayment($data['paymentId']);
                $unique_id = $data['paymentId'];

                // If payment is successful
                if (isset($paymentStatus['result'])) {
                    $success = $paymentStatus['result'] === 'SUCCESS';
                    $status = $success ? 'success' : 'failed';

                    // Extract additional information if available
                    if (isset($paymentStatus['amount'])) {
                        $amount = (float)$paymentStatus['amount'];
                    }

                    if (isset($paymentStatus['currency'])) {
                        $currency = $paymentStatus['currency'];
                    }
                }
            }

            return new PaymentResponse(
                success: $success,
                status: $status,
                unique_id: $unique_id,
                amount: $amount,
                currency: $currency,
                gateway_name: 'urway',
                raw: array_merge($data, ['verification_result' => $paymentStatus ?? []])
            );
        } catch (\Exception $e) {
            Log::error('Urway callback error: ' . $e->getMessage());
            return new PaymentResponse(
                success: false,
                status: 'error',
                unique_id: $data['TranId'] ?? ($data['paymentId'] ?? null),
                amount: null,
                currency: null,
                gateway_name: 'urway',
                raw: ['error' => $e->getMessage(), 'request_data' => $data ?? []]
            );
        }
    }

    /**
     * Verify payment status with Urway
     *
     * @param string $paymentId
     * @return array
     */
    public function verifyPayment(string $paymentId): array
    {
        try {
            $payload = [
                'merchantId' => $this->merchant_id,
                'terminalId' => $this->terminal_id,
                'password' => $this->password,
                'paymentId' => $paymentId
            ];

            $response = $this->buildRequest('POST', 'checkPaymentStatus', $payload);
            return $response->getData(true)['data'] ?? [];
        } catch (\Exception $e) {
            Log::error('Urway verification error: ' . $e->getMessage());
            return [
                'result' => 'ERROR',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Generate checksum for Urway payment request
     *
     * @param string $trackId
     * @param float $amount
     * @param string $currency
     * @return string
     */
    private function generateChecksum(string $trackId, float $amount, string $currency): string
    {
        // Format amount to have 2 decimal places
        $amount = number_format($amount, 2, '.', '');

        // Concatenate the values
        $data = $trackId . '|' . $this->terminal_id . '|' . $this->password . '|' . $this->merchant_key . '|' . $amount . '|' . $currency;

        // Generate SHA-256 hash
        return hash('sha256', $data);
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
            // Format amount to have 2 decimal places
            $amount = number_format($amount, 2, '.', '');

            // Generate refund request hash
            $refundHash = hash('sha256', $paymentId . '|' . $this->terminal_id . '|' . $this->password . '|' . $this->merchant_key . '|' . $amount . '|' . $this->currency);

            $payload = [
                'merchantId' => $this->merchant_id,
                'terminalId' => $this->terminal_id,
                'password' => $this->password,
                'paymentId' => $paymentId,
                'amount' => $amount,
                'currency' => $this->currency,
                'requestHash' => $refundHash,
                'action' => '2' // 2 for refund
            ];

            $response = $this->buildRequest('POST', 'refundRequest', $payload);
            return $response->getData(true);
        } catch (\Exception $e) {
            Log::error('Urway refund error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
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
            // Format amount to have 2 decimal places
            $amount = number_format($amount, 2, '.', '');

            // Generate capture request hash
            $captureHash = hash('sha256', $paymentId . '|' . $this->terminal_id . '|' . $this->password . '|' . $this->merchant_key . '|' . $amount . '|' . $this->currency);

            $payload = [
                'merchantId' => $this->merchant_id,
                'terminalId' => $this->terminal_id,
                'password' => $this->password,
                'paymentId' => $paymentId,
                'amount' => $amount,
                'currency' => $this->currency,
                'requestHash' => $captureHash,
                'action' => '3' // 3 for capture
            ];

            $response = $this->buildRequest('POST', 'captureRequest', $payload);
            return $response->getData(true);
        } catch (\Exception $e) {
            Log::error('Urway capture error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Void/cancel an authorized payment
     *
     * @param string $paymentId
     * @return array
     */
    public function voidPayment(string $paymentId): array
    {
        try {
            // Generate void request hash
            $voidHash = hash('sha256', $paymentId . '|' . $this->terminal_id . '|' . $this->password . '|' . $this->merchant_key);

            $payload = [
                'merchantId' => $this->merchant_id,
                'terminalId' => $this->terminal_id,
                'password' => $this->password,
                'paymentId' => $paymentId,
                'requestHash' => $voidHash,
                'action' => '4' // 4 for void
            ];

            $response = $this->buildRequest('POST', 'voidRequest', $payload);
            return $response->getData(true);
        } catch (\Exception $e) {
            Log::error('Urway void error: ' . $e->getMessage());
            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Process a payment request through Urway using STC Pay
     *
     * @param Request $request
     * @return array
     */
    public function sendStcPayment(Request $request): array
    {
        return $this->sendPayment($request, '13');
    }

    /**
     * Redirect to Urway payment page using auto-submit form
     * This method is similar to the official package's approach
     *
     * @param string $url The target URL with payment ID
     * @param string $message Optional message to display during redirect
     * @return \Illuminate\Http\Response
     */
    public function redirectToPaymentPage(string $url, string $message = 'Redirecting to payment gateway...'): \Illuminate\Http\Response
    {
        $html = '
        <html>
            <head>
                <title>Redirecting to Payment Gateway</title>
            </head>
            <body>
                <form name="urwayRedirectForm" method="POST" action="' . $url . '">
                    <h2 style="text-align: center; margin-top: 50px;">' . $message . '</h2>
                </form>
                <script type="text/javascript">document.urwayRedirectForm.submit();</script>
            </body>
        </html>';

        return response($html);
    }
}
