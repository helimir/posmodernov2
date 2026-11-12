<?php

use App\Models\Currency;
use App\Models\Store;
use App\Models\User;
use App\Support\Pos\PosContext;
use Illuminate\Support\Facades\DB;

/**
 * 1.13 — la TIENDA resuelve su PERFIL FISCAL.
 *
 * EL DEFECTO que esta prueba fija: *la tienda resolvia su perfil fiscal por
 * el identificador de su PAIS, como si ese identificador fuera el del perfil*.
 * `PosContext::resolveSeries()` leia `stores.country_id` y lo pasaba a
 * `seriesIdOfRole()` como si fuera un `fiscal_profile_id`.
 *
 * ESO FUNCIONABA POR CASUALIDAD: en la tienda de demostracion el pais
 * (1) y el perfil (1) coinciden. En una tienda de OTRO pais, o simplemente
 * si el orden de siembra cambia, la tienda resuelve las series de otro perfil
 * o no resuelve ninguna y cae al "la primera serie por rol", que es una caja
 * que NO es de su pais.
 *
 * Y ADEMAS el metodo no miraba la VIGENCIA del perfil
 * (`fiscal_profiles.effective_from` / `effective_to`): una tienda se quedaba
 * con el perfil de una version antigua del regimen.
 *
 * LA REGLA que esta prueba fija:
 *
 *  - la tienda resuelve su perfil por su PAIS, CON VIGENCIA, y si declara un
 *    perfil propio, ese manda;
 *  - si la tienda declara pais y NO hay perfil vigente, el fallo es UTIL (que
 *    tienda, que pais y que no hay perfil vigente) y NO se cae a "la primera
 *    serie por rol": esa serie puede ser de otro pais, y una serie ajena no
 *    se ve;
 *  - la tienda que NO declara pais conserva el fallback (la primera serie por
 *    rol): no hay forma de saber nada mas.
 *
 * LOS IDENTIFICADORES DE ESTA PRUEBA NO COINCIDEN A PROPOSITO: el pais y su
 * perfil tienen numeros distintos, para que la confusion entre uno y otro se
 * VE. Una prueba en la que el pais y el perfil comparten numero passaria
 * con el codigo defectuoso.
 */

/**
 * El identificador del PAIS de la prueba.
 *
 * LA PRUEBA DEL DEFECTO necesita que el numero del PAIS y el del PERFIL
 * puedan coincidir a proposito: con el codigo defectuoso, la tienda resolvia
 * las series del perfil cuyo identificador coincidia con el del pais. En la
 * semilla de demostracion ese accidente es real (el pais y el perfil
 * estiveram el numero 1), asi que la prueba lo REPRODUCE con un
 * identificador fijo: con numeros que dependen de como advanced los
 * contadores, la prueba dejaria de comprobar nada.
 */
const ID_PAIS_DE_PRUEBA = 900001;

/**
 * Un PAIS con su MONEDA y su identificador ELEGIDO.
 */
