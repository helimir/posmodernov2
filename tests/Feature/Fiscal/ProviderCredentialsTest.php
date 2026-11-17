<?php

use App\Domain\Fiscal\FiscalProviderResolver;
use App\Domain\Fiscal\LocalPrinterProvider;
use App\Models\FiscalProvider;
use App\Models\SmsProvider;
use Database\Seeders\FiscalProviderSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Tests\Fixtures\FiscalProviderFixture;

/**
 * F2-03 — la RESOLUCION de las credenciales fiscales.
 *
 * Reglas que fija:
 *
 *  - el secreto de un proveedor fiscal NO vive en la base de datos: la fila
 *    declara el PREFIJO de la variable de entorno (la misma convencion que
 *    `sms_providers`) y el secreto se resuelve en runtime, en el momento de
 *    usarlo;
 *  - el nombre de la variable se compone como el de los SMS: el prefijo de
 *    la fila, guion bajo, y el nombre de la variable en mayusculas;
 *  - un secreto AUSENTE no es un error de arranque: el canal local no tiene
 *    credenciales, y su prefijo en `null` es una configuracion LEGITIMA. Pero
 *    una fila SIN prefijo, usada por un canal que las necesita, es un error
 *    de CONFIGURACION, y lo dice quien lo usa;
 *  - el mensaje dice QUE credencial falta y DE QUE variable sale: el nombre
 *    de la variable, NUNCA su valor. Y el secreto no aparece en la fila, ni
 *    en el log, ni en la respuesta.
 */

/**
 * El RESOLVEDOR del canal fiscal.
 */
function credenciales_resolutor(): FiscalProviderResolver
{
    return new FiscalProviderResolver(DB::connection());
}

/**
 * F2-03 — la migracion anade el prefijo de credenciales, y su `down()` lo quita.
 *
 * REGLA que fija: *la credencial se declara, no se guarda*. La columna es el
 * PREFIJO de la variable de entorno (la convencion de `sms_providers`), y es
 * ANULABLE porque el canal local no tiene credenciales: su `null` es una
 * configuracion legitima, no un olvido.
 */
test('test_la_migracion_anade_el_prefijo_de_credenciales_a_los_proveedores_fiscales_y_su_down_lo_quita', function () {
    expect(Schema::hasColumn('fiscal_providers', 'credential_env_prefix'))->toBeTrue();

    $ruta = database_path('migrations/2026_11_17_000001_add_credential_env_prefix_to_fiscal_providers_table.php');

    expect(file_exists($ruta))->toBeTrue();

    $migracion = require $ruta;

    // El `down()` la quita.
    $migracion->down();

    expect(Schema::hasColumn('fiscal_providers', 'credential_env_prefix'))->toBeFalse();

    // Y el `up()` la vuelve a poner: la migracion es reversible.
    $migracion->up();

    expect(Schema::hasColumn('fiscal_providers', 'credential_env_prefix'))->toBeTrue();
});

/**
 * F2-03 — la credencial se RESUELVE del entorno, con el prefijo de la fila.
 *
 * REGLA que fija: *el secreto no esta en la base de datos*. La fila guarda el
 * prefijo, y el secreto sale del entorno en el momento de usarlo.
 */
test('test_la_credencial_del_proveedor_fiscal_se_resuelve_desde_el_entorno_con_el_prefijo_de_la_fila', function () {
    $contexto = FiscalProviderFixture::contexto();

    $proveedor = FiscalProvider::create([
        'fiscal_profile_id' => $contexto['profile_id'],
        'code' => 'CANAL_EXTERNO',
        'name' => 'Canal externo de prueba',
        'kind' => 'pac',
        'credential_env_prefix' => 'FISCAL_TEST',
    ]);

    putenv('FISCAL_TEST_API_KEY=secreto-de-prueba');

    // El secreto se resuelve del entorno...
    expect($proveedor->credential('API_KEY'))->toBe('secreto-de-prueba');

    // ...y la fila no lo contiene en ningun campo: solo guarda el PREFIJO.
    $fila = DB::table('fiscal_providers')->where('id', $proveedor->id)->first();

    expect(json_encode($fila))->not->toContain('secreto-de-prueba')
        ->and((array) $fila)->toHaveKey('credential_env_prefix', 'FISCAL_TEST');

    putenv('FISCAL_TEST_API_KEY');
});

/**
 * F2-03 — sin prefijo declarado, o sin variable en el entorno, no hay credencial.
 *
 * REGLA que fija: *un secreto ausente NO es un error de arranque*. La
 * credencial que no esta se devuelve como `null`, y lo dice quien la usa.
 */
