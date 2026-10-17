<?php

use App\Http\Controllers\Api\IntegrationAnalyticsController;
use App\Http\Controllers\Api\IntegrationTicketController;
use App\Http\Middleware\EnsureBearerToken;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureBearerToken::class, 'auth:sanctum'])->group(function () {
    Route::post('/integrations/tickets', [IntegrationTicketController::class, 'store'])
        ->name('api.integrations.tickets');
    Route::get('/integrations/boards', [IntegrationAnalyticsController::class, 'boards'])
        ->name('api.integrations.boards');
    Route::get('/integrations/boards/{board}/analytics', [IntegrationAnalyticsController::class, 'show'])
        ->name('api.integrations.boards.analytics');
});
