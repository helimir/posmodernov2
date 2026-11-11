<?php

namespace App\Console\Commands;

use App\Domain\Accounting\CashRegisterService;
use App\Domain\Accounting\LedgerWriter;
use App\Domain\Checkout\CheckoutInput;
use App\Domain\Checkout\CheckoutLineInput;
use App\Domain\Checkout\CheckoutPaymentInput;
use App\Domain\Checkout\CheckoutResult;
use App\Domain\Checkout\CheckoutService;
use App\Domain\Fiscal\ControlNumberGenerator;
use App\Domain\Fiscal\NumberSeries;
use App\Domain\Fiscal\TaxRateResolver;
use App\Domain\Inventory\LotConsumer;
use App\Domain\Inventory\StockInvariant;
use App\Domain\Sales\SalePaymentStatus;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxDocumentInput;
use App\Domain\Tax\TaxLineInput;
use App\Models\FiscalProfile;
use App\Models\PaymentMethod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Database\Seeders\DemoStoreSeeder;
use Database\Seeders\FiscalProfileSeeder;
use Database\Seeders\TaxonomySeeder;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use Illuminate\Support\Collection;
use RuntimeException;
use Throwable;

/**
 * El comando que HACE VER el dominio.
 *
 * REGLA que fija: *una costura que no se puede ver no es una costura*. La
 * Fase 0 tiene el motor de impuestos, el checkout atomico, el FIFO de lotes,
 * el libro contable y la numeracion fiscal: 152 tareas de dominio que solo
 * existen como pruebas. Este comando es la VISTA de todo eso: hace una venta
 * de PRUEBA REAL (la del `CheckoutService`, la misma que veria el navegador)
 * e imprime su anatomia completa.
 *
 * LO QUE NO HACE, Y POR QUE:
 *
 *  - NO simula la venta: llama al `CheckoutService` de verdad. Si el motor,
 *    el FIFO o el asiento fallan, el comando lo dice y sale con codigo
 *    distinto de cero. Como el checkout es atomico, un fallo no deja nada a
 *    medias.
 *  - NO reimprime lo que el servicio devolvio: RELee de la base de datos lo
 *    que se escribio, y eso es lo que ense\~na. Un comando que imprime su
 *    propia memoria miente cuando la escritura no fue la que creia.
 *  - NO inventa el escenario fiscal: si faltan los catalogues, llama a los
 *    SEMBRADORES del repo (que son idempotentes y son los duenos de esos
 *    datos). El dominio no inventa un plan de cuentas, y este comando tampoco
 *    inventa un perfil fiscal.
 *
 * El plan de cuentas SI es una dato de demostracion del comando: el dominio
 * lo recibe como entrada (`CheckoutInput::$accounts`) porque el dominio no
 * inventa un plan de cuentas. Aqui se declara uno de DEMOSTRACION, y se
 * IMPRIME, para que quien lo lea sepa que cuentas se usaron.
 */
class SmokeSaleCommand extends Command
{
    /**
     * La firma del comando.
     *
     * Las opciones son todas OPCIONALES: sin ninguna, el comando monta el
     * escenario de demostracion (la tienda sembrada, un producto con stock y
     * un medio de pago) y hace una venta de mostrador.
     */
    protected $signature = 'ops:smoke-sale
                            {--store= : El identificador de la tienda donde se hace la venta}
                            {--customer= : El identificador del cliente de la venta (sin el, la venta es de mostrador)}
                            {--quantity=1 : Las unidades que se venden}
                            {--payment= : El identificador del medio de pago}';

    /**
     * La descripcion que aparece en `php artisan list`.
     */
    protected $description = 'Hace una venta de prueba real y muestra su anatomia completa: cabecera, lineas, totales, asiento, reservas y stock.';

    /**
     * La ayuda de `--help`.
     */
    protected $help = <<<'HELP'
        Hace una VENTA DE PRUEBA REAL y muestra su anatomia completa.

        El comando llama al <info>CheckoutService</info> de verdad: la venta que deja escrita es la
        misma que despues ve el navegador en <info>/ventas</info>. No simula nada, y por eso
        puede FALLAR: si el motor de impuestos, el FIFO de lotes o el asiento contable fallan, el
        comando lo dice y sale con codigo distinto de cero. Como el checkout es atomico, un fallo
        no deja nada a medias: ni cabecera, ni cambio de stock, ni numeros quemados.

        Lo que imprime:

          * la CABECERA: numero de documento, numero de control, cliente, fecha de negocio, estado
            y quien la registro;
          * las LINEAS: producto, cantidad, precio cobrado, base imponible, impuesto y total de
            linea, con el PORCENTAJE CONGELADO y su estado de evidencia;
          * los TOTALES: subtotal, descuento, impuesto, AJUSTE DE REDONDEO y total;
          * el ASIENTO contable: tipo, cuenta, debito y credito de cada linea, y la comprobacion
            de que cuadra;
          * las RESERVAS: la del numero de documento y la del numero de control, con su serie, su
            numero y su rol;
          * el STOCK: el disponible del lote antes y despues de la venta.

        El escenario se MONTA si hace falta: la tienda de demostracion, un producto con su
        categoria fiscal, una ficha en <info>product_stores</info> y el stock, que sale de una
        COMPRA real (un lote sin su linea de compra no existe: el lote y su linea de compra son
        1:1). Los catalogues fiscales que faltan los ponen los sembradores del repo.

        El codigo de salida es 0 cuando todo fue bien, y distinto de cero con un mensaje claro
        cuando algo fallo.
        HELP;

    /**
     * El PLAN DE CUENTAS de DEMOSTRACION.
     *
     * El dominio NO inventa un plan de cuentas: `CheckoutInput::$accounts` es DATO de la entrada.
     * El repo todavia no tiene donde configurarlo (no hay tabla de plan de cuentas ni
     * configuracion por tienda), asi que aqui se declara uno de demostracion, y se imprime en la
     * salida para que quien lo lea sepa que cuentas se usaron.
     *
     * @var array<string, string>
     */
    private const CUENTAS = [
        'revenue' => '4010000',
        'tax_payable' => '2110000',
        'cash' => '1100000',
        'receivable' => '1200000',
    ];

