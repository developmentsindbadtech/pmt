<?php

use App\Http\Controllers\Api\IntegrationTicketController;
use App\Http\Middleware\EnsureBearerToken;
use Illuminate\Support\Facades\Route;

Route::middleware([EnsureBearerToken::class, 'auth:sanctum'])->group(function () {
    Route::post('/integrations/tickets', [IntegrationTicketController::class, 'store'])
        ->name('api.integrations.tickets');
});
