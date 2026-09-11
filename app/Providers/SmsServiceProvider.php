<?php

namespace App\Providers;

use App\Services\SmsLogger;
use App\Services\TwilioService;
use App\Services\SmsServiceInterface;
use Illuminate\Support\ServiceProvider;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->bind(SmsServiceInterface::class, function () {
            return app()->environment('production') 
                ? new TwilioService() 
                : new SmsLogger();
        });
    }
}