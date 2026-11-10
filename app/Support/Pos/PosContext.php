<?php

namespace App\Support\Pos;

use App\Models\Store;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El CONTEXTO de la caja: la tienda donde se cobra, sus series fiscales, sus
 * dos fechas y su plan de cuentas.
 *
 * REGLA que fija esta clase: *la caja trabaja sobre UN contexto y ese
 * contexto se resuelve UNA vez por peticion*. La pantalla de la caja, la
 * busqueda de productos y el cobro tienen que hablar de la MISMA tienda, de
 * las MISMAS series y de la MISMA fecha de negocio: si cada uno resolviera su
 * propia tienda por su cuenta, la pantalla podria ensenar un producto de una
 * tienda y el cobro escribir la venta en otra.
 *
 * Por eso el contexto se cachea: cada dato se resuelve la PRIMERA vez que se
 * pide y de ahi en adelante sale de la memoria de la peticion.
 *
 * QUE RESUELVE, Y POR QUE:
 *
 *  - LA TIENDA ACTIVA: la primera tienda del usuario (`user_stores`), y si el
 *    usuario no tiene ninguna, la primera tienda activa del sistema. Ver
 *    `store()`.
 *  - LAS SERIES: la del documento y la del CONTROL de esa tienda. Ver
 *    `documentSeriesId()` y `controlSeriesId()`.
 *  - LAS DOS FECHAS: la de emision (el instante) y la de NEGOCIO (la que
 *    manda la caja). Las dos en la zona horaria de la TIENDA, no la del
 *    servidor. Ver `issuedAt()` y `businessDate()`.
 *  - EL PLAN DE CUENTAS: el de la tienda si declara uno, y el `default` si no.
 *    Ver `accounts()`.
 *
 * NO ES UN SERVICIO DE DOMINIO: no cobra, no escribe y no decide nada de
 * negocio. Solo resuelve y cachea el escenario. Por eso NO es `readonly`: para
 * cachear tiene que poder guardar lo ya resuelto.
 */
final class PosContext
{
    /** La tienda ya resuelta. */
    private ?Store $store = null;

    /** Las series ya resueltas. */
    private bool $seriesResolved = false;

    /** El id de la serie del documento. */
    private ?int $documentSeriesId = null;

    /** El id de la serie del control. */
    private ?int $controlSeriesId = null;

    /** La moneda ya resuelta. */
    private ?string $currencyCode = null;

    /** El plan de cuentas ya resuelto. */
    private ?array $accounts = null;

    /** La zona horaria ya resuelta. */
    private ?DateTimeZone $timezone = null;

    /** El instante de emision ya resuelto. */
    private ?DateTimeImmutable $issuedAt = null;

    /** La fecha de negocio ya resuelta. */
    private ?DateTimeImmutable $businessDate = null;

    /**
     * @param  Connection  $connection  La MISMA conexion que usa el checkout:
     *                                  si el contexto leyera por otra, no veria
     *                                  la transaccion de la venta.
     * @param  int|null  $userId  El usuario autenticado; `null` si no hay
     *                            ninguno (una consola sin sesion).
     */
    public function __construct(
        private readonly Connection $connection,
        private readonly ?int $userId = null,
    ) {}

    /**
     * La TIENDA ACTIVA de la caja.
     *
     * REGLA que fija este metodo: *la tienda de la caja es la del usuario, y
     * solo si el usuario no tiene ninguna, la primera tienda activa*.
     *
     *  1. La PRIMERA tienda del usuario: la de `user_stores` con estado
     *     `active` y con la tienda ACTIVA, por `sort_order` y, a igualdad, por
     *     id. Un vinculo desactivado NO es una tienda donde el usuario venda:
     *     la desactivacion es por tienda, y el usuario que se da de baja en
     *     una tienda sigue operando en las demas. Y una tienda desactivada
     *     (`stores.status`) no es una tienda donde se cobre: en una tienda
     *     desactivada no se vende.
     *  2. Si el usuario no tiene ninguna (o no hay usuario), la PRIMERA
     *     tienda activa del sistema (`stores.status`), por id.
     *
     * @throws RuntimeException Cuando no hay ninguna tienda activa: sin
     *                          tienda la caja no tiene donde cobrar.
     */
    public function store(): Store
    {
        if ($this->store !== null) {
            return $this->store;
        }

        $store = $this->resolveStore();

        if ($store === null) {
            throw new RuntimeException(
                'No hay ninguna tienda activa: la caja no puede abrirse. '
                .'Crea una tienda y activala antes de cobrar.'
            );
        }

        return $this->store = $store;
    }

    /**
     * La serie que reparte el numero de DOCUMENTO de la tienda.
     *
     * Es `null` cuando la tienda no tiene serie de documento: el numero de
     * un documento fiscal no se inventa, se reserva, y sin serie no hay nada
     * que reservar.
     */
    public function documentSeriesId(): ?int
    {
        $this->resolveSeries();

        return $this->documentSeriesId;
    }

