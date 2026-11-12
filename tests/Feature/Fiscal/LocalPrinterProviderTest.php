<?php

use App\Domain\Fiscal\ControlNumberGenerator;
use App\Domain\Fiscal\LocalPrinterProvider;
use App\Domain\Fiscal\NumberSeries;
use App\Domain\Fiscal\ValueObjects\FiscalDocumentDraft;
use Illuminate\Support\Facades\DB;
use Tests\Fixtures\FiscalProviderFixture;

/**
 * 1.7 — el PROVEEDOR LOCAL: el camino de rol dual.
 *
 * REGLA que fija: *el proveedor local es el camino que el propio diseno
 * contempla*. El sistema es emisor E imprenta (`EnvironmentRole`), y el numero
 * de control lo genera el `ControlNumberGenerator` local, no un actor
 * externo.
 *
 * Reglas que fija este test:
 *
 *  - `submit()` devuelve un acuse TRAZABLE: su identificador se deriva del
 *    documento, y no es un valor aleatorio sin registro;
 *  - `assignControl()` devuelve el control COMPUESTO (identificador de dos
 *    digitos y secuencial) y el rango, y reserva el correlativo de forma
 *    atomica: la serie avanza UNA vez;
 *  - `issue()` devuelve el estado NATIVO del vocabulario del perfil, la
 *    referencia de autoridad con el ESQUEMA del perfil, y el QR SOLO si el
 *    perfil declara sus tokens.
 */
beforeEach(function () {
    $this->contexto = FiscalProviderFixture::contexto();
    $this->serieDocumento = FiscalProviderFixture::serieDocumento($this->contexto);
    $this->serieControl = FiscalProviderFixture::serieControl($this->contexto);
    FiscalProviderFixture::vocabulario($this->contexto);
});

/**
 * El proveedor local del escenario.
 *
 * @param  array<string, mixed>  $contexto
 */
function proveedorLocal(array $contexto, int $serieDocumento): LocalPrinterProvider
{
    $connection = DB::connection();

    return new LocalPrinterProvider(
        $connection,
        new ControlNumberGenerator($connection, new NumberSeries($connection)),
        $serieDocumento,
        $contexto['profile_id'],
        $contexto['store_id'],
    );
}

/**
 * El BORRADOR que se entrega al proveedor.
 */
function borradorLocal(int $numero = 142): FiscalDocumentDraft
{
    return new FiscalDocumentDraft(
        documentType: 'invoice',
        seriesPrefix: 'ZZ',
        documentNumber: $numero,
        issuedAt: new DateTimeImmutable('2026-11-11T10:00:00'),
        currencyCode: 'ZZA',
        lines: [],
    );
}

/**
 * 1.7 — `submit` devuelve un recibo TRAZABLE al documento.
 *
 * REGLA que fija: *el acuse identifica al documento*. El identificador se
 * deriva de la serie y del numero, asi que el mismo documento produce SIEMPRE
 * el mismo acuse: un identificador aleatorio seria una traza que no lleva a
 * ninguna parte.
 */
test('test_el_submit_devuelve_un_recibo_trazable_al_documento', function () {
    $proveedor = proveedorLocal($this->contexto, $this->serieDocumento);

    $recibo = $proveedor->submit(borradorLocal());

    // 1. El identificador lleva la serie y el numero del documento.
    expect($recibo->submissionId)->toContain((string) $this->serieDocumento)
        ->and($recibo->submissionId)->toContain('142');

    // 2. Y es ESTABLE: el mismo documento produce el mismo acuse. Un
    //    identificador aleatorio seria distinto cada vez.
    $otro = $proveedor->submit(borradorLocal());

    expect($otro->submissionId)->toBe($recibo->submissionId);

    // 3. Y un documento DISTINTO produce un acuse distinto.
    $distinto = $proveedor->submit(borradorLocal(143));

    expect($distinto->submissionId)->not->toBe($recibo->submissionId);

    // 4. El acuse se acepta, y su estado es el del canal local.
    expect($recibo->status->status)->toBe('accepted');
});

