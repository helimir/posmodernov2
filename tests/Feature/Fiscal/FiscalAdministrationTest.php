<?php

use App\Domain\Auth\Enums\Permission;
use App\Models\Role;
use App\Models\Store;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia;
use Tests\Fixtures\PermissionFixture;
use Tests\Fixtures\PosFixture;

/**
 * F3-01 — la ADMINISTRACION FISCAL: las series, los perfiles y los
 * proveedores que hoy solo se siembran.
 *
 * REGLA que fija: *una tienda se instala desde la aplicacion*: la serie de
 * documento, la de control, el perfil fiscal y el proveedor se dan de alta
 * desde la pantalla, sin tocar la base de datos a mano.
 *
 * Y LO QUE LA UNIDAD NO HACE, Y POR QUE:
 *
 *  - LA NUMERACION NO SE REPARTE AQUI: el correlativo lo reserva el DOMINIO
 *    (`NumberSeries`, con `UPDATE ... RETURNING`), y el alta de una serie NO
 *    consume ningun numero: la serie empieza en el principio de su rango y el
 *    contador solo lo mueve el dominio;
 *  - UNA SERIE YA DECLARADA NO SE RENUMERA: el perfil, la tienda, el rol, la
 *    autoridad, el prefijo, el rango y el contador son la identidad de la
 *    numeracion, y se declaran UNA vez. Editar una serie es cerrar su
 *    vigencia, anotar su autorizacion y decir que imprenta la respalda;
 *  - UN PERFIL ES UN REGIMEN: su codigo, su pais, su moneda, su vigencia y su
 *    modelo de firma se declaran al darlo de alta. Editarlo es corregir COMO
 *    SE ESCRIBE (la descripcion tecnica) y su nombre;
 *  - EL CANAL FISCAL ES DATO: el `kind` del proveedor es el canal, y el
 *    canal no se cambia: se apaga. Un canal `pac` o `portal` NO tiene
 *    implementacion en esta version, y la pantalla lo dice en vez de fingir
 *    que emite;
 *  - EL SECRETO DE UN PROVEEDOR NUNCA ESTA EN LA BASE: solo el PREFIJO de la
 *    variable de entorno. La pantalla no pide contrasenas y no las guarda.
 *
 * Y LA COMPROBACION ESTA EN EL SERVIDOR: el permiso protege la ruta, y el
 * boton de la pantalla se oculta con el MISMO slug.
 *
 * Y EL PERMISO ES EL DEL MODULO, COMO EL DE LA IMPRESION: la administracion
 * fiscal es UN area con tres pantallas, y el area tiene su modulo (`fiscal`)
 * y sus tres acciones (`read`, `create`, `update`). El catalogo entero usa
 * la forma `{modulo}.{accion}`, y un modulo con tres pantallas se reparte
 * como se reparte la impresion.
 */

/**
 * El ADMINISTRADOR fiscal: una persona con los permisos de esta unidad.
 *
 * @param  list<string>  $permisos  Los slugs del catalogo; por defecto,
 *                                  todos los de la administracion fiscal.
 */
function admin_fiscal(int $storeId, array $permisos = []): User
{
    PermissionFixture::catalogo();

    if ($permisos === []) {
        $permisos = [
            Permission::FiscalRead->value,
            Permission::FiscalCreate->value,
            Permission::FiscalUpdate->value,
        ];
    }

    $rol = PermissionFixture::rol('fiscal-'.uniqid(), $permisos);

    return PermissionFixture::usuario($rol, $storeId);
}

/**
 * Los DATOS del escenario fiscal: el perfil, su pais y su moneda.
 *
 * @return array{profile_id: int, country_id: int, currency_id: int}
 */
function datos_fiscales(int $profileId): array
{
    $perfil = DB::table('fiscal_profiles')->where('id', $profileId)->first();

    $paisId = (int) DB::table('countries')
        ->where('iso_code', DB::table('countries')->where('id', (int) $perfil->country_id)->value('iso_code'))
        ->value('id');

    return [
        'profile_id' => (int) $profileId,
        'country_id' => $paisId,
        'currency_id' => (int) $perfil->default_currency_id,
    ];
}

/**
 * Una SERIE creada por la API, con los datos de una serie de RETENCION: el
 * rol que la tienda de demostracion no tiene, para no chocar con lo sembrado.
 *
 * @param  array<string, mixed>  $cambios
 * @return array<string, mixed>
 */
function serie_nueva(int $profileId, int $storeId, array $cambios = []): array
{
    return array_merge([
        'fiscal_profile_id' => $profileId,
        'store_id' => $storeId,
        'series_role' => 'retention_receipt',
        'assigned_by' => 'issuer',
        'prefix' => 'RET-001',
        'from_number' => 1,
        'to_number' => 100,
        'valid_from' => '2026-01-01',
        'credential_ref' => 'Rango declarado por el instalador',
    ], $cambios);
}

/**
 * La SERIE creada aparece en el listado, con su tienda y con su perfil.
 *
 * REGLA que fija: *la serie creada aparece en el listado con su tienda y su
 * perfil*. Una serie sin nombre de tienda ni de perfil es un numero suelto:
 * el listado dice de que tienda es y de que regimen es.
 */
