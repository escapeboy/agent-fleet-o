<?php

use App\Http\Controllers\Api\Chatbot\ChatController;
use App\Http\Middleware\AuthenticateChatbotToken;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Chatbot Widget API Routes
|--------------------------------------------------------------------------
|
| Public-facing API for embeddable chatbot widgets.
| Authentication uses per-chatbot bearer tokens (SHA-256 hashed).
| These routes do NOT use Sanctum — they use AuthenticateChatbotToken.
|
*/

Route::prefix('chatbot')
    ->middleware([AuthenticateChatbotToken::class, 'throttle:60,1'])
    ->group(function () {
        Route::post('/sessions', [ChatController::class, 'createSession']);
        Route::post('/sessions/{session}/messages', [ChatController::class, 'sendMessage']);
        Route::post('/sessions/{session}/messages/stream', [ChatController::class, 'sendMessageStream']);
        Route::get('/sessions/{session}/events', [ChatController::class, 'events']);
    });
