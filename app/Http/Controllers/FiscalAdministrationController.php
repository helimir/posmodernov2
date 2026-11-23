<?php

namespace App\Http\Controllers;

use App\Domain\Fiscal\Enums\ProviderKind;
use App\Domain\Fiscal\Enums\RoundingRule;
use App\Domain\Fiscal\Enums\SignerModel;
use App\Fiscal\Enums\SeriesAuthority;
use App\Fiscal\Enums\SeriesRole;
use App\Fiscal\FiscalChannels;
use App\Fiscal\FiscalProfileRegistry;
use App\Fiscal\FiscalProviderRegistry;
use App\Fiscal\FiscalSeriesRegistry;
use App\Models\FiscalProfile;
use App\Models\FiscalProvider;
use App\Models\FiscalSeries;
use App\Support\Audit\AuditWriter;
use App\Support\Pos\PosContext;
use Illuminate\Database\Connection;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rule;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * La ADMINISTRACION FISCAL: las series, los perfiles y los proveedores.
 *
 * REGLA que fija esta clase: *una tienda se instala desde la aplicacion*.
 * Hoy las series, los perfiles y los proveedores solo existen en los
 * sembradores, y `PosContext` resuelve la serie de las filas sembradas: sin
 * esta pantalla, instalar un cliente nuevo es una tarea de base de datos.
 *
 * Y LO QUE ESTA CLASE NO HACE, Y POR QUE:
 *
 *  - NO NUMERA: el correlativo lo reserva el DOMINIO (`NumberSeries`, con
 *    `UPDATE ... RETURNING`). El alta de una serie no consume ningun numero:
 *    la serie empieza en el principio de su rango y el contador solo lo mueve
 *    el dominio;
 *  - NO RENUMERA: el perfil, la tienda, el papel, la autoridad, el prefijo, el
 *    rango y el contador se declaran UNA vez. Editar una serie es cerrar su
 *    vigencia y anotar su autorizacion; editar un perfil es corregir su
 *    descripcion tecnica; editar un proveedor es su direccion, su referencia
 *    y su interruptor;
 *  - NO GUARDA SECRETOS: el secreto de un proveedor vive en el ENTORNO, y la
 *    fila solo declara el PREFIJO de la variable;
 *  - NO FINGE: un proveedor de un canal sin implementacion (`pac`, `portal`)
 *    aparece con el aviso de que ese canal no emite.
 *
 * LAS PAGINAS SON INERTIA y las ESCRITURAS SON JSON, como en el resto del
 * repositorio: la pantalla se pide con un GET y lo que se escribe viaja por la
 * API, con el mismo contrato de siempre (422 con `{"errorMsg": "..."}`).
 *
 * Y EL AMBITO DE TIENDAS lo resuelve `PosContext`: las series son de las
 * tiendas donde el usuario puede operar, y aqui no se reimplementa.
 */
class FiscalAdministrationController extends Controller
{
    /**
     * La ACCION de la auditoria: se ha dado de alta una serie.
     */
    private const ACCION_SERIE_CREADA = 'fiscal_series.created';

    /**
     * La ACCION de la auditoria: se ha editado una serie.
     */
    private const ACCION_SERIE_ACTUALIZADA = 'fiscal_series.updated';

    /**
     * La ACCION de la auditoria: se ha dado de alto un perfil.
     */
    private const ACCION_PERFIL_CREADO = 'fiscal_profile.created';

    /**
     * La ACCION de la auditoria: se ha editado un perfil.
     */
    private const ACCION_PERFIL_ACTUALIZADO = 'fiscal_profile.updated';

    /**
     * La ACCION de la auditoria: se ha dado de alta un proveedor.
     */
    private const ACCION_PROVEEDOR_CREADO = 'fiscal_provider.created';

    /**
     * La ACCION de la auditoria: se ha editado un proveedor.
     */
    private const ACCION_PROVEEDOR_ACTUALIZADO = 'fiscal_provider.updated';

    /**
     * La ENTIDAD de la auditoria: la serie.
     */
    private const ENTIDAD_SERIE = 'fiscal_series';

    /**
     * La ENTIDAD de la auditoria: el perfil.
     */
    private const ENTIDAD_PERFIL = 'fiscal_profile';

    /**
     * La ENTIDAD de la auditoria: el proveedor.
     */
    private const ENTIDAD_PROVEEDOR = 'fiscal_provider';

    /**
     * Las CLAVES que un proveedor NO acepta.
     *
     * REGLA que fija esta lista: *el secreto de un proveedor NUNCA esta en la
     * base de datos*. La fila declara el PREFIJO de la variable de entorno y
     * el secreto se resuelve en runtime (`ProviderCredentialResolver`), asi
     * que una carga que trae una contrasena se rechaza en vez de guardarse.
     *
     * @var list<string>
     */
    private const CLAVES_SECRETAS = [
        'password',
        'passwd',
        'secret',
        'token',
        'api_key',
        'apikey',
        'access_token',
        'client_secret',
        'private_key',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly AuditWriter $audit,
        private readonly FiscalSeriesRegistry $series,
        private readonly FiscalProfileRegistry $profiles,
        private readonly FiscalProviderRegistry $providers,
    ) {}

    /**
     * La PANTALLA de las series fiscales.
     *
     * EL CONTRATO de los props es el de
     * `resources/js/pages/fiscal/series.tsx`:
     *
     *  - `series`: lista de `{ id, fiscal_profile_id, profile_code, profile_name,
     *    store_id, store_name, provider_id, provider_code, series_role,
     *    assigned_by, prefix, next_number, from_number, to_number, valid_from,
     *    valid_to, credential_ref, control_identifier, sequence_length }`.
     *  - `profiles`: los regimenes que se pueden declarar.
     *  - `stores`: las tiendas donde se puede declarar una serie.
     *  - `providers`: las imprentas que se pueden declarar.
     *  - `roles`: los TRES papeles de serie, del conjunto cerrado.
     *  - `authorities`: las CUATRO autoridades que asignan el numero.
     */
    public function series(Request $request): Response
    {
        return Inertia::render('fiscal/series', $this->seriesPayload($request));
    }

