<?php

use App\Http\Controllers\Api\AdminStateController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DigiflazzProductController;
use App\Http\Controllers\Api\MobileLegendsController;
use App\Http\Controllers\Api\PakasirController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:auth-login');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:auth-register');
    Route::middleware(['jwt.auth', 'throttle:account'])->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
        Route::get('/profile-extras', [AuthController::class, 'profileExtras']);
        Route::put('/profile-extras', [AuthController::class, 'updateProfileExtras']);
        Route::get('/data', [AdminStateController::class, 'customerData']);
        Route::put('/password', [AuthController::class, 'changePassword']);
        Route::delete('/account', [AuthController::class, 'destroy']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::get('/store/state', [AdminStateController::class, 'publicState'])->middleware('throttle:public-api');
Route::get('/orders/{orderId}', [AdminStateController::class, 'publicOrder'])
    ->where('orderId', '[A-Za-z0-9-]{1,100}')
    ->middleware('throttle:invoice');
Route::get('/store/orders/recent', [AdminStateController::class, 'recentOrders'])->middleware('throttle:public-api');
Route::get('/products/mobile-legends', [DigiflazzProductController::class, 'mobileLegends'])
    ->middleware('throttle:public-api');
Route::post('/game/mobile-legends/check', [MobileLegendsController::class, 'check'])
    ->middleware('throttle:account-check');
Route::post('/payments/pakasir/create', [PakasirController::class, 'create'])
    ->middleware('throttle:payment-create');
Route::post('/payments/pakasir/{orderId}/simulate', [PakasirController::class, 'simulate'])
    ->where('orderId', '[A-Za-z0-9-]{1,100}')
    ->middleware('throttle:payment-create');
Route::get('/payments/pakasir/{orderId}', [PakasirController::class, 'show'])
    ->where('orderId', '[A-Za-z0-9-]{1,100}')
    ->middleware('throttle:invoice');
Route::post('/payments/pakasir/webhook', [PakasirController::class, 'webhook'])
    ->middleware('throttle:webhook');

Route::prefix('admin')->middleware(['jwt.auth', 'admin', 'throttle:admin-api'])->group(function (): void {
    Route::post('/digiflazz/sync', [DigiflazzProductController::class, 'sync'])
        ->middleware('throttle:10,1');
    Route::post('/uploads', [UploadController::class, 'store'])->middleware('throttle:10,1');
    Route::get('/state', [AdminStateController::class, 'show']);
    Route::get('/operations', [AdminStateController::class, 'operations']);
    Route::get('/customers', [AdminStateController::class, 'customers']);
    Route::put('/state', [AdminStateController::class, 'update'])->middleware('throttle:10,1');
    Route::put('/operations', [AdminStateController::class, 'updateOperations'])->middleware('throttle:10,1');
});
