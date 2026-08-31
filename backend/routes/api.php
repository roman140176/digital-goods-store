<?php

use App\Http\Controllers\DevController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentSimulatorController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductController::class, 'index']);

Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{order}', [OrderController::class, 'show']);

// Вебхук платёжной системы. Идемпотентен, терпит дубли и нарушенный порядок.
Route::post('/webhook/payment', [WebhookController::class, 'handle']);

// Эмулятор оплаты: реального эквайринга нет, эта ручка шлёт вебхук по контракту.
Route::post('/dev/pay/{order}', [PaymentSimulatorController::class, 'pay']);

// Заказ с заранее известным id — только для сценария «вебхук раньше заказа».
Route::post('/dev/orders', [DevController::class, 'createOrderWithId']);
