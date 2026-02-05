<?php

use App\Http\Controllers\AuthController;
use App\Http\Controllers\BoardController;
use App\Http\Controllers\ItemController;
use App\Http\Controllers\UserController;
use App\Http\Controllers\UserManagementController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    if (auth()->check()) {
        return redirect()->route('boards.index');
    }
    return redirect()->route('login');
})->name('home');

Route::get('/login', [AuthController::class, 'showLogin'])->name('login');
Route::post('/logout', [AuthController::class, 'logout'])->name('logout');

Route::get('/auth/microsoft', [AuthController::class, 'redirectToMicrosoft'])->name('auth.microsoft');
Route::get('/auth/microsoft/callback', [AuthController::class, 'handleMicrosoftCallback'])->name('auth.microsoft.callback');

Route::middleware(['auth'])->group(function () {
    Route::get('/user-management', [UserManagementController::class, 'index'])->name('user-management.index');
    Route::put('/user-management/boards/{board}', [UserManagementController::class, 'update'])->name('user-management.update');
    Route::get('/boards', [BoardController::class, 'index'])->name('boards.index');
    Route::get('/boards/create', [BoardController::class, 'create'])->name('boards.create');
    Route::post('/boards', [BoardController::class, 'store'])->name('boards.store');
    Route::get('/api/boards/{board}/mentionable-users', [BoardController::class, 'mentionableUsers'])->name('api.boards.mentionable-users');
    Route::get('/api/users/{user}/photo', [UserController::class, 'getPhoto'])->name('api.users.photo');
    // Specific routes must come before general routes
    Route::get('/boards/{board}/ticket/{item}', [BoardController::class, 'showItem'])->name('boards.show.item');
    Route::get('/boards/{board}/export-csv', [BoardController::class, 'exportCsv'])->name('boards.export-csv');
    Route::get('/boards/{board}', [BoardController::class, 'show'])->name('boards.show');
    Route::post('/boards/{board}/filters', [BoardController::class, 'applyFilters'])->name('boards.filters.apply');
    Route::delete('/boards/{board}', [BoardController::class, 'destroy'])->name('boards.destroy');
    Route::post('/boards/{board}/items', [ItemController::class, 'store'])->name('items.store');
    Route::put('/boards/{board}/items/{item}', [ItemController::class, 'update'])->name('items.update');
    Route::post('/boards/{board}/items/{item}/move', [ItemController::class, 'move'])->name('items.move');
    Route::delete('/boards/{board}/items/{item}', [ItemController::class, 'destroy'])->name('items.destroy');
    Route::post('/boards/{board}/items/{item}/attachments', [ItemController::class, 'storeAttachment'])->name('items.attachments.store');
    Route::delete('/boards/{board}/items/{item}/attachments', [ItemController::class, 'deleteAttachment'])->name('items.attachments.destroy');
    Route::post('/boards/{board}/items/{item}/comments', [ItemController::class, 'storeComment'])->name('items.comments.store');
    Route::delete('/boards/{board}/items/{item}/comments/{comment}', [ItemController::class, 'destroyComment'])->name('items.comments.destroy');
});