    /**
     * El precio de venta de demostracion, como texto decimal.
     */
    private const PRECIO_VENTA = '25.0000';

    /**
     * El precio de compra de demostracion, como texto decimal.
     */
    private const PRECIO_COMPRA = '10.0000';

    /**
     * Las unidades que trae el lote de demostracion.
     */
    private const LOTE_DEMOSTRACION = '10.0000';

    /**
     * El codigo del producto de demostracion.
     */
    private const CODIGO_PRODUCTO = 'HUMO-VENTA-001';

    /**
     * El codigo del medio de pago de demostracion.
     */
    private const CODIGO_MEDIO_PAGO = 'HUMO-EFECTIVO';

    /**
     * Las tablas que el comando necesita. Si falta alguna, la base de datos no tiene las
     * migraciones aplicadas, y el comando lo dice antes de tocar nada.
     *
     * @var list<string>
     */
    private const TABLAS = [
        'stores', 'store_settings', 'products', 'product_stores', 'purchases', 'purchase_items',
        'inventory_lots', 'payment_methods', 'payment_method_stores', 'customers', 'sales',
        'sale_items', 'sale_totals', 'sale_payments', 'sale_item_lot_consumptions', 'ledger_entries',
        'ledger_lines', 'ledger_entry_types', 'fiscal_series', 'fiscal_document_reservations',
        'tax_categories', 'tax_rates', 'cash_registers',
    ];

    public function __construct(private readonly Connection $connection)
    {
        parent::__construct();
    }

    /**
     * Hace la venta de prueba e imprime su anatomia.
     *
     * REGLAS que fija este metodo:
     *
     *  1. El codigo de salida es 0 si todo fue bien, y DISTINTO de cero con
     *     un mensaje claro si algo fallo. Un comando de humo que sale con
     *     codigo cero cuando la venta no se hizo no es un comando de humo.
     *  2. Lo que se imprime es lo que esta ESCRITO en la base de datos: el
     *     comando RELee la venta, sus lineas, sus totales, su pago, su
     *     asiento, sus reservas y sus lotes despues del checkout.
     *  3. Las COMPROBACIONES son las de verdad: si el total no es la suma de
     *     las lineas mas el ajuste, si el asiento no cuadra o si el stock no
     *     bajo lo vendido, el comando lo dice y falla.
     */
    public function handle(): int
    {
        try {
            $this->comprobarEsquema();

            $cantidad = $this->cantidad();
            $escenario = $this->escenario();
            [$productoId, $precio] = $this->producto($escenario);
            $loteAntes = $this->asegurarStock($escenario, $productoId, $cantidad);
            $clienteId = $this->cliente();
            [$medioPagoId, $tipoMedioPago] = $this->medioDePago($escenario);

            $linea = new CheckoutLineInput(
                productId: $productoId,
                quantity: $cantidad,
                unitPrice: $precio,
                discount: '0',
            );

            // El importe que se cobra lo calcula el MISMO motor que usa el
            // checkout: el comando no replica la aritmetica, la pide.
            $importe = $this->importeACobrar($escenario, $linea);

            // LA CAJA DEL DIA: el comando vende de verdad, y una venta sin
            // caja es una venta sin contra que contrastar.
            $this->asegurarCajaDelDia($escenario);

            $resultado = $this->servicio()->checkout(new CheckoutInput(
                storeId: $escenario['store']->id,
                customerId: $clienteId,
                documentSeriesId: (int) $escenario['document_series']->id,
                controlSeriesId: (int) $escenario['control_series']->id,
                lines: [$linea],
                payments: [
                    new CheckoutPaymentInput(
                        paymentMethodId: $medioPagoId,
                        amount: $importe,
                        // El TIPO del medio de pago es DATO: el dominio no
                        // consulta la base de datos para saber si el pago es
                        // un cobro o es deuda.
                        paymentMethodType: $tipoMedioPago,
                    ),
                ],
                accounts: self::CUENTAS,
                issuedAt: $escenario['issued_at'],
                businessDate: $escenario['business_date'],
                actorId: $this->actor(),
            ));

            $fallos = $this->pintar($escenario, $resultado, $loteAntes, $cantidad, $clienteId, $medioPagoId);

            return $fallos === [] ? self::SUCCESS : self::FAILURE;
        } catch (Throwable $error) {
            $this->error('La prueba de humo ha FALLADO: '.$error->getMessage());

            return self::FAILURE;
        }
    }

    /**
     * El servicio del checkout, con TODOS sus colaboradores en la MISMA
     * conexion: es lo que hace que la transaccion de la venta sea una sola.
     */
    private function servicio(): CheckoutService
    {
        return new CheckoutService(
            $this->connection,
            new NumberSeries($this->connection),
            new ControlNumberGenerator($this->connection, new NumberSeries($this->connection)),
            new TaxRateResolver($this->connection),
            new LotConsumer($this->connection, new StockInvariant($this->connection)),
            new LedgerWriter($this->connection),
            new SalePaymentStatus($this->connection),
        );
    }

    /**
     * Comprueba que la base de datos tiene el esquema del dominio.
     *
     * Una base sembrada por un dump sin migraciones aplicadas falla EN EL
     * PRIMER `SELECT` con un error de PostgreSQL que no explica nada. Aqui el
     * comando dice QUE falta y QUE hay que hacer.
     */
    private function comprobarEsquema(): void
    {
        $existentes = $this->connection->selectOne(
            'SELECT COUNT(*)::int AS total FROM information_schema.tables WHERE table_schema = current_schema()'
        );

        $tablas = array_map(
            static fn (object $fila): string => (string) $fila->table_name,
            $this->connection->select(
                'SELECT table_name FROM information_schema.tables WHERE table_schema = current_schema()'
            )
        );

        $faltantes = array_values(array_diff(self::TABLAS, $tablas));

        if ($faltantes === []) {
            return;
        }

        throw new RuntimeException(
            'La base de datos no tiene el esquema del dominio: faltan las tablas '
            .implode(', ', $faltantes).'. Ejecuta `php artisan migrate` (y `php artisan db:seed`) '
            .'sobre esta base de datos antes de la prueba de humo.'
        );
    }

