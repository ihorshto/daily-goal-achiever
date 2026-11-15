<?php

use App\Http\Controllers\HealthController;
use App\Http\Controllers\TelegramWebhookController;
use Illuminate\Support\Facades\Route;

Route::post('/telegram/webhook', TelegramWebhookController::class)
    ->middleware('throttle:telegram')
    ->name('telegram.webhook');

Route::get('/health', HealthController::class)->name('health');
