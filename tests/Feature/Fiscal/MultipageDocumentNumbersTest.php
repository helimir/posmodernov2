<?php

use App\Domain\Fiscal\ReceiptRenderer;
use App\Domain\Fiscal\ReceiptTemplate;

/**
 * 0.9.7 — el mismo número de documento y de control en todas las páginas.
 *
 * Regla que fija: *el mismo número de documento y de control en todas las
 * páginas*. Los dos números identifican el DOCUMENTO, no la página: un
 * comprobante de tres páginas lleva el mismo número de documento y el mismo
 * número de control que uno de una sola página.
 *
 * Por eso no son un contador de página: salen UNA vez, y no cambian con el
 * número de líneas que el comprobante lleva.
 */
test('test_un_documento_multipagina_conserva_el_mismo_numero_de_documento_y_de_control', function () {
    /**
     * El comprobante de UN documento, con las lineas que se le pidan.
     *
     * @param  int  $lineas  Cuantas lineas lleva el comprobante.
     */
    $comprobante = function (int $lineas): ReceiptTemplate {
        $lineasDelComprobante = [];

        for ($i = 1; $i <= $lineas; $i++) {
            $lineasDelComprobante[] = [
                'description' => 'Linea '.$i,
                'quantity' => '1',
                'amount' => '1.0000',
            ];
        }

        return new ReceiptTemplate(
            denomination: 'invoice',
            documentNumber: '42',
            controlNumber: '00-00000042',
            issuedAt: new DateTimeImmutable('2026-11-02 10:30:00'),
            currencyCode: 'ZZA',
            originalCurrencyCode: null,
            exchangeRate: null,
            totalAmount: '116.0000',
            parties: [
                ['role' => 'issuer', 'name' => 'Comercial del Norte', 'fiscal_id' => 'J-00000000-0', 'fiscal_id_kind' => 'rif'],
            ],
            lines: $lineasDelComprobante,
            taxes: [
                ['tax_type' => 'transfer', 'tax_rate_snapshot' => '16.0000', 'base_amount' => '100.0000', 'tax_amount' => '16.0000'],
            ],
            exemptionMarker: null,
            formalContributorPhrase: null,
            printerName: null,
            printerTaxId: null,
            provisionReference: null,
        );
    };

    // 1. Un comprobante de UNA pagina.
    $corta = $comprobante(20);
    $htmlCorta = (new ReceiptRenderer)->render($corta);

    // 2. Y otro de VARIAS paginas: 60 lineas no caben en un rollo de 42.
    $larga = $comprobante(60);
    $htmlLarga = (new ReceiptRenderer)->render($larga);

    expect(substr_count($htmlLarga, '<tr>'))->toBe(60)
        ->and(substr_count($htmlLarga, '<tr>'))->toBeGreaterThan(42);

    // 3. Los dos numeros son los del DOCUMENTO, y son los mismos en el
    //    comprobante de una pagina y en el de varias.
    foreach ([$htmlCorta, $htmlLarga] as $html) {
        expect($html)->toContain('N&#186; 42')
            ->and($html)->toContain('N&#186; Control 00-00000042');
    }

    // 4. Y no son un CONTADOR DE PAGINA: salen una vez, no una por linea ni
    //    una por pagina.
    expect(substr_count($htmlLarga, 'N&#186; 42'))->toBe(1)
        ->and(substr_count($htmlLarga, 'N&#186; Control 00-00000042'))->toBe(1);

    // 5. Y el numero de control no depende de la pagina: el largo y el corto
    //    llevan el MISMO.
    expect($htmlCorta)->toContain('N&#186; Control 00-00000042')
        ->and($htmlLarga)->toContain('N&#186; Control 00-00000042');
});
