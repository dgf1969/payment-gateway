<?php

namespace Arafa\Payments\Gateways;


use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Arafa\Payments\Gateways\PaymentResponse;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;

/**
 * TamaraService - Handles payment processing through Tamara payment gateway
 *
 * This service integrates with Tamara's API to provide payment processing capabilities
 * including checkout creation, payment verification, capture, cancellation, and refunds.
 *
 * API Documentation: https://docs.tamara.co/reference/tamara-api-reference-documentation
 */
class TamaraService extends BasePaymentService implements PaymentGatewayInterface
{
    protected $name;
    protected $mode;
    protected $api_key;
    protected $notification_key;
    protected $merchant_id;
    protected $default_currency;

    /**
     * Initialize the Tamara service with configuration values
     */
    public function __construct()
    {
        $this->name = 'tamara';

        $this->mode = config("payments.{$this->name}.mode");

        $this->api_key = config("payments.{$this->name}.{$this->mode}_api_key");
        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->notification_key = config("payments.{$this->name}.{$this->mode}_notification_key");
        $this->merchant_id = config("payments.{$this->name}.merchant_id");
        $this->default_currency = config("payments.{$this->name}.currency", 'SAR');

        $this->header = $this->buildHeader();
    }

    public function buildHeader(): array
    {
        return [
            'Accept' => 'application/json',
            'Content-Type' => 'application/json',
            'Authorization' => 'Bearer ' . $this->api_key
        ];
    }

