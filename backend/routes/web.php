<?php

use App\Http\Controllers\Admin\OrderAdminController;
use Illuminate\Support\Facades\Route;

Route::middleware('admin.token')->prefix('admin')->group(function (): void {
    Route::get('/orders', [OrderAdminController::class, 'index'])->name('admin.orders');
    Route::get('/promocodes', [OrderAdminController::class, 'promocodes'])->name('admin.promocodes');
    Route::post('/orders/{order}/redeliver', [OrderAdminController::class, 'redeliver'])->name('admin.redeliver');
    Route::post('/suppliers/{supplier}/restock', [OrderAdminController::class, 'restock'])->name('admin.restock');
});
