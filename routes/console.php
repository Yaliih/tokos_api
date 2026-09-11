<?php

use App\Models\VerificationCode;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

// ⭐ Scheduling des commandes
Schedule::command('verification:clean')->everyTenMinutes();

// Alternative avec une closure
Schedule::call(function () {
    VerificationCode::cleanExpired();
})->everyTenMinutes()->name('clean-verification-codes');

// Si vous voulez garder la commande
Schedule::command('verification:clean')->everyTenMinutes();