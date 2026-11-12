<?php

use App\Domain\Fiscal\ReceiptTemplate;
use App\Models\Country;
use App\Models\Currency;
use App\Models\FiscalProfile;
use App\Models\Printer;
use App\Models\PrintTarget;
use App\Models\Store;
use App\Print\PrintJobDispatcher;
use App\Print\ReceiptPrintPayload;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\WithoutWrappingTransaction;

/**
 * 1.8 — el PUENTE entre el comprobante fiscal y el ticket ESC/POS.
 *
 * REGLA que fija: *el mismo modelo de plantilla alimenta a los dos
 * renderizadores*. `ReceiptTemplate` (el modelo fiscal) y `PrintPayload` (el
 * modelo del ticket) son dos modelos SIN conexion, y sin el puente un ticket
 * puede llevar un numero y un comprobante otro: es el defecto que la unidad
 * 1.7 acabo de arreglar, y no se puede reintroducir por otro lado.
 *
 * Y EL QUE NO ADMITE EL QR FALLA EN EL SERVIDOR: el destino lo declara, y el
 * renderer lo dice antes de enviar nada. Un ticket mudo en el papel es un
 * fallo del backoffice, no de la impresora.
 */
uses(WithoutWrappingTransaction::class);

beforeEach(function () {
    DB::statement(
        'TRUNCATE users, currencies, countries, stores, products, customers, payment_methods, sales'
        .' RESTART IDENTITY CASCADE'
    );
});

afterEach(function () {
    DB::statement(
        'TRUNCATE users, currencies, countries, stores, products, customers, payment_methods, sales'
        .' RESTART IDENTITY CASCADE'
    );
});

/**
 * Un documento fiscal emitido, con su control y su QR.
 *
 * @return array<string, mixed>
 */