    /**
     * El ESCENARIO: la tienda, su perfil fiscal, sus dos series, la moneda,
     * la categoria fiscal con su tasa vigente, y las dos fechas.
     *
     * @return array<string, mixed>
     */
    private function escenario(): array
    {
        $storeId = $this->opcion('store');

        if ($storeId === null) {
            $this->asegurarCatalogoSembrado();
            $storeId = (int) $this->connection->table('stores')->where('code_name', 'DEMO')->value('id');
        }

        $store = $this->connection->table('stores')->where('id', $storeId)->first();

        if ($store === null) {
            throw new RuntimeException("La tienda {$storeId} no existe.");
        }

        // Las dos fechas: la de EMISION (el instante) y la de NEGOCIO (la que
        // manda la caja). La zona horaria es la de la TIENDA, no la del
        // servidor.
        $timezone = $this->connection->table('store_settings')
            ->where('store_id', $storeId)
            ->value('timezone');

        $zona = new DateTimeZone($timezone === null || $timezone === '' ? (string) config('app.timezone') : (string) $timezone);

        $issuedAt = new DateTimeImmutable('now', $zona);
        $businessDate = new DateTimeImmutable($issuedAt->format('Y-m-d'), $zona);

        // EL PERFIL Y SUS DOS SERIES.
        //
        // REGLA que fija este metodo: *el perfil se elige por lo que HACE*,
        // por su tratamiento fiscal TRANSCRITO (categorias con tasa
        // vigente), y sus dos series son las SUyas.
        //
        // El sembrador siembra un perfil por regimen, pero solo transcribe el
        // detalle fiscal de uno: los demas son su catalogo sin categorias ni
        // tasas. Y "la primera serie que sale" NO es una eleccion: un SELECT
        // sin ORDER BY no tiene orden, y la misma tienda puede devolver otra
        // serie en otra ejecucion. Una prueba de humo que a veces vende con
        // el regimen equivocado no es una prueba de humo.
        //
        // Las DOS series son del MISMO perfil: el numero de control lo
        // compone la serie del control, y su identificador es el del perfil.
        [$profile, $documentSeries, $controlSeries, [$category, $rate]] = $this->perfilYSeries(
            $storeId,
            $businessDate
        );

        $currency = $profile->defaultCurrency;

        if ($currency === null) {
            throw new RuntimeException("El perfil fiscal {$profile->id} no tiene moneda.");
        }

        $context = $profile->taxContext($currency);

        return [
            'store' => $store,
            'profile' => $profile,
            'currency' => $currency,
            'context' => $context,
            'document_series' => $documentSeries,
            'control_series' => $controlSeries,
            'category' => $category,
            'rate' => $rate,
            'issued_at' => $issuedAt,
            'business_date' => $businessDate,
            'timezone' => $zona->getName(),
        ];
    }

    /**
     * El PERFIL con tratamiento fiscal vigente y SUS DOS series.
     *
     * @param  int  $storeId  La tienda de la venta.
     * @param  DateTimeImmutable  $fecha  La fecha de negocio de la venta.
     * @return array{0: FiscalProfile, 1: object, 2: object, 3: array{0: object, 1: array<string, mixed>}}
     *
     * @throws RuntimeException Cuando la tienda no tiene un perfil con
     *                          tratamiento fiscal vigente, o sus series.
     */
    private function perfilYSeries(int $storeId, DateTimeImmutable $fecha): array
    {
        $series = $this->connection->table('fiscal_series')
            ->where('store_id', $storeId)
            ->where('series_role', 'document')
            ->orderBy('id')
            ->get();

        foreach ($series as $documentSeries) {
            $perfilId = (int) $documentSeries->fiscal_profile_id;

            $categoria = $this->categoriaYTasa($perfilId, $fecha);

            if ($categoria === null) {
                // Este perfil no tiene tratamiento fiscal transcrito: no es
                // el regimen de esta prueba de humo.
                continue;
            }

            $controlSeries = $this->connection->table('fiscal_series')
                ->where('store_id', $storeId)
                ->where('fiscal_profile_id', $perfilId)
                ->where('series_role', 'control')
                ->orderBy('id')
                ->first();

            if ($controlSeries === null) {
                continue;
            }

            $profile = FiscalProfile::query()
                ->with(['defaultCurrency', 'fields'])
                ->find($perfilId);

            if ($profile === null) {
                throw new RuntimeException("El perfil fiscal {$perfilId} no existe.");
            }

            return [$profile, $documentSeries, $controlSeries, $categoria];
        }

        throw new RuntimeException(
            "La tienda {$storeId} no tiene un perfil fiscal con tratamiento vigente el "
            .$fecha->format('Y-m-d').' y sus dos series (la del documento y la del control): '
            .'ejecuta `php artisan db:seed` para sembrar el perfil fiscal y sus series.'
        );
    }

    /**
     * Asegura el CATALOGO sembrado que la venta necesita: los paises y las
     * monedas, la tienda de demostracion y el perfil fiscal con sus series.
     *
     * Los sembradores son idempotentes, asi que esto no duplica nada. Y son
     * los duenos de esos datos: el comando no declara ni un perfil fiscal, ni
     * una tasa, ni una serie.
     */
    private function asegurarCatalogoSembrado(): void
    {
        if (! $this->connection->table('countries')->exists()) {
            (new TaxonomySeeder)->run();
        }

        if (! $this->connection->table('stores')->where('code_name', 'DEMO')->exists()) {
            (new DemoStoreSeeder)->run();
        }

        $storeId = (int) $this->connection->table('stores')->where('code_name', 'DEMO')->value('id');

        $tieneSeries = $this->connection->table('fiscal_series')
            ->where('store_id', $storeId)
            ->where('series_role', 'document')
            ->exists();

        if (! $tieneSeries) {
            (new FiscalProfileSeeder)->run();
        }
    }