function resolucion_pais(string $iso, string $codigoMoneda, int $id): int
{
    $moneda = Currency::create([
        'code' => $codigoMoneda,
        'name' => 'Moneda de prueba '.$codigoMoneda,
        'decimals' => 2,
    ]);

    DB::table('countries')->insert([
        'id' => $id,
        'iso_code' => $iso,
        'name' => 'Pais de prueba '.$iso,
        'default_currency_id' => $moneda->id,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

/**
 * Un PERFIL FISCAL de un pais, con su VIGENCIA y su identificador ELEGIDO.
 */
function resolucion_perfil(int $countryId, string $code, ?string $desde, ?string $hasta, ?int $id = null): int
{
    $fila = [
        'country_id' => $countryId,
        'code' => $code,
        'name' => 'Perfil de prueba '.$code,
        'signer_model' => 'printer',
        'rounding_rule' => 'line',
        'effective_from' => $desde,
        'effective_to' => $hasta,
        'created_at' => now(),
        'updated_at' => now(),
    ];

    if ($id === null) {
        return (int) DB::table('fiscal_profiles')->insertGetId($fila);
    }

    DB::table('fiscal_profiles')->insert($fila + ['id' => $id]);

    return $id;
}

/**
 * Una TIENDA, con su pais y su perfil DECLARADO.
 */
function resolucion_tienda(?int $countryId, ?int $profileId): int
{
    $tienda = Store::create([
        'name' => 'Tienda de prueba',
        'country_id' => $countryId,
        'fiscal_profile_id' => $profileId,
        'status' => true,
    ]);

    return (int) $tienda->id;
}

/**
 * Una SERIE de un perfil en una tienda.
 */
function resolucion_serie(int $storeId, int $profileId, string $role): int
{
    $esDeControl = $role === 'control';

    return DB::table('fiscal_series')->insertGetId([
        'fiscal_profile_id' => $profileId,
        'store_id' => $storeId,
        'provider_id' => null,
        'series_role' => $role,
        'assigned_by' => $esDeControl ? 'printer' : 'issuer',
        'prefix' => $esDeControl ? null : 'P-',
        'next_number' => 1,
        'from_number' => 1,
        'to_number' => 999999,
        'valid_from' => null,
        'valid_to' => null,
        'credential_ref' => null,
        'control_identifier' => $esDeControl ? '00' : null,
        'sequence_length' => $esDeControl ? 8 : null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);
}

/**
 * Un USUARIO con su vinculo por la tienda: la caja abre en ESA tienda.
 */
function resolucion_usuario(int $storeId): int
{
    $usuario = User::factory()->create();

    DB::table('user_stores')->insert([
        'user_id' => $usuario->id,
        'store_id' => $storeId,
        'status' => 'active',
        'sort_order' => 0,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return (int) $usuario->id;
}

/**
 * El MENSAJE de un fallo, o una cadena vacia si no hubo fallo.
 */
function resolucion_mensaje(callable $accion): string
{
    try {
        $accion();
    } catch (RuntimeException $fallo) {
        return $fallo->getMessage();
    }

    return '';
}

/**
 * 1.13 — la tienda resuelve las series del PERFIL VIGENTE de su pais.
 *
 * REGLA que fija: *la serie de la tienda es la de su perfil fiscal, y el
 * perfil es el vigente de su pais*. La tienda tiene series de VARIOS
 * perfiles (como la de demostracion, que tiene las de los cinco paises), y
 * la que vale es la del pais de la tienda.
 */
test('test_la_tienda_resuelve_las_series_del_perfil_vigente_de_su_pais', function () {
    $paisId = resolucion_pais('ZZ', 'ZZA', ID_PAIS_DE_PRUEBA);
    $otroPaisId = resolucion_pais('ZY', 'ZZB', ID_PAIS_DE_PRUEBA + 1);

    // El perfil AJENO lleva el MISMO identificador que el PAIS: es EL
    // ACCIDENTE de la semilla de demostracion (el numero del pais coincide
    // con el de un perfil), reproducido a proposito. Con el codigo
    // defectuoso, la tienda resolvia las series de ESE perfil.
    $perfilAjenoId = resolucion_perfil($otroPaisId, 'ZY_AJENO', '2020-01-01', null, ID_PAIS_DE_PRUEBA);
    $perfilId = resolucion_perfil($paisId, 'ZZ_VIGENTE', '2020-01-01', null);

    expect($perfilAjenoId)->toBe($paisId)
        ->and($perfilId)->not->toBe($paisId);

    $tiendaId = resolucion_tienda($paisId, null);

    // La serie AJENA se crea PRIMERO: el "la primera serie por rol" del
    // codigo defectuoso la cogeria, y esa serie es de otro pais.
    $serieAjena = resolucion_serie($tiendaId, $perfilAjenoId, 'document');
    $seriePropia = resolucion_serie($tiendaId, $perfilId, 'document');
    $controlPropio = resolucion_serie($tiendaId, $perfilId, 'control');

    $contexto = new PosContext(DB::connection(), resolucion_usuario($tiendaId));

    expect($contexto->documentSeriesId())->toBe($seriePropia)
        ->and($contexto->controlSeriesId())->toBe($controlPropio)
        ->and($contexto->documentSeriesId())->not->toBe($serieAjena);
});

/**
 * 1.13 — la tienda que DECLARA un perfil resuelve las series de ESE perfil.
 *
 * REGLA que fija: *el perfil declarado por la tienda MANDA sobre el del
 * pais*. Es el caso del regimen especial: una tienda que opera bajo un
 * regimen que no es el de su pais lo dice, y su decirlo es un dato.
 */
test('test_la_tienda_resuelve_las_series_del_perfil_que_declara', function () {
    $paisId = resolucion_pais('ZZ', 'ZZA', ID_PAIS_DE_PRUEBA);

    $perfilDelPaisId = resolucion_perfil($paisId, 'ZZ_VIGENTE', '2020-01-01', null);
    $perfilDeclaradoId = resolucion_perfil($paisId, 'ZZ_ESPECIAL', '2020-01-01', null);

    $tiendaId = resolucion_tienda($paisId, $perfilDeclaradoId);

    $serieDelPais = resolucion_serie($tiendaId, $perfilDelPaisId, 'document');
    $serieDeclarada = resolucion_serie($tiendaId, $perfilDeclaradoId, 'document');
    $controlDeclarado = resolucion_serie($tiendaId, $perfilDeclaradoId, 'control');

    $contexto = new PosContext(DB::connection(), resolucion_usuario($tiendaId));

    expect($contexto->documentSeriesId())->toBe($serieDeclarada)
        ->and($contexto->controlSeriesId())->toBe($controlDeclarado)
        ->and($contexto->documentSeriesId())->not->toBe($serieDelPais);
});

/**
 * 1.13 — un pais SIN perfil vigente es un FALLO UTIL, no una serie ajena.
 *
 * REGLA que fija: *una tienda que declara pais y no tiene perfil vigente NO
 * tiene series, y el fallo lo dice*. La tienda tiene una serie de otro
 * perfil (a proposito): si el codigo cayera al "la primera serie por rol",
 * la usaria, y esa serie es de OTRO pais.
 */
test('test_la_tienda_de_un_pais_sin_perfil_vigente_falla_con_un_mensaje_util', function () {
    $paisId = resolucion_pais('ZZ', 'ZZA', ID_PAIS_DE_PRUEBA);
    $otroPaisId = resolucion_pais('ZY', 'ZZB', ID_PAIS_DE_PRUEBA + 1);
    $perfilAjenoId = resolucion_perfil($otroPaisId, 'ZY_AJENO', '2020-01-01', null);

    $tiendaId = resolucion_tienda($paisId, null);

    // La serie AJENA: es la que el fallback cogeria.
    $serieAjena = resolucion_serie($tiendaId, $perfilAjenoId, 'document');

    $contexto = new PosContext(DB::connection(), resolucion_usuario($tiendaId));

    $mensaje = resolucion_mensaje(fn () => $contexto->documentSeriesId());

    // 1. El fallo dice QUE TIENDA, QUE PAIS y que NO HAY PERFIL VIGENTE.
    expect($mensaje)->not->toBe('')
        ->and($mensaje)->toContain('La tienda '.$tiendaId)
        ->and($mensaje)->toContain('pais '.$paisId)
        ->and($mensaje)->toContain('perfil fiscal vigente');

    // 2. Y la serie ajena NO se resuelve: el fallo es el fallo.
    expect(resolucion_mensaje(fn () => $contexto->documentSeriesId()))->not->toBe('')
        ->and($serieAjena)->not->toBeNull();
});

/**
 * 1.13 — el fallo de la resolucion sale POR PANTALLA, no como un 500.
 *
 * REGLA que fija: *una tienda sin perfil vigente NO es un error de servidor*.
 * La pantalla dice que la tienda no tiene perfil vigente y por que, con el
 * mismo mensaje que el dominio: un 500 esconde el motivo, y el motivo es lo
 * que hay que ver.
 */
test('test_la_pantalla_de_la_caja_dice_el_fallo_del_perfil_y_no_es_un_error_de_servidor', function () {
    $paisId = resolucion_pais('ZZ', 'ZZA', ID_PAIS_DE_PRUEBA);

    // El pais de la tienda NO tiene perfil vigente.
    $tiendaId = resolucion_tienda($paisId, null);
    $usuario = User::find(resolucion_usuario($tiendaId));

    $respuesta = $this->actingAs($usuario)->get('/caja');

    $respuesta->assertStatus(409)
        ->assertSee('perfil fiscal vigente');
});

/**
 * 1.13 — el CATALOGO tampoco es un 500 cuando la tienda no tiene perfil.
 *
 * REGLA que fija: *el fallo de la resolucion se dice en todas las pantallas
 * que necesitan el perfil de la tienda*. El catalogo ofrece las categorias
 * fiscales DEL PERFIL de la tienda, asi que sin perfil no puede abrir.
 */
test('test_la_pantalla_del_catalogo_dice_el_fallo_del_perfil_y_no_es_un_error_de_servidor', function () {
    $paisId = resolucion_pais('ZZ', 'ZZA', ID_PAIS_DE_PRUEBA);

    $tiendaId = resolucion_tienda($paisId, null);
    $usuario = User::find(resolucion_usuario($tiendaId));

    $respuesta = $this->actingAs($usuario)->get('/catalogo');

    $respuesta->assertStatus(409)
        ->assertSee('perfil fiscal vigente');
});

/**
 * 1.13 — la tienda SIN PAIS conserva el fallback: la primera serie por rol.
 *
 * REGLA que fija: *el fallback se queda, pero solo para la tienda que no
 * declara pais*. Sin pais no hay forma de saber mas, y una serie de la
 * tienda es mejor que ninguna.
 */
test('test_la_tienda_sin_pais_toma_la_primera_serie_por_rol', function () {
    $paisId = resolucion_pais('ZZ', 'ZZA', ID_PAIS_DE_PRUEBA);
    $perfilId = resolucion_perfil($paisId, 'ZZ_VIGENTE', '2020-01-01', null);

    $tiendaId = resolucion_tienda(null, null);

    $serieDocumento = resolucion_serie($tiendaId, $perfilId, 'document');
    $serieControl = resolucion_serie($tiendaId, $perfilId, 'control');

    $contexto = new PosContext(DB::connection(), resolucion_usuario($tiendaId));

    expect($contexto->documentSeriesId())->toBe($serieDocumento)
        ->and($contexto->controlSeriesId())->toBe($serieControl);
});

/**
 * 1.13 — un perfil CADUCADO no se usa, y uno vigente si.
 *
 * REGLA que fija: *la vigencia manda*. El `effective_to` es el ULTIMO dia
 * del regimen (inclusivo), y un regimen cuyo ultimo dia ya paso no es el
 * regimen de la tienda.
 */
test('test_un_perfil_caducado_no_se_usa_y_un_perfil_vigente_si', function () {
    $paisId = resolucion_pais('ZZ', 'ZZA', ID_PAIS_DE_PRUEBA);

    $caducadoId = resolucion_perfil($paisId, 'ZZ_CADUCADO', '2020-01-01', '2021-12-31');
    $vigenteId = resolucion_perfil($paisId, 'ZZ_VIGENTE', '2022-01-01', null);

    $tiendaId = resolucion_tienda($paisId, null);

    $serieCaducada = resolucion_serie($tiendaId, $caducadoId, 'document');
    $serieVigente = resolucion_serie($tiendaId, $vigenteId, 'document');

    $contexto = new PosContext(DB::connection(), resolucion_usuario($tiendaId));

    expect($contexto->documentSeriesId())->toBe($serieVigente)
        ->and($contexto->documentSeriesId())->not->toBe($serieCaducada);
});

/**
 * 1.13 — un pais cuyo UNICO perfil esta caducado NO tiene perfil vigente.
 *
 * REGLA que fija: *un regimen caducado no es un regimen*. Un pais con un
 * solo perfil, y ese caducado, es un pais sin perfil vigente.
 */
test('test_un_pais_cuyo_unico_perfil_esta_caducado_no_tiene_perfil_vigente', function () {
    $paisId = resolucion_pais('ZZ', 'ZZA', ID_PAIS_DE_PRUEBA);
    $caducadoId = resolucion_perfil($paisId, 'ZZ_CADUCADO', '2020-01-01', '2021-12-31');

    $tiendaId = resolucion_tienda($paisId, null);
    $serieCaducada = resolucion_serie($tiendaId, $caducadoId, 'document');

    $contexto = new PosContext(DB::connection(), resolucion_usuario($tiendaId));

    $mensaje = resolucion_mensaje(fn () => $contexto->documentSeriesId());

    expect($mensaje)->not->toBe('')
        ->and($mensaje)->toContain('La tienda '.$tiendaId)
        ->and($mensaje)->toContain('pais '.$paisId);
});

/**
 * 1.13 — con VARIOS perfiles vigentes, manda el mas reciente.
 *
 * REGLA que fija: *el desempate es DETERMINISTA*: el perfil vigente mas
 * reciente (`effective_from` mas nuevo) y, a igualdad de vigencia, el de
 * identificador mas alto. Sin ese desempate, dos cajas podrian resolver
 * perfiles distintos el mismo dia.
 */
test('test_cuando_hay_varios_perfiles_vigentes_manda_el_mas_reciente', function () {
    $paisId = resolucion_pais('ZZ', 'ZZA', ID_PAIS_DE_PRUEBA);

    $antiguoId = resolucion_perfil($paisId, 'ZZ_ANTIGUO', '2020-01-01', null);
    $recienteId = resolucion_perfil($paisId, 'ZZ_RECIENTE', '2023-01-01', null);

    $tiendaId = resolucion_tienda($paisId, null);

    $serieAntigua = resolucion_serie($tiendaId, $antiguoId, 'document');
    $serieReciente = resolucion_serie($tiendaId, $recienteId, 'document');

    $contexto = new PosContext(DB::connection(), resolucion_usuario($tiendaId));

    expect($contexto->documentSeriesId())->toBe($serieReciente)
        ->and($contexto->documentSeriesId())->not->toBe($serieAntigua);
});

/**
 * 1.13 — a igual de vigencia, manda el perfil de identificador mas alto.
 *
 * REGLA que fija: *el desempate tiene un segundo criterio*. Dos perfiles que
 * entran en vigor el mismo dia son la misma version del regimen, y el de
 * identificador mas alto es el que se ha sembrado despues.
 */
test('test_a_igual_de_vigencia_manda_el_perfil_de_identificador_mas_alto', function () {
    $paisId = resolucion_pais('ZZ', 'ZZA', ID_PAIS_DE_PRUEBA);

    $primeroId = resolucion_perfil($paisId, 'ZZ_PRIMERO', '2022-01-01', null);
    $segundoId = resolucion_perfil($paisId, 'ZZ_SEGUNDO', '2022-01-01', null);

    expect($segundoId)->toBeGreaterThan($primeroId);

    $tiendaId = resolucion_tienda($paisId, null);

    $seriePrimera = resolucion_serie($tiendaId, $primeroId, 'document');
    $serieSegunda = resolucion_serie($tiendaId, $segundoId, 'document');

    $contexto = new PosContext(DB::connection(), resolucion_usuario($tiendaId));

    expect($contexto->documentSeriesId())->toBe($serieSegunda)
        ->and($contexto->documentSeriesId())->not->toBe($seriePrimera);
});

/**
 * 1.13 — un perfil declarado de OTRO pais es un error explicito.
 *
 * REGLA que fija: *un perfil de otro pais es un error de DATOS, no un fallo
 * silencioso*. Emitir con el regimen de otro pais es peor que no emitir, y
 * el mensaje dice que tienda es, que perfil declara, de que pais es ese
 * perfil y de que pais es la tienda.
 */
test('test_el_perfil_declarado_de_otro_pais_es_un_error_explicito', function () {
    $paisId = resolucion_pais('ZZ', 'ZZA', ID_PAIS_DE_PRUEBA);
    $otroPaisId = resolucion_pais('ZY', 'ZZB', ID_PAIS_DE_PRUEBA + 1);
    $perfilAjenoId = resolucion_perfil($otroPaisId, 'ZY_AJENO', '2020-01-01', null);

    $tiendaId = resolucion_tienda($paisId, $perfilAjenoId);

    $contexto = new PosContext(DB::connection(), resolucion_usuario($tiendaId));

    $mensaje = resolucion_mensaje(fn () => $contexto->documentSeriesId());

    expect($mensaje)->not->toBe('')
        ->and($mensaje)->toContain('La tienda '.$tiendaId)
        ->and($mensaje)->toContain('perfil fiscal '.$perfilAjenoId)
        ->and($mensaje)->toContain('pais '.$otroPaisId)
        ->and($mensaje)->toContain('error de datos');
});
