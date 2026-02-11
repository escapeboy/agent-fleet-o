<?php

use App\Http\Controllers\SignalWebhookController;
use App\Http\Controllers\TrackingController;
use Illuminate\Support\Facades\Route;

// Signal ingestion (public webhook — HMAC validated in controller)
Route::post('/signals/webhook', SignalWebhookController::class)->name('signals.webhook');

// Tracking endpoints (public, no auth)
Route::get('/track/click', [TrackingController::class, 'click'])->name('track.click');
Route::get('/track/pixel', [TrackingController::class, 'pixel'])->name('track.pixel');