test('test_la_serie_creada_aparece_en_el_listado_con_su_tienda_y_su_perfil', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $respuesta = $this->actingAs($admin)->postJson('/api/fiscal/series', serie_nueva(
        $escenario['profile_id'],
        $escenario['store_id'],
    ));

    $respuesta->assertCreated()->assertJsonPath('series_role', 'retention_receipt');

    // 1. La fila existe con lo declarado.
    $serie = DB::table('fiscal_series')
        ->where('prefix', 'RET-001')
        ->where('store_id', $escenario['store_id'])
        ->first();

    expect($serie)->not->toBeNull('La serie no se dio de alta.')
        ->and((int) $serie->fiscal_profile_id)->toBe($escenario['profile_id'])
        ->and((string) $serie->series_role)->toBe('retention_receipt')
        ->and((string) $serie->assigned_by)->toBe('issuer')
        ->and((int) $serie->from_number)->toBe(1)
        ->and((int) $serie->to_number)->toBe(100);

    // 2. Y el listado la enseña CON su tienda y CON su perfil.
    $listado = $this->actingAs($admin)->getJson('/api/fiscal/series');

    $listado->assertOk();

    $enElListado = collect($listado->json('series'))
        ->firstWhere('prefix', 'RET-001');

    expect($enElListado)->not->toBeNull('La serie no aparece en el listado.')
        ->and((int) $enElListado['store_id'])->toBe($escenario['store_id'])
        ->and($enElListado['store_name'])->not->toBe('')
        ->and((int) $enElListado['fiscal_profile_id'])->toBe($escenario['profile_id'])
        ->and($enElListado['profile_code'])->not->toBe('');
});

/**
 * La serie creada NO reparte ningun numero: el contador empieza en el
 * principio del rango y lo unico que lo mueve es el dominio.
 *
 * REGLA que fija: *dar de alta una serie no consume ningun numero*. El
 * correlativo lo reserva `NumberSeries` con `UPDATE ... RETURNING`, y un alta
 * que repartiera un numero seria un numero quemado sin traza.
 */
test('test_la_serie_creada_no_reparte_ningun_numero_y_su_contador_empieza_en_el_rango', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $this->actingAs($admin)->postJson('/api/fiscal/series', serie_nueva(
        $escenario['profile_id'],
        $escenario['store_id'],
    ))->assertCreated();

    $serie = DB::table('fiscal_series')->where('prefix', 'RET-001')->first();

    expect($serie)->not->toBeNull()
        // El contador empieza en el principio del rango: NADIE ha repartido nada.
        ->and((int) $serie->next_number)->toBe((int) $serie->from_number)
        // Y no hay traza de reservas: no se ha quemado ningun numero.
        ->and(DB::table('fiscal_document_reservations')->where('series_id', (int) $serie->id)->count())->toBe(0);
});

/**
 * Dos series del MISMO rol en la MISMA tienda y con el MISMO perfil se
 * rechazan: la resolucion toma la primera, y dos series de un rol son una
 * ambiguedad.
 *
 * REGLA que fija: *una tienda tiene UNA serie de cada rol dentro de un
 * perfil*. El resolutor de series (`PosContext`) toma la primera por
 * identificador, y con dos seria la numeracion dependeria del orden de las
 * filas.
 */
test('test_dos_series_del_mismo_rol_en_la_misma_tienda_se_rechazan', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $this->actingAs($admin)->postJson('/api/fiscal/series', serie_nueva(
        $escenario['profile_id'],
        $escenario['store_id'],
    ))->assertCreated();

    $repetida = $this->actingAs($admin)->postJson('/api/fiscal/series', serie_nueva(
        $escenario['profile_id'],
        $escenario['store_id'],
        ['prefix' => 'RET-002'],
    ));

    $repetida->assertStatus(422);

    $mensaje = (string) $repetida->json('errorMsg');

    expect($mensaje)->toContain('retention_receipt')
        ->and($mensaje)->not->toContain('violacion de restriccion')
        ->and($mensaje)->not->toContain('duplicate key');

    // Y no se ha creado la segunda.
    expect(DB::table('fiscal_series')->where('prefix', 'RET-002')->count())->toBe(0);
});

/**
 * La EDICION de una serie no toca la numeracion: ni el contador, ni el
 * rango, ni el prefijo, ni el perfil, ni la tienda.
 *
 * REGLA que fija: *una serie declarada no se renumera*. El perfil, la tienda,
 * el rol, la autoridad, el prefijo, el rango y el contador son la identidad
 * de la numeracion: se declaran UNA vez y se quedan. Editar una serie es
 * cerrar su vigencia y anotar su autorizacion.
 */
