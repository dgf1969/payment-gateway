<?php

namespace Arafa\Payments;

use Illuminate\Foundation\AliasLoader;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\ServiceProvider;

class PaymentServiceProvider extends ServiceProvider
{
    public function register()
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/payments.php', 'payments');

        $this->app->singleton(PaymentManager::class, function ($app) {
            return new PaymentManager($app);
        });

        // register alias for facade
        $this->app->alias(PaymentManager::class, 'payment.manager');

        $this->app->booting(function () {
            AliasLoader::getInstance()->alias('Payment', \Arafa\Payments\Facades\Payment::class);
        });
    }


    public function boot()
    {
        // Publish config
        $this->publishes([
            __DIR__ . '/../config/payments.php' => config_path('payments.php'),
        ], 'config');

        
        //       $this->publishes([
        //     __DIR__ . '/PaymentServiceProvider.php' => app_path('Providers/PaymentServiceProvider.php'),
        // ], 'provider');

        // $this->app->booted(function () {                                                                                                                                                                     
        //     $host = request()->getSchemeAndHttpHost(); // e.g https://example.com

        //     \Illuminate\Support\Facades\Config::set('payments.callback_url', $host . '/payment/callback');
        //     \Illuminate\Support\Facades\Config::set('payments.success_url', $host . '/payment-success');
        //     \Illuminate\Support\Facades\Config::set('payments.failed_url', $host . '/payment-failed');
        // });
    }
}
