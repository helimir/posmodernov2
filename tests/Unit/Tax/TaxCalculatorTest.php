<?php

use App\Domain\Fiscal\Enums\RoundingRule;
use App\Domain\Fiscal\Enums\TaxMethod;
use App\Domain\Tax\TaxCalculator;
use App\Domain\Tax\TaxContext;
use App\Domain\Tax\TaxDocumentInput;
use App\Domain\Tax\TaxLineInput;

/**
 * 0.6.1 / 0.6.2 / 0.6.3 — el motor de impuestos.
 *
 * Reglas que fija:
 * - el motor es PURO: sin base de datos, sin red, sin locale y sin reloj. La
 *   misma entrada da el mismo resultado;
 * - y es puro DE ESTRUCTURA: en su codigo no hay ninguna via por la que
 *   pueda leer la base de datos, la red o el reloj. El determinismo NO lo
 *   demuestra: un motor que lee la base de datos puede ser determinista;
 * - la base es el precio COBRADO, no el de catálogo;
 * - el ajuste de redondeo es VISIBLE: `suma de las lineas + roundingAdjustment
 *   = total redondeado`;
 * - los snapshots de la tasa viajan CONGELADOS al documento.
 */

/**
 * La carpeta del motor de impuestos.
 *
 * Este test es de `tests/Unit`: no arranca Laravel, asi que `app_path()` no
 * existe aqui. La ruta sale del propio fichero.
 */
function carpeta_del_motor(): string
{
    return dirname(__DIR__, 3).'/app/Domain/Tax';
}

/**
 * Las referencias que el motor de impuestos NO puede tener.
 *
 * Cada token es una via por la que un motor puro dejaria de serlo.
 *
 * @return array<string, string> token => lo que abre
 */
function referencias_prohibidas(): array
{
    return [
        'DB::' => 'la base de datos',
        'Illuminate\Support\Facades\DB' => 'la base de datos',
        'Http::' => 'la red',
        'file_get_contents' => 'el sistema de ficheros',
        'curl_' => 'la red',
        'now()' => 'el reloj',
        'date(' => 'el reloj',
    ];
}

/**
 * Los ficheros del motor de impuestos: TODOS los de su carpeta.
 *
 * La carpeta `app/Domain/Tax/` es el motor y SOLO el motor: el resolutor de
 * la tasa vigente (`TaxRateResolver`) vive en `app/Domain/Fiscal/`, porque
 * resuelve un dato del perfil fiscal (LECTURA) y no calcula (CALCULO). Por
 * eso aqui, en esta carpeta, ni un solo fichero puede tocar la base de
 * datos: el motor no lee, y ni siquiera puede inyectar una conexion.
 *
 * @return array<int, string>
 */
function ficheros_del_motor(): array
{
    return array_map('basename', glob(carpeta_del_motor().'/*.php') ?: []);
}

test('test_el_motor_de_impuestos_no_consulta_la_base_de_datos_ni_la_red', function () {
    $context = new TaxContext(
        roundingRule: RoundingRule::Line,
        taxInclusiveDefault: false,
        currencyDecimals: 2,
        exemptionMarker: '(E)',
        formalContributorPhrase: 'Contribuyentes Formales del IVA',
        seriesPrefixRequiredToken: 'serie',
    );

    $calculator = new TaxCalculator($context);

    $line = new TaxLineInput(
        taxCategoryId: 1,
        taxRateId: 7,
        taxRateSnapshot: '16.0000',
        evidenceStatus: 'verified',
        method: TaxMethod::Exclusive,
        unitPrice: '10.0050',
        quantity: '1.0000',
    );

    $input = new TaxDocumentInput(
        lines: [$line, $line],
        documentDiscount: '0.0000',
        issuedAt: new DateTimeImmutable('2026-11-03 12:00:00'),
    );

    $result = $calculator->calculate($input);

    // 1. La base es el precio COBRADO: 10.0050 por linea, y el 16 % encima.
    expect($result->lines)->toHaveCount(2)
        ->and($result->lines[0]['base_amount'])->toBe('10.0050')
        ->and($result->lines[0]['tax_amount'])->toBe('1.6008')
        ->and($result->lines[0]['line_total'])->toBe('11.6100');

    // 2. El total redondeado y el AJUSTE visible: las lineas suman 23.22 y el
    //    total redondeado es 23.21, asi que el ajuste es -0.01.
    expect($result->grandTotal)->toBe('23.2100')
        ->and($result->roundingAdjustment)->toBe('-0.0100');

    $sumOfLines = bcadd($result->lines[0]['line_total'], $result->lines[1]['line_total'], 4);

    expect(bcadd($sumOfLines, $result->roundingAdjustment, 4))->toBe($result->grandTotal);

    // 3. Los snapshots viajan CONGELADOS: un cambio posterior de la tasa no
    //    altera el documento emitido.
    expect($result->lines[0]['tax_rate_snapshot'])->toBe('16.0000')
        ->and($result->lines[0]['evidence_status'])->toBe('verified')
        ->and($result->lines[0]['tax_category_id'])->toBe(1)
        ->and($result->lines[0]['tax_rate_id'])->toBe(7);

    // 4. Y el motor es PURO: la misma entrada da el mismo resultado.
    $again = $calculator->calculate($input);

    expect($again->grandTotal)->toBe($result->grandTotal)
        ->and($again->roundingAdjustment)->toBe($result->roundingAdjustment)
        ->and($again->subtotal)->toBe($result->subtotal);

    // 5. El descuento de DOCUMENTO se prorratea a las lineas por base
    //    imponible, no se aplica entero a la primera linea.
    $withDiscount = $calculator->calculate(new TaxDocumentInput(
        lines: [$line, $line],
        documentDiscount: '2.0000',
        issuedAt: new DateTimeImmutable('2026-11-03 12:00:00'),
    ));

    expect($withDiscount->discountTotal)->toBe('2.0000')
        ->and($withDiscount->subtotal)->toBe('18.0100');

    // 6. Y el motor es PURO DE ESTRUCTURA: en su codigo no hay ninguna via
    //    por la que pueda leer la base de datos, la red o el reloj. El
    //    determinismo de arriba NO lo demuestra: un motor que lee la base de
    //    datos puede ser perfectamente determinista.
    $violaciones = [];

    foreach (glob(carpeta_del_motor().'/*.php') ?: [] as $fichero) {
        $contenido = (string) file_get_contents($fichero);

        foreach (referencias_prohibidas() as $token => $via) {
            if (str_contains($contenido, $token)) {
                $violaciones[] = basename($fichero).' referencia '.$token.' ('.$via.')';
            }
        }
    }

    expect($violaciones)->toBe([]);

    // 7. Y el motor PURO ni siquiera puede INYECTAR una conexion: sin
    //    conexion no hay consulta que hacer. La carpeta entera es el motor:
    //    el resolutor de la tasa vigente vive en `app/Domain/Fiscal/`.
    $conConexion = [];

    foreach (ficheros_del_motor() as $fichero) {
        $contenido = (string) file_get_contents(carpeta_del_motor().'/'.$fichero);

        if (str_contains($contenido, 'Illuminate\Database\Connection')) {
            $conConexion[] = $fichero.' inyecta una conexion a la base de datos';
        }
    }

    expect($conConexion)->toBe([]);
});