    /**
     * La PANTALLA de los perfiles fiscales.
     *
     * EL CONTRATO de los props es el de
     * `resources/js/pages/fiscal/profiles.tsx`:
     *
     *  - `profiles`: lista de `{ id, country_id, country_name, country_iso_code,
     *    code, name, document_standard, schema_version, schema_reference_url,
     *    xml_namespace, xsd_url, id_scheme_pattern, numbering_scheme,
     *    signer_model, default_currency_id, currency_code, effective_from,
     *    effective_to, requires_control_number, authorized_range_required,
     *    holes_allowed, rounding_rule, tax_inclusive_default }`.
     *  - `countries`: los paises del catalogo.
     *  - `currencies`: las monedas del catalogo.
     *  - `signer_models`: los CUATRO modelos de firma, del conjunto cerrado.
     *  - `rounding_rules`: los DOS reglas de redondeo, del conjunto cerrado.
     */
    public function profiles(Request $request): Response
    {
        return Inertia::render('fiscal/profiles', $this->profilesPayload());
    }

    /**
     * La PANTALLA de los proveedores fiscales.
     *
     * EL CONTRATO de los props es el de
     * `resources/js/pages/fiscal/providers.tsx`:
     *
     *  - `providers`: lista de `{ id, fiscal_profile_id, profile_code,
     *    profile_name, code, name, kind, base_url, auth_scheme, credentials_ref,
     *    credential_env_prefix, is_active, has_implementation, environments }`.
     *  - `profiles`: los regimenes a los que puede pertenecer un proveedor.
     *  - `kinds`: los TRES canales, con la marca de los que no emiten.
     */
    public function providers(Request $request): Response
    {
        return Inertia::render('fiscal/providers', $this->providersPayload());
    }

    /**
     * Las SERIES, en JSON.
     */
    public function indexSeries(Request $request): JsonResponse
    {
        return response()->json($this->seriesPayload($request));
    }

    /**
     * Los PERFILES, en JSON.
     */
    public function indexProfiles(Request $request): JsonResponse
    {
        return response()->json($this->profilesPayload());
    }

    /**
     * Los PROVEEDORES, en JSON.
     */
    public function indexProviders(Request $request): JsonResponse
    {
        return response()->json($this->providersPayload());
    }