    /**
     * La serie que reparte el numero de CONTROL de la tienda.
     *
     * Es `null` cuando la tienda no tiene serie de control: no todas las
     * tiendas necesitan numero de control, y su ausencia no es un error.
     */
    public function controlSeriesId(): ?int
    {
        $this->resolveSeries();

        return $this->controlSeriesId;
    }

    /**
     * El CODIGO de la moneda de la tienda.
     *
     * REGLA que fija este metodo: *la moneda es la del perfil fiscal de la
     * tienda, y si no lo hay, la del pais de la tienda*. Las dos son FILAS:
     * no hay ningun literal de pais aqui.
     *
     * @throws RuntimeException Cuando la tienda no tiene moneda.
     */
    public function currencyCode(): string
    {
        if ($this->currencyCode !== null) {
            return $this->currencyCode;
        }

        $delPerfil = $this->currencyOfProfile();

        if ($delPerfil !== null) {
            return $this->currencyCode = $delPerfil;
        }

        $delPais = $this->currencyOfCountry();

        if ($delPais !== null) {
            return $this->currencyCode = $delPais;
        }

        throw new RuntimeException(
            'La tienda '.$this->store()->id.' no tiene moneda: ni su perfil fiscal '
            .'ni su pais declaran una. Sin moneda no se puede cobrar.'
        );
    }

    /**
     * La MONEDA de la tienda, con su simbolo y sus separadores.
     *
     * REGLA que fija este metodo: *el codigo de la moneda no basta para
     * enseñar un importe*. Un importe sin el simbolo y sin los separadores no
     * se lee, asi que la fila de `currencies` viaja entera a la pantalla: el
     * simbolo, donde va el simbolo, los decimales y los dos separadores.
     *
     * Los datos son de la FILA, no de la interfaz: el simbolo y los
     * separadores cambian de pais a pais, y un literal en la pantalla es un
     * fork por pais.
     *
     * @return array{code: string, symbol: string|null, symbol_position: string|null, decimals: int, thousand_separator: string|null, decimal_separator: string|null}
     *
     * @throws RuntimeException Cuando la moneda declarada no existe.
     */
    public function currency(): array
    {
        $code = $this->currencyCode();

        $row = $this->connection->table('currencies')->where('code', $code)->first();

        if ($row === null) {
            throw new RuntimeException(
                'La moneda '.$code.' de la tienda '.$this->store()->id.' no existe en el catalogo.'
            );
        }

        return [
            'code' => (string) $row->code,
            'symbol' => $row->symbol === null ? null : (string) $row->symbol,
            'symbol_position' => $row->symbol_position === null ? null : (string) $row->symbol_position,
            'decimals' => (int) $row->decimals,
            'thousand_separator' => $row->thousand_separator === null ? null : (string) $row->thousand_separator,
            'decimal_separator' => $row->decimal_separator === null ? null : (string) $row->decimal_separator,
        ];
    }

    /**
     * El PLAN DE CUENTAS de la tienda: rol => codigo de cuenta.
     *
     * REGLA que fija este metodo: *el plan de la tienda gana al plan por
     * defecto*. Si la tienda declara uno en `config('pos.accounts')`, ese es
     * el suyo; si no, se usa el `default`.
     *
     * @return array<string, string>
     */
    public function accounts(): array
    {
        if ($this->accounts !== null) {
            return $this->accounts;
        }

        $configurados = (array) config('pos.accounts', []);

        $deLaTienda = $configurados[(string) $this->store()->id] ?? null;

        if (is_array($deLaTienda) && $deLaTienda !== []) {
            return $this->accounts = $deLaTienda;
        }

        $porDefecto = $configurados['default'] ?? [];

        return $this->accounts = is_array($porDefecto) ? $porDefecto : [];
    }

    /**
     * La ZONA HORARIA de la tienda.
     *
     * Es la de `store_settings.timezone`, y si la tienda no la declara, la
     * de la aplicacion. El reloj de la caja es el de la TIENDA: dos tiendas
     * en paises distintos no comparten reloj.
     */
    public function timezone(): DateTimeZone
    {
        if ($this->timezone !== null) {
            return $this->timezone;
        }

        $timezone = $this->connection->table('store_settings')
            ->where('store_id', $this->store()->id)
            ->value('timezone');

        $nombre = is_string($timezone) ? trim($timezone) : '';

        if ($nombre === '') {
            $nombre = (string) config('app.timezone');
        }

        return $this->timezone = new DateTimeZone($nombre);
    }

    /**
     * El INSTANTE de emision del documento: el ahora de la tienda.
     */
    public function issuedAt(): DateTimeImmutable
    {
        return $this->issuedAt ??= new DateTimeImmutable('now', $this->timezone());
    }

    /**
     * La FECHA DE NEGOCIO: la que manda la caja.
     *
     * Es el dia de la tienda, a medianoche. Puede NO ser el dia de emision:
     * una venta que se emite a las once de la noche pertenece, si el negocio
     * dice que el dia cierra a las seis, al dia de NEGOCIO de la tienda.
     */
    public function businessDate(): DateTimeImmutable
    {
        if ($this->businessDate !== null) {
            return $this->businessDate;
        }

        $issuedAt = $this->issuedAt();

        return $this->businessDate = new DateTimeImmutable(
            $issuedAt->format('Y-m-d'),
            $this->timezone()
        );
    }