test('test_la_credencial_es_null_sin_prefijo_declarado_y_sin_variable_de_entorno', function () {
    $contexto = FiscalProviderFixture::contexto();

    // SIN prefijo declarado: no hay ninguna variable que resolver.
    $sinPrefijo = FiscalProvider::create([
        'fiscal_profile_id' => $contexto['profile_id'],
        'code' => 'CANAL_SIN_PREFIJO',
        'name' => 'Canal sin prefijo',
        'kind' => 'pac',
    ]);

    expect($sinPrefijo->credential('API_KEY'))->toBeNull()
        ->and($sinPrefijo->credentialVariable('API_KEY'))->toBeNull();

    // CON prefijo declarado, pero la variable NO esta en el entorno: tambien
    // `null`. Un secreto ausente no se sustituye por nada leido de la base.
    $conPrefijo = FiscalProvider::create([
        'fiscal_profile_id' => $contexto['profile_id'],
        'code' => 'CANAL_CON_PREFIJO',
        'name' => 'Canal con prefijo',
        'kind' => 'pac',
        'credential_env_prefix' => 'FISCAL_TEST',
    ]);

    putenv('FISCAL_TEST_API_KEY');

    expect($conPrefijo->credential('API_KEY'))->toBeNull();

    putenv('FISCAL_TEST_API_KEY');
});

/**
 * F2-03 — el nombre de la variable se compone IGUAL que el de los SMS.
 *
 * REGLA que fija: *un prefijo, y solo uno*. La composicion del nombre de la
 * variable es la de `sms_providers`: el prefijo de la fila, guion bajo, y el
 * nombre de la variable en mayusculas. No una parecida: la MISMA.
 *
 * Y la prueba es una TRIANGULACION: los dos modelos con el MISMO prefijo
 * tienen que leer la MISMA variable. Si uno compusiera el nombre de otra
 * manera, uno de los dos volveria con `null`.
 */
test('test_el_nombre_de_la_variable_se_compone_igual_que_el_de_los_proveedores_de_sms', function () {
    $contexto = FiscalProviderFixture::contexto();

    $prefijo = 'COMPARACION_TEST';

    $fiscal = FiscalProvider::create([
        'fiscal_profile_id' => $contexto['profile_id'],
        'code' => 'CANAL_COMPARADO',
        'name' => 'Canal comparado',
        'kind' => 'pac',
        'credential_env_prefix' => $prefijo,
    ]);

    $sms = SmsProvider::create([
        'code' => 'canal_comparado',
        'name' => 'Canal comparado',
        'credential_env_prefix' => $prefijo,
    ]);

    putenv('COMPARACION_TEST_API_KEY=valor-compartido');

    expect($fiscal->credential('api_key'))->toBe('valor-compartido')
        ->and($sms->credential('api_key'))->toBe('valor-compartido')
        // Y el nombre es el prefijo, guion bajo, y la variable en mayusculas.
        ->and($fiscal->credentialVariable('api_key'))->toBe('COMPARACION_TEST_API_KEY')
        // Y el guion bajo es PARTE del formato: pegado, la variable no existe.
        ->and($fiscal->credential('apikey'))->toBeNull();

    putenv('COMPARACION_TEST_API_KEY');
});

/**
 * F2-03 — un canal que NECESITA credenciales y no las tiene, falla con un
 * mensaje util.
 *
 * REGLA que fija: *el que usa la credencial es el que dice que falta*. El
 * mensaje dice QUE credencial falta y DE QUE variable sale: el NOMBRE de la
 * variable, nunca su valor.
 */
test('test_un_canal_que_necesita_credenciales_y_no_las_tiene_falla_diciendo_que_falta_y_de_que_variable_sale', function () {
    $contexto = FiscalProviderFixture::contexto();
    $serie = FiscalProviderFixture::serieDocumento($contexto);
    FiscalProviderFixture::entorno($contexto);

    // El canal pasa a ser EXTERNO: necesita credenciales.
    DB::table('fiscal_providers')->where('id', $contexto['provider_id'])->update([
        'kind' => 'pac',
        'credential_env_prefix' => 'FISCAL_TEST',
        'updated_at' => now(),
    ]);

    config(['fiscal.environment' => 'testing']);

    // El secreto NO esta en el entorno: el canal no puede autenticarse.
    putenv('FISCAL_TEST_API_KEY');

    expect(fn () => credenciales_resolutor()->credential($serie, 'api_key'))
        ->toThrow(RuntimeException::class)
        ->toThrow('api_key')
        ->toThrow('FISCAL_TEST_API_KEY');
});

/**
 * F2-03 — una fila SIN prefijo, usada por un canal que las necesita, es un
 * error de CONFIGURACION.
 *
 * REGLA que fija: *la falta de un secreto y la falta de la declaracion no son
 * lo mismo*. Un secreto que no esta en el entorno se dice como lo que es. Una
 * fila que no declara el prefijo es una configuracion que hay que corregir, y
 * el mensaje lo dice.
 */
test('test_una_fila_sin_prefijo_usada_por_un_canal_que_necesita_credenciales_es_un_error_de_configuracion', function () {
    $contexto = FiscalProviderFixture::contexto();
    $serie = FiscalProviderFixture::serieDocumento($contexto);
    FiscalProviderFixture::entorno($contexto);

    // El canal es EXTERNO (necesita credenciales) y la fila NO declara prefijo.
    DB::table('fiscal_providers')->where('id', $contexto['provider_id'])->update([
        'kind' => 'pac',
        'credential_env_prefix' => null,
        'updated_at' => now(),
    ]);

    config(['fiscal.environment' => 'testing']);

    expect(fn () => credenciales_resolutor()->credential($serie, 'api_key'))
        ->toThrow(RuntimeException::class)
        ->toThrow('credential_env_prefix')
        ->toThrow('api_key');
});

