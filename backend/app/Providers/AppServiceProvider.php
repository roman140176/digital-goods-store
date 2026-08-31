<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Delivery\HttpSupplier;
use App\Domain\Delivery\IssueOrderCode;
use App\Domain\Delivery\SupplierRegistry;
use Illuminate\Support\ServiceProvider;

final class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // Порядок в конфиге определяет предпочтение: сначала основной
        // поставщик, затем резервный.
        $this->app->singleton(SupplierRegistry::class, function (): SupplierRegistry {
            $timeout = (float) config('store.supplier_timeout');

            $suppliers = [];
            foreach ((array) config('store.suppliers') as $id => $url) {
                $suppliers[(string) $id] = new HttpSupplier((string) $id, (string) $url, $timeout);
            }

            return new SupplierRegistry($suppliers);
        });

        $this->app->bind(IssueOrderCode::class, fn ($app): IssueOrderCode => new IssueOrderCode(
            $app->make(SupplierRegistry::class),
            (int) config('store.supplier_attempts'),
            (int) config('store.delivery_lock_seconds'),
        ));
    }

    public function boot(): void
    {
        //
    }
}