/**
 * 1.7 — `assignControl` devuelve el control COMPUESTO y su rango.
 *
 * REGLA que fija: *el control local es el compuesto*. Son dos digitos de
 * identificador mas un secuencial de hasta ocho, y el rango total se
 * registra porque se imprime.
 */
test('test_el_assign_control_devuelve_el_control_compuesto_y_el_rango', function () {
    $proveedor = proveedorLocal($this->contexto, $this->serieDocumento);

    $recibo = $proveedor->submit(borradorLocal());

    $control = $proveedor->assignControl($recibo);

    // 1. El control COMPUESTO: el identificador de la serie y el secuencial.
    expect($control)->not->toBeNull()
        ->and($control->controlNumber)->toBe('00-00000001');

    // 2. Y el RANGO total, que se registra y se imprime.
    expect($control->controlRangeFrom)->toBe('1')
        ->and($control->controlRangeTo)->toBe('99999999');

    // 3. La fecha de asignacion es un instante.
    expect($control->assignedAt)->toBeInstanceOf(DateTimeImmutable::class);
});

/**
 * 1.7 — el correlativo del control se reserva de forma ATOMICA.
 *
 * REGLA que fija: *el numero de control se reserva, no se deduce*. La serie
 * avanza UNA vez y deja UNA sola traza de reserva: dos cajas simultaneas no
 * pueden recibir el mismo control.
 */
test('test_el_assign_control_reserva_el_correlativo_de_forma_atomica', function () {
    $proveedor = proveedorLocal($this->contexto, $this->serieDocumento);

    $recibo = $proveedor->submit(borradorLocal());

    $control = $proveedor->assignControl($recibo);

    // 1. La serie AVANZO UNA VEZ: de 1 a 2.
    expect((int) DB::table('fiscal_series')->where('id', $this->serieControl)->value('next_number'))
        ->toBe(2);

    // 2. Y el correlativo reservado es el que se entrego.
    expect($control->controlNumber)->toBe('00-00000001');

    // 3. Queda UNA sola traza de reserva en la serie del control.
    expect(DB::table('fiscal_document_reservations')->where('series_id', $this->serieControl)->count())
        ->toBe(1);

    // 4. Y un SEGUNDO control es el siguiente: la reserva es correlativa.
    $otro = $proveedor->assignControl($proveedor->submit(borradorLocal(143)));

    expect($otro->controlNumber)->toBe('00-00000002')
        ->and((int) DB::table('fiscal_series')->where('id', $this->serieControl)->value('next_number'))
        ->toBe(3);
});

/**
 * 1.7 — `issue` devuelve la referencia de autoridad con el ESQUEMA del perfil.
 *
 * REGLA que fija: *la referencia de autoridad la DECLARA el perfil*. El
 * proveedor local no inventa una referencia: usa el `authority_reference_scheme`
 * de la fila, tal cual, para que el documento sea distinguible de uno
 * emitido ante una autoridad.
 */
test('test_el_issue_devuelve_la_referencia_de_autoridad_con_el_esquema_del_perfil', function () {
    $proveedor = proveedorLocal($this->contexto, $this->serieDocumento);

    $datos = $proveedor->issue(borradorLocal(), null);

    // 1. El ESQUEMA es el del perfil, leido de la fila.
    $esquema = DB::table('fiscal_profiles')
        ->where('id', $this->contexto['profile_id'])
        ->value('authority_reference_scheme');

    expect($esquema)->toBe('ZZ.PENDING')
        ->and($datos->authorityReferenceScheme)->toBe($esquema)
        ->and($datos->authorityReference)->toBe($esquema);
});

/**
 * 1.7 — el QR SOLO se emite si el perfil declara sus tokens.
 *
 * REGLA que fija: *un QR mal hecho es PEOR que ningun QR*. El perfil que no
 * declara los tokens del QR NO tiene QR, y el documento lo dice: su contenido
 * de QR es nulo, no una cadena vacia que alguien podria imprimir.
 */