    /**
     * La tienda del usuario, o la primera tienda activa.
     */
    private function resolveStore(): ?Store
    {
        $storeId = $this->storeIdOfUser() ?? $this->firstActiveStoreId();

        if ($storeId === null) {
            return null;
        }

        return Store::query()->find($storeId);
    }

    /**
     * La PRIMERA tienda del usuario, entre las que estan activas.
     *
     * La tienda tambien tiene que estar ACTIVA: una tienda desactivada no es
     * una tienda donde se cobre.
     */
    private function storeIdOfUser(): ?int
    {
        if ($this->userId === null) {
            return null;
        }

        $storeId = $this->connection->table('user_stores')
            ->join('stores', 'stores.id', '=', 'user_stores.store_id')
            ->where('user_stores.user_id', $this->userId)
            ->where('user_stores.status', 'active')
            ->where('stores.status', true)
            ->orderBy('user_stores.sort_order')
            ->orderBy('user_stores.id')
            ->value('stores.id');

        return $storeId === null ? null : (int) $storeId;
    }

    /**
     * La PRIMERA tienda activa del sistema.
     */
    private function firstActiveStoreId(): ?int
    {
        $storeId = $this->connection->table('stores')
            ->where('status', true)
            ->orderBy('id')
            ->value('id');

        return $storeId === null ? null : (int) $storeId;
    }

    /**
     * LAS DOS SERIES de la tienda: la del documento y la del control.
     *
     * REGLA que fija este metodo: *la serie de la tienda es la de su perfil
     * fiscal*. Una tienda puede tener series de VARIOS perfiles (el
     * sembrador de demostracion deja las de los cinco paises en la tienda de
     * demostracion), y la que vale es la del pais de la tienda. Si la tienda
     * no declara pais, se toma la primera serie por id.
     */
    private function resolveSeries(): void
    {
        if ($this->seriesResolved) {
            return;
        }

        $this->seriesResolved = true;

        $storeId = $this->store()->id;

        $profileId = $this->connection->table('stores')
            ->where('id', $storeId)
            ->value('country_id');

        if ($profileId !== null) {
            $profileId = (int) $profileId;

            $this->documentSeriesId = $this->seriesIdOfRole($storeId, $profileId, 'document');
            $this->controlSeriesId = $this->seriesIdOfRole($storeId, $profileId, 'control');

            if ($this->documentSeriesId !== null) {
                return;
            }
        }

        $this->documentSeriesId = $this->firstSeriesIdOfRole($storeId, 'document');
        $this->controlSeriesId = $this->firstSeriesIdOfRole($storeId, 'control');
    }

    /**
     * La serie de un ROL dentro de un perfil fiscal y una tienda.
     */
    private function seriesIdOfRole(int $storeId, int $profileId, string $role): ?int
    {
        $seriesId = $this->connection->table('fiscal_series')
            ->where('store_id', $storeId)
            ->where('fiscal_profile_id', $profileId)
            ->where('series_role', $role)
            ->orderBy('id')
            ->value('id');

        return $seriesId === null ? null : (int) $seriesId;
    }

    /**
     * La primera serie de un ROL en una tienda, sin filtrar por perfil.
     */
    private function firstSeriesIdOfRole(int $storeId, string $role): ?int
    {
        $seriesId = $this->connection->table('fiscal_series')
            ->where('store_id', $storeId)
            ->where('series_role', $role)
            ->orderBy('id')
            ->value('id');

        return $seriesId === null ? null : (int) $seriesId;
    }

    /**
     * La moneda del PERFIL FISCAL de la tienda.
     */
    private function currencyOfProfile(): ?string
    {
        $seriesId = $this->documentSeriesId();

        if ($seriesId === null) {
            return null;
        }

        $profileId = $this->connection->table('fiscal_series')
            ->where('id', $seriesId)
            ->value('fiscal_profile_id');

        if ($profileId === null) {
            return null;
        }

        $code = $this->connection->table('currencies')
            ->join('fiscal_profiles', 'fiscal_profiles.default_currency_id', '=', 'currencies.id')
            ->where('fiscal_profiles.id', (int) $profileId)
            ->value('currencies.code');

        return $code === null ? null : (string) $code;
    }

    /**
     * La moneda del PAIS de la tienda.
     */
    private function currencyOfCountry(): ?string
    {
        $countryId = $this->connection->table('stores')
            ->where('id', $this->store()->id)
            ->value('country_id');

        if ($countryId === null) {
            return null;
        }

        $code = $this->connection->table('currencies')
            ->join('countries', 'countries.default_currency_id', '=', 'currencies.id')
            ->where('countries.id', (int) $countryId)
            ->value('currencies.code');

        return $code === null ? null : (string) $code;
    }
}
