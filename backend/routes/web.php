<?php

use App\Http\Controllers\Admin\OfferAdminController;
use App\Http\Controllers\Admin\OrderAdminController;
use Illuminate\Support\Facades\Route;

Route::middleware('admin.token')->prefix('admin')->group(function (): void {
    Route::get('/orders', [OrderAdminController::class, 'index'])->name('admin.orders');
    Route::get('/promocodes', [OrderAdminController::class, 'promocodes'])->name('admin.promocodes');
    Route::post('/orders/{order}/redeliver', [OrderAdminController::class, 'redeliver'])->name('admin.redeliver');
    Route::post('/suppliers/{supplier}/restock', [OrderAdminController::class, 'restock'])->name('admin.restock');

    Route::post('/offers/{offer}/price', [OfferAdminController::class, 'price'])->name('admin.offers.price');
    Route::post('/offers/{offer}/stock', [OfferAdminController::class, 'stock'])->name('admin.offers.stock');
    Route::post('/offers/{offer}/toggle', [OfferAdminController::class, 'toggle'])->name('admin.offers.toggle');
    Route::post('/offers/{offer}/leave-one', [OfferAdminController::class, 'leaveOne'])->name('admin.offers.leave-one');
});