test('test_la_edicion_de_la_serie_no_toca_el_contador_ni_el_rango_ni_el_prefijo', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $serie = DB::table('fiscal_series')
        ->where('store_id', $escenario['store_id'])
        ->where('fiscal_profile_id', $escenario['profile_id'])
        ->where('series_role', 'document')
        ->first();

    expect($serie)->not->toBeNull('El escenario no tiene la serie de documento.');

    $antes = [
        'next_number' => (int) $serie->next_number,
        'from_number' => (int) $serie->from_number,
        'to_number' => (int) $serie->to_number,
        'prefix' => (string) $serie->prefix,
        'fiscal_profile_id' => (int) $serie->fiscal_profile_id,
        'store_id' => (int) $serie->store_id,
        'series_role' => (string) $serie->series_role,
        'assigned_by' => (string) $serie->assigned_by,
    ];

    // 1. La edicion VALIDA: la vigencia y la referencia a la autorizacion.
    $this->actingAs($admin)->putJson('/api/fiscal/series/'.$serie->id, [
        'valid_from' => '2026-01-01',
        'valid_to' => '2026-12-31',
        'credential_ref' => 'Autorizacion de la imprenta, documento 1234',
    ])->assertOk();

    $despues = DB::table('fiscal_series')->where('id', $serie->id)->first();

    expect((string) $despues->valid_to)->toBe('2026-12-31')
        ->and((string) $despues->credential_ref)->toBe('Autorizacion de la imprenta, documento 1234');

    // 2. Y el intento de RENUMERAR: ni el contador, ni el rango, ni el
    //    prefijo, ni el perfil, ni la tienda se mueven.
    $this->actingAs($admin)->putJson('/api/fiscal/series/'.$serie->id, [
        'valid_from' => '2026-01-01',
        'valid_to' => '2027-12-31',
        'next_number' => 999999,
        'from_number' => 500,
        'to_number' => 600,
        'prefix' => 'HACK',
        'fiscal_profile_id' => 999999,
        'store_id' => 999999,
        'series_role' => 'control',
        'assigned_by' => 'printer',
    ])->assertOk();

    $tras = DB::table('fiscal_series')->where('id', $serie->id)->first();

    expect((int) $tras->next_number)->toBe($antes['next_number'])
        ->and((int) $tras->from_number)->toBe($antes['from_number'])
        ->and((int) $tras->to_number)->toBe($antes['to_number'])
        ->and((string) $tras->prefix)->toBe($antes['prefix'])
        ->and((int) $tras->fiscal_profile_id)->toBe($antes['fiscal_profile_id'])
        ->and((int) $tras->store_id)->toBe($antes['store_id'])
        ->and((string) $tras->series_role)->toBe($antes['series_role'])
        ->and((string) $tras->assigned_by)->toBe($antes['assigned_by']);
});

/**
 * La IMPRENTA de una serie es del MISMO perfil que la serie.
 *
 * REGLA que fija: *la imprenta que respalda un rango pertenece al regimen
 * que declara ese rango*. Una serie del perfil A con la imprenta del perfil B
 * es una serie que dice una cosa y significa otra: el perfil es el regimen,
 * y la imprenta es un actor de ESE regimen. El esquema no lo impone (la FK
 * es suelta), asi que lo impone la pantalla.
 */
test('test_la_imprenta_de_una_serie_es_del_mismo_perfil_que_la_serie', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    // Un proveedor de OTRO perfil: el de la tienda de demostracion no.
    $otroPerfil = (int) DB::table('fiscal_profiles')
        ->where('id', '!=', $escenario['profile_id'])
        ->orderBy('id')
        ->value('id');

    expect($otroPerfil)->toBeGreaterThan(0);

    $proveedorAjeno = (int) DB::table('fiscal_providers')->insertGetId([
        'fiscal_profile_id' => $otroPerfil,
        'code' => 'imprenta_de_otro_perfil',
        'name' => 'Imprenta de otro regimen',
        'kind' => 'printer',
        'base_url' => null,
        'auth_scheme' => null,
        'credentials_ref' => null,
        'is_active' => true,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $respuesta = $this->actingAs($admin)->postJson('/api/fiscal/series', serie_nueva(
        $escenario['profile_id'],
        $escenario['store_id'],
        ['provider_id' => $proveedorAjeno],
    ));

    $respuesta->assertStatus(422);

    expect((string) $respuesta->json('errorMsg'))->toContain('perfil');

    // Y la serie NO se ha creado.
    expect(DB::table('fiscal_series')->where('prefix', 'RET-001')->count())->toBe(0);
});

/**
 * El PERFIL creado aparece en el listado con su pais y con su moneda.
 *
 * REGLA que fija: *el perfil fiscal se da de alta con su pais y con su
 * moneda*. El perfil es el regimen de un pais, y sin moneda su comprobante
 * no se puede emitir.
 */
test('test_el_perfil_creado_aparece_en_el_listado_con_su_pais_y_su_moneda', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $datos = datos_fiscales($escenario['profile_id']);

    $respuesta = $this->actingAs($admin)->postJson('/api/fiscal/profiles', [
        'country_id' => $datos['country_id'],
        'code' => 'VE_INSTALACION',
        'name' => 'Regimen de instalacion',
        'signer_model' => 'printer',
        'default_currency_id' => $datos['currency_id'],
        'effective_from' => '2026-01-01',
        'document_standard' => 'seniat_means',
        'schema_version' => '1.0',
        'numbering_scheme' => 'serie_emisor+control',
        'requires_control_number' => true,
        'authorized_range_required' => true,
        'holes_allowed' => false,
        'rounding_rule' => 'line',
        'tax_inclusive_default' => false,
    ]);

    $respuesta->assertCreated()->assertJsonPath('code', 'VE_INSTALACION');

    $perfil = DB::table('fiscal_profiles')->where('code', 'VE_INSTALACION')->first();

    expect($perfil)->not->toBeNull('El perfil no se dio de alta.')
        ->and((int) $perfil->country_id)->toBe($datos['country_id'])
        ->and((int) $perfil->default_currency_id)->toBe($datos['currency_id'])
        ->and((string) $perfil->signer_model)->toBe('printer');

    // Y el listado lo enseña con su pais y con su moneda.
    $listado = $this->actingAs($admin)->getJson('/api/fiscal/profiles');

    $listado->assertOk();

    $enElListado = collect($listado->json('profiles'))->firstWhere('code', 'VE_INSTALACION');

    expect($enElListado)->not->toBeNull('El perfil no aparece en el listado.')
        ->and($enElListado['country_name'])->not->toBe('')
        ->and($enElListado['currency_code'])->not->toBe('');
});

