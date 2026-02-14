<?php

use App\Http\Controllers\DiscordWebhookController;
use App\Http\Controllers\SignalWebhookController;
use App\Http\Controllers\TrackingController;
use App\Http\Controllers\WhatsAppWebhookController;
use Illuminate\Support\Facades\Route;

// Signal ingestion (public webhook — HMAC validated in controller)
Route::post('/signals/webhook', SignalWebhookController::class)->name('signals.webhook');

// WhatsApp webhook (verification + message ingestion)
Route::get('/signals/whatsapp', [WhatsAppWebhookController::class, 'verify'])->name('signals.whatsapp.verify');
Route::post('/signals/whatsapp', [WhatsAppWebhookController::class, 'handle'])->name('signals.whatsapp.handle');

// Discord interactions endpoint (Ed25519 validated in controller)
Route::post('/signals/discord', DiscordWebhookController::class)->name('signals.discord');

// Tracking endpoints (public, no auth)
Route::get('/track/click', [TrackingController::class, 'click'])->name('track.click');
Route::get('/track/pixel', [TrackingController::class, 'pixel'])->name('track.pixel');
