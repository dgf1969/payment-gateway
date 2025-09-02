# payment-gateway 💳

[![Latest Version on Packagist](https://img.shields.io/packagist/v/arafa/payments.svg?style=flat-square)](https://packagist.org/packages/arafa/payments)
[![Total Downloads](https://img.shields.io/packagist/dt/arafa/payments.svg?style=flat-square)](https://packagist.org/packages/arafa/payments)
[![License](https://img.shields.io/packagist/l/arafa/payments.svg?style=flat-square)](LICENSE)

## Table of Contents
- [Introduction](#introduction)
- [Features](#features)
- [Available Payment Gateways](#available-payment-gateways)
- [Installation](#installation)
- [Configuration](#configuration)
- [Usage](#usage)
- [Creating a New Payment Gateway](#creating-a-new-payment-gateway)
- [License](#license)

## 🚀 Introduction

**payment-gateway** is a PHP package that provides a unified way to integrate multiple payment gateways into your Laravel application. It simplifies the process of handling different payment providers by offering a standardized interface and response format. 

No more dealing with different API structures and response formats for each payment gateway! With payment-gateway, you can switch between payment providers with minimal code changes. ⚡

## ✨ Features

- **Unified Interface** - Interact with all payment gateways using the same methods
- **Standardized Responses** - All payment responses are normalized using the `PaymentResponse` class
- **Multiple Gateways** - Support for popular payment gateways in the MENA region and globally
- **Easy Configuration** - Simple configuration through Laravel's config system
- **Flexible Architecture** - Easily extend with your own payment gateway implementations
- **Error Handling** - Consistent error handling across all payment gateways
- **Test Mode Support** - Switch between test and live environments with a single config change

## 💳 Available Payment Gateways

The package currently supports the following payment gateways:

- **Moyasar** - Popular payment gateway in Saudi Arabia
- **Tap** - Kuwait-based payment gateway
- **Paymob** - Egyptian payment gateway
- **MyFatoorah** - Kuwait-based payment solution
- **AlRajhiBank** - Saudi bank payment gateway
- **Geidea** - Saudi-based payment processor
- **Stripe** - Global payment gateway
- **PayPal** - Global payment solution
- **Fawry** - Egyptian payment service
- **Tabby** - Buy now, pay later service
- **Hyperpay** - Payment gateway by HyperPay
- **ClickPay** - Middle East payment gateway
- **Telr** - UAE-based payment gateway
- **Urway** - Saudi payment gateway
- **Tamara** - Buy now, pay later service

## 📦 Installation

You can install the package via composer:

```bash
composer require arafadev/payment-gateways
```

## ⚙️ Configuration

After installation, publish the configuration file:

```bash
php artisan vendor:publish --provider="Arafa\Payments\PaymentServiceProvider" --tag="config" --force
```

This will create a `config/payments.php` file where you can configure your payment gateways.

### Configuration Example

```php
// config/payments.php
return [
    'callback_url' => 'https://your-domain.com/api/payment/callback',
    'success_url'  => 'https://your-domain.com/payment-success',
    'failed_url'   => 'https://your-domain.com/payment-failed',
    
    'moyasar' => [
        'mode'             => 'test', // or 'live'
        'test_base_url'    => 'https://api.moyasar.com',
        'test_api_key'     => 'your_test_api_key',
        'live_base_url'    => 'https://api.moyasar.com',
        'live_api_key'     => 'your_live_api_key',
        'callback_url'     => 'https://your-domain.com/callback',
    ],
    
    // Other gateway configurations...
];
```

## 🔍 Usage

### Basic Usage with MoyasarService

Here's an example of how to use the package with Moyasar payment gateway:

```php
<?php

namespace App\Http\Controllers;

use Illuminate\Http\Request;
use Arafa\Payments\PaymentManager;
use Payment;

class PaymentController extends Controller
{
    public function paymentProcess(Request $request)
    {
        $gateway = Payment::gateway('moyasar');

        $response = $gateway->sendPayment($request);

        return $response;
    }

    public function callBack(Request $request): \Illuminate\Http\RedirectResponse
    {

        $gateway = Payment::gateway('moyasar');

        $response = $gateway->callBack($request);

        if ($response->success) {
            return redirect()->route('your_success_route');
        }
        return redirect()->route('your_failed_route');
    }
}
```

You can send the following JSON data to Moyasar, either via **Postman**, **cURL**, or directly from your code:

```json
{
    "amount": 100,
    "currency": "SAR",
    "description": "this description"
}
```

### Using the PaymentResponse

The `PaymentResponse` class standardizes all payment gateway responses with the following properties:

```php
$response->success      // bool - Payment success indicator
$response->status       // string - Detailed status (success, failed, pending, etc.)
$response->unique_id    // string|null - Transaction ID
$response->amount       // float|null - Payment amount
$response->currency     // string|null - Currency code
$response->gateway_name // string - Payment gateway name
$response->raw          // array - Raw response data from the gateway
```

This standardization allows you to handle responses from different payment gateways in a consistent way.

## 🛠️ Creating a New Payment Gateway

To create your own payment gateway, you need to:

1. Create a new class that implements the `PaymentGatewayInterface`
2. Extend the `BasePaymentService` class for common functionality
3. Implement the required methods

Here's an example of a custom payment gateway:

```php
<?php

namespace App\Services\Payment;

use Illuminate\Http\Request;
use Arafa\Payments\Gateways\BasePaymentService;
use Arafa\Payments\Contracts\PaymentGatewayInterface;
use Arafa\Payments\Gateways\PaymentResponse;

class CustomGatewayService extends BasePaymentService implements PaymentGatewayInterface
{
    protected $name;
    protected $api_key;
    protected $base_url;
    protected array $header;
    
    public function __construct()
    {
        $this->name = 'custom_gateway';
        $this->mode = config("payments.{$this->name}.mode");
        $this->base_url = config("payments.{$this->name}.{$this->mode}_base_url");
        $this->api_key = config("payments.{$this->name}.{$this->mode}_api_key");
        $this->header = $this->buildHeader();
    }
    
    public function buildHeader(): array
    {
        return [
            'Authorization' => "Bearer {$this->api_key}",
            'Content-Type' => 'application/json',
            'Accept' => 'application/json'
        ];
    }
    
    public function sendPayment(Request $request)
    {
        // Implement your payment logic here
        $data = $request->all();
        $data['success_url'] = config("payments.callback_url");
        
        // Make API request to your payment gateway
        $response = $this->buildRequest('POST', '/api/payments', $data);
        
        // Return response with payment URL
        if ($response->getData(true)['success']) {
            return ['success' => true, 'url' => $response->getData(true)['payment_url']];
        }
        
        return ['success' => false, 'url' => null];
    }
    
    public function callBack(Request $request): PaymentResponse
    {
        try {
            // Extract data from request
            $raw = $request->all();
            
            // Determine success status
            $success = ($raw['status'] ?? '') === 'completed';
            
            // Extract payment status
            $status = $raw['status'] ?? 'unknown';
            
            // Extract transaction ID
            $transactionId = $raw['transaction_id'] ?? null;
            
            // Extract amount and currency
            $amount = $raw['amount'] ?? null;
            $currency = $raw['currency'] ?? null;
            
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
            // Handle exceptions
            \Log::error("Custom gateway callback error: " . $e->getMessage());
            
            return new PaymentResponse(
                success: false,
                status: 'error',
                unique_id: null,
                amount: null,
                currency: null,
                gateway_name: $this->name,
                raw: ['error' => $e->getMessage(), 'request' => $request->all()]
            );
        }
    }
}
```

## 📄 License

The payment-gateway package is open-sourced software licensed under the [MIT license](LICENSE).

For more details, please refer to the [LICENSE](LICENSE) file included with this package.