/**
 * El perfil creado AVISA de que no declara el resto del regimen.
 *
 * REGLA que fija: *la pantalla no finge*. Un perfil declara el regimen de un
 * pais —su codigo, su moneda, quien firma y como se escribe el comprobante—
 * pero NO declara los tipos de documento, los esquemas de impuesto ni el
 * vocabulario de estados: eso viene del Manual Tecnico, y es de otra unidad.
 * Un perfil sin ellos no puede emitir, y la pantalla lo dice.
 */
test('test_el_perfil_creado_avisa_de_que_no_declara_el_resto_del_regimen', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $datos = datos_fiscales($escenario['profile_id']);

    $respuesta = $this->actingAs($admin)->postJson('/api/fiscal/profiles', [
        'country_id' => $datos['country_id'],
        'code' => 'VE_SIN_TIPOS',
        'name' => 'Regimen sin tipos de documento',
        'signer_model' => 'printer',
        'default_currency_id' => $datos['currency_id'],
    ]);

    $respuesta->assertCreated();

    $avisos = $respuesta->json('warnings');

    expect($avisos)->toBeArray()->not->toBeEmpty('El perfil incompleto no avisa de nada.')
        ->and(implode(' ', $avisos))->toContain('document types');
});

/**
 * Un perfil SIN MONEDA se rechaza: su comprobante no se puede emitir.
 *
 * REGLA que fija: *un perfil fiscal declara su moneda*. El emisor toma la
 * moneda del perfil (`FiscalIssuanceCoordinator`), y un perfil sin moneda no
 * puede emitir: se rechaza al darlo de alta, no en la primera venta.
 */
test('test_un_perfil_sin_moneda_se_rechaza_porque_no_puede_emitir', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $datos = datos_fiscales($escenario['profile_id']);

    $respuesta = $this->actingAs($admin)->postJson('/api/fiscal/profiles', [
        'country_id' => $datos['country_id'],
        'code' => 'VE_SIN_MONEDA',
        'name' => 'Perfil sin moneda',
        'signer_model' => 'printer',
    ]);

    $respuesta->assertStatus(422);

    expect((string) $respuesta->json('errorMsg'))->toContain('moneda');

    // Y no se ha creado el perfil.
    expect(DB::table('fiscal_profiles')->where('code', 'VE_SIN_MONEDA')->count())->toBe(0);
});

/**
 * La EDICION de un perfil no toca su moneda, ni su vigencia, ni su codigo:
 * el regimen se declara una vez.
 *
 * REGLA que fija: *un perfil fiscal es un regimen, y un regimen no se
 * muta*. Su codigo es su clave natural, su moneda es la del comprobante y su
 * vigencia decide que perfil resuelve un pais. Editar un perfil es corregir
 * COMO SE ESCRIBE y su nombre.
 */
test('test_la_edicion_del_perfil_no_toca_su_moneda_ni_su_vigencia_ni_su_codigo', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $perfil = DB::table('fiscal_profiles')->where('id', $escenario['profile_id'])->first();

    $antes = [
        'code' => (string) $perfil->code,
        'default_currency_id' => (int) $perfil->default_currency_id,
        'effective_from' => $perfil->effective_from === null ? null : (string) $perfil->effective_from,
        'effective_to' => $perfil->effective_to === null ? null : (string) $perfil->effective_to,
        'signer_model' => (string) $perfil->signer_model,
        'country_id' => (int) $perfil->country_id,
    ];

    // 1. La edicion VALIDA: el nombre y la descripcion tecnica (lo que la
    //    semilla deja a NULL porque el Manual Tecnico no se ha transcrito).
    $this->actingAs($admin)->putJson('/api/fiscal/profiles/'.$perfil->id, [
        'name' => 'Medios digitales transcritos',
        'schema_version' => '1.0',
        'xml_namespace' => 'http://www.seniat.gob.ve/medios',
        'xsd_url' => 'http://www.seniat.gob.ve/medios/medios.xsd',
    ])->assertOk();

    $despues = DB::table('fiscal_profiles')->where('id', $perfil->id)->first();

    expect((string) $despues->name)->toBe('Medios digitales transcritos')
        ->and((string) $despues->schema_version)->toBe('1.0')
        ->and((string) $despues->xml_namespace)->toBe('http://www.seniat.gob.ve/medios');

    // 2. Y el intento de MUTAR el regimen: no cambia ni el codigo, ni la
    //    moneda, ni la vigencia, ni el modelo de firma, ni el pais.
    $otraMoneda = (int) DB::table('currencies')->where('id', '!=', $antes['default_currency_id'])->value('id');

    $this->actingAs($admin)->putJson('/api/fiscal/profiles/'.$perfil->id, [
        'name' => 'Medios digitales transcritos',
        'code' => 'VE_CAMBIADO',
        'default_currency_id' => $otraMoneda,
        'effective_from' => '2030-01-01',
        'effective_to' => '2030-12-31',
        'signer_model' => 'authority',
        'country_id' => 999999,
    ])->assertOk();

    $tras = DB::table('fiscal_profiles')->where('id', $perfil->id)->first();

    expect((string) $tras->code)->toBe($antes['code'])
        ->and((int) $tras->default_currency_id)->toBe($antes['default_currency_id'])
        ->and((string) $tras->effective_from)->toBe((string) $antes['effective_from'])
        ->and((string) $tras->effective_to)->toBe((string) $antes['effective_to'])
        ->and((string) $tras->signer_model)->toBe($antes['signer_model'])
        ->and((int) $tras->country_id)->toBe($antes['country_id']);
});