function documentoParaElTicket(): array
{
    $currency = Currency::create([
        'code' => 'ZZA',
        'name' => 'Moneda de prueba',
        'decimals' => 2,
    ]);

    $country = Country::create([
        'iso_code' => 'ZZ',
        'name' => 'Pais de prueba',
        'default_currency_id' => $currency->id,
    ]);

    $profile = FiscalProfile::create([
        'country_id' => $country->id,
        'code' => 'ZZ_MAIN',
        'name' => 'Perfil de prueba',
        'signer_model' => 'printer',
        'default_currency_id' => $currency->id,
    ]);

    $store = Store::create(['name' => 'Tienda del ticket']);

    $documentId = DB::table('fiscal_documents')->insertGetId([
        'fiscal_profile_id' => $profile->id,
        'store_id' => $store->id,
        'document_type' => 'invoice',
        'status' => 'issued',
        'number' => 77,
        'issue_date' => '2026-11-04',
        'issued_at' => '2026-11-04 15:30:45+00',
        'currency_id' => $currency->id,
        'qr_content' => 'document_number=77&control_number=00-00000077',
        'subtotal' => '50.0000',
        'tax_total' => '8.0000',
        'total_amount' => '58.0000',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('fiscal_document_control_assignments')->insert([
        'fiscal_document_id' => $documentId,
        'control_number' => '00-00000077',
        'control_range_from' => 1,
        'control_range_to' => 9999,
        'control_assigned_at' => now(),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('fiscal_document_parties')->insert([
        'fiscal_document_id' => $documentId,
        'role' => 'issuer',
        'name' => 'Tienda del ticket',
        'fiscal_id' => 'J-12345678-9',
        'fiscal_id_kind' => 'rif',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return [
        'store_id' => (int) $store->id,
        'document_id' => $documentId,
    ];
}

/**
 * 1.8 — del comprobante sale un ticket con el MISMO numero y el MISMO total.
 *
 * REGLA que fija: *el ticket y el comprobante cuentan la misma venta*. El
 * numero de documento, el numero de control y el total son los del
 * comprobante, no los recalculados.
 */
test('test_del_comprobante_sale_un_ticket_con_el_mismo_numero_de_control_y_el_mismo_total', function () {
    $escenario = documentoParaElTicket();

    $template = ReceiptTemplate::fromDocument(DB::connection(), $escenario['document_id']);

    $payload = ReceiptPrintPayload::fromTemplate($template);

    // 1. El TITULO es la denominacion del comprobante.
    expect($payload->title)->toBe($template->denomination);

    // 2. Y las LINEAS llevan el MISMO numero de documento y el MISMO numero
    //    de control.
    $textos = array_map(static fn (array $linea): string => (string) $linea['text'], $payload->lines);

    expect($textos)->toContain('Nº 77')
        ->and($textos)->toContain('Nº Control 00-00000077');

    // 3. Y el MISMO total.
    expect($textos)->toContain('Total: 58.0000 ZZA');

    // 4. Y el QR, si el documento lo trae.
    expect($payload->qrContent)->toBe('document_number=77&control_number=00-00000077');
});

/**
 * 1.8 — el ticket se puede encolar a un destino de impresion.
 *
 * REGLA que fija: *el comprobante se puede imprimir en la impresora de la
 * tienda*. El `PrintJobDispatcher` congela los bytes y el trabajo queda en la
 * cola: lo toma el agente, no el servidor.
 */
test('test_el_ticket_del_comprobante_se_puede_encolar_a_un_destino_de_impresion', function () {
    $escenario = documentoParaElTicket();

    $printer = Printer::create([
        'code' => 'TKT-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
        'name' => 'Impresora del ticket',
    ]);

    $target = PrintTarget::create([
        'printer_id' => $printer->id,
        'store_id' => $escenario['store_id'],
        'name' => 'Destino del ticket',
        'transport' => 'lan_agent',
        'endpoint' => 'tcp://192.168.1.70:9100',
        'printer_profile' => 'default',
        'supports_qr' => true,
    ]);

    $template = ReceiptTemplate::fromDocument(DB::connection(), $escenario['document_id']);

    $jobId = app(PrintJobDispatcher::class)->dispatch(
        $escenario['store_id'],
        (int) $target->id,
        ReceiptPrintPayload::fromTemplate($template),
    );

    // 1. El trabajo existe, en la cola, con sus bytes congelados.
    $job = DB::table('print_jobs')->where('id', $jobId)->first();

    expect($job)->not->toBeNull()
        ->and((string) $job->status)->toBe('queued')
        ->and((string) $job->payload_sha256)->not->toBe('');

    // 2. Y el MODELO del ticket es el del comprobante: el mismo numero de
    //    control y el mismo total.
    $modelo = json_decode((string) $job->payload, true);

    expect($modelo['title'])->toBe($template->denomination)
        ->and($modelo['qr_content'])->toBe('document_number=77&control_number=00-00000077');

    $textos = array_map(static fn (array $linea): string => (string) $linea['text'], $modelo['lines']);

    expect($textos)->toContain('Nº Control 00-00000077')
        ->and($textos)->toContain('Total: 58.0000 ZZA');
});

/**
 * 1.8 — un destino que NO admite el QR falla con un error util.
 *
 * REGLA que fija: *el servidor rechaza el ticket que no es representable*.
 * El fallo ocurre ANTES de enviar nada, y no queda NINGUN trabajo a medias.
 */
test('test_un_destino_que_no_admite_el_qr_falla_con_un_error_util', function () {
    $escenario = documentoParaElTicket();

    $printer = Printer::create([
        'code' => 'TKT-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
        'name' => 'Impresora sin QR',
    ]);

    $target = PrintTarget::create([
        'printer_id' => $printer->id,
        'store_id' => $escenario['store_id'],
        'name' => 'Destino sin QR',
        'transport' => 'lan_agent',
        'endpoint' => 'tcp://192.168.1.71:9100',
        'printer_profile' => 'default',
        'supports_qr' => false,
    ]);

    $template = ReceiptTemplate::fromDocument(DB::connection(), $escenario['document_id']);

    $mensaje = null;

    try {
        app(PrintJobDispatcher::class)->dispatch(
            $escenario['store_id'],
            (int) $target->id,
            ReceiptPrintPayload::fromTemplate($template),
        );
    } catch (RuntimeException $error) {
        $mensaje = $error->getMessage();
    }

    // 1. El error dice QUE pasa.
    expect($mensaje)->not->toBeNull()
        ->and($mensaje)->toContain('QR');

    // 2. Y no queda NINGUN trabajo a medias: un ticket sin bytes no lo puede
    //    imprimir el agente.
    expect(DB::table('print_jobs')->where('print_target_id', $target->id)->count())->toBe(0);
});
