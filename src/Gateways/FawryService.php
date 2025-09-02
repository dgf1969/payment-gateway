<?php

namespace Arafa\Payments\Gateways;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class FawryService extends BasePaymentService implements PaymentGatewayInterface
{
    protected $name;
    protected $mode;
    protected $merchant_code;
    protected $security_key;
    protected $merchant_reference_id;

    public function __construct()
    {
        $this->name = 'fawry';

        $this->mode = config("payments.{$this->name}.mode");

        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->merchant_code = config("payments.{$this->name}.{$this->mode}_merchant_code");
        $this->security_key = config("payments.{$this->name}.{$this->mode}_security_key");

        $this->header   = $this->buildHeader();
    }


    /**
     * Build the header for Fawry API requests
     *
     * @return array The header
     */
    public function buildHeader(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
        ];
    }

    /**
     * Generate a signature for Fawry API requests
     *
     * @param array $data Payment data
     * @return string The generated signature
     */
    protected function generateSignature($data)
    {
        // Fawry signature format: merchantCode + merchantRefNum + customerProfileId + paymentMethod + amount + securityKey
        $signatureString = $this->merchant_code .
            $data['merchantRefNum'] .
            (isset($data['customerProfileId']) ? $data['customerProfileId'] : '') .
            'PAYATFAWRY' .
            $data['amount'] .
            $this->security_key;

        return hash('sha256', $signatureString);
    }

    /**
     * Initiate a payment request to Fawry
     *
     * @param Request $request The HTTP request containing payment data
     * @return array Response with payment URL or error
     */
    public function sendPayment(Request $request): array
    {
        try {
            $data = $request->all();

            // Generate a unique merchant reference number if not provided
            if (!isset($data['merchantRefNum'])) {
                $data['merchantRefNum'] = uniqid('fawry_');
            }

            // Prepare payment request data
            $paymentData = [
                'merchantCode' => $this->merchant_code,
                'merchantRefNum' => $data['merchantRefNum'],
                'customerProfileId' => $data['customerProfileId'] ?? uniqid('customer_'),
                'customerName' => $data['customerName'] ?? 'Customer',
                'customerMobile' => $data['customerMobile'] ?? '',
                'customerEmail' => $data['customerEmail'] ?? '',
                'amount' => $data['amount'],
                'currencyCode' => $data['currencyCode'] ?? 'EGP',
                'language' => $data['language'] ?? 'en-gb',
                'chargeItems' => $data['chargeItems'] ?? [
                    [
                        'itemId' => $data['itemId'] ?? 'item1',
                        'description' => $data['description'] ?? 'Payment for services',
                        'price' => $data['amount'],
                        'quantity' => 1
                    ]
                ],
                'paymentMethod' => 'PAYATFAWRY',
                'paymentExpiry' => $data['paymentExpiry'] ?? 1440, // Default to 24 hours (in minutes)
                'description' => $data['description'] ?? 'Payment for services',
            ];

            // Generate and add signature
            $paymentData['signature'] = $this->generateSignature($paymentData);

            // Make API request to Fawry
            $response = $this->buildRequest('POST', '/ECommerceWeb/Fawry/payments/charge', $paymentData);
            $responseData = $response->getData(true);

            if ($responseData['success'] && isset($responseData['data']['referenceNumber'])) {
                // Store the reference number for later verification
                $this->merchant_reference_id = $data['merchantRefNum'];

                // Return success with payment URL
                // For Fawry, we typically return a reference number that customers use to pay
                return [
                    'success' => true,
                    'referenceNumber' => $responseData['data']['referenceNumber'],
                    'merchantRefNum' => $data['merchantRefNum'],
                    'url' => $this->base_url . '/ECommerceWeb/Fawry/payments/status?referenceNumber=' . $responseData['data']['referenceNumber']
                ];
            }

            // Return failure response
            return ['success' => false, 'url' => $response];
        } catch (\Exception $e) {
            Log::error('Fawry payment error: ' . $e->getMessage());
            return ['success' => false, 'url' => config("payments.failed_url"), 'error' => $e->getMessage()];
        }
    }

    /**
     * Handle callback from Fawry
     *
     * @param Request $request The callback request from Fawry
     * @return PaymentResponse Standardized payment response
     */
    public function callBack(Request $request): PaymentResponse
    {
        try {
            $response = $request->all();

            // Log the callback for debugging
            Log::info('Fawry callback received', $response);

            // Default values
            $success = false;
            $status = 'failed';
            $transactionId = $response['fawryRefNumber'] ?? null;
            $amount = $response['paymentAmount'] ?? null;
            $currency = 'EGP'; // Fawry typically uses Egyptian Pound

            // Verify the signature if provided
            if (isset($response['merchantRefNumber']) && isset($response['signature'])) {
                $expectedSignature = hash(
                    'sha256',
                    $response['merchantRefNumber'] .
                        $response['fawryRefNumber'] .
                        $response['paymentAmount'] .
                        $response['paymentStatus'] .
                        $this->security_key
                );

                if ($expectedSignature !== $response['signature']) {
                    Log::warning('Fawry callback signature verification failed');
                    return new PaymentResponse(
                        false,
                        'signature_failed',
                        $transactionId,
                        $amount,
                        $currency,
                        $this->name,
                        $response
                    );
                }
            }

            // Check payment status
            if (isset($response['paymentStatus'])) {
                switch ($response['paymentStatus']) {
                    case 'PAID':
                        $success = true;
                        $status = 'success';
                        break;
                    case 'UNPAID':
                        $status = 'pending';
                        break;
                    case 'EXPIRED':
                        $status = 'expired';
                        break;
                    case 'REFUNDED':
                        $status = 'refunded';
                        break;
                    case 'CANCELED':
                        $status = 'canceled';
                        break;
                    default:
                        $status = 'unknown';
                        break;
                }
            }

            return new PaymentResponse(
                $success,
                $status,
                $transactionId,
                $amount,
                $currency,
                $this->name,
                $response
            );
        } catch (\Exception $e) {
            Log::error('Fawry callback error: ' . $e->getMessage());
            return new PaymentResponse(
                false,
                'error',
                null,
                null,
                'EGP',
                $this->name,
                ['error' => $e->getMessage(), 'request_data' => $request->all()]
            );
        }
    }

    /**
     * Verify payment status with Fawry
     *
     * @param string $merchantRefNum The merchant reference number
     * @return array Payment status information
     */
    public function verifyPayment($merchantRefNum)
    {
        try {
            // Generate signature for verification
            $signatureString = $this->merchant_code . $merchantRefNum . $this->security_key;
            $signature = hash('sha256', $signatureString);

            // Build the verification URL
            $verificationUrl = "/ECommerceWeb/Fawry/payments/status?merchantCode={$this->merchant_code}&merchantRefNumber={$merchantRefNum}&signature={$signature}";

            // Make API request to Fawry
            $response = $this->buildRequest('GET', $verificationUrl);

            return $response->getData(true);
        } catch (\Exception $e) {
            Log::error('Fawry payment verification error: ' . $e->getMessage());
            return [
                'success' => false,
                'error' => $e->getMessage()
            ];
        }
    }
}
