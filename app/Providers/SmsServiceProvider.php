<?php

namespace App\Providers;

use App\Services\Messaging\Messenger;
use App\Services\MessengerSms;
use App\Services\SmsServiceInterface;
use Illuminate\Support\ServiceProvider;

class SmsServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(Messenger::class);
        $this->app->bind(SmsServiceInterface::class, MessengerSms::class);
    }
}
