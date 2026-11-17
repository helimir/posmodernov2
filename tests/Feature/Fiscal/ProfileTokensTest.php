<?php

use App\Domain\Fiscal\ReceiptRenderer;
use App\Domain\Fiscal\ReceiptTemplate;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\CreditFixture;
use Tests\Fixtures\PosFixture;

/**
 * F2-02 — los TRES TOKENS DEL PERFIL, y la inscripcion fiscal del emisor.
 *
 * REGLA que fija: *el perfil fiscal declara el texto de su propia providencia
 * y el nombre de su consumidor final; el codigo no los escribe*.
 *
 * El codigo YA leia los tres tokens, y por eso el comprobante que emitia el
 * sistema NO cumplia la providencia:
 *
 *  - `provision_reference` (art. 19): sin el, el comprobante no decia que se
 *    emite conforme a la providencia;
 *  - `final_consumer_name` (art. 8.6): sin el, una venta SIN cliente no
 *    tenia receptor, y un comprobante sin receptor no es un comprobante;
 *  - `qr_required_tokens`: NO se siembra, porque el QR NO es requisito legal
 *    de la providencia (es un HALLAZGO NEGATIVO verificado del research). Pero
 *    "el token no existe" y "el token esta vacio" significan lo MISMO: SIN QR.
 *
 * Y EL EMISOR TIENE IDENTIFICATIVO FISCAL: el art. 8.4 lo exige, y hoy la
 * `store_fiscal_registrations` no la siembra nadie, asi que el comprobante
 * salia con el RIF del emisor en `null`.
 *
 * EL ESCENARIO ES EL DE DEMOSTRACION DEL REPO (`PosFixture`): la tienda
 * sembrada, su perfil fiscal, sus dos series y un producto con stock. Los
 * tokens se leen de la SEMILLA, porque eso es lo que se prueba: que el dato
 * llega al comprobante desde el perfil y no desde el codigo.
 */
beforeEach(function () {
    // La emision fiscal ENCENDIDA: sin ella la venta no tiene documento y no
    // hay comprobante que mirar.
    config(['fiscal.emission_enabled' => true]);

    $this->escenario = PosFixture::escenario();
    $this->usuario = PosFixture::usuario($this->escenario['store_id']);

    // El COBRO: una venta de dos unidades a 25, pagada entera. Devuelve el
    // ID del documento fiscal emitido.
    $this->cobrar = function (?int $clienteId = null): int {
        $pedido = [
            'lines' => [
                [
                    'product_id' => $this->escenario['product_id'],
                    'quantity' => '2',
                    'unit_price' => '25.0000',
                ],
            ],
            'payments' => [
                [
                    'payment_method_id' => $this->escenario['payment_method_id'],
                    'amount' => '58.0000',
                ],
            ],
        ];

        if ($clienteId !== null) {
            $pedido['customer_id'] = $clienteId;
        }

        $respuesta = $this->actingAs($this->usuario)->postJson('/caja/cobrar', $pedido);

        $respuesta->assertCreated();
        $respuesta->assertJsonPath('fiscal.status', 'issued');

        return (int) $respuesta->json('fiscal.document_id');
    };
});

/**
 * La MENCION DE CONFORMIDAD que declara el perfil: la de la providencia.
 *
 * Es el art. 19 ("deberan indicar que se emiten conforme a lo dispuesto en
 * esta Providencia Administrativa") con la NOMENCLATURA y la FECHA que pide el
 * art. 9.3 para la imprenta digital.
 */
function profile_tokens_mencion_de_conformidad(): string
{
    return 'Providencia Administrativa SNAT/2026/00093 del 07/09/2026'
        .' (Gaceta Oficial N.º 43.484 del 21/10/2026)';
}

/**
 * El NOMBRE del consumidor final que declara el perfil.
 */
function profile_tokens_consumidor_final(): string
{
    return 'Consumidor Final';
}

/**
 * El RIF de DEMOSTRACION de la tienda de demostracion.
 */
function profile_tokens_rif_de_demonstracion(): string
{
    return 'DEMO-J-000000000-0';
}

/**
 * Una PARTE del comprobante, por su rol.
 */
function profile_tokens_parte(int $documentId, string $rol): ?object
{
    return DB::table('fiscal_document_parties')
        ->where('fiscal_document_id', $documentId)
        ->where('role', $rol)
        ->first();
}

/**
 * Un TOKEN del perfil fiscal.
 */
function profile_tokens_token(int $profileId, string $clave): ?object
{
    return DB::table('fiscal_profile_fields')
        ->where('fiscal_profile_id', $profileId)
        ->where('key', $clave)
        ->first();
}

