<?php

return [

    'callback_url' => 'https://example.com/example_callback_url',
    'success_url'  => 'https://example.com/example_success_url',
    'failed_url'   => 'https://example.com/example_failed_url',


    'gateways' => [
        'urway'      => \Arafa\Payments\Gateways\UrwayService::class,
        'stripe'     => \Arafa\Payments\Gateways\StripeService::class,
        'myfatoorah' => \Arafa\Payments\Gateways\MyFatoorahService::class,
        'tabby'      => \Arafa\Payments\Gateways\TabbyService::class,
        'fawry'      => \Arafa\Payments\Gateways\FawryService::class,
        'geidea'     => \Arafa\Payments\Gateways\GeideaService::class,
        'paymob'     => \Arafa\Payments\Gateways\PaymobService::class,
        'hyperpay'   => \Arafa\Payments\Gateways\HyperpayService::class,
        'clickpay'   => \Arafa\Payments\Gateways\ClickpayService::class,
        'moyasar'    => \Arafa\Payments\Gateways\MoyasarService::class,
        'tap'        => \Arafa\Payments\Gateways\TapService::class,
        'paypal'     => \Arafa\Payments\Gateways\PaypalService::class,
        'tamara'     => \Arafa\Payments\Gateways\TamaraService::class,
        'telr'       => \Arafa\Payments\Gateways\TelrService::class,
        'alrajhibank'=> \Arafa\Payments\Gateways\AlrajhibankService::class,
        // ...
    ],


    // modes test, live
    'myfatoorah' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_api_key'          => '',
        'live_base_url'         => '',
        'live_api_key'          => '',
    ],

    'paymob' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_iframe_id'        => '',
        'test_iframe_link'      => '',
        'live_base_url'         => '',
        'iframe_id'             => '',
        'iframe_link'           => '',
        'version'               => '',
        'integrations_id'       => '',
        'test_api_key'          => ''
    ],

    'moyasar' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_api_key'          => '',
        'live_base_url'         => '',
        'live_api_key'          => '',
    ],

    'stripe' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_api_key'          => '',
        'live_base_url'         => '',
        'live_api_key'          => '',
    ],

    'tap' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_api_key'          => '',
        'live_base_url'         => '',
        'live_api_key'          => '',
    ],

    'alrajhibank' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_transportal_id'   => '',
        'test_password'         => '',
        'test_encryption_key'   => '',
        'test_iv'               => '',
    ],

    'hyperpay' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_api_key'          => '',
        'live_base_url'         => '',
        'live_api_key'          => '',
        'entity_id'             => '',
        'checkout_url'          => '',
    ],

    'paypal' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_client_secret'    => '',
        'test_client_id'        => '',
        'live_base_url'         => '',
        'live_client_secret'    => '',
    ],

    'fawry' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_merchant_code'    => '',
        'test_security_key'     => '',
        'live_base_url'         => '',
        'live_merchant_code'    => '',
        'live_security_key'     => '',
    ],

    'clickpay' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_server_key'       => '',
        'test_client_key'       => '',
        'live_base_url'         => '',
        'live_server_key'       => '',
        'live_client_key'       => '',
        'merchant_id'           => '',
    ],

    'telr' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_store_id'         => '',
        'test_auth_key'         => '',
        'live_base_url'         => '',
        'live_store_id'         => '',
        'live_auth_key'         => '',
        'currency'              => '',
        'merchant_id'           => '',
    ],

    'geidea' => [
        'mode'                  => '',
        'base_url'              => '',
        'test_api_key'          => '',
        'test_password'         => '',
        'live_base_url'         => '',
        'live_api_key'          => '',
    ],

    'tabby' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_api_key'          => '',
        'live_base_url'         => '',
        'live_api_key'          => '',
        'merchant_id'           => '',
        'merchant_code'         => '',

    ],

    'tamara' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_api_key'          => '',
        'test_notification_key' => '',
        'live_base_url'         => '',
        'live_api_key'          => '',
        'live_notification_key' => '',
        'merchant_id'           => '',
    ],

    'urway' => [
        'mode'                  => '',
        'test_base_url'         => '',
        'test_terminal_id'      => '',
        'test_merchant_key'     => '',
        'test_password'         => '',
        'live_base_url'         => '',
        'live_terminal_id'      => '',
        'live_merchant_key'     => '',
        'live_password'         => '',
        'merchant_id'           => '',
        'currency'              => '',
    ],

];
