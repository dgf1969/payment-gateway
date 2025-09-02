<?php

namespace Arafa\Payments\Gateways;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

class TabbyService extends BasePaymentService implements PaymentGatewayInterface
{
    protected $name;
    protected $mode;
    protected $api_key;
    protected $merchant_id;
    protected $merchant_code;

    public function __construct()
    {
        $this->name = 'tabby';

        $this->mode = config("payments.{$this->name}.mode");

        $this->api_key = config("payments.{$this->name}.{$this->mode}_api_key");
        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->merchant_id = config("payments.{$this->name}.merchant_id");
        $this->merchant_code = config("payments.{$this->name}.merchant_code");

        $this->header = $this->buildHeader();
    }

      public function buildHeader(): array
    {
        return   [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key
        ];
    }

    /**
     * Create a payment session with Tabby
     *
     * @param Request $request
     * @return array
     */
    public function sendPayment(Request $request): array
    {
        try {
            // Extract data from request
            $data = $request->all();

            // Validate required fields
            $requiredFields = ['amount', 'currency', 'customer_email', 'customer_phone', 'customer_name'];
            foreach ($requiredFields as $field) {
                if (!isset($data[$field]) || empty($data[$field])) {
                    return [
                        'success' => false,
                        'message' => "Missing required field: {$field}",
                    ];
                }
            }

            // Format items correctly for Tabby API
            $items = [];
            if (isset($data['items']) && is_array($data['items'])) {
                foreach ($data['items'] as $item) {
                    $items[] = [
                        'title' => $item['title'] ?? '',
                        'description' => $item['description'] ?? '',
                        'quantity' => $item['quantity'] ?? 1,
                        'unit_price' => $item['unit_price'] ?? 0,
                        'discount_amount' => $item['discount_amount'] ?? 0,
                        'reference_id' => $item['reference_id'] ?? '',
                        'category' => $item['category'] ?? 'other',
                        'image_url' => $item['image_url'] ?? null,
                        'product_url' => $item['product_url'] ?? null,
                    ];
                }
            }

            // Prepare shipping address
            $shippingAddress = [
                'city' => $data['shipping_city'] ?? '',
                'address' => $data['shipping_address'] ?? '',
                'zip' => $data['shipping_zip'] ?? '',
            ];

            // Prepare payment request payload for Tabby
            $payload = [
                'merchant' => [
                    'id' => $this->merchant_id
                ],
                'amount' => $data['amount'],
                'currency' => $data['currency'] ?? 'SAR',
                'description' => $data['description'] ?? 'Order payment',
                'order' => [
                    'reference_id' => $data['order_id'] ?? uniqid('order_'),
                    'items' => $items,
                    'shipping_amount' => $data['shipping_amount'] ?? 0,
                    'tax_amount' => $data['tax_amount'] ?? 0,
                    'discount_amount' => $data['discount_amount'] ?? 0,
                ],
                'buyer' => [
                    'phone' => $data['customer_phone'],
                    'email' => $data['customer_email'],
                    'name' => $data['customer_name'],
                ],
                'shipping_address' => $shippingAddress,
                'expires_at' => $data['expires_at'] ?? date('c', strtotime('+1 hour')),
                'lang' => $data['lang'] ?? 'en',
                'success_url' => config("payments.success_url"),
                'cancel_url' => config("payments.failed_url"),
                'callback_url' => config("payments.callback_url"),
            ];

            // Add optional fields if provided
            if (isset($data['payment_method']) && !empty($data['payment_method'])) {
                $payload['payment_method'] = $data['payment_method'];
            }

            if (isset($data['buyer_history']) && !empty($data['buyer_history'])) {
                $payload['buyer_history'] = $data['buyer_history'];
            }

            // Send request to Tabby API to create a payment session
            $response = $this->buildRequest('POST', '/api/v2/checkout', $payload);
            $responseData = $response->getData(true);

            // Log the response for debugging
            \Illuminate\Support\Facades\Log::debug('Tabby API Response', $responseData);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                if (isset($responseData['data']['checkout_url'])) {
                    return [
                        'success' => true,
                        'url' => $responseData['data']['checkout_url'],
                        'session_id' => $responseData['data']['id'] ?? null,
                    ];
                } else {
                    return [
                        'success' => false,
                        'message' => 'Checkout URL not found in response',
                        'response' => $responseData,
                    ];
                }
            }

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Failed to create Tabby payment session',
                'response' => $responseData,
            ];
        } catch (\Exception $e) {
            // Log the exception
            \Illuminate\Support\Facades\Log::error('Tabby payment error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage(),
            ];
        }
    }

    /**
     * Handle callback from Tabby
     *
     * @param Request $request
     * @return PaymentResponse
     */
    public function callBack(Request $request): PaymentResponse
    {
        try {
            $data = $request->all();

            // Log the callback data for debugging
            \Illuminate\Support\Facades\Log::info('Tabby callback received', $data);

            // Default values for failed response
            $success = false;
            $status = 'failed';
            $paymentId = null;
            $amount = null;
            $currency = null;
            $rawResponse = $data;

            // Validate required fields
            if (!isset($data['payment_id']) || empty($data['payment_id'])) {
                \Illuminate\Support\Facades\Log::warning('Tabby callback missing payment_id', $data);
                return new PaymentResponse($success, $status, $paymentId, $amount, $currency, $this->name, $rawResponse);
            }

            // Verify the payment status
            $paymentStatus = $this->verifyPayment($data['payment_id']);
            \Illuminate\Support\Facades\Log::info('Tabby payment status', $paymentStatus);

            // Update raw response with payment status
            $rawResponse = array_merge($data, ['payment_status' => $paymentStatus]);

            // Check if payment status is valid
            if (!isset($paymentStatus['status'])) {
                \Illuminate\Support\Facades\Log::warning('Tabby payment status missing', $paymentStatus);
                return new PaymentResponse($success, $status, $paymentId, $amount, $currency, $this->name, $rawResponse);
            }

            // Extract payment details
            $paymentId = $paymentStatus['id'] ?? $data['payment_id'] ?? null;
            $amount = $paymentStatus['amount'] ?? null;
            $currency = $paymentStatus['currency'] ?? null;

            // Process based on payment status
            switch ($paymentStatus['status']) {
                case 'AUTHORIZED':
                    // Payment is authorized but not yet captured
                    $success = true;
                    $status = 'pending';
                    break;

                case 'CAPTURED':
                    // Payment is captured (completed)
                    $success = true;
                    $status = 'success';
                    break;

                case 'CANCELED':
                    // Payment was canceled
                    \Illuminate\Support\Facades\Log::info('Tabby payment was canceled', $paymentStatus);
                    $status = 'canceled';
                    break;

                case 'CLOSED':
                    // Payment is closed (could be refunded, expired, etc.)
                    \Illuminate\Support\Facades\Log::info('Tabby payment is closed', $paymentStatus);
                    $status = 'closed';
                    break;

                case 'EXPIRED':
                    // Payment session expired
                    \Illuminate\Support\Facades\Log::info('Tabby payment expired', $paymentStatus);
                    $status = 'expired';
                    break;

                case 'REJECTED':
                    // Payment was rejected
                    \Illuminate\Support\Facades\Log::info('Tabby payment was rejected', $paymentStatus);
                    $status = 'rejected';
                    break;

                default:
                    // Unknown status
                    \Illuminate\Support\Facades\Log::warning('Unknown Tabby payment status: ' . $paymentStatus['status'], $paymentStatus);
                    $status = 'unknown';
                    break;
            }

            return new PaymentResponse($success, $status, $paymentId, $amount, $currency, $this->name, $rawResponse);
        } catch (\Exception $e) {
            // Log the error
            \Illuminate\Support\Facades\Log::error('Tabby callback error: ' . $e->getMessage(), [
                'trace' => $e->getTraceAsString(),
                'request_data' => $request->all()
            ]);

            return new PaymentResponse(
                false,
                'error',
                null,
                null,
                null,
                $this->name,
                ['error' => $e->getMessage(), 'request_data' => $request->all()]
            );
        }
    }

    /**
     * Verify payment status with Tabby
     *
     * @param string $paymentId
     * @return array
     */
    public function verifyPayment(string $paymentId): array
    {
        try {
            // Validate payment ID
            if (empty($paymentId)) {
                \Illuminate\Support\Facades\Log::warning('Empty payment ID provided for verification');
                return [
                    'status' => 'ERROR',
                    'message' => 'Empty payment ID provided'
                ];
            }

            // Log the verification request
            \Illuminate\Support\Facades\Log::info('Verifying Tabby payment', ['payment_id' => $paymentId]);

            // Send request to Tabby API to verify payment
            $response = $this->buildRequest('GET', "/api/v2/payments/{$paymentId}");
            $responseData = $response->getData(true);

            // Log the verification response
            \Illuminate\Support\Facades\Log::debug('Tabby verification response', $responseData);

            // Check if the response is successful
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                if (isset($responseData['data'])) {
                    return $responseData['data'];
                } else {
                    \Illuminate\Support\Facades\Log::warning('Tabby verification response missing data', $responseData);
                    return [
                        'status' => 'ERROR',
                        'message' => 'Response missing data'
                    ];
                }
            } else {
                \Illuminate\Support\Facades\Log::warning('Tabby verification failed', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $responseData
                ]);
                return [
                    'status' => 'ERROR',
                    'message' => $responseData['message'] ?? 'Verification failed',
                    'code' => $response->getStatusCode()
                ];
            }
        } catch (\Exception $e) {
            // Log the error
            \Illuminate\Support\Facades\Log::error('Tabby verification error: ' . $e->getMessage(), [
                'payment_id' => $paymentId,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'ERROR',
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
            // Validate parameters
            if (empty($paymentId)) {
                \Illuminate\Support\Facades\Log::warning('Empty payment ID provided for capture');
                return [
                    'success' => false,
                    'message' => 'Empty payment ID provided'
                ];
            }

            if ($amount <= 0) {
                \Illuminate\Support\Facades\Log::warning('Invalid amount provided for capture', ['amount' => $amount]);
                return [
                    'success' => false,
                    'message' => 'Amount must be greater than zero'
                ];
            }

            // Prepare payload
            $payload = [
                'amount' => $amount
            ];

            // Log the capture request
            \Illuminate\Support\Facades\Log::info('Capturing Tabby payment', [
                'payment_id' => $paymentId,
                'amount' => $amount
            ]);

            // Send request to Tabby API to capture payment
            $response = $this->buildRequest('POST', "/api/v2/payments/{$paymentId}/captures", $payload);
            $responseData = $response->getData(true);

            // Log the capture response
            \Illuminate\Support\Facades\Log::debug('Tabby capture response', $responseData);

            // Check if the response is successful
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                if (isset($responseData['data'])) {
                    return [
                        'success' => true,
                        'data' => $responseData['data']
                    ];
                } else {
                    \Illuminate\Support\Facades\Log::warning('Tabby capture response missing data', $responseData);
                    return [
                        'success' => false,
                        'message' => 'Response missing data',
                        'response' => $responseData
                    ];
                }
            } else {
                \Illuminate\Support\Facades\Log::warning('Tabby capture failed', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $responseData
                ]);
                return [
                    'success' => false,
                    'message' => $responseData['message'] ?? 'Capture failed',
                    'code' => $response->getStatusCode(),
                    'response' => $responseData
                ];
            }
        } catch (\Exception $e) {
            // Log the error
            \Illuminate\Support\Facades\Log::error('Tabby capture error: ' . $e->getMessage(), [
                'payment_id' => $paymentId,
                'amount' => $amount,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Cancel/void an authorized payment
     *
     * @param string $paymentId
     * @return array
     */
    public function cancelPayment(string $paymentId): array
    {
        try {
            // Validate payment ID
            if (empty($paymentId)) {
                \Illuminate\Support\Facades\Log::warning('Empty payment ID provided for cancellation');
                return [
                    'success' => false,
                    'message' => 'Empty payment ID provided'
                ];
            }

            // Log the cancellation request
            \Illuminate\Support\Facades\Log::info('Cancelling Tabby payment', ['payment_id' => $paymentId]);

            // Send request to Tabby API to cancel payment
            $response = $this->buildRequest('POST', "/api/v2/payments/{$paymentId}/cancellations");
            $responseData = $response->getData(true);

            // Log the cancellation response
            \Illuminate\Support\Facades\Log::debug('Tabby cancellation response', $responseData);

            // Check if the response is successful
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                if (isset($responseData['data'])) {
                    return [
                        'success' => true,
                        'data' => $responseData['data']
                    ];
                } else {
                    \Illuminate\Support\Facades\Log::warning('Tabby cancellation response missing data', $responseData);
                    return [
                        'success' => false,
                        'message' => 'Response missing data',
                        'response' => $responseData
                    ];
                }
            } else {
                \Illuminate\Support\Facades\Log::warning('Tabby cancellation failed', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $responseData
                ]);
                return [
                    'success' => false,
                    'message' => $responseData['message'] ?? 'Cancellation failed',
                    'code' => $response->getStatusCode(),
                    'response' => $responseData
                ];
            }
        } catch (\Exception $e) {
            // Log the error
            \Illuminate\Support\Facades\Log::error('Tabby cancellation error: ' . $e->getMessage(), [
                'payment_id' => $paymentId,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Refund a captured payment
     *
     * @param string $paymentId
     * @param float $amount
     * @return array
     */
    public function refundPayment(string $paymentId, float $amount): array
    {
        try {
            // Validate parameters
            if (empty($paymentId)) {
                \Illuminate\Support\Facades\Log::warning('Empty payment ID provided for refund');
                return [
                    'success' => false,
                    'message' => 'Empty payment ID provided'
                ];
            }

            if ($amount <= 0) {
                \Illuminate\Support\Facades\Log::warning('Invalid amount provided for refund', ['amount' => $amount]);
                return [
                    'success' => false,
                    'message' => 'Amount must be greater than zero'
                ];
            }

            // Prepare payload
            $payload = [
                'amount' => $amount
            ];

            // Log the refund request
            \Illuminate\Support\Facades\Log::info('Refunding Tabby payment', [
                'payment_id' => $paymentId,
                'amount' => $amount
            ]);

            // Send request to Tabby API to refund payment
            $response = $this->buildRequest('POST', "/api/v2/payments/{$paymentId}/refunds", $payload);
            $responseData = $response->getData(true);

            // Log the refund response
            \Illuminate\Support\Facades\Log::debug('Tabby refund response', $responseData);

            // Check if the response is successful
            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                if (isset($responseData['data'])) {
                    return [
                        'success' => true,
                        'data' => $responseData['data']
                    ];
                } else {
                    \Illuminate\Support\Facades\Log::warning('Tabby refund response missing data', $responseData);
                    return [
                        'success' => false,
                        'message' => 'Response missing data',
                        'response' => $responseData
                    ];
                }
            } else {
                \Illuminate\Support\Facades\Log::warning('Tabby refund failed', [
                    'status_code' => $response->getStatusCode(),
                    'response' => $responseData
                ]);
                return [
                    'success' => false,
                    'message' => $responseData['message'] ?? 'Refund failed',
                    'code' => $response->getStatusCode(),
                    'response' => $responseData
                ];
            }
        } catch (\Exception $e) {
            // Log the error
            \Illuminate\Support\Facades\Log::error('Tabby refund error: ' . $e->getMessage(), [
                'payment_id' => $paymentId,
                'amount' => $amount,
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