    /**
     * Create a payment session with Tamara
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
                    Log::warning('Tamara payment missing required field', ['field' => $field]);
                    return [
                        'success' => false,
                        'message' => "Missing required field: {$field}",
                    ];
                }
            }

            // Prepare payment request payload for Tamara
            $payload = [
                'order_reference_id' => $data['order_id'] ?? uniqid('tamara_'),
                'total_amount' => [
                    'amount' => $data['amount'],
                    'currency' => $data['currency'] ?? $this->default_currency,
                ],
                'description' => $data['description'] ?? 'Order payment',
                'country_code' => $data['country_code'] ?? 'SA',
                'payment_type' => $data['payment_type'] ?? 'PAY_BY_INSTALMENTS',
                'instalments' => $data['instalments'] ?? 3,
                'locale' => $data['lang'] ?? 'en',
                'items' => $this->formatItems($data['items'] ?? []),
                'consumer' => [
                    'first_name' => $data['first_name'] ?? $this->extractFirstName($data['customer_name']),
                    'last_name' => $data['last_name'] ?? $this->extractLastName($data['customer_name']),
                    'phone_number' => $data['customer_phone'],
                    'email' => $data['customer_email'],
                ],
                'billing_address' => [
                    'first_name' => $data['first_name'] ?? $this->extractFirstName($data['customer_name']),
                    'last_name' => $data['last_name'] ?? $this->extractLastName($data['customer_name']),
                    'line1' => $data['billing_address'] ?? $data['shipping_address'] ?? '',
                    'line2' => $data['billing_address_line2'] ?? '',
                    'city' => $data['billing_city'] ?? $data['shipping_city'] ?? '',
                    'country_code' => $data['country_code'] ?? 'SA',
                    'phone_number' => $data['customer_phone'],
                ],
                'shipping_address' => [
                    'first_name' => $data['first_name'] ?? $this->extractFirstName($data['customer_name']),
                    'last_name' => $data['last_name'] ?? $this->extractLastName($data['customer_name']),
                    'line1' => $data['shipping_address'] ?? '',
                    'line2' => $data['shipping_address_line2'] ?? '',
                    'city' => $data['shipping_city'] ?? '',
                    'country_code' => $data['country_code'] ?? 'SA',
                    'phone_number' => $data['customer_phone'],
                ],
                'discount' => [
                    'amount' => $data['discount_amount'] ?? 0,
                    'name' => $data['discount_name'] ?? 'Discount',
                ],
                'tax_amount' => [
                    'amount' => $data['tax_amount'] ?? 0,
                    'currency' => $data['currency'] ?? $this->default_currency,
                ],
                'shipping_amount' => [
                    'amount' => $data['shipping_amount'] ?? 0,
                    'currency' => $data['currency'] ?? $this->default_currency,
                ],
                'merchant_url' => [
                    'success' => config("payments.success_url"),
                    'failure' => config("payments.failed_url"),
                    'cancel' => config("payments.cancel_url"),
                    'notification' =>  config("payments.callback_url"),
                ],
                'platform' => 'Laravel',
                'is_mobile' => $data['is_mobile'] ?? false,
                'risk_assessment' => [
                    'customer_age' => $data['customer_age'] ?? null,
                    'customer_dob' => $data['customer_dob'] ?? null,
                    'customer_gender' => $data['customer_gender'] ?? null,
                    'customer_nationality' => $data['customer_nationality'] ?? null,
                    'is_pre_ordered' => $data['is_pre_ordered'] ?? false,
                    'is_guest_checkout' => $data['is_guest_checkout'] ?? true,
                    'has_delivered_order' => $data['has_delivered_order'] ?? false,
                    'is_existing_customer' => $data['is_existing_customer'] ?? false,
                    'order_history_length' => $data['order_history_length'] ?? 0,
                    'customer_account_creation_date' => $data['customer_account_creation_date'] ?? null,
                ],
            ];
            // Log the request payload
            // Log::debug('Tamara payment request', ['payload' => $payload]);

            // Send request to Tamara API to create a checkout
            $response = $this->buildRequest('POST', '/checkout', $payload);
            $responseData = $response->getData(true);

            // Log the response
            // Log::debug('Tamara payment response', ['response' => $responseData]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300 && isset($responseData['checkout_url'])) {
                return [
                    'success' => true,
                    'url' => $responseData['checkout_url'],
                    'session_id' => $responseData['checkout_id'] ?? null,
                    'order_id' => $responseData['order_id'] ?? null,
                ];
            }

            // Log::warning('Tamara payment failed', [
            //     'status_code' => $response->getStatusCode(),
            //     'response' => $responseData
            // ]);

            return [
                'success' => false,
                'message' => $responseData['message'] ?? 'Failed to create Tamara payment session',
                'response' => $responseData,
            ];
        } catch (\Exception $e) {
            Log::error('Tamara payment error', [
                'message' => $e->getMessage(),
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
     * Extract first name from full name
     *
     * @param string $fullName
     * @return string
     */
    private function extractFirstName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return $parts[0] ?? '';
    }

    /**
     * Extract last name from full name
     *
     * @param string $fullName
     * @return string
     */
    private function extractLastName(string $fullName): string
    {
        $parts = explode(' ', trim($fullName));
        return count($parts) > 1 ? implode(' ', array_slice($parts, 1)) : '';
    }

    /**
     * Format items for Tamara API
     *
     * @param array $items
     * @return array
     */
    private function formatItems(array $items): array
    {
        $formattedItems = [];

        foreach ($items as $item) {
            $quantity = $item['quantity'] ?? 1;
            $unitPrice = $item['unit_price'] ?? 0;
            $discountAmount = $item['discount_amount'] ?? 0;
            $taxAmount = $item['tax_amount'] ?? 0;
            $currency = $item['currency'] ?? $this->default_currency;

            $totalAmount = ($unitPrice * $quantity) - $discountAmount;

            $formattedItems[] = [
                'reference_id' => $item['reference_id'] ?? uniqid('item_'),
                'type' => $item['type'] ?? 'physical',
                'name' => $item['title'] ?? $item['name'] ?? 'Product',
                'sku' => $item['sku'] ?? $item['reference_id'] ?? uniqid(),
                'quantity' => $quantity,
                'unit_price' => [
                    'amount' => $unitPrice,
                    'currency' => $currency,
                ],
                'discount_amount' => [
                    'amount' => $discountAmount,
                    'currency' => $currency,
                ],
                'tax_amount' => [
                    'amount' => $taxAmount,
                    'currency' => $currency,
                ],
                'total_amount' => [
                    'amount' => $totalAmount,
                    'currency' => $currency,
                ],
                'image_url' => $item['image_url'] ?? null,
                'category' => $item['category'] ?? null,
            ];
        }

        return $formattedItems;
    }

    /**
     * Handle callback from Tamara
     *
     * @param Request $request
     * @return PaymentResponse
     */
    public function callBack(Request $request): PaymentResponse
    {
        try {
            $data = $request->all();

            // Log the callback data for debugging
            Log::info('Tamara callback received', ['data' => $data]);

            // Default values for failed response
            $success = false;
            $status = 'failed';
            $orderId = $data['order_id'] ?? null;
            $amount = null;
            $currency = null;
            $rawResponse = $data;

            // Verify the webhook signature if notification_key is set
            if ($this->notification_key) {
                $signature = $request->header('Signature');
                if (!$this->verifyWebhookSignature($signature, json_encode($data))) {
                    Log::warning('Tamara invalid webhook signature', [
                        'signature' => $signature,
                        'data' => $data
                    ]);
                    return new PaymentResponse(
                        false,
                        'signature_failed',
                        $orderId,
                        $amount,
                        $currency,
                        $this->name,
                        $rawResponse
                    );
                }
            }

            // Verify the payment status
            if (isset($data['order_id'])) {
                $orderStatus = $this->getOrderStatus($data['order_id']);
                Log::info('Tamara order status', ['status' => $orderStatus]);

                // Update raw response with order status
                $rawResponse = array_merge($data, ['order_status' => $orderStatus]);

                // Extract payment details
                $orderId = $data['order_id'];

                // Extract amount and currency if available
                if (isset($orderStatus['order_value'])) {
                    $amount = (float)($orderStatus['order_value']['amount'] ?? null);
                    $currency = $orderStatus['order_value']['currency'] ?? null;
                }

                // If payment is approved or captured, consider it successful
                if (isset($orderStatus['status'])) {
                    $validStatuses = ['approved', 'captured', 'fully_captured'];
                    if (in_array($orderStatus['status'], $validStatuses)) {
                        Log::info('Tamara payment successful', [
                            'order_id' => $data['order_id'],
                            'status' => $orderStatus['status']
                        ]);
                        $success = true;
                        $status = 'success';
                    } else {
                        Log::warning('Tamara payment not successful', [
                            'order_id' => $data['order_id'],
                            'status' => $orderStatus['status']
                        ]);
                        $status = $orderStatus['status'];
                    }
                } else {
                    Log::warning('Tamara order status missing status field', [
                        'order_id' => $data['order_id'],
                        'response' => $orderStatus
                    ]);
                    $status = 'unknown';
                }
            } else {
                Log::warning('Tamara callback missing order_id', ['data' => $data]);
                $status = 'missing_order_id';
            }

            return new PaymentResponse(
                $success,
                $status,
                $orderId,
                $amount,
                $currency,
                $this->name,
                $rawResponse
            );
        } catch (\Exception $e) {
            // Log the error
            Log::error('Tamara callback error', [
                'message' => $e->getMessage(),
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
     * Verify webhook signature
     *
     * @param string|null $signature
     * @param string $payload
     * @return bool
     */
    private function verifyWebhookSignature(?string $signature, string $payload): bool
    {
        if (empty($signature) || empty($this->notification_key)) {
            return false;
        }

        $expectedSignature = hash_hmac('sha256', $payload, $this->notification_key);
        return hash_equals($expectedSignature, $signature);
    }

    /**
     * Get the status of an order
     *
     * @param string $orderId
     * @return array
     */
    public function getOrderStatus(string $orderId): array
    {
        try {
            Log::info('Getting Tamara order status', ['order_id' => $orderId]);

            if (empty($orderId)) {
                Log::warning('Empty order ID provided for status check');
                return [
                    'status' => 'ERROR',
                    'message' => 'Empty order ID provided'
                ];
            }

            $response = $this->buildRequest('GET', "/orders/{$orderId}");
            $responseData = $response->getData(true);

            // Log::debug('Tamara order status response', ['response' => $responseData]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return $responseData;
            } else {
                Log::warning('Failed to get Tamara order status', [
                    'order_id' => $orderId,
                    'status_code' => $response->getStatusCode(),
                    'response' => $responseData
                ]);
                return [
                    'status' => 'ERROR',
                    'message' => $responseData['message'] ?? 'Failed to get order status',
                    'code' => $response->getStatusCode()
                ];
            }
        } catch (\Exception $e) {

            // dd($e->getMessage());
            Log::error('Tamara order status error', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'status' => 'ERROR',
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Authorize an order with Tamara
     *
     * @param string $orderId
     * @return array
     */
    public function authorizeOrder(string $orderId): array
    {
        try {
            Log::info('Authorizing Tamara order', ['order_id' => $orderId]);

            if (empty($orderId)) {
                Log::warning('Empty order ID provided for authorization');
                return [
                    'success' => false,
                    'message' => 'Empty order ID provided'
                ];
            }

            $response = $this->buildRequest('POST', "/orders/{$orderId}/authorise");
            $responseData = $response->getData(true);

            Log::debug('Tamara authorization response', ['response' => $responseData]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return [
                    'success' => true,
                    'data' => $responseData
                ];
            } else {
                // Log::warning('Failed to authorize Tamara order', [
                //     'order_id' => $orderId,
                //     'status_code' => $response->getStatusCode(),
                //     'response' => $responseData
                // ]);
                return [
                    'success' => false,
                    'message' => $responseData['message'] ?? 'Authorization failed',
                    'code' => $response->getStatusCode(),
                    'response' => $responseData
                ];
            }
        } catch (\Exception $e) {
            // Log::error('Tamara authorization error', [
            //     'order_id' => $orderId,
            //     'message' => $e->getMessage(),
            //     'trace' => $e->getTraceAsString()
            // ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Capture an authorized payment
     *
     * @param string $orderId
     * @param float $amount
     * @return array
     */
    public function capturePayment(string $orderId, float $amount): array
    {
        try {
            Log::info('Capturing Tamara payment', [
                'order_id' => $orderId,
                'amount' => $amount
            ]);

            // Validate parameters
            if (empty($orderId)) {
                Log::warning('Empty order ID provided for capture');
                return [
                    'success' => false,
                    'message' => 'Empty order ID provided'
                ];
            }

            if ($amount <= 0) {
                Log::warning('Invalid amount provided for capture', ['amount' => $amount]);
                return [
                    'success' => false,
                    'message' => 'Amount must be greater than zero'
                ];
            }

            $payload = [
                'amount' => [
                    'amount' => $amount,
                    'currency' => $this->default_currency
                ],
                'shipping_info' => [
                    'shipped_at' => date('Y-m-d\TH:i:s\Z'),
                    'shipping_company' => 'Default Shipping',
                    'tracking_number' => uniqid('track_'),
                    'tracking_url' => null
                ]
            ];

            $response = $this->buildRequest('POST', "/orders/{$orderId}/capture", $payload);
            $responseData = $response->getData(true);

            Log::debug('Tamara capture response', ['response' => $responseData]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return [
                    'success' => true,
                    'data' => $responseData
                ];
            } else {
                Log::warning('Failed to capture Tamara payment', [
                    'order_id' => $orderId,
                    'amount' => $amount,
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
            Log::error('Tamara capture error', [
                'order_id' => $orderId,
                'amount' => $amount,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }

    /**
     * Cancel an order
     *
     * @param string $orderId
     * @return array
     */
    public function cancelOrder(string $orderId): array
    {
        try {
            Log::info('Cancelling Tamara order', ['order_id' => $orderId]);

            // Validate parameters
            if (empty($orderId)) {
                Log::warning('Empty order ID provided for cancellation');
                return [
                    'success' => false,
                    'message' => 'Empty order ID provided'
                ];
            }

            $payload = [
                'total_amount' => [
                    'amount' => 0,
                    'currency' => $this->default_currency
                ]
            ];

            $response = $this->buildRequest('POST', "/orders/{$orderId}/cancel", $payload);
            $responseData = $response->getData(true);

            Log::debug('Tamara cancellation response', ['response' => $responseData]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return [
                    'success' => true,
                    'data' => $responseData
                ];
            } else {
                Log::warning('Failed to cancel Tamara order', [
                    'order_id' => $orderId,
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
            Log::error('Tamara cancellation error', [
                'order_id' => $orderId,
                'message' => $e->getMessage(),
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
     * @param string $orderId
     * @param float $amount
     * @return array
     */
    public function refundPayment(string $orderId, float $amount): array
    {
        try {
            Log::info('Refunding Tamara payment', [
                'order_id' => $orderId,
                'amount' => $amount
            ]);

            // Validate parameters
            if (empty($orderId)) {
                Log::warning('Empty order ID provided for refund');
                return [
                    'success' => false,
                    'message' => 'Empty order ID provided'
                ];
            }

            if ($amount <= 0) {
                Log::warning('Invalid amount provided for refund', ['amount' => $amount]);
                return [
                    'success' => false,
                    'message' => 'Amount must be greater than zero'
                ];
            }

            $payload = [
                'refund' => [
                    'amount' => [
                        'amount' => $amount,
                        'currency' => $this->default_currency
                    ],
                    'comment' => 'Refund requested by customer',
                    'reference_id' => uniqid('refund_')
                ]
            ];

            $response = $this->buildRequest('POST', "/orders/{$orderId}/refunds", $payload);
            $responseData = $response->getData(true);

            Log::debug('Tamara refund response', ['response' => $responseData]);

            if ($response->getStatusCode() >= 200 && $response->getStatusCode() < 300) {
                return [
                    'success' => true,
                    'data' => $responseData
                ];
            } else {
                Log::warning('Failed to refund Tamara payment', [
                    'order_id' => $orderId,
                    'amount' => $amount,
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
            Log::error('Tamara refund error', [
                'order_id' => $orderId,
                'amount' => $amount,
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return [
                'success' => false,
                'message' => $e->getMessage()
            ];
        }
    }
}