    /**
     * El ALTA de una serie: la numeracion declarada de una tienda.
     */
    public function storeSeries(Request $request): JsonResponse
    {
        $error = $this->valida($request, [
            // El PERFIL y la TIENDA: la FORMA la comprueba la costura y el
            // CONTENIDO el registro, que dice por que no valen.
            'fiscal_profile_id' => ['nullable', 'integer', 'exists:fiscal_profiles,id'],
            'store_id' => ['nullable', 'integer', 'exists:stores,id'],
            'series_role' => ['required', Rule::enum(SeriesRole::class)],
            'assigned_by' => ['required', Rule::enum(SeriesAuthority::class)],
            'prefix' => ['nullable', 'string', 'max:30'],
            'from_number' => ['required', 'integer', 'min:1'],
            'to_number' => ['required', 'integer', 'min:1'],
            'provider_id' => ['nullable', 'integer', 'exists:fiscal_providers,id'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
            'credential_ref' => ['nullable', 'string', 'max:255'],
            'control_identifier' => ['nullable', 'string', 'max:10'],
            'sequence_length' => ['nullable', 'integer', 'min:1', 'max:8'],
        ]);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        $datos = $request->all();

        // EL AMBITO: la serie es de una tienda donde el usuario puede
        // operar. Y si la tienda no viene, lo dice el REGISTRO: un ambito
        // que no se puede comprobar no es un motivo para inventar una tienda.
        $tiendaId = (int) ($datos['store_id'] ?? 0);

        if ($tiendaId > 0) {
            $error = $this->exigeTiendaEnAmbito($request, $tiendaId);

            if ($error !== null) {
                return response()->json(['errorMsg' => $error], 422);
            }
        }

        try {
            $id = $this->series->create($datos);
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $serie = FiscalSeries::query()->findOrFail($id);

        $this->audit->recordFor(
            $request,
            self::ACCION_SERIE_CREADA,
            self::ENTIDAD_SERIE,
            $id,
            $this->contextoDeLaSerie($serie),
            (int) $serie->store_id,
        );

        return response()->json($this->serieProps($this->serieRow($id)), 201);
    }

    /**
     * La EDICION de una serie: su vigencia, su autorizacion y su imprenta.
     *
     * Y LO QUE NO SE EDITA: el perfil, la tienda, el papel, la autoridad, el
     * prefijo, el rango y el contador. Se declaran una vez, y renumerar una
     * serie emitiendo numeros que ya se emitieron no se puede defender.
     */
    public function updateSeries(Request $request, FiscalSeries $series): JsonResponse
    {
        $error = $this->valida($request, [
            'provider_id' => ['nullable', 'integer', 'exists:fiscal_providers,id'],
            'valid_from' => ['nullable', 'date_format:Y-m-d'],
            'valid_to' => ['nullable', 'date_format:Y-m-d'],
            'credential_ref' => ['nullable', 'string', 'max:255'],
        ]);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        $error = $this->exigeTiendaEnAmbito($request, (int) $series->store_id);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $this->series->update((int) $series->id, $request->all());
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $serie = FiscalSeries::query()->findOrFail((int) $series->id);

        $this->audit->recordFor(
            $request,
            self::ACCION_SERIE_ACTUALIZADA,
            self::ENTIDAD_SERIE,
            (int) $serie->id,
            $this->contextoDeLaSerie($serie),
            (int) $serie->store_id,
        );

        return response()->json($this->serieProps($this->serieRow((int) $serie->id)));
    }

    /**
     * El ALTA de un perfil fiscal: el regimen de un pais.
     */
    public function storeProfile(Request $request): JsonResponse
    {
        $error = $this->valida($request, [
            'country_id' => ['nullable', 'integer', 'exists:countries,id'],
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:100'],
            'signer_model' => ['required', Rule::enum(SignerModel::class)],
            'default_currency_id' => ['nullable', 'integer', 'exists:currencies,id'],
            'effective_from' => ['nullable', 'date_format:Y-m-d'],
            'effective_to' => ['nullable', 'date_format:Y-m-d'],
            'document_standard' => ['nullable', 'string', 'max:50'],
            'schema_version' => ['nullable', 'string', 'max:20'],
            'schema_reference_url' => ['nullable', 'string', 'max:255'],
            'xml_namespace' => ['nullable', 'string', 'max:255'],
            'xsd_url' => ['nullable', 'string', 'max:255'],
            'id_scheme_pattern' => ['nullable', 'string', 'max:255'],
            'numbering_scheme' => ['nullable', 'string', 'max:50'],
            'requires_control_number' => ['nullable', 'boolean'],
            'authorized_range_required' => ['nullable', 'boolean'],
            'holes_allowed' => ['nullable', 'boolean'],
            'rounding_rule' => ['nullable', Rule::enum(RoundingRule::class)],
            'tax_inclusive_default' => ['nullable', 'boolean'],
        ]);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $id = $this->profiles->create($request->all());
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $perfil = FiscalProfile::query()->findOrFail($id);

        $this->audit->recordFor(
            $request,
            self::ACCION_PERFIL_CREADO,
            self::ENTIDAD_PERFIL,
            $id,
            $this->contextoDelPerfil($perfil),
        );

        $fila = $this->perfilRow($id);

        return response()->json(array_merge(
            $this->perfilProps($fila),
            ['warnings' => $this->avisosDelPerfil($fila)],
        ), 201);
    }

    /**
     * La EDICION de un perfil: su nombre y su descripcion tecnica.
     *
     * Y LO QUE NO SE EDITA: el codigo, el pais, la moneda, la vigencia, el
     * modelo de firma y las reglas del comprobante. Un regimen se declara, y
     * lo que se corrige despues es como se escribe.
     */
    public function updateProfile(Request $request, FiscalProfile $profile): JsonResponse
    {
        $error = $this->valida($request, [
            'name' => ['nullable', 'string', 'max:100'],
            'document_standard' => ['nullable', 'string', 'max:50'],
            'schema_version' => ['nullable', 'string', 'max:20'],
            'schema_reference_url' => ['nullable', 'string', 'max:255'],
            'xml_namespace' => ['nullable', 'string', 'max:255'],
            'xsd_url' => ['nullable', 'string', 'max:255'],
            'id_scheme_pattern' => ['nullable', 'string', 'max:255'],
            'numbering_scheme' => ['nullable', 'string', 'max:50'],
        ]);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $this->profiles->update((int) $profile->id, $request->all());
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $perfil = FiscalProfile::query()->findOrFail((int) $profile->id);

        $this->audit->recordFor(
            $request,
            self::ACCION_PERFIL_ACTUALIZADO,
            self::ENTIDAD_PERFIL,
            (int) $perfil->id,
            $this->contextoDelPerfil($perfil),
        );

        $fila = $this->perfilRow((int) $perfil->id);

        return response()->json(array_merge(
            $this->perfilProps($fila),
            ['warnings' => $this->avisosDelPerfil($fila)],
        ));
    }

    /**
     * El ALTA de un proveedor fiscal: el canal de emision de un regimen.
     */
    public function storeProvider(Request $request): JsonResponse
    {
        $error = $this->exigeSinSecreto($request);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        $error = $this->valida($request, [
            'fiscal_profile_id' => ['nullable', 'integer', 'exists:fiscal_profiles,id'],
            'code' => ['nullable', 'string', 'max:50'],
            'name' => ['nullable', 'string', 'max:100'],
            'kind' => ['required', Rule::enum(ProviderKind::class)],
            'base_url' => ['nullable', 'string', 'max:255'],
            'auth_scheme' => ['nullable', 'string', 'max:50'],
            'credentials_ref' => ['nullable', 'string', 'max:255'],
            'credential_env_prefix' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $id = $this->providers->create($request->all());
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $proveedor = FiscalProvider::query()->findOrFail($id);

        $this->audit->recordFor(
            $request,
            self::ACCION_PROVEEDOR_CREADO,
            self::ENTIDAD_PROVEEDOR,
            $id,
            $this->contextoDelProveedor($proveedor),
        );

        $fila = $this->proveedorRow($id);

        return response()->json(array_merge(
            $this->proveedorProps($fila),
            ['warnings' => $this->avisosDelProveedor($fila)],
        ), 201);
    }

    /**
     * La EDICION de un proveedor: su nombre, su direccion, su referencia, su
     * prefijo y su interruptor.
     *
     * Y LO QUE NO SE EDITA: el canal (`kind`) y el codigo. El canal decide
     * como se emite, y el codigo es la clave natural del proveedor.
     */
    public function updateProvider(Request $request, FiscalProvider $provider): JsonResponse
    {
        $error = $this->exigeSinSecreto($request);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        $error = $this->valida($request, [
            'name' => ['nullable', 'string', 'max:100'],
            'base_url' => ['nullable', 'string', 'max:255'],
            'auth_scheme' => ['nullable', 'string', 'max:50'],
            'credentials_ref' => ['nullable', 'string', 'max:255'],
            'credential_env_prefix' => ['nullable', 'string', 'max:100'],
            'is_active' => ['nullable', 'boolean'],
        ]);

        if ($error !== null) {
            return response()->json(['errorMsg' => $error], 422);
        }

        try {
            $this->providers->update((int) $provider->id, $request->all());
        } catch (RuntimeException $fallo) {
            return response()->json(['errorMsg' => $fallo->getMessage()], 422);
        }

        $proveedor = FiscalProvider::query()->findOrFail((int) $provider->id);

        $this->audit->recordFor(
            $request,
            self::ACCION_PROVEEDOR_ACTUALIZADO,
            self::ENTIDAD_PROVEEDOR,
            (int) $proveedor->id,
            $this->contextoDelProveedor($proveedor),
        );

        $fila = $this->proveedorRow((int) $proveedor->id);

        // Y los AVISOS: un canal sin implementacion no emite, y un canal
        // apagado no emite. El numero ya emitido no se renumera.
        return response()->json(array_merge(
            $this->proveedorProps($fila),
            ['warnings' => $this->avisosDelProveedor($fila)],
        ));
    }

    /**
     * El CONTENIDO de la pantalla de series, en JSON.
     *
     * @return array<string, mixed>
     */
    private function seriesPayload(Request $request): array
    {
        return [
            'series' => $this->seriesRows($request),
            'profiles' => $this->profileOptions(),
            'stores' => $this->storeOptions($request),
            'providers' => $this->providerOptions(),
            'roles' => $this->roles(),
            'authorities' => $this->authorities(),
        ];
    }

    /**
     * El CONTENIDO de la pantalla de perfiles, en JSON.
     *
     * @return array<string, mixed>
     */
    private function profilesPayload(): array
    {
        return [
            'profiles' => $this->profileRows(),
            'countries' => $this->countryOptions(),
            'currencies' => $this->currencyOptions(),
            'signer_models' => $this->signerModels(),
            'rounding_rules' => $this->roundingRules(),
        ];
    }

    /**
     * El CONTENIDO de la pantalla de proveedores, en JSON.
     *
     * @return array<string, mixed>
     */
    private function providersPayload(): array
    {
        return [
            'providers' => $this->providerRows(),
            'profiles' => $this->profileOptions(),
            'kinds' => $this->kinds(),
        ];
    }

    /**
     * Las SERIES del ambito, con su perfil, su tienda y su imprenta.
     *
     * @return list<array<string, mixed>>
     */
    private function seriesRows(Request $request): array
    {
        $consulta = $this->connection->table('fiscal_series')
            ->join('stores', 'stores.id', '=', 'fiscal_series.store_id')
            ->join('fiscal_profiles', 'fiscal_profiles.id', '=', 'fiscal_series.fiscal_profile_id')
            ->leftJoin('fiscal_providers', 'fiscal_providers.id', '=', 'fiscal_series.provider_id');

        $ambito = $this->ambito($request);

        if ($ambito !== null) {
            $consulta->whereIn('fiscal_series.store_id', $ambito);
        }

        $filas = $consulta
            ->orderBy('stores.name')
            ->orderBy('fiscal_profiles.code')
            ->orderBy('fiscal_series.series_role')
            ->get([
                'fiscal_series.id',
                'fiscal_series.fiscal_profile_id',
                'fiscal_profiles.code as profile_code',
                'fiscal_profiles.name as profile_name',
                'fiscal_series.store_id',
                'stores.name as store_name',
                'fiscal_series.provider_id',
                'fiscal_providers.code as provider_code',
                'fiscal_series.series_role',
                'fiscal_series.assigned_by',
                'fiscal_series.prefix',
                'fiscal_series.next_number',
                'fiscal_series.from_number',
                'fiscal_series.to_number',
                'fiscal_series.valid_from',
                'fiscal_series.valid_to',
                'fiscal_series.credential_ref',
                'fiscal_series.control_identifier',
                'fiscal_series.sequence_length',
            ]);

        $series = [];

        foreach ($filas as $fila) {
            $series[] = $this->serieRow((int) $fila->id, $fila);
        }

        return $series;
    }

    /**
     * UNA SERIE, con su perfil, su tienda y su imprenta.
     *
     * @return array<string, mixed>
     */
    private function serieRow(int $seriesId, ?object $fila = null): array
    {
        if ($fila === null) {
            $fila = $this->connection->table('fiscal_series')
                ->join('stores', 'stores.id', '=', 'fiscal_series.store_id')
                ->join('fiscal_profiles', 'fiscal_profiles.id', '=', 'fiscal_series.fiscal_profile_id')
                ->leftJoin('fiscal_providers', 'fiscal_providers.id', '=', 'fiscal_series.provider_id')
                ->where('fiscal_series.id', $seriesId)
                ->first([
                    'fiscal_series.id',
                    'fiscal_series.fiscal_profile_id',
                    'fiscal_profiles.code as profile_code',
                    'fiscal_profiles.name as profile_name',
                    'fiscal_series.store_id',
                    'stores.name as store_name',
                    'fiscal_series.provider_id',
                    'fiscal_providers.code as provider_code',
                    'fiscal_series.series_role',
                    'fiscal_series.assigned_by',
                    'fiscal_series.prefix',
                    'fiscal_series.next_number',
                    'fiscal_series.from_number',
                    'fiscal_series.to_number',
                    'fiscal_series.valid_from',
                    'fiscal_series.valid_to',
                    'fiscal_series.credential_ref',
                    'fiscal_series.control_identifier',
                    'fiscal_series.sequence_length',
                ]);
        }

        if ($fila === null) {
            return [];
        }

        return [
            'id' => (int) $fila->id,
            'fiscal_profile_id' => (int) $fila->fiscal_profile_id,
            'profile_code' => (string) $fila->profile_code,
            'profile_name' => (string) $fila->profile_name,
            'store_id' => (int) $fila->store_id,
            'store_name' => (string) $fila->store_name,
            'provider_id' => $fila->provider_id === null ? null : (int) $fila->provider_id,
            'provider_code' => $fila->provider_code === null ? null : (string) $fila->provider_code,
            'series_role' => (string) $fila->series_role,
            'assigned_by' => (string) $fila->assigned_by,
            'prefix' => $fila->prefix === null ? null : (string) $fila->prefix,
            'next_number' => (int) $fila->next_number,
            'from_number' => (int) $fila->from_number,
            'to_number' => (int) $fila->to_number,
            'valid_from' => $fila->valid_from === null ? null : (string) $fila->valid_from,
            'valid_to' => $fila->valid_to === null ? null : (string) $fila->valid_to,
            'credential_ref' => $fila->credential_ref === null ? null : (string) $fila->credential_ref,
            'control_identifier' => $fila->control_identifier === null ? null : (string) $fila->control_identifier,
            'sequence_length' => $fila->sequence_length === null ? null : (int) $fila->sequence_length,
        ];
    }

    /**
     * La SERIE con su contrato de props.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function serieProps(array $fila): array
    {
        return $fila + ['warnings' => []];
    }

    /**
     * Los PERFILES, con su pais y su moneda.
     *
     * @return list<array<string, mixed>>
     */
    private function profileRows(): array
    {
        $filas = $this->connection->table('fiscal_profiles')
            ->join('countries', 'countries.id', '=', 'fiscal_profiles.country_id')
            ->leftJoin('currencies', 'currencies.id', '=', 'fiscal_profiles.default_currency_id')
            ->orderBy('countries.name')
            ->orderBy('fiscal_profiles.code')
            ->get([
                'fiscal_profiles.id',
                'fiscal_profiles.country_id',
                'countries.name as country_name',
                'countries.iso_code as country_iso_code',
                'fiscal_profiles.code',
                'fiscal_profiles.name',
                'fiscal_profiles.document_standard',
                'fiscal_profiles.schema_version',
                'fiscal_profiles.schema_reference_url',
                'fiscal_profiles.xml_namespace',
                'fiscal_profiles.xsd_url',
                'fiscal_profiles.id_scheme_pattern',
                'fiscal_profiles.numbering_scheme',
                'fiscal_profiles.signer_model',
                'fiscal_profiles.default_currency_id',
                'currencies.code as currency_code',
                'fiscal_profiles.effective_from',
                'fiscal_profiles.effective_to',
                'fiscal_profiles.requires_control_number',
                'fiscal_profiles.authorized_range_required',
                'fiscal_profiles.holes_allowed',
                'fiscal_profiles.rounding_rule',
                'fiscal_profiles.tax_inclusive_default',
            ]);

        $perfiles = [];

        foreach ($filas as $fila) {
            $perfiles[] = $this->perfilRow((int) $fila->id, $fila);
        }

        return $perfiles;
    }

    /**
     * UN PERFIL, con su pais y su moneda.
     *
     * @return array<string, mixed>
     */
    private function perfilRow(int $profileId, ?object $fila = null): array
    {
        if ($fila === null) {
            $fila = $this->connection->table('fiscal_profiles')
                ->join('countries', 'countries.id', '=', 'fiscal_profiles.country_id')
                ->leftJoin('currencies', 'currencies.id', '=', 'fiscal_profiles.default_currency_id')
                ->where('fiscal_profiles.id', $profileId)
                ->first([
                    'fiscal_profiles.id',
                    'fiscal_profiles.country_id',
                    'countries.name as country_name',
                    'countries.iso_code as country_iso_code',
                    'fiscal_profiles.code',
                    'fiscal_profiles.name',
                    'fiscal_profiles.document_standard',
                    'fiscal_profiles.schema_version',
                    'fiscal_profiles.schema_reference_url',
                    'fiscal_profiles.xml_namespace',
                    'fiscal_profiles.xsd_url',
                    'fiscal_profiles.id_scheme_pattern',
                    'fiscal_profiles.numbering_scheme',
                    'fiscal_profiles.signer_model',
                    'fiscal_profiles.default_currency_id',
                    'currencies.code as currency_code',
                    'fiscal_profiles.effective_from',
                    'fiscal_profiles.effective_to',
                    'fiscal_profiles.requires_control_number',
                    'fiscal_profiles.authorized_range_required',
                    'fiscal_profiles.holes_allowed',
                    'fiscal_profiles.rounding_rule',
                    'fiscal_profiles.tax_inclusive_default',
                ]);
        }

        if ($fila === null) {
            return [];
        }

        return [
            'id' => (int) $fila->id,
            'country_id' => (int) $fila->country_id,
            'country_name' => (string) $fila->country_name,
            'country_iso_code' => (string) $fila->country_iso_code,
            'code' => (string) $fila->code,
            'name' => (string) $fila->name,
            'document_standard' => $fila->document_standard === null ? null : (string) $fila->document_standard,
            'schema_version' => $fila->schema_version === null ? null : (string) $fila->schema_version,
            'schema_reference_url' => $fila->schema_reference_url === null ? null : (string) $fila->schema_reference_url,
            'xml_namespace' => $fila->xml_namespace === null ? null : (string) $fila->xml_namespace,
            'xsd_url' => $fila->xsd_url === null ? null : (string) $fila->xsd_url,
            'id_scheme_pattern' => $fila->id_scheme_pattern === null ? null : (string) $fila->id_scheme_pattern,
            'numbering_scheme' => $fila->numbering_scheme === null ? null : (string) $fila->numbering_scheme,
            'signer_model' => (string) $fila->signer_model,
            'default_currency_id' => $fila->default_currency_id === null ? null : (int) $fila->default_currency_id,
            'currency_code' => $fila->currency_code === null ? null : (string) $fila->currency_code,
            'effective_from' => $fila->effective_from === null ? null : (string) $fila->effective_from,
            'effective_to' => $fila->effective_to === null ? null : (string) $fila->effective_to,
            'requires_control_number' => (bool) $fila->requires_control_number,
            'authorized_range_required' => (bool) $fila->authorized_range_required,
            'holes_allowed' => (bool) $fila->holes_allowed,
            'rounding_rule' => $fila->rounding_rule === null ? null : (string) $fila->rounding_rule,
            'tax_inclusive_default' => (bool) $fila->tax_inclusive_default,
        ];
    }

    /**
     * El PERFIL con su contrato de props.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function perfilProps(array $fila): array
    {
        return $fila + ['warnings' => []];
    }

    /**
     * Los PROVEEDORES, con su perfil y con sus entornos.
     *
     * @return list<array<string, mixed>>
     */
    private function providerRows(): array
    {
        $filas = $this->connection->table('fiscal_providers')
            ->join('fiscal_profiles', 'fiscal_profiles.id', '=', 'fiscal_providers.fiscal_profile_id')
            ->orderBy('fiscal_profiles.code')
            ->orderBy('fiscal_providers.code')
            ->get([
                'fiscal_providers.id',
                'fiscal_providers.fiscal_profile_id',
                'fiscal_profiles.code as profile_code',
                'fiscal_profiles.name as profile_name',
                'fiscal_providers.code',
                'fiscal_providers.name',
                'fiscal_providers.kind',
                'fiscal_providers.base_url',
                'fiscal_providers.auth_scheme',
                'fiscal_providers.credentials_ref',
                'fiscal_providers.credential_env_prefix',
                'fiscal_providers.is_active',
            ]);

        $entornos = $this->environmentsPorProveedor();

        $proveedores = [];

        foreach ($filas as $fila) {
            $proveedores[] = $this->proveedorRow((int) $fila->id, $fila, $entornos);
        }

        return $proveedores;
    }

    /**
     * UN PROVEEDOR, con su perfil y con sus entornos.
     *
     * @param  array<int, list<array<string, mixed>>>  $entornos
     * @return array<string, mixed>
     */
    private function proveedorRow(int $providerId, ?object $fila = null, array $entornos = []): array
    {
        if ($fila === null) {
            $fila = $this->connection->table('fiscal_providers')
                ->join('fiscal_profiles', 'fiscal_profiles.id', '=', 'fiscal_providers.fiscal_profile_id')
                ->where('fiscal_providers.id', $providerId)
                ->first([
                    'fiscal_providers.id',
                    'fiscal_providers.fiscal_profile_id',
                    'fiscal_profiles.code as profile_code',
                    'fiscal_profiles.name as profile_name',
                    'fiscal_providers.code',
                    'fiscal_providers.name',
                    'fiscal_providers.kind',
                    'fiscal_providers.base_url',
                    'fiscal_providers.auth_scheme',
                    'fiscal_providers.credentials_ref',
                    'fiscal_providers.credential_env_prefix',
                    'fiscal_providers.is_active',
                ]);
        }

        if ($fila === null) {
            return [];
        }

        if ($entornos === []) {
            $entornos = $this->environmentsPorProveedor();
        }

        $canal = (string) $fila->kind;

        return [
            'id' => (int) $fila->id,
            'fiscal_profile_id' => (int) $fila->fiscal_profile_id,
            'profile_code' => (string) $fila->profile_code,
            'profile_name' => (string) $fila->profile_name,
            'code' => (string) $fila->code,
            'name' => (string) $fila->name,
            'kind' => $canal,
            'base_url' => $fila->base_url === null ? null : (string) $fila->base_url,
            'auth_scheme' => $fila->auth_scheme === null ? null : (string) $fila->auth_scheme,
            'credentials_ref' => $fila->credentials_ref === null ? null : (string) $fila->credentials_ref,
            'credential_env_prefix' => $fila->credential_env_prefix === null ? null : (string) $fila->credential_env_prefix,
            'is_active' => (bool) $fila->is_active,
            // El canal sin implementacion NO se emula, y la pantalla lo dice.
            'has_implementation' => FiscalChannels::tieneImplementacion($canal),
            'environments' => $entornos[(int) $fila->id] ?? [],
        ];
    }

    /**
     * El PROVEEDOR con su contrato de props.
     *
     * @param  array<string, mixed>  $fila
     * @return array<string, mixed>
     */
    private function proveedorProps(array $fila): array
    {
        return $fila + ['warnings' => []];
    }

    /**
     * Los ENTORNOS que apuntan a cada proveedor.
     *
     * Son de SOLO LECTURA en esta unidad: el entorno decide que despliegue
     * usa el canal, y esa puerta es de otra unidad.
     *
     * @return array<int, list<array<string, mixed>>>
     */
    private function environmentsPorProveedor(): array
    {
        $filas = $this->connection->table('fiscal_profile_environments')
            ->whereNotNull('provider_id')
            ->orderBy('code')
            ->get([
                'id',
                'provider_id',
                'code',
                'label',
                'role',
                'is_testing',
                'is_production',
            ]);

        $entornos = [];

        foreach ($filas as $fila) {
            $entornos[(int) $fila->provider_id][] = [
                'id' => (int) $fila->id,
                'code' => (string) $fila->code,
                'label' => $fila->label === null ? null : (string) $fila->label,
                'role' => (string) $fila->role,
                'is_testing' => (bool) $fila->is_testing,
                'is_production' => (bool) $fila->is_production,
            ];
        }

        return $entornos;
    }

    /**
     * Los AVISOS de un perfil: lo que hay que saber de ese regimen.
     *
     * REGLA que fija este metodo: *la pantalla no finge*. Un perfil declara el
     * regimen de un pais —su codigo, su moneda, quien firma y como se escribe
     * el comprobante—, pero NO declara los tipos de documento, los esquemas de
     * impuesto ni el vocabulario de estados: eso viene del Manual Tecnico, y
     * es de otra unidad. Un perfil sin ellos no puede emitir, y la pantalla
     * lo dice en vez de dejar un regimen a medias.
     *
     * @param  array<string, mixed>  $fila
     * @return list<string>
     */
    private function avisosDelPerfil(array $fila): array
    {
        $perfilId = (int) ($fila['id'] ?? 0);

        if ($perfilId <= 0) {
            return [];
        }

        $avisos = [];

        $tipos = (int) $this->connection->table('fiscal_profile_document_types')
            ->where('fiscal_profile_id', $perfilId)
            ->count();

        if ($tipos === 0) {
            $avisos[] = 'This profile declares the regime of a country, but not its document types, '
                .'its tax schemes or its status vocabulary: those come from the technical manual. '
                .'Without them the regime cannot issue a document.';
        }

        return $avisos;
    }

    /**
     * Los AVISOS de un proveedor: lo que hay que saber de ese canal.
     *
     * @param  array<string, mixed>  $fila
     * @return list<string>
     */
    private function avisosDelProveedor(array $fila): array
    {
        $avisos = [];

        $aviso = FiscalChannels::aviso((string) ($fila['kind'] ?? ''));

        if ($aviso !== null) {
            $avisos[] = $aviso;
        }

        if (! (bool) ($fila['is_active'] ?? true)) {
            $avisos[] = "The channel '{$fila['code']}' is deactivated: no document will go through it. "
                .'The numbering is not touched: the numbers that already went out are not renumbered.';
        }

        if ($avisos !== [] && ! $this->hayOtroCanalActivo((int) ($fila['fiscal_profile_id'] ?? 0), (int) ($fila['id'] ?? 0))) {
            $avisos[] = 'The profile has no other active channel: the stores of that regime cannot emit.';
        }

        return $avisos;
    }

    /**
     * El PERFIL tiene OTRO canal activo, aparte del que se mira.
     *
     * @param  int  $profileId  El perfil del proveedor.
     * @param  int  $providerId  El proveedor que se mira (para no contarse).
     */
    private function hayOtroCanalActivo(int $profileId, int $providerId): bool
    {
        return $this->connection->table('fiscal_providers')
            ->where('fiscal_profile_id', $profileId)
            ->where('id', '!=', $providerId)
            ->where('is_active', true)
            ->exists();
    }

    /**
     * Los PERFILES que se pueden declarar, con su pais.
     *
     * @return list<array<string, mixed>>
     */
    private function profileOptions(): array
    {
        $filas = $this->connection->table('fiscal_profiles')
            ->join('countries', 'countries.id', '=', 'fiscal_profiles.country_id')
            ->orderBy('countries.name')
            ->orderBy('fiscal_profiles.code')
            ->get([
                'fiscal_profiles.id',
                'fiscal_profiles.code',
                'fiscal_profiles.name',
                'countries.name as country_name',
            ]);

        $perfiles = [];

        foreach ($filas as $fila) {
            $perfiles[] = [
                'id' => (int) $fila->id,
                'code' => (string) $fila->code,
                'name' => (string) $fila->name,
                'country_name' => (string) $fila->country_name,
            ];
        }

        return $perfiles;
    }

    /**
     * Las TIENDAS del ambito, donde se puede declarar una serie.
     *
     * @return list<array<string, mixed>>
     */
    private function storeOptions(Request $request): array
    {
        $consulta = $this->connection->table('stores')->orderBy('name');

        $ambito = $this->ambito($request);

        if ($ambito !== null) {
            $consulta->whereIn('stores.id', $ambito);
        }

        $filas = $consulta->get(['id', 'name', 'code_name']);

        $tiendas = [];

        foreach ($filas as $fila) {
            $tiendas[] = [
                'id' => (int) $fila->id,
                'name' => (string) $fila->name,
                'code_name' => $fila->code_name === null ? null : (string) $fila->code_name,
            ];
        }

        return $tiendas;
    }

    /**
     * Los PROVEEDORES que se pueden declarar como imprenta de una serie.
     *
     * Cada uno lleva su perfil: la pantalla filtra por el perfil de la serie,
     * porque la imprenta que respalda un rango es del mismo regimen.
     *
     * @return list<array<string, mixed>>
     */
    private function providerOptions(): array
    {
        $filas = $this->connection->table('fiscal_providers')
            ->orderBy('code')
            ->get(['id', 'fiscal_profile_id', 'code', 'name', 'kind']);

        $proveedores = [];

        foreach ($filas as $fila) {
            $proveedores[] = [
                'id' => (int) $fila->id,
                'fiscal_profile_id' => (int) $fila->fiscal_profile_id,
                'code' => (string) $fila->code,
                'name' => (string) $fila->name,
                'kind' => (string) $fila->kind,
            ];
        }

        return $proveedores;
    }

    /**
     * Los PAISES del catalogo.
     *
     * @return list<array<string, mixed>>
     */
    private function countryOptions(): array
    {
        $filas = $this->connection->table('countries')->orderBy('name')->get(['id', 'iso_code', 'name']);

        $paises = [];

        foreach ($filas as $fila) {
            $paises[] = [
                'id' => (int) $fila->id,
                'iso_code' => (string) $fila->iso_code,
                'name' => (string) $fila->name,
            ];
        }

        return $paises;
    }

    /**
     * Las MONEDAS del catalogo.
     *
     * @return list<array<string, mixed>>
     */
    private function currencyOptions(): array
    {
        $filas = $this->connection->table('currencies')->orderBy('code')->get(['id', 'code', 'name']);

        $monedas = [];

        foreach ($filas as $fila) {
            $monedas[] = [
                'id' => (int) $fila->id,
                'code' => (string) $fila->code,
                'name' => (string) $fila->name,
            ];
        }

        return $monedas;
    }

    /**
     * Los PAPELES de serie, del conjunto cerrado.
     *
     * @return list<array{value: string, label: string}>
     */
    private function roles(): array
    {
        $roles = [];

        foreach (SeriesRole::cases() as $papel) {
            $roles[] = [
                'value' => $papel->value,
                'label' => $papel->label(),
            ];
        }

        return $roles;
    }

    /**
     * Las AUTORIDADES que asignan el numero, del conjunto cerrado.
     *
     * @return list<array{value: string, label: string}>
     */
    private function authorities(): array
    {
        $autoridades = [];

        foreach (SeriesAuthority::cases() as $autoridad) {
            $autoridades[] = [
                'value' => $autoridad->value,
                'label' => $autoridad->label(),
            ];
        }

        return $autoridades;
    }

    /**
     * Los MODELOS de firma, del conjunto cerrado.
     *
     * @return list<array{value: string, label: string}>
     */
    private function signerModels(): array
    {
        $etiquetas = [
            'authority' => 'The authority signs',
            'psp' => 'The payment service provider signs',
            'printer' => 'The printer signs',
            'legal_signature' => 'A legal signature signs',
        ];

        $modelos = [];

        foreach (SignerModel::cases() as $modelo) {
            $modelos[] = [
                'value' => $modelo->value,
                'label' => $etiquetas[$modelo->value],
            ];
        }

        return $modelos;
    }

    /**
     * Las REGLAS de redondeo, del conjunto cerrado.
     *
     * REGLA que fija este metodo: *el conjunto cerrado lo manda el servidor*.
     * Una lista de reglas escrita en la pantalla es una lista que se queda
     * vieja, y el motor de impuestos usa la del dominio.
     *
     * @return list<array{value: string, label: string}>
     */
    private function roundingRules(): array
    {
        $etiquetas = [
            'line' => 'Round every line',
            'total' => 'Round the total',
        ];

        $reglas = [];

        foreach (RoundingRule::cases() as $regla) {
            $reglas[] = [
                'value' => $regla->value,
                'label' => $etiquetas[$regla->value],
            ];
        }

        return $reglas;
    }

    /**
     * Los CANALES de emision, con la marca de los que no emiten.
     *
     * @return list<array{value: string, label: string, has_implementation: bool}>
     */
    private function kinds(): array
    {
        $etiquetas = [
            'printer' => 'The local printer',
            'pac' => 'An external provider (PAC)',
            'portal' => 'The tax authority portal',
        ];

        $canales = [];

        foreach (ProviderKind::cases() as $tipo) {
            $canales[] = [
                'value' => $tipo->value,
                'label' => $etiquetas[$tipo->value],
                'has_implementation' => FiscalChannels::tieneImplementacion($tipo->value),
            ];
        }

        return $canales;
    }

    /**
     * EL AMBITO de tiendas del usuario: donde puede declarar.
     *
     * Lo resuelve `PosContext` —el mismo que usa la caja— y aqui no se
     * reimplementa. `null` significa "todas las tiendas".
     *
     * @return list<int>|null
     */
    private function ambito(Request $request): ?array
    {
        return (new PosContext($this->connection, $request->user()?->id))->scopeIds();
    }

    /**
     * La TIENDA donde se declara tiene que estar en el ambito.
     *
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function exigeTiendaEnAmbito(Request $request, int $storeId): ?string
    {
        $ambito = $this->ambito($request);

        if ($ambito === null || in_array($storeId, $ambito, true)) {
            return null;
        }

        return "La tienda {$storeId} no esta en el ambito de tiendas del usuario: "
            .'una serie solo se declara en una tienda donde se puede operar.';
    }

    /**
     * La CARGA no puede traer un secreto: el secreto vive en el entorno.
     *
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function exigeSinSecreto(Request $request): ?string
    {
        $claves = array_map('strtolower', array_keys($request->all()));

        $rechazadas = array_values(array_intersect($claves, self::CLAVES_SECRETAS));

        if ($rechazadas === []) {
            return null;
        }

        return 'El secreto de un proveedor fiscal NUNCA esta en la base de datos: declare el PREFIJO de la '
            .'variable de entorno en «credential_env_prefix» y ponga el secreto ahi. La clave «'
            .implode('», «', $rechazadas).'» no se acepta.';
    }

    /**
     * VALIDA la entrada de una escritura.
     *
     * REGLA que fija este metodo: *la FORMA la comprueba la costura y el
     * CONTENIDO el registro*. Y el fallo es el del contrato: un 422 con
     * `{"errorMsg": "..."}`.
     *
     * @param  array<int, mixed>  $reglas
     * @return string|null El mensaje del error, o `null` si todo esta bien.
     */
    private function valida(Request $request, array $reglas): ?string
    {
        $validador = Validator::make($request->all(), $reglas);

        if ($validador->fails()) {
            return (string) $validador->errors()->first();
        }

        return null;
    }

    /**
     * El CONTEXTO de la auditoria de una serie: lo que se declaro.
     *
     * @return array<string, mixed>
     */
    private function contextoDeLaSerie(FiscalSeries $serie): array
    {
        return [
            'fiscal_profile_id' => (int) $serie->fiscal_profile_id,
            'store_id' => (int) $serie->store_id,
            'series_role' => (string) $serie->series_role,
            'assigned_by' => (string) $serie->assigned_by,
            'prefix' => $serie->prefix,
            'from_number' => (int) $serie->from_number,
            'to_number' => (int) $serie->to_number,
            'valid_from' => $serie->valid_from === null ? null : $serie->valid_from->format('Y-m-d'),
            'valid_to' => $serie->valid_to === null ? null : $serie->valid_to->format('Y-m-d'),
            'credential_ref' => $serie->credential_ref,
        ];
    }

    /**
     * El CONTEXTO de la auditoria de un perfil: lo que se declaro.
     *
     * @return array<string, mixed>
     */
    private function contextoDelPerfil(FiscalProfile $perfil): array
    {
        return [
            'country_id' => (int) $perfil->country_id,
            'code' => (string) $perfil->code,
            'name' => (string) $perfil->name,
            'signer_model' => (string) $perfil->signer_model,
            'default_currency_id' => $perfil->default_currency_id === null ? null : (int) $perfil->default_currency_id,
            'effective_from' => $perfil->effective_from === null ? null : $perfil->effective_from->format('Y-m-d'),
            'effective_to' => $perfil->effective_to === null ? null : $perfil->effective_to->format('Y-m-d'),
        ];
    }

    /**
     * El CONTEXTO de la auditoria de un proveedor: lo que se declaro.
     *
     * Y EL SECRETO NO ESTA: el prefijo si, y el secreto nunca (no hay).
     *
     * @return array<string, mixed>
     */
    private function contextoDelProveedor(FiscalProvider $proveedor): array
    {
        return [
            'fiscal_profile_id' => (int) $proveedor->fiscal_profile_id,
            'code' => (string) $proveedor->code,
            'name' => (string) $proveedor->name,
            'kind' => (string) $proveedor->kind,
            'base_url' => $proveedor->base_url,
            'auth_scheme' => $proveedor->auth_scheme,
            'credential_env_prefix' => $proveedor->credential_env_prefix,
            'is_active' => (bool) $proveedor->is_active,
        ];
    }
}