test('test_el_issue_no_devuelve_qr_si_el_perfil_no_declara_los_tokens', function () {
    $proveedor = proveedorLocal($this->contexto, $this->serieDocumento);

    $datos = $proveedor->issue(borradorLocal(), null);

    expect($datos->qrContent)->toBeNull();
});

/**
 * 1.7 — con los tokens declarados, el QR se compone con ellos.
 *
 * REGLA que fija: *el contenido del QR es DATO del perfil*. Los tokens los
 * declara el perfil, en su catalogo de claves, y el proveedor los compone en
 * ese orden: el perfil decide que se escanea.
 */
test('test_el_issue_devuelve_el_qr_cuando_el_perfil_declara_los_tokens', function () {
    FiscalProviderFixture::clave(
        $this->contexto,
        'qr_required_tokens',
        'document_number,control_number,issued_at'
    );

    $proveedor = proveedorLocal($this->contexto, $this->serieDocumento);

    $recibo = $proveedor->submit(borradorLocal());
    $control = $proveedor->assignControl($recibo);

    $datos = $proveedor->issue(borradorLocal(), $control);

    expect($datos->qrContent)->not->toBeNull()
        ->and($datos->qrContent)->toContain('document_number=ZZ142')
        ->and($datos->qrContent)->toContain('control_number=00-00000001')
        ->and($datos->qrContent)->toContain('issued_at=2026-11-11');
});

/**
 * 1.7 — el estado NATIVO sale del VOCABULARIO del perfil, no del codigo.
 *
 * REGLA que fija: *el estado del documento lo declara el perfil*. El
 * proveedor devuelve el codigo nativo que el perfil asocia al estado
 * generico, y no un literal escrito en el codigo.
 */
test('test_el_estado_nativo_sale_del_vocabulario_del_perfil_y_no_del_codigo', function () {
    $proveedor = proveedorLocal($this->contexto, $this->serieDocumento);

    $datos = $proveedor->issue(borradorLocal(), null);

    // 1. El codigo NATIVO es el de la fila del vocabulario.
    $nativo = DB::table('fiscal_status_vocabularies')
        ->where('fiscal_profile_id', $this->contexto['profile_id'])
        ->where('generic_status', 'issued')
        ->value('native_code');

    expect($nativo)->toBe('ZZ-EMITIDO')
        ->and($datos->status)->toBe($nativo);

    // 2. Y NO es el estado generico escrito a mano en el codigo.
    expect($datos->status)->not->toBe('issued');
});

/**
 * 1.7 — un perfil SIN vocabulario no consolida: falla con mensaje.
 *
 * REGLA que fija: *un perfil sin vocabulario no puede consolidar*. El
 * proveedor falla diciendo que falta, en vez de inventar un estado.
 */
test('test_un_perfil_sin_vocabulario_no_consolida_y_falla_con_mensaje', function () {
    DB::table('fiscal_status_vocabularies')
        ->where('fiscal_profile_id', $this->contexto['profile_id'])
        ->where('generic_status', 'issued')
        ->delete();

    $proveedor = proveedorLocal($this->contexto, $this->serieDocumento);

    expect(fn () => $proveedor->issue(borradorLocal(), null))
        ->toThrow(RuntimeException::class)
        ->toThrow('vocabulario');
});

/**
 * 1.7 — el perfil que NO exige control no recibe ninguno.
 *
 * REGLA que fija: *el control se asigna cuando el perfil lo exige*. Un perfil
 * que no lo exige no recibe un control que nadie pidio, y la consolidacion
 * lo admite.
 */
test('test_un_perfil_que_no_exige_control_no_recibe_ninguno', function () {
    DB::table('fiscal_profiles')
        ->where('id', $this->contexto['profile_id'])
        ->update(['requires_control_number' => false, 'updated_at' => now()]);

    $proveedor = proveedorLocal($this->contexto, $this->serieDocumento);

    $recibo = $proveedor->submit(borradorLocal());

    expect($proveedor->assignControl($recibo))->toBeNull();
});
