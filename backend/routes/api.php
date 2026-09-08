<?php

use App\Http\Controllers\CatalogController;
use App\Http\Controllers\DevController;
use App\Http\Controllers\OrderController;
use App\Http\Controllers\PaymentSimulatorController;
use App\Http\Controllers\ProductController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::get('/products', [ProductController::class, 'index']);

// Каталог с поиском и фильтрами: q/type/price_min/price_max/in_stock/seller,
// сортировка, постраничная выдача (7 и 5.1 спеки).
Route::get('/catalog', [CatalogController::class, 'index']);

// Все активные предложения позиции — альтернатива продавца и список
// предложений на карточке товара (5.1 спеки).
Route::get('/offers', [CatalogController::class, 'offers']);

Route::post('/orders', [OrderController::class, 'store']);
Route::get('/orders/{order}', [OrderController::class, 'show']);

// Принять изменившуюся цену предложения до оплаты (1.3 ТЗ, 6.6 спеки).
Route::post('/orders/{order}/reprice', [OrderController::class, 'reprice']);

// Вебхук платёжной системы. Идемпотентен, терпит дубли и нарушенный порядок.
Route::post('/webhook/payment', [WebhookController::class, 'handle']);

// Эмулятор оплаты: реального эквайринга нет, эта ручка шлёт вебхук по контракту.
Route::post('/dev/pay/{order}', [PaymentSimulatorController::class, 'pay']);

// Заказ с заранее известным id — только для сценария «вебхук раньше заказа».
Route::post('/dev/orders', [DevController::class, 'createOrderWithId']);

// Просрочить бронь сейчас — не ждать TTL ни в тестах, ни на демонстрации (5.4 спеки).
Route::post('/dev/reservations/{order}/expire', [DevController::class, 'expireReservation']);
