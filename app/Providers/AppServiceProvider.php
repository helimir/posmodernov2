<?php

namespace App\Providers;

use App\Domain\Accounting\LedgerWriter;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Fiscal\ControlNumberGenerator;
use App\Domain\Fiscal\NumberSeries;
use App\Domain\Fiscal\TaxRateResolver;
use App\Domain\Inventory\LotConsumer;
use App\Domain\Inventory\StockInvariant;
use App\Domain\Sales\SalePaymentStatus;
use Illuminate\Database\Connection;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        // El CHECKOUT y el CREDITO se construyen aqui, con TODOS sus
        // colaboradores en la MISMA conexion: es lo que hace que la
        // transaccion de la venta sea una sola. El contenedor no puede
        // resolverlos solo (el `NumberSeries` depende de `ConnectionInterface`,
        // que no esta registrado), asi que se registramos aqui de forma
        // explicita.
        $this->app->singleton(CheckoutService::class, function ($app): CheckoutService {
            $connection = $app->make(Connection::class);

            return new CheckoutService(
                $connection,
                new NumberSeries($connection),
                new ControlNumberGenerator($connection, new NumberSeries($connection)),
                new TaxRateResolver($connection),
                new LotConsumer($connection, new StockInvariant($connection)),
                new LedgerWriter($connection),
                new SalePaymentStatus($connection),
            );
        });

        $this->app->singleton(CustomerBalanceService::class, function ($app): CustomerBalanceService {
            return new CustomerBalanceService($app->make(Connection::class));
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
