<?php

use App\Http\Controllers\Api\AdminStateController;
use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\DigiflazzProductController;
use App\Http\Controllers\Api\MobileLegendsController;
use App\Http\Controllers\Api\PakasirController;
use App\Http\Controllers\Api\UploadController;
use Illuminate\Support\Facades\Route;

Route::prefix('auth')->group(function (): void {
    Route::post('/login', [AuthController::class, 'login'])->middleware('throttle:10,1');
    Route::post('/register', [AuthController::class, 'register'])->middleware('throttle:5,1');
    Route::middleware('jwt.auth')->group(function (): void {
        Route::get('/me', [AuthController::class, 'me']);
        Route::patch('/profile', [AuthController::class, 'updateProfile']);
        Route::put('/password', [AuthController::class, 'changePassword']);
        Route::delete('/account', [AuthController::class, 'destroy']);
        Route::post('/logout', [AuthController::class, 'logout']);
    });
});

Route::get('/store/state', [AdminStateController::class, 'publicState']);
Route::get('/orders/{orderId}', [AdminStateController::class, 'publicOrder'])->middleware('throttle:30,1');
Route::get('/products/mobile-legends', [DigiflazzProductController::class, 'mobileLegends'])
    ->middleware('throttle:30,1');
Route::post('/game/mobile-legends/check', [MobileLegendsController::class, 'check'])
    ->middleware('throttle:20,1');
Route::post('/payments/pakasir/create', [PakasirController::class, 'create'])
    ->middleware('throttle:10,1');
Route::get('/payments/pakasir/{orderId}', [PakasirController::class, 'show'])
    ->middleware('throttle:20,1');
Route::post('/payments/pakasir/webhook', [PakasirController::class, 'webhook'])
    ->middleware('throttle:60,1');

Route::prefix('admin')->middleware(['jwt.auth', 'admin'])->group(function (): void {
    Route::post('/digiflazz/sync', [DigiflazzProductController::class, 'sync'])
        ->middleware('throttle:10,1');
    Route::post('/uploads', [UploadController::class, 'store']);
    Route::get('/state', [AdminStateController::class, 'show']);
    Route::get('/customers', [AdminStateController::class, 'customers']);
    Route::put('/state', [AdminStateController::class, 'update']);
});