    /**
     * La categoria fiscal con su tasa VIGENTE, y solo con su tasa vigente.
     *
     * La categoria se busca entre las del PERFIL (no hay categorias globales
     * con tasa) y se prefiere la que tenga una tasa DISTINTA de cero: es la
     * que hace que la venta tenga impuesto de verdad.
     *
     * Devuelve `null` cuando el perfil NO tiene ninguna tasa vigente: es lo
     * que le dice al comando que este perfil no es el regimen de la prueba
     * de humo, y no un fallo de la operacion.
     *
     * @return array{0: object, 1: array<string, mixed>}|null
     */
    private function categoriaYTasa(int $profileId, DateTimeImmutable $fecha): ?array
    {
        $resolver = new TaxRateResolver($this->connection);

        $elegida = null;

        foreach ($this->connection->table('tax_categories')
            ->where('fiscal_profile_id', $profileId)
            ->orderBy('id')
            ->get() as $category) {
            $rate = $resolver->resolve((int) $category->id, $fecha);

            if ($rate === null) {
                continue;
            }

            if ($elegida === null) {
                $elegida = [$category, $rate];
            }

            if (! BigDecimal::of($rate['percentage'])->isZero()) {
                return [$category, $rate];
            }
        }

        return $elegida;
    }

    /**
     * El PRODUCTO de demostracion y su ficha en la tienda.
     *
     * El producto se reutiliza si ya existe (el codigo es su clave natural):
     * el comando no crea un producto nuevo en cada ejecucion.
     *
     * @param  array<string, mixed>  $escenario
     * @return array{0: int, 1: string}
     */
    private function producto(array $escenario): array
    {
        $storeId = (int) $escenario['store']->id;
        $categoryId = (int) $escenario['category']->id;

        $productId = $this->connection->table('products')->where('code', self::CODIGO_PRODUCTO)->value('id');

        if ($productId === null) {
            $productId = (int) $this->connection->table('products')->insertGetId([
                'code' => self::CODIGO_PRODUCTO,
                'name' => 'Producto de prueba de humo',
                'tax_category_id' => $categoryId,
                'type' => 'product',
                'is_active' => true,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $productId = (int) $productId;

            $this->connection->table('products')->where('id', $productId)->update([
                'tax_category_id' => $categoryId,
                'updated_at' => now(),
            ]);
        }

        $ficha = $this->connection->table('product_stores')
            ->where('product_id', $productId)
            ->where('store_id', $storeId)
            ->first();

        if ($ficha === null) {
            $this->connection->table('product_stores')->insert([
                'product_id' => $productId,
                'store_id' => $storeId,
                // El override de categoria lo lleva la linea, no la ficha: asi
                // se ve que la precedencia la resuelve el dominio.
                'tax_category_id' => null,
                'purchase_price' => self::PRECIO_COMPRA,
                'sell_price' => self::PRECIO_VENTA,
                'quantity_in_stock' => '0.0000',
                'status' => 'active',
                'sort_order' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        } else {
            $this->connection->table('product_stores')->where('id', $ficha->id)->update([
                'sell_price' => self::PRECIO_VENTA,
                'purchase_price' => self::PRECIO_COMPRA,
                'updated_at' => now(),
            ]);
        }

        return [$productId, self::PRECIO_VENTA];
    }

    /**
     * El STOCK: un lote con unidades disponibles.
     *
     * El lote NO se inventa: `inventory_lots.purchase_item_id` es NOT NULL y
     * UNIQUE (el lote y su linea de compra son 1:1), asi que el stock tiene
     * que venir de una COMPRA real, como en el legacy. Se crea la compra, su
     * linea y el lote, y el cache de la tienda se refresca desde los lotes.
     *
     * @param  array<string, mixed>  $escenario
     * @return array<string, string> El disponible del producto ANTES de la venta.
     */
    private function asegurarStock(array $escenario, int $productId, string $cantidad): array
    {
        $storeId = (int) $escenario['store']->id;
        $invariant = new StockInvariant($this->connection);

        $disponible = $invariant->availableInLots($productId, $storeId);

        if (BigDecimal::of($disponible)->isLessThan(BigDecimal::of($cantidad))) {
            $this->compraQueAbreElLote($escenario, $productId, $cantidad);
            $invariant->refresh($productId, $storeId);
            $disponible = $invariant->availableInLots($productId, $storeId);
        }

        return ['antes' => $disponible];
    }

    /**
     * La COMPRA que abre el lote: cabecera, linea y lote.
     *
     * @param  array<string, mixed>  $escenario
     */
    private function compraQueAbreElLote(array $escenario, int $productId, string $cantidad): void
    {
        $storeId = (int) $escenario['store']->id;
        $businessDate = $escenario['business_date'];

        $unidades = BigDecimal::of(self::LOTE_DEMOSTRACION)->isGreaterThan(BigDecimal::of($cantidad))
            ? self::LOTE_DEMOSTRACION
            : $cantidad;

        $purchaseId = (int) $this->connection->table('purchases')->insertGetId([
            'store_id' => $storeId,
            'purchase_date' => $businessDate->format('Y-m-d'),
            'status' => 'received',
            'invoice_no' => 'HUMO-COMPRA-'.$businessDate->format('Ymd').'-'.$storeId,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $purchaseItemId = (int) $this->connection->table('purchase_items')->insertGetId([
            'purchase_id' => $purchaseId,
            'product_id' => $productId,
            'store_id' => $storeId,
            'quantity' => $unidades,
            'unit_price' => self::PRECIO_COMPRA,
            'discount' => '0.0000',
            'tax_category_id' => (int) $escenario['category']->id,
            'tax_rate_id' => (int) $escenario['rate']['id'],
            'tax_rate_snapshot' => $escenario['rate']['percentage'],
            'tax_amount' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->connection->table('purchase_totals')->insert([
            'purchase_id' => $purchaseId,
            'sub_total' => (string) BigDecimal::of($unidades)
                ->multipliedBy(self::PRECIO_COMPRA)
                ->toScale(4, RoundingMode::HALF_UP),
            'discount' => '0.0000',
            'tax_total' => '0.0000',
            'rounding_adjustment' => '0.0000',
            'grand_total' => (string) BigDecimal::of($unidades)
                ->multipliedBy(self::PRECIO_COMPRA)
                ->toScale(4, RoundingMode::HALF_UP),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->connection->table('inventory_lots')->insert([
            'product_id' => $productId,
            'store_id' => $storeId,
            'purchase_item_id' => $purchaseItemId,
            'quantity_received' => $unidades,
            'quantity_consumed' => '0.0000',
            'quantity_returned' => '0.0000',
            'unit_cost' => self::PRECIO_COMPRA,
            'received_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * El CLIENTE de la venta, si lo hay.
     *
     * Sin `--customer`, la venta es de MOSTRADOR: el dominio cobra en efectivo
     * y no toca el saldo del cliente.
     */
    private function cliente(): ?int
    {
        $customerId = $this->opcion('customer');

        if ($customerId === null) {
            return null;
        }

        if (! $this->connection->table('customers')->where('id', $customerId)->exists()) {
            throw new RuntimeException("El cliente {$customerId} no existe.");
        }

        return $customerId;
    }

    /**
     * La CAJA DEL DIA de la tienda de la venta.
     *
     * REGLA que fija este metodo: *el comando vende como la caja*: no vende
     * con la caja del dia CERRADA, y abre la que no existe.
     *
     *  - SIN CAJA: el comando la ABRE, con el saldo de partida en CERO y con
     *    quien sea el actor. Un comando de humo que exige una preparacion
     *    manual no es un comando de humo: su trabajo es ensejar el dominio, y
     *    el dominio empieza por tener una caja donde vender.
     *  - CAJA CERRADA: el comando FALLA con un mensaje claro. Una caja
     *    cerrada es un dia que ya se arqueo: vender en ella es vender fuera
     *    del periodo, y el arqueo de ese dia ya no podria contrastar nada.
     *
     * @param  array<string, mixed>  $escenario
     *
     * @throws RuntimeException Cuando la caja del dia ya esta cerrada.
     */
    private function asegurarCajaDelDia(array $escenario): void
    {
        $storeId = (int) $escenario['store']->id;
        $fecha = $escenario['business_date'];
        $dia = $fecha->format('Y-m-d');

        $caja = $this->connection->table('cash_registers')
            ->where('store_id', $storeId)
            ->where('business_date', $dia)
            ->first();

        if ($caja === null) {
            // NO HAY CAJA: el comando la abre, con el saldo de partida en
            // CERO y con el actor que sea.
            (new CashRegisterService($this->connection))
                ->open($storeId, $fecha, '0.0000', null, $this->actor());

            return;
        }

        if ((string) $caja->status === 'closed') {
            throw new RuntimeException(
                "La caja del dia de la tienda {$storeId} para la fecha de negocio {$dia} ya esta cerrada: "
                .'un dia ya arqueado no admite ventas.'
            );
        }
    }

    /**
     * El MEDIO DE PAGO de la venta, con su TIPO.
     *
     * El TIPO es DATO de la fila (`payment_methods.type`), y el dominio lo
     * necesita: uno de tipo `credit` no es un cobro, es deuda, y no entra al
     * cajon.
     *
     * @param  array<string, mixed>  $escenario
     * @return array{0: int, 1: string|null}
     */
    private function medioDePago(array $escenario): array
    {
        $storeId = (int) $escenario['store']->id;

        $paymentId = $this->opcion('payment');

        if ($paymentId !== null) {
            $fila = $this->connection->table('payment_methods')->where('id', $paymentId)->first();

            if ($fila === null) {
                throw new RuntimeException("El medio de pago {$paymentId} no existe.");
            }

            return [$paymentId, $fila->type === null ? null : (string) $fila->type];
        }

        $existente = $this->connection->table('payment_methods')
            // Un metodo de tipo `credit` NO es un cobro: es deuda. La venta
            // de humo es una venta de MOSTRADOR, y su pago tiene que ser de
            // verdad un pago.
            ->where('type', '!=', PaymentMethod::TYPE_CREDIT)
            ->orderBy('id')
            ->first();

        if ($existente !== null) {
            return [
                (int) $existente->id,
                $existente->type === null ? null : (string) $existente->type,
            ];
        }

        $paymentId = (int) $this->connection->table('payment_methods')->insertGetId([
            'code' => self::CODIGO_MEDIO_PAGO,
            'name' => 'Efectivo',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        // El vinculo por tienda: los medios de pago son del catalogo, pero
        // su estado es de cada tienda.
        $this->connection->table('payment_method_stores')->insert([
            'payment_method_id' => $paymentId,
            'store_id' => $storeId,
            'status' => 'active',
            'sort_order' => 0,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$paymentId, null];
    }

    /**
     * El importe que se COBRA, calculado con el MISMO motor que usa el
     * checkout.
     *
     * El pago tiene que cubrir el total EXACTO: es la regla del estado de
     * pago, y un pago que no la cumple deja la venta en `confirmed` en vez de
     * `paid`.
     *
     * @param  array<string, mixed>  $escenario
     */
    private function importeACobrar(array $escenario, CheckoutLineInput $linea): string
    {
        $calculator = new TaxCalculator($escenario['context']);

        $resultado = $calculator->calculate(new TaxDocumentInput(
            lines: [
                new TaxLineInput(
                    taxCategoryId: (int) $escenario['category']->id,
                    taxRateId: (int) $escenario['rate']['id'],
                    taxRateSnapshot: $escenario['rate']['percentage'],
                    evidenceStatus: $escenario['rate']['evidence_status'],
                    method: $escenario['context']->taxMethod(),
                    unitPrice: $linea->unitPrice,
                    quantity: $linea->quantity,
                    discount: $linea->discount,
                ),
            ],
            documentDiscount: '0',
            issuedAt: $escenario['issued_at'],
        ));

        return $resultado->grandTotal;
    }

    /**
     * QUIEN registra la operacion.
     *
     * Un comando no tiene sesion: se usa el primer usuario de la base de
     * datos, si hay alguno. Sin usuarios, la venta se queda sin autor.
     */
    private function actor(): ?int
    {
        $actorId = $this->connection->table('users')->orderBy('id')->value('id');

        return $actorId === null ? null : (int) $actorId;
    }

    /**
     * La cantidad que se vende, como texto decimal.
     */
    private function cantidad(): string
    {
        $bruto = trim((string) $this->option('quantity'));

        try {
            $cantidad = BigDecimal::of($bruto)->toScale(4, RoundingMode::HALF_UP);
        } catch (Throwable) {
            throw new RuntimeException("La cantidad '{$bruto}' no es un numero decimal.");
        }

        if ($cantidad->isZero() || $cantidad->isNegative()) {
            throw new RuntimeException("La cantidad '{$bruto}' tiene que ser mayor que cero.");
        }

        return (string) $cantidad;
    }

    /**
     * Una opcion entera, o `null` si no viene.
     */
    private function opcion(string $nombre): ?int
    {
        $valor = $this->option($nombre);

        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }

        $id = filter_var($valor, FILTER_VALIDATE_INT);

        if ($id === false || $id <= 0) {
            throw new RuntimeException("La opcion --{$nombre} tiene que ser un identificador valido.");
        }

        return (int) $id;
    }

    /**
     * IMPRIME la anatomia de la venta.
     *
     * Todo lo que se imprime se RELee de la base de datos: el comando enseña
     * lo que quedo escrito, no lo que creia que escribio.
     *
     * @param  array<string, mixed>  $escenario
     * @param  array<string, string>  $stock
     * @return list<string> Las comprobaciones que NO cuadran, si las hay.
     */
    private function pintar(array $escenario, CheckoutResult $resultado, array $stock, string $cantidad, ?int $clienteId, int $medioPagoId): array
    {
        $saleId = (int) $resultado->saleId;

        $sale = $this->connection->table('sales')->where('id', $saleId)->first();
        $items = $this->connection->table('sale_items')->where('sale_id', $saleId)->orderBy('id')->get();
        $totals = $this->connection->table('sale_totals')->where('sale_id', $saleId)->first();
        $payments = $this->connection->table('sale_payments')->where('sale_id', $saleId)->orderBy('id')->get();

        $this->line('');
        $this->line('POS MODERNO — LA VENTA DE PRUEBA, POR DENTRO');
        $this->line('================================================');
        $this->line('');

        $this->pintarEscenario($escenario, $cantidad, $clienteId, $medioPagoId);
        $this->pintarCabecera($sale, $escenario, $resultado);
        $this->pintarLineas($items);
        $this->pintarTotales($totals);
        $this->pintarPagos($payments);
        $this->pintarAsiento($saleId);
        $this->pintarReservas($escenario, $saleId);
        $this->pintarStock($stock, $saleId);

        return $this->pintarComprobaciones($saleId, $items, $totals);
    }

    /**
     * El ESCENARIO de la prueba.
     *
     * @param  array<string, mixed>  $escenario
     */
    private function pintarEscenario(array $escenario, string $cantidad, ?int $clienteId, int $medioPagoId): void
    {
        $cliente = $clienteId === null
            ? 'mostrador (sin cliente)'
            : (string) $this->connection->table('customers')->where('id', $clienteId)->value('name');

        $medio = (string) $this->connection->table('payment_methods')->where('id', $medioPagoId)->value('name');

        $this->seccion('EL ESCENARIO');
        $this->detalle('Tienda', $escenario['store']->name.' (#'.$escenario['store']->id.')');
        $this->detalle('Perfil fiscal', $escenario['profile']->code.' — '.$escenario['profile']->name);
        $this->detalle('Moneda', $escenario['currency']->code.' ('.$escenario['currency']->decimals.' decimales)');
        $this->detalle('Zona horaria', $escenario['timezone']);
        $this->detalle('Serie del documento', '#'.$escenario['document_series']->id
            .' (rol '.$escenario['document_series']->series_role
            .', prefijo '.($escenario['document_series']->prefix ?? '—').')');
        $this->detalle('Serie del control', '#'.$escenario['control_series']->id
            .' (rol '.$escenario['control_series']->series_role
            .', identificador '.($escenario['control_series']->control_identifier ?? '—').')');
        $this->detalle('Categoria fiscal', $escenario['category']->name
            .' ('.$escenario['rate']['percentage'].' %, '.$escenario['rate']['evidence_status'].')');
        $this->detalle('Cantidad vendida', $cantidad);
        $this->detalle('Cliente', $cliente);
        $this->detalle('Medio de pago', $medio);
        $this->detalle('Plan de cuentas', implode(', ', array_map(
            static fn (string $rol, string $cuenta): string => $rol.'='.$cuenta,
            array_keys(self::CUENTAS),
            self::CUENTAS
        )));
        $this->line('');
    }

    /**
     * La CABECERA de la venta.
     *
     * @param  array<string, mixed>  $escenario
     */
    private function pintarCabecera(object $sale, array $escenario, CheckoutResult $resultado): void
    {
        $actor = $sale->created_by === null
            ? '(sin usuario)'
            : '#'.$sale->created_by.' '.(string) $this->connection->table('users')->where('id', $sale->created_by)->value('name');

        $this->seccion('LA CABECERA');
        $this->detalle('Venta', '#'.$sale->id);
        $this->detalle('Numero de documento', (string) $sale->invoice_no);
        $this->detalle('Numero de control', (string) ($resultado->controlNumber ?? '(sin serie de control)'));
        $this->detalle('Fecha de negocio', (string) $sale->sale_date);
        $this->detalle('Emitida', (string) $sale->created_at);
        $this->detalle('Estado', (string) $sale->status);
        $this->detalle('Registrado por', $actor);
        $this->detalle('Documento fiscal', $sale->fiscal_document_id === null
            ? '(sin emitir: T2 no forma parte de T1)'
            : '#'.$sale->fiscal_document_id);
        $this->line('');
    }

    /**
     * Las LINEAS de la venta, con el porcentaje CONGELADO y su evidencia.
     */
    private function pintarLineas($items): void
    {
        $this->seccion('LAS LINEAS');

        $this->line(sprintf(
            '  %-3s %-28s %10s %10s %10s %10s %10s  %s',
            '#', 'Producto', 'Cantidad', 'Precio', 'Base', 'Impuesto', 'Total', 'Tasa (evidencia)'
        ));

        foreach ($items as $indice => $item) {
            $nombre = (string) $this->connection->table('products')
                ->where('id', $item->product_id)
                ->value('name');

            $this->line(sprintf(
                '  %-3d %-28s %10s %10s %10s %10s %10s  %s%% (%s)',
                $indice + 1,
                mb_substr($nombre, 0, 28),
                (string) $item->quantity,
                (string) $item->unit_price,
                (string) $item->base_amount,
                (string) $item->tax_amount,
                (string) $item->line_total,
                (string) $item->tax_rate_snapshot,
                (string) ($item->evidence_status ?? '—')
            ));
        }

        $this->line('');
    }

    /**
     * Los TOTALES de la venta, con el AJUSTE DE REDONDEO a la vista.
     */
    private function pintarTotales(object $totals): void
    {
        $this->seccion('LOS TOTALES');
        $this->detalle('Subtotal', (string) $totals->sub_total);
        $this->detalle('Descuento', (string) $totals->discount);
        $this->detalle('Impuesto', (string) $totals->tax_total);
        $this->detalle('Ajuste de redondeo', (string) $totals->rounding_adjustment);
        $this->detalle('Total', (string) $totals->grand_total);
        $this->detalle('Evidencia', (string) ($totals->evidence_status ?? '—'));
        $this->line('');
    }

    /**
     * El ASIENTO contable de la venta, y si CUADRA.
     */
    private function pintarAsiento(int $saleId): void
    {
        $entry = $this->connection->table('ledger_entries')
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->first();

        if ($entry === null) {
            $this->seccion('EL ASIENTO CONTABLE');
            $this->line('  (esta venta no tiene asiento)');
            $this->line('');

            return;
        }

        $tipo = (string) $this->connection->table('ledger_entry_types')
            ->where('id', $entry->entry_type_id)
            ->value('code');

        $lines = $this->connection->table('ledger_lines')
            ->where('ledger_entry_id', $entry->id)
            ->orderBy('id')
            ->get();

        $this->seccion('EL ASIENTO CONTABLE');
        $this->detalle('Asiento', '#'.$entry->id.' (tipo '.$tipo.')');
        $this->detalle('Fecha de negocio', (string) $entry->business_date);
        $this->detalle('Importe', (string) $entry->amount);
        $this->line('');
        $this->line(sprintf('  %-12s %12s %12s', 'Cuenta', 'Debito', 'Credito'));

        $debe = '0';
        $haber = '0';

        foreach ($lines as $linea) {
            $this->line(sprintf(
                '  %-12s %12s %12s',
                (string) $linea->account_code,
                (string) $linea->debit,
                (string) $linea->credit
            ));

            $debe = (string) BigDecimal::of($debe)->plus((string) $linea->debit)->toScale(4, RoundingMode::HALF_UP);
            $haber = (string) BigDecimal::of($haber)->plus((string) $linea->credit)->toScale(4, RoundingMode::HALF_UP);
        }

        $this->line(sprintf('  %-12s %12s %12s', 'SUMA', $debe, $haber));
        $this->line('');
        $this->line('  '.(bccomp($debe, $haber, 4) === 0
            ? 'El asiento CUADRA: los debitos son iguales a los creditos.'
            : 'El asiento NO CUADRA: '.$debe.' de debito contra '.$haber.' de credito.'));
        $this->line('');
    }

    /**
     * LAS RESERVAS: la del documento y la del control.
     *
     * @param  array<string, mixed>  $escenario
     */
    private function pintarReservas(array $escenario, int $saleId): void
    {
        $this->seccion('LAS RESERVAS');

        foreach ($this->connection->table('fiscal_document_reservations')
            ->whereIn('series_id', [
                (int) $escenario['document_series']->id,
                (int) $escenario['control_series']->id,
            ])
            ->orderBy('id')
            ->get() as $reserva) {
            $serie = $this->connection->table('fiscal_series')->where('id', $reserva->series_id)->first();

            $enlace = $reserva->reference_type === null
                ? 'sin enlace (la emision fiscal la enlaza)'
                : 'enlazada a '.$reserva->reference_type.' #'.$reserva->reference_id;

            $this->line(sprintf(
                '  %-9s serie #%-4s numero %-10s %s',
                (string) $reserva->series_role,
                (string) $reserva->series_id,
                (string) $reserva->number,
                $enlace
            ));
        }

        $this->line('');
    }

    /**
     * El STOCK: el disponible del lote antes y despues de la venta.
     *
     * @param  array<string, string>  $stock
     */
    private function pintarStock(array $stock, int $saleId): void
    {
        $this->seccion('EL STOCK');

        $items = $this->connection->table('sale_items')->where('sale_id', $saleId)->get();

        $consumos = $this->connection->table('sale_item_lot_consumptions')
            ->whereIn('sale_item_id', $items->pluck('id'))
            ->orderBy('id')
            ->get();

        foreach ($consumos as $consumo) {
            $lote = $this->connection->table('inventory_lots')->where('id', $consumo->lot_id)->first();

            $this->line(sprintf(
                '  Lote #%-4s  recibido %-10s  consumido %-10s  disponible %s',
                (string) $lote->id,
                (string) $lote->quantity_received,
                (string) $lote->quantity_consumed,
                (string) $lote->available
            ));
            $this->line(sprintf(
                '             de este consumo: %s unidades a un coste de %s',
                (string) $consumo->quantity,
                (string) $consumo->unit_cost
            ));
        }

        if ($consumos->isEmpty()) {
            $this->line('  (esta venta no consumio stock)');
        }

        // El disponible TOTAL del producto, antes y despues: es el numero
        // que ve la caja.
        if ($items->isNotEmpty()) {
            $productId = (int) $items->first()->product_id;
            $storeId = (int) $items->first()->store_id;

            $invariant = new StockInvariant($this->connection);

            $this->line(sprintf(
                '  Disponible del producto %d en la tienda %d: %s antes, %s despues',
                $productId,
                $storeId,
                $stock['antes'] ?? '—',
                $invariant->availableInLots($productId, $storeId)
            ));

            $this->line(sprintf(
                '  Cache de la tienda (product_stores.quantity_in_stock): %s',
                $invariant->cachedStock($productId, $storeId)
            ));
        }

        $this->line('');
    }

    /**
     * Los PAGOS de la venta.
     */
    private function pintarPagos($payments): void
    {
        $this->seccion('LOS PAGOS');

        foreach ($payments as $payment) {
            $medio = $payment->payment_method_id === null
                ? '(sin medio de pago)'
                : (string) $this->connection->table('payment_methods')
                    ->where('id', $payment->payment_method_id)
                    ->value('name');

            $this->line(sprintf('  %-28s %12s', $medio, (string) $payment->amount));
        }

        if ($payments->isEmpty()) {
            $this->line('  (esta venta no tiene pagos)');
        }

        $this->line('');
    }

    /**
     * Las COMPROBACIONES: las cifras cuadran o no cuadran.
     *
     * Cada comprobacion dice QUE mira y si se cumple. El comando no dice
     * "todo bien" sin mirarlo: mira y lo dice.
     *
     * @param  Collection<int, object>  $items
     * @return list<string> Las comprobaciones que NO cuadran.
     */
    private function pintarComprobaciones(int $saleId, $items, object $totals): array
    {
        $this->seccion('LAS COMPROBACIONES');

        $fallos = [];

        // 1. La suma de las lineas mas el ajuste de redondeo es el total.
        $suma = '0';

        foreach ($items as $item) {
            $suma = (string) BigDecimal::of($suma)->plus((string) $item->line_total)->toScale(4, RoundingMode::HALF_UP);
        }

        $conAjuste = (string) BigDecimal::of($suma)
            ->plus((string) $totals->rounding_adjustment)
            ->toScale(4, RoundingMode::HALF_UP);

        $this->comprobacion(
            'La suma de las lineas ('.$suma.') mas el ajuste de redondeo es el total ('.$totals->grand_total.')',
            bccomp($conAjuste, (string) $totals->grand_total, 4) === 0,
            $fallos
        );

        // 2. El total es el subtotal menos el descuento mas el impuesto mas el
        //    ajuste.
        $totalEsperado = (string) BigDecimal::of((string) $totals->sub_total)
            ->minus((string) $totals->discount)
            ->plus((string) $totals->tax_total)
            ->plus((string) $totals->rounding_adjustment)
            ->toScale(4, RoundingMode::HALF_UP);

        $this->comprobacion(
            'El total es el subtotal menos el descuento mas el impuesto mas el ajuste',
            bccomp($totalEsperado, (string) $totals->grand_total, 4) === 0,
            $fallos
        );

        // 3. El asiento cuadra.
        $entry = $this->connection->table('ledger_entries')
            ->where('reference_type', 'sale')
            ->where('reference_id', $saleId)
            ->first();

        if ($entry === null) {
            $this->comprobacion('La venta tiene asiento contable', false, $fallos);
        } else {
            $debe = '0';
            $haber = '0';

            foreach ($this->connection->table('ledger_lines')->where('ledger_entry_id', $entry->id)->get() as $linea) {
                $debe = (string) BigDecimal::of($debe)->plus((string) $linea->debit)->toScale(4, RoundingMode::HALF_UP);
                $haber = (string) BigDecimal::of($haber)->plus((string) $linea->credit)->toScale(4, RoundingMode::HALF_UP);
            }

            $this->comprobacion(
                'El asiento cuadra: '.$debe.' de debito contra '.$haber.' de credito',
                bccomp($debe, $haber, 4) === 0,
                $fallos
            );

            $this->comprobacion(
                'El importe del asiento es el total de la venta',
                bccomp($debe, (string) $totals->grand_total, 4) === 0,
                $fallos
            );
        }

        // 4. El consumo del stock es EXACTAMENTE lo vendido.
        $consumido = '0';
        $vendido = '0';

        foreach ($items as $item) {
            $vendido = (string) BigDecimal::of($vendido)->plus((string) $item->quantity)->toScale(4, RoundingMode::HALF_UP);
        }

        foreach ($this->connection->table('sale_item_lot_consumptions')
            ->whereIn('sale_item_id', $items->pluck('id'))
            ->get() as $consumo) {
            $consumido = (string) BigDecimal::of($consumido)->plus((string) $consumo->quantity)->toScale(4, RoundingMode::HALF_UP);
        }

        $this->comprobacion(
            'El stock bajo exactamente lo vendido ('.$consumido.')',
            bccomp($consumido, $vendido, 4) === 0,
            $fallos
        );

        // 5. Y el cache del stock sigue la invariante.
        foreach ($items as $item) {
            $invariant = new StockInvariant($this->connection);

            $this->comprobacion(
                'El cache del stock es la suma de los lotes disponibles del producto '.$item->product_id,
                $invariant->holds((int) $item->product_id, (int) $item->store_id),
                $fallos
            );
        }

        // 6. Y las dos reservas: la del documento y la del control.
        $reservas = $this->connection->table('fiscal_document_reservations')
            ->whereIn('series_role', ['document', 'control'])
            ->count();

        $this->comprobacion(
            'La venta reservo un numero de documento y uno de control',
            $reservas >= 2,
            $fallos
        );

        if ($fallos !== []) {
            $this->line('');
            $this->error('La venta se escribio, pero NO cuadra:');
        }

        $this->line('');

        return $fallos;
    }

    /**
     * Una COMPROBACION: se dice si se cumple y, si no, se cuenta como fallo.
     *
     * @param  list<string>  $fallos
     */
    private function comprobacion(string $texto, bool $cumple, array &$fallos): void
    {
        $this->line('  '.($cumple ? 'OK   ' : 'FALLO').'  '.$texto);

        if (! $cumple) {
            $fallos[] = $texto;
        }
    }

    /**
     * Un TITULO de seccion.
     */
    private function seccion(string $titulo): void
    {
        $this->line($titulo);
        $this->line(str_repeat('-', mb_strlen($titulo)));
    }

    /**
     * Una FILA de detalle: la etiqueta y su valor.
     */
    private function detalle(string $etiqueta, string $valor): void
    {
        $this->line('  '.str_pad($etiqueta, 24, '.').' '.$valor);
    }
}
