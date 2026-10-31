<?php

use Brick\Math\BigDecimal;
use Mike42\Escpos\Printer;

/**
 * 0.1.1-T — el importe se calcula con decimal exacto y el ESC/POS se genera en el servidor,
 * no en el agente de impresión.
 */
test('test_las_dependencias_de_decimal_exacto_y_de_impresion_estan_instaladas', function () {
    expect(class_exists(BigDecimal::class))->toBeTrue()
        ->and(class_exists(Printer::class))->toBeTrue();

    // Sin `ext-bcmath` y sin `float`: la suma decimal es exacta.
    $sum = BigDecimal::of('0.1')->plus(BigDecimal::of('0.2'));

    expect((string) $sum)->toBe('0.3');
});