/**
 * El PROVEEDOR creado aparece en el listado con su perfil y con su canal.
 *
 * REGLA que fija: *el proveedor fiscal se da de alta con su perfil y con su
 * canal*. El canal es DATO (`printer`, `pac` o `portal`), y el perfil es el
 * regimen al que pertenece.
 */
test('test_el_proveedor_creado_aparece_en_el_listado_con_su_perfil_y_su_canal', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $respuesta = $this->actingAs($admin)->postJson('/api/fiscal/proveedores', [
        'fiscal_profile_id' => $escenario['profile_id'],
        'code' => 'imprenta_instalacion',
        'name' => 'Imprenta de la instalacion',
        'kind' => 'printer',
        'base_url' => null,
        'auth_scheme' => null,
        'credentials_ref' => 'Contrato de prestacion, documento 77',
        'credential_env_prefix' => 'IMPRENTA_INSTALACION',
        'is_active' => true,
    ]);

    $respuesta->assertCreated()->assertJsonPath('code', 'imprenta_instalacion');

    $proveedor = DB::table('fiscal_providers')->where('code', 'imprenta_instalacion')->first();

    expect($proveedor)->not->toBeNull('El proveedor no se dio de alta.')
        ->and((int) $proveedor->fiscal_profile_id)->toBe($escenario['profile_id'])
        ->and((string) $proveedor->kind)->toBe('printer')
        ->and((string) $proveedor->credential_env_prefix)->toBe('IMPRENTA_INSTALACION')
        ->and((bool) $proveedor->is_active)->toBeTrue();

    // Y el listado lo enseña con su perfil.
    $listado = $this->actingAs($admin)->getJson('/api/fiscal/proveedores');

    $listado->assertOk();

    $enElListado = collect($listado->json('providers'))->firstWhere('code', 'imprenta_instalacion');

    expect($enElListado)->not->toBeNull('El proveedor no aparece en el listado.')
        ->and($enElListado['profile_code'])->not->toBe('')
        ->and($enElListado['kind'])->toBe('printer');
});

/**
 * El proveedor NO acepta una contrasena: el secreto vive en el entorno.
 *
 * REGLA que fija: *el secreto de un proveedor NUNCA esta en la base de
 * datos*. La fila declara el PREFIJO de la variable de entorno y el secreto
 * se resuelve en runtime. Una pantalla que guardara contrasenas seria un
 * cofre de copias.
 */
test('test_el_proveedor_no_acepta_una_contrasena_porque_el_secreto_vive_en_el_entorno', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $respuesta = $this->actingAs($admin)->postJson('/api/fiscal/proveedores', [
        'fiscal_profile_id' => $escenario['profile_id'],
        'code' => 'imprenta_con_secreto',
        'name' => 'Imprenta con un secreto en la carga',
        'kind' => 'printer',
        'password' => 'un-secreto-que-no-debe-existir',
        'credential_env_prefix' => 'IMPRENTA_CON_SECRETO',
    ]);

    $respuesta->assertStatus(422);

    $mensaje = (string) $respuesta->json('errorMsg');

    expect($mensaje)->toContain('password')
        ->and($mensaje)->toContain('credential_env_prefix');

    // Y no se ha creado el proveedor.
    expect(DB::table('fiscal_providers')->where('code', 'imprenta_con_secreto')->count())->toBe(0);
});

/**
 * Un proveedor de un canal SIN IMPLEMENTACION avisa de que no puede emitir.
 *
 * REGLA que fija: *un canal sin implementacion no se emula*. Los canales
 * `pac` y `portal` no tienen implementacion en esta version: la pantalla lo
 * dice, en vez de fingir que el comprobante sale.
 */
test('test_un_proveedor_de_un_canal_sin_implementacion_avisa_que_no_puede_emitir', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $respuesta = $this->actingAs($admin)->postJson('/api/fiscal/proveedores', [
        'fiscal_profile_id' => $escenario['profile_id'],
        'code' => 'portal_seniat',
        'name' => 'Portal del SENIAT',
        'kind' => 'portal',
        'base_url' => 'https://www.seniat.gob.ve/portal',
        'auth_scheme' => 'token',
        'credential_env_prefix' => 'PORTAL_SENIAT',
        'is_active' => true,
    ]);

    $respuesta->assertCreated();

    // 1. La respuesta DICE que ese canal no puede emitir.
    expect((bool) $respuesta->json('has_implementation'))->toBeFalse();

    $avisos = $respuesta->json('warnings');

    expect($avisos)->toBeArray()->not->toBeEmpty('El proveedor sin implementacion no avisa de nada.')
        ->and(implode(' ', $avisos))->toContain('portal');

    // 2. Y el listado tambn lo dice.
    $listado = $this->actingAs($admin)->getJson('/api/fiscal/proveedores');

    $enElListado = collect($listado->json('providers'))->firstWhere('code', 'portal_seniat');

    expect($enElListado)->not->toBeNull()
        ->and((bool) $enElListado['has_implementation'])->toBeFalse();

    // 3. Y el canal local SI puede emitir: el aviso no es para todos.
    $local = collect($listado->json('providers'))->firstWhere('code', 'local_printer');

    expect($local)->not->toBeNull()
        ->and((bool) $local['has_implementation'])->toBeTrue();
});