/**
 * F2-03 — el secreto no aparece en la fila, ni en el log, ni en el mensaje.
 *
 * REGLA que fija: *el secreto no se escribe en ningun sitio*. No en la base
 * de datos, no en el log, y no en la respuesta: el mensaje dice el NOMBRE de
 * la variable de entorno, que no es el secreto.
 */
test('test_el_secreto_no_aparece_en_la_fila_ni_en_el_log_ni_en_la_respuesta', function () {
    $contexto = FiscalProviderFixture::contexto();
    $serie = FiscalProviderFixture::serieDocumento($contexto);
    FiscalProviderFixture::entorno($contexto);

    DB::table('fiscal_providers')->where('id', $contexto['provider_id'])->update([
        'kind' => 'pac',
        'credential_env_prefix' => 'FISCAL_TEST',
        'updated_at' => now(),
    ]);

    config(['fiscal.environment' => 'testing']);

    putenv('FISCAL_TEST_API_KEY=secreto-de-prueba');

    // 1. LA FILA: el secreto no esta en ningun campo de la base de datos.
    $fila = DB::table('fiscal_providers')->where('id', $contexto['provider_id'])->first();

    expect(json_encode($fila))->not->toContain('secreto-de-prueba');

    // 2. EL LOG: lo que el manejador de errores escribe cuando el canal no
    //    puede autenticarse. El canal pide una credencial que no existe.
    $ruta = storage_path('logs/prueba-credenciales.log');

    @unlink($ruta);

    config([
        'logging.default' => 'prueba_credenciales',
        'logging.channels.prueba_credenciales' => [
            'driver' => 'single',
            'path' => $ruta,
            'level' => 'debug',
        ],
    ]);

    $mensaje = null;

    try {
        credenciales_resolutor()->credential($serie, 'otra_credencial');
    } catch (RuntimeException $e) {
        $mensaje = $e->getMessage();

        // Esto es lo que hace el manejador de errores: escribir el mensaje
        // del fallo en el log.
        Log::error($mensaje);
    }

    $log = (string) file_get_contents($ruta);

    // 3. LA RESPUESTA: el mensaje que el canal devuelve dice QUE falta y DE
    //    QUE variable sale, y NO dice el valor.
    expect($mensaje)->toContain('otra_credencial')
        ->and($mensaje)->toContain('FISCAL_TEST_OTRA_CREDENCIAL')
        ->and($mensaje)->not->toContain('secreto-de-prueba');

    // Y el log lleva el nombre de la variable, no el secreto.
    expect($log)->toContain('FISCAL_TEST_OTRA_CREDENCIAL')
        ->and($log)->not->toContain('secreto-de-prueba');

    @unlink($ruta);

    putenv('FISCAL_TEST_API_KEY');
});

/**
 * F2-03 — el proveedor LOCAL no necesita credenciales, y su prefijo en `null`
 * es legitimo.
 *
 * REGLA que fija: *un secreto ausente NO es un error de arranque*. El canal
 * local es local: no hay a quien autenticarse, asi que su prefijo en `null` es
 * una configuracion LEGITIMA, y el canal resuelve y emite igual.
 */
test('test_el_proveedor_local_no_necesita_credenciales_y_su_prefijo_en_null_es_legitimo', function () {
    $contexto = FiscalProviderFixture::contexto();
    $serie = FiscalProviderFixture::serieDocumento($contexto);
    FiscalProviderFixture::entorno($contexto);

    // El seeder del canal local: su prefijo en `null`, DICHIENTELO, no de
    // memoria.
    $this->seed(FiscalProviderSeeder::class);

    $sembrado = DB::table('fiscal_providers')->where('code', 'local_printer')->first();

    expect($sembrado)->not->toBeNull()
        ->and((array) $sembrado)->toHaveKey('credential_env_prefix', null);

    // Y el modelo de esa fila devuelve `null`, sin error: no hay credencial
    // que pedir y no hay ninguna que falte.
    $local = FiscalProvider::query()->find($sembrado->id);

    expect($local->credential('API_KEY'))->toBeNull()
        ->and($local->credentialVariable('API_KEY'))->toBeNull();

    // Y el canal local RESUELVE y emite: la falta de credenciales no es un
    // error de arranque.
    config(['fiscal.environment' => 'testing']);

    expect(credenciales_resolutor()->resolve($serie))
        ->toBeInstanceOf(LocalPrinterProvider::class);

    // Y pedirle una credencial a un canal local es un ERROR, no un silencio:
    // el canal local no se autentica.
    expect(fn () => credenciales_resolutor()->credential($serie, 'api_key'))
        ->toThrow(RuntimeException::class)
        ->toThrow('local');
});
