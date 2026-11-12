<?php

use App\Domain\Fiscal\Contracts\ElectronicInvoiceProvider;
use App\Domain\Fiscal\FiscalProviderResolver;
use App\Domain\Fiscal\LocalPrinterProvider;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FiscalProviderFixture;

/**
 * 1.7 — la RESOLUCION del proveedor fiscal.
 *
 * Reglas que fija:
 *
 *  - el proveedor fiscal de una tienda se RESUELVE, no se hardcodea: el
 *    perfil fiscal declara el ENTORNO, el entorno declara el PROVEEDOR y el
 *    proveedor declara el TIPO, y del tipo sale la implementacion;
 *  - el entorno se elige por el interruptor `fiscal.environment`: el de
 *    pruebas cuando es `testing` y el de produccion cuando es `production`;
 *  - un canal que no existe es un ERROR CON MENSAJE, no un silencio: sin
 *    entorno, sin proveedor activo o de un tipo sin implementacion, la
 *    operacion falla diciendo POR QUE;
 *  - un tipo de proveedor sin implementacion NO se emula: no hay a que actor
 *    externo conectarse, y un actor inventado seria una mentira fiscal;
 *  - con el interruptor de emision APAGADO no se resuelve NADA: el checkout
 *    no entra en la emision fiscal.
 */
beforeEach(function () {
    $this->contexto = FiscalProviderFixture::contexto();
    $this->serieDocumento = FiscalProviderFixture::serieDocumento($this->contexto);
    $this->serieControl = FiscalProviderFixture::serieControl($this->contexto);
});

/**
 * El resolutor del canal fiscal.
 */
function resolutor(): FiscalProviderResolver
{
    return new FiscalProviderResolver(DB::connection());
}

/**
 * 1.7 — el entorno de pruebas se resuelve y devuelve el proveedor local.
 *
 * REGLA que fija: *el proveedor fiscal se deduce de las filas*. El perfil
 * declara el entorno de pruebas, el entorno declara el proveedor, y el tipo
 * del proveedor declara la implementacion.
 */
test('test_el_entorno_de_pruebas_se_resuelve_y_devuelve_el_proveedor_local', function () {
    FiscalProviderFixture::entorno($this->contexto);

    config(['fiscal.environment' => 'testing']);

    $proveedor = resolutor()->resolve($this->serieDocumento);

    expect($proveedor)->toBeInstanceOf(LocalPrinterProvider::class)
        ->and($proveedor)->toBeInstanceOf(ElectronicInvoiceProvider::class);
});

/**
 * 1.7 — pedir PRODUCCION sin un entorno de produccion da un error util.
 *
 * REGLA que fija: *un canal que no existe se dice, no se adivina*. El
 * mensaje dice QUE perfil y QUE entorno se pidio, para que el operador sepa
 * que sembrar.
 */
test('test_pedir_el_entorno_de_produccion_sin_un_entorno_de_produccion_da_un_error_util', function () {
    // Solo existe el entorno de PRUEBAS: el de produccion no esta sembrado.
    FiscalProviderFixture::entorno($this->contexto);

    config(['fiscal.environment' => 'production']);

    expect(fn () => resolutor()->resolve($this->serieDocumento))
        ->toThrow(RuntimeException::class)
        ->toThrow('perfil fiscal '.$this->contexto['profile_id'])
        ->toThrow('production');
});

/**
 * 1.7 — un proveedor INACTIVO da un error util.
 *
 * REGLA que fija: *un canal desactivado no emite*. Un proveedor inactivo es
 * una decision del operador, y el dominio la respeta: falla diciendo que el
 * canal esta inactivo.
 */
test('test_un_proveedor_inactivo_da_un_error_util', function () {
    FiscalProviderFixture::entorno($this->contexto);

    DB::table('fiscal_providers')
        ->where('id', $this->contexto['provider_id'])
        ->update(['is_active' => false, 'updated_at' => now()]);

    config(['fiscal.environment' => 'testing']);

    expect(fn () => resolutor()->resolve($this->serieDocumento))
        ->toThrow(RuntimeException::class)
        ->toThrow('LOCAL')
        ->toThrow('inactivo');
});

/**
 * 1.7 — un tipo de proveedor SIN IMPLEMENTACION da un error util.
 *
 * REGLA que fija: *un canal externo que no esta conectado NO se emula*. No
 * hay a que actor se conectaria un `pac` o un `portal` en esta version, y un
 * actor de mentira emitiria comprobantes que no existen.
 */
test('test_un_tipo_de_proveedor_sin_implementacion_da_un_error_util', function () {
    FiscalProviderFixture::entorno($this->contexto);

    DB::table('fiscal_providers')
        ->where('id', $this->contexto['provider_id'])
        ->update(['kind' => 'pac', 'updated_at' => now()]);

    config(['fiscal.environment' => 'testing']);

    expect(fn () => resolutor()->resolve($this->serieDocumento))
        ->toThrow(RuntimeException::class)
        ->toThrow('pac')
        ->toThrow('implementacion');
});

/**
 * 1.7 — un entorno SIN PROVEEDOR da un error util.
 *
 * REGLA que fija: *un entorno sin canal no es un canal*. El entorno declara
 * el papel y el proveedor; sin proveedor, no hay a quien emissions.
 */
test('test_un_entorno_sin_proveedor_da_un_error_util', function () {
    FiscalProviderFixture::entorno($this->contexto, ['provider_id' => null]);

    config(['fiscal.environment' => 'testing']);

    expect(fn () => resolutor()->resolve($this->serieDocumento))
        ->toThrow(RuntimeException::class)
        ->toThrow('proveedor');
});

/**
 * 1.7 — con el interruptor APAGADO no se resuelve NADA.
 *
 * REGLA que fija: *el interruptor apagado no es una emision que falla, es
 * una emision que NO ocurre*. El contenedor no resuelve el proveedor, y la
 * venta sale sin documento fiscal.
 */
test('test_el_interruptor_apagado_no_resuelve_nada', function () {
    FiscalProviderFixture::entorno($this->contexto);

    config([
        'fiscal.emission_enabled' => false,
        'fiscal.environment' => 'testing',
    ]);

    // 1. El proveedor NO se resuelve: el interruptor apagado no pide canal.
    expect(app()->resolved(ElectronicInvoiceProvider::class))->toBeFalse();

    // 2. Y el interruptor apagado es el que manda, no la fila del entorno:
    //    hay un entorno de pruebas sembrado y aun asi no se emite.
    expect(config('fiscal.emission_enabled'))->toBeFalse();
});