/**
 * Un proveedor APAGADO avisa de que por ese canal no sale un comprobante.
 *
 * REGLA que fija: *un canal apagado no emite, y la pantalla lo dice*. El
 * dominio se niega a resolver un proveedor inactivo (`FiscalProviderResolver`),
 * y la administracion avisa en vez de dejar un canal muerto en la lista. Y el
 * numero ya repartido NO se toca: apagar un canal no renumera nada.
 */
test('test_un_proveedor_apagado_avisa_que_por_ese_canal_no_sale_un_comprobante', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $proveedor = DB::table('fiscal_providers')
        ->where('code', 'local_printer')
        ->where('fiscal_profile_id', $escenario['profile_id'])
        ->first();

    expect($proveedor)->not->toBeNull('El escenario no tiene el proveedor local.');

    $respuesta = $this->actingAs($admin)->putJson('/api/fiscal/proveedores/'.$proveedor->id, [
        'name' => 'Imprenta local del sistema',
        'is_active' => false,
    ]);

    $respuesta->assertOk();

    $avisos = $respuesta->json('warnings');

    expect($avisos)->toBeArray()->not->toBeEmpty('El proveedor apagado no avisa de nada.')
        ->and(implode(' ', $avisos))->toContain('deactivated')
        // Y el aviso dice que la numeracion no se toca.
        ->and(implode(' ', $avisos))->toContain('numbering');

    // Y el contador de la serie del perfil sigue donde estaba.
    $serie = DB::table('fiscal_series')
        ->where('store_id', $escenario['store_id'])
        ->where('fiscal_profile_id', $escenario['profile_id'])
        ->where('series_role', 'document')
        ->first();

    expect((int) $serie->next_number)->toBeGreaterThanOrEqual((int) $serie->from_number);
});

/**
 * La EDICION de un proveedor no toca su canal ni su codigo: el canal se
 * apaga, no se cambia.
 *
 * REGLA que fija: *el canal fiscal es DATO y no se muta*. El `kind` decide
 * como se emite y quien asigna el numero de control; el codigo es la clave
 * natural del proveedor dentro de su perfil.
 */
test('test_la_edicion_del_proveedor_no_toca_su_canal_ni_su_codigo', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $proveedor = DB::table('fiscal_providers')
        ->where('code', 'local_printer')
        ->where('fiscal_profile_id', $escenario['profile_id'])
        ->first();

    expect($proveedor)->not->toBeNull('El escenario no tiene el proveedor local.');

    // 1. La edicion VALIDA: el nombre, la direccion y el interruptor.
    $this->actingAs($admin)->putJson('/api/fiscal/proveedores/'.$proveedor->id, [
        'name' => 'Imprenta local del sistema',
        'base_url' => 'https://imprenta.local/api',
        'auth_scheme' => 'token',
        'credential_env_prefix' => 'IMPRENTA_LOCAL',
        'is_active' => false,
    ])->assertOk();

    $despues = DB::table('fiscal_providers')->where('id', $proveedor->id)->first();

    expect((string) $despues->base_url)->toBe('https://imprenta.local/api')
        ->and((string) $despues->credential_env_prefix)->toBe('IMPRENTA_LOCAL')
        ->and((bool) $despues->is_active)->toBeFalse();

    // 2. Y el intento de CAMBIAR el canal o la clave natural.
    $this->actingAs($admin)->putJson('/api/fiscal/proveedores/'.$proveedor->id, [
        'name' => 'Imprenta local del sistema',
        'kind' => 'portal',
        'code' => 'otro_codigo',
        'fiscal_profile_id' => 999999,
        'is_active' => true,
    ])->assertOk();

    $tras = DB::table('fiscal_providers')->where('id', $proveedor->id)->first();

    expect((string) $tras->kind)->toBe('printer')
        ->and((string) $tras->code)->toBe('local_printer')
        ->and((int) $tras->fiscal_profile_id)->toBe($escenario['profile_id'])
        ->and((bool) $tras->is_active)->toBeTrue();
});

/**
 * Los cambios de la administracion fiscal quedan AUDITADOS.
 *
 * REGLA que fija: *todo cambio de la administracion queda escrito, con QUIEN
 * lo hizo y desde DONDE*. Una serie, un perfil o un proveedor que cambian son
 * datos fiscales, y los datos fiscales se defienden.
 */