/**
 * F2-02 — el perfil sembrado DECLARA la mencion de conformidad y el
 * consumidor final.
 *
 * REGLA que fija: *el perfil fiscal declara el texto de su propia providencia
 * y el nombre de su consumidor final*. Los dos tokens son DATOS de la
 * semilla: el codigo los lee, pero no los escribe.
 *
 * Y el texto es EL DE LA PROVIDENCIA: la nomenclatura, la fecha y la Gaceta
 * Oficial que el research transcribe.
 */
test('test_el_perfil_sembrado_declara_la_mencion_de_conformidad_y_el_consumidor_final', function () {
    $conformidad = profile_tokens_token($this->escenario['profile_id'], 'provision_reference');

    // 1. La MENCION DE CONFORMIDAD existe, y es la de la providencia.
    expect($conformidad)->not->toBeNull('El perfil no declara la mencion de conformidad.')
        ->and((string) $conformidad->value)->toBe(profile_tokens_mencion_de_conformidad())
        ->and((string) $conformidad->evidence_status)->toBe('verified')
        ->and((string) $conformidad->source_ref)->toContain('art. 19');

    // 2. Y el CONSUMIDOR FINAL existe, con el nombre que declara el perfil.
    $consumidor = profile_tokens_token($this->escenario['profile_id'], 'final_consumer_name');

    expect($consumidor)->not->toBeNull('El perfil no declara el consumidor final.')
        ->and((string) $consumidor->value)->toBe(profile_tokens_consumidor_final())
        ->and((string) $consumidor->source_ref)->toContain('art. 8.6');

    // 3. Y el QR NO se declara: el QR no es requisito legal de la providencia.
    expect(profile_tokens_token($this->escenario['profile_id'], 'qr_required_tokens'))->toBeNull();
});

/**
 * F2-02 — una venta SIN cliente produce un comprobante CON receptor.
 *
 * REGLA que fija: *una venta sin cliente tiene receptor*. El art. 8.6 exige
 * el nombre del receptor, y cuando la venta no tiene cliente el receptor es
 * el CONSUMIDOR FINAL que declara el perfil.
 *
 * ANTES de esta unidad el comprobante salia SIN receptor: el nombre del
 * consumidor final es un token del perfil, y el perfil no lo declaraba.
 */
test('test_una_venta_sin_cliente_produce_un_comprobante_con_receptor_el_consumidor_final', function () {
    $documentId = ($this->cobrar)();

    $receptor = profile_tokens_parte($documentId, 'receiver');

    // 1. El RECEPTOR EXISTE, y es el consumidor final del perfil.
    expect($receptor)->not->toBeNull('El comprobante de una venta sin cliente no tiene receptor.')
        ->and((string) $receptor->name)->toBe(profile_tokens_consumidor_final());

    // 2. Y no inventa un identificativo fiscal: el consumidor final no tiene
    //    RIF, y un RIF inventado seria peor que un hueco.
    expect($receptor->fiscal_id)->toBeNull()
        ->and($receptor->fiscal_id_kind)->toBeNull();
});

/**
 * F2-02 — una venta CON cliente sigue produciendo el receptor CLIENTE.
 *
 * REGLA que fija: *el token del consumidor final NO pisa al cliente*. El
 * receptor de una venta con cliente es el cliente, con su nombre y su
 * documento; el consumidor final es el de las ventas SIN cliente.
 */
test('test_una_venta_con_cliente_conserva_el_receptor_cliente', function () {
    $clienteId = CreditFixture::customer(
        $this->escenario['store_id'],
        'Cliente del comprobante',
        'V-12345678'
    );

    $documentId = ($this->cobrar)($clienteId);

    $receptor = profile_tokens_parte($documentId, 'receiver');

    // 1. El RECEPTOR es el cliente, no el consumidor final.
    expect($receptor)->not->toBeNull()
        ->and((string) $receptor->name)->toBe('Cliente del comprobante')
        ->and((string) $receptor->name)->not->toBe(profile_tokens_consumidor_final());

    // 2. Y lleva su documento.
    expect((string) $receptor->fiscal_id)->toBe('V-12345678');
});

/**
 * F2-02 — el comprobante RENDERIZADO incluye la mencion de conformidad.
 *
 * REGLA que fija: *el comprobante dice que se emite conforme a la
 * providencia* (art. 19). Y lo dice con el texto que DECLARA EL PERFIL: el
 * renderer pone laformula y el perfil pone la providencia.
 */
test('test_el_comprobante_renderizado_incluye_la_mencion_de_conformidad', function () {
    $documentId = ($this->cobrar)();

    $html = (new ReceiptRenderer)->render(ReceiptTemplate::fromDocument(DB::connection(), $documentId));

    // 1. La MENCION DE CONFORMIDAD, con la providencia que declara el perfil.
    expect($html)->toContain('Emitido conforme a')
        ->and($html)->toContain('SNAT/2026/00093')
        ->and($html)->toContain('43.484');

    // 2. Y el perfil NO es un literal del renderer: el texto viene del perfil.
    expect($template = ReceiptTemplate::fromDocument(DB::connection(), $documentId))
        ->and($template->provisionReference)->toBe(profile_tokens_mencion_de_conformidad());
});

