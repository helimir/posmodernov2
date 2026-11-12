<?php

namespace App\Providers;

use App\Domain\Accounting\CashDay;
use App\Domain\Accounting\CashRegisterService;
use App\Domain\Accounting\LedgerWriter;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Credit\CustomerBalanceService;
use App\Domain\Credit\CustomerPaymentService;
use App\Domain\Fiscal\Contracts\ElectronicInvoiceProvider;
use App\Domain\Fiscal\ControlNumberGenerator;
use App\Domain\Fiscal\FiscalIssuanceCoordinator;
use App\Domain\Fiscal\FiscalIssuanceRunner;
use App\Domain\Fiscal\FiscalIssuanceService;
use App\Domain\Fiscal\FiscalProviderResolver;
use App\Domain\Fiscal\FiscalProviderScope;
use App\Domain\Fiscal\NumberSeries;
use App\Domain\Fiscal\TaxRateResolver;
use App\Domain\Inventory\LotConsumer;
use App\Domain\Inventory\StockInvariant;
use App\Domain\Sales\SalePaymentStatus;
use App\Support\Pos\PosBudget;
use Illuminate\Contracts\Container\Container;
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
                new CustomerBalanceService($connection),
            );
        });

        $this->app->singleton(CustomerBalanceService::class, function ($app): CustomerBalanceService {
            return new CustomerBalanceService($app->make(Connection::class));
        });

        // El COBRO de un cliente: el movimiento de su libro y el asiento
        // contable, en la MISMA transaccion. Los dos colaboradores usan la
        // MISMA conexion que el cobro, asi que el savepoint del libro del
        // cliente y el del asiento son el mismo.
        $this->app->singleton(CustomerPaymentService::class, function ($app): CustomerPaymentService {
            return new CustomerPaymentService(
                $app->make(Connection::class),
                $app->make(CustomerBalanceService::class),
                $app->make(LedgerWriter::class),
            );
        });

        // El PRESUPUESTO de la caja: el total que calcula el motor de
        // impuestos SIN cobrar. Se construye aqui, con la MISMA conexion que
        // el checkout: el resolvedor de tasas lee de la base de datos, y con
        // otra conexion no veria la transaccion de la venta.
        $this->app->singleton(PosBudget::class, function ($app): PosBudget {
            $connection = $app->make(Connection::class);

            return new PosBudget($connection, new TaxRateResolver($connection));
        });

        // La CAJA DEL DIA: el registro de caja y las cifras que se derivan de
        // los asientos y de los pagos.
        //
        // Los dos se construyen aqui, con la MISMA conexion que el checkout:
        // el saldo en curso se deriva de los asientos, y leerlos por otra
        // conexion seria leer una foto de la venta en vez de la venta.
        $this->app->singleton(CashRegisterService::class, function ($app): CashRegisterService {
            return new CashRegisterService($app->make(Connection::class));
        });

        $this->app->singleton(CashDay::class, function ($app): CashDay {
            return new CashDay($app->make(Connection::class));
        });

        // La EMISION FISCAL (T2).
        //
        // EL PROVEEDOR SE RESUELVE, NO SE FIJA: el binding del contenedor no
        // elige una implementacion, la deduce de las FILAS del perfil
        // (entorno, proveedor y tipo). El ambito dice de que venta es la
        // emision, y sin ambito no hay canal que resolver.
        //
        // El ambito es SCOPED: vive lo que dura una peticion, y no se filtra
        // de una emision a la siguiente.
        $this->app->scoped(FiscalProviderScope::class);

        $this->app->bind(ElectronicInvoiceProvider::class, function (Container $app): ElectronicInvoiceProvider {
            $ambito = $app->make(FiscalProviderScope::class);

            return $app->make(FiscalProviderResolver::class)
                ->resolve($ambito->documentSeriesId(), $ambito->saleId());
        });

        // El COORDINADOR no es singleton: se construye con el proveedor que
        // este en curso, y no con el de la emision anterior.
        $this->app->bind(FiscalIssuanceCoordinator::class, function (Container $app): FiscalIssuanceCoordinator {
            $connection = $app->make(Connection::class);

            return new FiscalIssuanceCoordinator(
                $connection,
                new FiscalIssuanceService($connection, $app->make(ElectronicInvoiceProvider::class)),
                new SalePaymentStatus($connection),
            );
        });

        // El EMISOR: el que dispara T2 y convierte el resultado en el estado
        // fiscal que viaja en la respuesta del cobro.
        $this->app->bind(FiscalIssuanceRunner::class, function (Container $app): FiscalIssuanceRunner {
            return new FiscalIssuanceRunner($app->make(Connection::class), $app);
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