test('test_los_cambios_fiscales_quedan_auditados', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $this->actingAs($admin)->postJson('/api/fiscal/series', serie_nueva(
        $escenario['profile_id'],
        $escenario['store_id'],
    ))->assertCreated();

    $alta = DB::table('audit_logs')
        ->where('action', 'fiscal_series.created')
        ->orderByDesc('id')
        ->first();

    expect($alta)->not->toBeNull('El alta de la serie no quedo auditada.')
        ->and((int) $alta->user_id)->toBe((int) $admin->id)
        ->and((string) $alta->entity_type)->toBe('fiscal_series')
        ->and((string) $alta->ip_address)->not->toBe('');

    $contexto = json_decode((string) $alta->context, true);

    expect($contexto)->toBeArray()
        ->and((string) $contexto['prefix'])->toBe('RET-001')
        ->and((string) $contexto['series_role'])->toBe('retention_receipt');

    // Y la EDICION tambien.
    $serie = DB::table('fiscal_series')->where('prefix', 'RET-001')->first();

    $this->actingAs($admin)->putJson('/api/fiscal/series/'.$serie->id, [
        'valid_to' => '2026-12-31',
        'credential_ref' => 'Cerrada por el instalador',
    ])->assertOk();

    $edicion = DB::table('audit_logs')
        ->where('action', 'fiscal_series.updated')
        ->orderByDesc('id')
        ->first();

    expect($edicion)->not->toBeNull('La edicion de la serie no quedo auditada.')
        ->and((int) $edicion->user_id)->toBe((int) $admin->id)
        ->and((int) $edicion->entity_id)->toBe((int) $serie->id);
});

/**
 * Una serie no se declara en una tienda FUERA del ambito, y tampoco se ve.
 *
 * REGLA que fija: *la serie es de una tienda donde el usuario puede
 * operar*. El ambito lo resuelve `PosContext` (el mismo que usa la caja), y
 * aqui no se reimplementa: el listado enseña las series de las tiendas del
 * ambito y el alta se rechaza fuera de el.
 */
test('test_una_serie_no_se_declara_en_una_tienda_fuera_del_ambito', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $rol = PermissionFixture::rol('fiscal-'.uniqid(), [
        Permission::FiscalRead->value,
        Permission::FiscalCreate->value,
    ]);

    // El AMBITO del rol: solo la tienda de la caja.
    PermissionFixture::ambito($rol, [$escenario['store_id']]);

    $admin = PermissionFixture::usuario($rol, $escenario['store_id']);

    $otra = Store::create(['name' => 'Tienda fuera del ambito']);

    // 1. El ALTA fuera del ambito se rechaza, y no se escribe nada.
    $respuesta = $this->actingAs($admin)->postJson('/api/fiscal/series', serie_nueva(
        $escenario['profile_id'],
        (int) $otra->id,
    ));

    $respuesta->assertStatus(422);

    expect((string) $respuesta->json('errorMsg'))->toContain('ambito');

    expect(DB::table('fiscal_series')->where('store_id', (int) $otra->id)->count())->toBe(0);

    // 2. Y una serie que YA esta en la tienda de fuera tampoco se ve: el
    //    listado es del ambito.
    DB::table('fiscal_series')->insert([
        'fiscal_profile_id' => $escenario['profile_id'],
        'store_id' => (int) $otra->id,
        'provider_id' => null,
        'series_role' => 'retention_receipt',
        'assigned_by' => 'issuer',
        'prefix' => 'FUERA-001',
        'next_number' => 1,
        'from_number' => 1,
        'to_number' => 100,
        'valid_from' => null,
        'valid_to' => null,
        'credential_ref' => null,
        'control_identifier' => null,
        'sequence_length' => null,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $listado = $this->actingAs($admin)->getJson('/api/fiscal/series');

    $listado->assertOk();

    expect(collect($listado->json('series'))->firstWhere('prefix', 'FUERA-001'))->toBeNull()
        ->and(collect($listado->json('stores'))->pluck('id')->all())->toBe([$escenario['store_id']]);
});

/**
 * Sin el PERMISO fiscal, la administracion fiscal no se abre.
 *
 * REGLA que fija: *el permiso es la ley*. Un cajero puede cobrar, pero no
 * instalar la numeracion fiscal de una tienda.
 */
test('test_sin_el_permiso_fiscal_no_se_abre_la_administracion_fiscal', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $cajero = PermissionFixture::usuario(
        PermissionFixture::rol('cajero-'.uniqid(), [Permission::SalesCreate->value]),
        $escenario['store_id'],
    );

    $this->actingAs($cajero)->getJson('/api/fiscal/series')->assertStatus(403);
    $this->actingAs($cajero)->getJson('/api/fiscal/profiles')->assertStatus(403);
    $this->actingAs($cajero)->getJson('/api/fiscal/proveedores')->assertStatus(403);

    $this->actingAs($cajero)->postJson('/api/fiscal/series', serie_nueva(
        $escenario['profile_id'],
        $escenario['store_id'],
    ))->assertStatus(403);

    expect(DB::table('fiscal_series')->where('prefix', 'RET-001')->count())->toBe(0);
});

/**
 * El CAJERO del contrato no puede crear una serie fiscal.
 *
 * REGLA que fija: *instalar la numeracion fiscal no es trabajo del
 * mostrador*. El cajero cobra, y la numeracion la declara quien instala: el
 * rol por defecto del cajero no tiene NINGUN permiso de la administracion
 * fiscal, ni de lectura ni de escritura.
 */
test('test_el_cajero_no_puede_crear_una_serie_fiscal', function () {
    $escenario = PosFixture::escenario();

    PermissionFixture::catalogo();

    $cajero = Role::query()->where('slug', 'cashier')->first();

    expect($cajero)->not->toBeNull('El rol por defecto del cajero no esta sembrado.');

    $usuario = PermissionFixture::usuario($cajero, $escenario['store_id']);

    // Ni el permiso de la administracion fiscal...
    expect($usuario->can('fiscal.read'))->toBeFalse()
        ->and($usuario->can('fiscal.create'))->toBeFalse()
        ->and($usuario->can('fiscal.update'))->toBeFalse();

    // ... ni la puerta que el permiso abre.
    $this->actingAs($usuario)->getJson('/api/fiscal/series')->assertStatus(403);

    $this->actingAs($usuario)->postJson('/api/fiscal/series', serie_nueva(
        $escenario['profile_id'],
        $escenario['store_id'],
    ))->assertStatus(403);

    expect(DB::table('fiscal_series')->where('prefix', 'RET-001')->count())->toBe(0);
});