/**
 * F2-02 — el comprobante NO lleva QR ni con el token inexistente ni con el
 * token vacio.
 *
 * REGLA que fija: *el QR no es requisito legal de la providencia*, asi que el
 * perfil NO declara sus tokens. Y "el token no existe" y "el token esta
 * vacio" significan LO MISMO: SIN QR.
 *
 * El caso del token VACIO no es un caso teorico: mañana alguien puede sembrar
 * la fila con el valor vacio, y el QR no puede salir distinto.
 */
test('test_el_comprobante_no_lleva_qr_ni_con_el_token_inexistente_ni_con_el_token_vacio', function () {
    // 1. El token NO EXISTE: el documento emitido no lleva QR.
    $documentId = ($this->cobrar)();

    expect(DB::table('fiscal_documents')->where('id', $documentId)->value('qr_content'))->toBeNull();

    // 2. El token EXISTE y esta VACIO: el MISMO resultado.
    DB::table('fiscal_profile_fields')->insert([
        'fiscal_profile_id' => $this->escenario['profile_id'],
        'key' => 'qr_required_tokens',
        'data_type' => 'string',
        'label' => 'Tokens del QR',
        'value' => '',
        'required' => false,
        'applies_to' => 'document',
        'evidence_status' => 'verified',
        'source_ref' => 'REF-PRUEBA',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $otroDocumentId = ($this->cobrar)();

    expect(DB::table('fiscal_documents')->where('id', $otroDocumentId)->value('qr_content'))->toBeNull()
        ->and(ReceiptTemplate::fromDocument(DB::connection(), $otroDocumentId)->qrContent)->toBeNull();
});

/**
 * F2-02 — el emisor del comprobante lleva su identificativo fiscal y su tipo.
 *
 * REGLA que fija: *el emisor tiene identificativo fiscal* (art. 8.4). El
 * emisor es la tienda, con su inscripcion fiscal primaria: su nombre, su RIF
 * y el TIPO de ese RIF, que sale del esquema de la inscripcion y no de una
 * adivinanza.
 */
test('test_el_emisor_del_comprobante_lleva_su_identificativo_fiscal_y_su_tipo', function () {
    $documentId = ($this->cobrar)();

    $emisor = profile_tokens_parte($documentId, 'issuer');

    expect($emisor)->not->toBeNull()
        ->and((string) $emisor->name)->toBe('Tienda de demostracion')
        ->and((string) $emisor->fiscal_id)->toBe(profile_tokens_rif_de_demonstracion())
        ->and((string) $emisor->fiscal_id_kind)->toBe('rif');
});

/**
 * F2-02 — la inscripcion fiscal de demostracion es OBVIAMENTE de ejemplo.
 *
 * REGLA que fija: *el sistema avisa cuando el identificativo que tiene es de
 * DEMOSTRACION*. Un RIF con formato verosimil se podria usar por error; un RIF
 * que dice DEMO no se confunde con ninguno.
 */
test('test_la_inscripcion_fiscal_de_demonstracion_es_evidentemente_de_ejemplo', function () {
    $inscripcion = DB::table('store_fiscal_registrations')
        ->where('store_id', $this->escenario['store_id'])
        ->where('is_primary', true)
        ->first();

    expect($inscripcion)->not->toBeNull('La tienda de demostracion no tiene inscripcion fiscal.')
        ->and((string) $inscripcion->tax_id)->toBe(profile_tokens_rif_de_demonstracion())
        ->and((string) $inscripcion->tax_id)->toContain('DEMO')
        ->and((string) $inscripcion->tax_id_scheme)->toBe('rif');
});

/**
 * F2-02 — un token de nombre VACIO no inventa un receptor.
 *
 * REGLA que fija: *un nombre inventado es peor que un hueco*. Un token
 * declarado y VACIO no es un nombre: sin nombre no hay receptor, y el hueco
 * se ve.
 */
test('test_un_token_de_nombre_vacio_no_inventa_un_receptor', function () {
    // El token declarado y VACIO: el perfil lo declara, pero sin nombre.
    DB::table('fiscal_profile_fields')
        ->where('fiscal_profile_id', $this->escenario['profile_id'])
        ->where('key', 'final_consumer_name')
        ->update([
            'value' => '   ',
            'updated_at' => now(),
        ]);

    $documentId = ($this->cobrar)();

    // Y NO hay receptor: un nombre vacio no es un nombre.
    expect(profile_tokens_parte($documentId, 'receiver'))->toBeNull();
});
