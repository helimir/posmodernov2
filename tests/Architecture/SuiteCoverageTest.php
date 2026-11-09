<?php

use Tests\Support\SuiteInventory;

/**
 * Normaliza un texto para buscarlo: sin mayusculas y sin tildes, porque la
 * suite nombra las reglas en castellano y los nombres de los tests no llevan
 * tildes.
 */
function suite_normaliza(string $texto): string
{
    return mb_strtolower(
        (string) str_replace(
            ['á', 'é', 'í', 'ó', 'ú', 'ü', 'ñ'],
            ['a', 'e', 'i', 'o', 'u', 'u', 'n'],
            $texto
        )
    );
}

/**
 * 0.9.7 — el dominio se prueba sin interfaz.
 *
 * Regla que fija: *el dominio se prueba sin interfaz*. La suite del dominio
 * cubre las siete areas que exige `calidad-continua` §"Suite Pest del
 * dominio": el checkout, el FIFO, los impuestos, la reserva fiscal, las
 * cuotas, el saldo de caja y la devolucion que repone stock.
 *
 * La comprobacion es la HONESTA: no basta con que exista un fichero con el
 * nombre del area; tiene que existir un test que la mencione (en su nombre o
 * en su documentacion) y ese test tiene que ASERTAR algo.
 */
test('test_la_suite_del_dominio_cubre_checkout_fifo_impuestos_reserva_fiscal_cuotas_caja_y_devolucion', function () {
    // Las SIETE areas, con las palabras que las nombran en la suite.
    $areas = [
        'checkout' => ['checkout'],
        'FIFO' => ['fifo'],
        'impuestos' => ['impuesto'],
        'reserva fiscal' => ['reserva'],
        'cuotas' => ['cuota'],
        'saldo de caja' => ['caja'],
        'devolucion que repone stock' => ['devolucion'],
    ];

    $tests = SuiteInventory::tests();

    foreach ($areas as $area => $palabras) {
        $candidatos = array_values(array_filter(
            $tests,
            static function (array $test) use ($palabras): bool {
                $texto = suite_normaliza($test['nombre'].' '.$test['docblock']);

                foreach ($palabras as $palabra) {
                    if (str_contains($texto, suite_normaliza($palabra))) {
                        return true;
                    }
                }

                return false;
            }
        ));

        expect($candidatos)->not->toBeEmpty("Ningun test de la suite menciona el area '{$area}'.");

        // Y el test que la cubre NO esta vacio: tiene al menos una asercion.
        $conAserciones = array_values(array_filter(
            $candidatos,
            static fn (array $test): bool => str_contains($test['cuerpo'], 'expect(')
        ));

        expect($conAserciones)->not->toBeEmpty(
            "Ningun test que menciona el area '{$area}' hace una asercion: el area no esta probada."
        );
    }
});