/**
 * SIN SESION no se llega a la administracion fiscal.
 *
 * REGLA que fija: *la administracion exige sesion y permiso*. Sin sesion no
 * hay quien decida, y sin permiso la puerta no se abre.
 */ test('test_sin_sesion_no_se_llega_a_la_administracion_fiscal', function () {
    // 1. La API responde 401: no hay sesion, y no hay quien decida.
    $this->getJson('/api/fiscal/series')->assertStatus(401);
    $this->getJson('/api/fiscal/profiles')->assertStatus(401);
    $this->getJson('/api/fiscal/proveedores')->assertStatus(401);

    // 2. Y la pantalla redirige al login.
    $this->get('/fiscal/series')->assertRedirect('/login');
});

/**
 * La PANTALLA de las series responde con su contrato de props.
 *
 * REGLA que fija: *la pantalla de series entrega el catalogo de lo que se
 * puede declarar*: las series, los perfiles, las tiendas donde se puede
 * declarar, los roles de serie y las autoridades que asignan el numero.
 */
test('test_la_pantalla_de_series_responde_con_su_contrato_de_props', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $this->actingAs($admin)
        ->get('/fiscal/series')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('fiscal/series')
            // Las SERIES de la tienda, con su perfil y su tienda.
            ->has('series')
            ->where('series', fn ($series): bool => collect($series)->firstWhere('series_role', 'document') !== null
                && collect($series)->firstWhere('series_role', 'control') !== null)
            // Los PERFILES: el regimen que se puede declarar.
            ->has('profiles')
            ->where('profiles', fn ($perfiles): bool => collect($perfiles)->firstWhere('code', 'VE_MEDIOS_DIGITALES') !== null)
            // Las TIENDAS donde se puede declarar una serie.
            ->has('stores')
            // Los TRES roles de serie, del dominio.
            ->has('roles', 3)
            ->where('roles', fn ($roles): bool => collect($roles)->pluck('value')->all() === [
                'document',
                'control',
                'retention_receipt',
            ])
            // Y las CUATRO autoridades que asignan el numero.
            ->has('authorities', 4)
            ->etc());
});

/**
 * La PANTALLA de los perfiles responde con su contrato de props.
 *
 * REGLA que fija: *la pantalla de perfiles entrega el catalogo de lo que se
 * puede declarar*: los regimenes con su pais y su moneda, los paises del
 * catalogo, las monedas del catalogo y los modelos de firma, que son un
 * conjunto cerrado del dominio.
 */
test('test_la_pantalla_de_perfiles_responde_con_su_contrato_de_props', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $this->actingAs($admin)
        ->get('/fiscal/perfiles')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('fiscal/profiles')
            // Los REGIMENES, con su pais y su moneda.
            ->has('profiles')
            ->where('profiles', fn ($perfiles): bool => collect($perfiles)->firstWhere('code', 'VE_MEDIOS_DIGITALES') !== null)
            // Los PAISES del catalogo.
            ->has('countries')
            // Las MONEDAS del catalogo.
            ->has('currencies')
            // Y los CUATRO modelos de firma, del conjunto cerrado.
            ->has('signer_models', 4)
            // Y las DOS reglas de redondeo, del conjunto cerrado.
            ->has('rounding_rules', 2)
            ->etc());
});

/**
 * La PANTALLA de los proveedores dice que un canal sin implementacion no
 * puede emitir.
 *
 * REGLA que fija: *la pantalla no finge*. Un proveedor `portal` aparece con
 * su canal y con el aviso de que ese canal no emite todavia.
 */
test('test_la_pantalla_de_proveedores_dice_que_un_canal_sin_implementacion_no_puede_emitir', function () {
    $escenario = PosFixture::escenario();

    $admin = admin_fiscal($escenario['store_id']);

    $this->actingAs($admin)->postJson('/api/fiscal/proveedores', [
        'fiscal_profile_id' => $escenario['profile_id'],
        'code' => 'portal_de_la_instalacion',
        'name' => 'Portal de la instalacion',
        'kind' => 'portal',
        'credential_env_prefix' => 'PORTAL_DE_LA_INSTALACION',
        'is_active' => true,
    ])->assertCreated();

    $this->actingAs($admin)
        ->get('/fiscal/proveedores')
        ->assertOk()
        ->assertInertia(fn (AssertableInertia $page): AssertableInertia => $page
            ->component('fiscal/providers')
            ->has('providers')
            ->where('providers', fn ($proveedores): bool => collect($proveedores)
                ->firstWhere('code', 'portal_de_la_instalacion')['has_implementation'] === false)
            // Y el catalogo de canales, con el aviso en los que no emiten.
            ->has('kinds', 3)
            ->where('kinds', fn ($canales): bool => collect($canales)->firstWhere('value', 'portal')['has_implementation'] === false
                && collect($canales)->firstWhere('value', 'printer')['has_implementation'] === true)
            ->etc());
});
