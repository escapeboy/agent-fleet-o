<?php

use App\Http\Controllers\Api\V1\OpenAiCompatibleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| OpenAI-Compatible API Routes
|--------------------------------------------------------------------------
|
| These routes implement an OpenAI-compatible API surface so that any tool
| or SDK configured for the OpenAI API (Cursor, Continue, LiteLLM, etc.)
| can point at FleetQ as a drop-in replacement.
|
| Base URL for clients: https://fleetq.net/v1
|
*/

Route::middleware(['auth:sanctum', 'throttle:api'])->group(function () {
    Route::get('/models', [OpenAiCompatibleController::class, 'listModels'])
        ->name('openai.models.list');

    Route::get('/models/{model}', [OpenAiCompatibleController::class, 'retrieveModel'])
        ->where('model', '.*')
        ->name('openai.models.retrieve');

    Route::post('/chat/completions', [OpenAiCompatibleController::class, 'chatCompletions'])
        ->name('openai.chat.completions');
});
