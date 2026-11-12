<?php

namespace App\Domain\Fiscal;

use App\Domain\Fiscal\Contracts\ElectronicInvoiceProvider;
use App\Domain\Fiscal\ValueObjects\CancellationReason;
use App\Domain\Fiscal\ValueObjects\CancellationReceipt;
use App\Domain\Fiscal\ValueObjects\ControlAssignment;
use App\Domain\Fiscal\ValueObjects\DeliveryReceipt;
use App\Domain\Fiscal\ValueObjects\DeliveryRecipient;
use App\Domain\Fiscal\ValueObjects\FiscalDocumentData;
use App\Domain\Fiscal\ValueObjects\FiscalDocumentDraft;
use App\Domain\Fiscal\ValueObjects\ProviderStatus;
use App\Domain\Fiscal\ValueObjects\SubmissionReceipt;
use DateTimeImmutable;
use DateTimeInterface;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El PROVEEDOR LOCAL: el camino de ROL DUAL.
 *
 * REGLA que fija esta clase: *el proveedor local es el camino que el propio
 * diseno contempla*. El sistema es emisor E imprenta (`EnvironmentRole`), y
 * el numero de control lo genera el `ControlNumberGenerator` local, no un
 * actor externo (design §C.1, ADR-0002).
 *
 * LO QUE HACE, Y POR QUE:
 *
 *  - `submit()` devuelve un acuse TRAZABLE: su identificador se deriva de la
 *    serie y del numero del documento, asi que el mismo documento produce
 *    SIEMPRE el mismo acuse. Un identificador aleatorio seria una traza que
 *    no lleva a ninguna parte;
 *  - `assignControl()` usa el `ControlNumberGenerator` sobre la serie de
 *    control DEL PERFIL: la reserva es atomica, y el control es el
 *    COMPUESTO (identificador de dos digitos mas secuencial). Y es
 *    IDEMPOTENTE: si el documento ya tiene un control PUBLICADO, se
 *    conserva, porque un numero publicado no se devuelve ni se reasigna. Y
 *    si el control pertenece a una VENTA, se REUTILIZA el que la venta ya
 *    reservo: el comprobante y el documento fiscal llevan el MISMO numero;
 *  - `issue()` devuelve el estado NATIVO que el PERFIL declara en su
 *    vocabulario, la referencia de autoridad con el ESQUEMA del perfil, y el
 *    QR SOLO si el perfil declara sus tokens.
 *
 * Y LO QUE NO HACE:
 *
 *  - NO inventa un QR. Un QR mal hecho es PEOR que ningun QR: si el perfil
 *    no declara los tokens, el contenido del QR es NULO (no una cadena
 *    vacia), y el documento lo dice con ese nulo;
 *  - NO entrega el comprobante. La entrega la hace el agente de impresion
 *    (`print_jobs`), y un acuse de entrega mentiria sobre lo que hizo este
 *    canal.
 */
final class LocalPrinterProvider implements ElectronicInvoiceProvider
{
    /**
     * El prefijo del acuse local. El identificador completo lleva la serie y
     * el numero del documento.
     */
    private const PREFIJO = 'LOCAL-';

    /**
     * El estado GENERICO del documento consolidado. El codigo NATIVO lo
     * declara el perfil, en su vocabulario.
     */
    private const ESTADO_EMITIDO = 'issued';

    /**
     * La clave del perfil que declara los TOKENS del QR.
     */
    private const CLAVE_TOKENS_QR = 'qr_required_tokens';

    public function __construct(
        private readonly Connection $connection,
        private readonly ControlNumberGenerator $controlGenerator,
        private readonly int $documentSeriesId,
        private readonly int $fiscalProfileId,
        private readonly int $storeId,
        private readonly ?int $saleId = null,
    ) {}

    /**
     * El ACUSE de la entrega de la estructura.
     *
     * El identificador es TRAZABLE: lleva la serie y el numero del
     * documento, y por eso el mismo documento produce siempre el mismo
     * acuse.
     */
    public function submit(FiscalDocumentDraft $draft): SubmissionReceipt
    {
        return new SubmissionReceipt(
            submissionId: $this->submissionId((int) $draft->documentNumber),
            status: new ProviderStatus('accepted', null, true),
            receivedAt: now()->toDateTimeImmutable(),
        );
    }

    /**
     * El NUMERO DE CONTROL, del generador local.
     *
     * Es `null` cuando el perfil NO lo exige: un control que nadie pidio no
     * se asigna, y la consolidacion lo admite (design §C.1).
     *
     * Y es IDEMPOTENTE: un control ya PUBLICADO se conserva. Un numero
     * publicado no se devuelve ni se reasigna, y el reintento de la emision
     * no puede crear un segundo control para el mismo documento.
     *
     * Y es EL MISMO que el del COMPROBANTE: la venta ya reservo su numero
     * de control, y este canal lo REUTILIZA en vez de reservar otro. Dos
     * reservas serian dos numeros para una venta, y el comprobante impreso
     * llevaria un control que el documento fiscal no lleva.
     */
    public function assignControl(SubmissionReceipt $receipt): ?ControlAssignment
    {
        if (! $this->exigeControl()) {
            return null;
        }

        [$serieId, $numero] = $this->documentoDelAcuse($receipt);

        $publicado = $this->controlPublicado($serieId, $numero);

        if ($publicado !== null) {
            return $publicado;
        }

        // El control de la VENTA: el MISMO que lleva el comprobante. La
        // reserva la hizo el checkout y ya esta enlazada a la venta, asi que
        // reutilizarla no quema numeros.
        if ($this->saleId !== null) {
            return $this->controlGenerator->assignForSale(
                $this->serieDeControl(),
                $this->saleId,
                now()->toDateTimeImmutable()
            );
        }

        // SIN VENTA no hay nada que reutilizar: este es el camino de los
        // comprobantes que no pertenecen a ninguna venta.
        return $this->controlGenerator->assign($this->serieDeControl(), now()->toDateTimeImmutable());
    }

    /**
     * El DOCUMENTO CONSOLIDADO.
     *
     * El estado es el NATIVO que el perfil declara, la referencia de
     * autoridad lleva el ESQUEMA del perfil, y el QR depende de los tokens
     * que el perfil declare.
     */
    public function issue(FiscalDocumentDraft $draft, ?ControlAssignment $control): FiscalDocumentData
    {
        $esquema = $this->esquemaDeReferencia();

        return new FiscalDocumentData(
            documentType: $draft->documentType,
            documentNumber: (string) $draft->documentNumber,
            status: $this->estadoNativo(),
            // La referencia lleva el ESQUEMA del perfil: el documento queda
            // distinguible de uno emitido ante una autoridad, sin reescribir
            // lo que el perfil declara.
            authorityReference: $esquema,
            authorityReferenceScheme: $esquema,
            qrContent: $this->contenidoQr($draft, $control),
            controlNumber: $control?->controlNumber,
        );
    }

    /**
     * La ANULACION del comprobante.
     *
     * En el canal local la anulacion es un hecho LOCAL: el documento se
     * anula y su numero queda consumido (`FiscalIssuanceService::void()`).
     * No hay autoridad a la que pedirle permiso, asi que el acuse refleja la
     * anulacion local.
     */
    public function cancel(FiscalDocumentData $document, CancellationReason $reason): CancellationReceipt
    {
        return new CancellationReceipt(
            $document->documentNumber,
            new ProviderStatus('cancelled', $reason->code, true),
            now()->toDateTimeImmutable(),
        );
    }

    /**
     * El ESTADO del comprobante en este canal.
     */
    public function queryStatus(FiscalDocumentData $document): ProviderStatus
    {
        return new ProviderStatus($document->status, null, true);
    }

    /**
     * La ENTREGA del comprobante al destinatario.
     *
     * Este canal NO entrega: la entrega la hace el agente de impresion
     * (`print_jobs`). Un acuse de entrega que no describe ninguna entrega
     * seria peor que un error que dice lo que pasa.
     */
    public function deliver(FiscalDocumentData $document, DeliveryRecipient $recipient): DeliveryReceipt
    {
        throw new RuntimeException(
            'El canal fiscal local no entrega el comprobante: la entrega la hace el agente de impresion.'
        );
    }

    /**
     * El IDENTIFICADOR del acuse: la serie y el numero del documento.
     */
    private function submissionId(int $number): string
    {
        return self::PREFIJO.$this->documentSeriesId.'-'.$number;
    }

    /**
     * El DOCUMENTO que identifica el acuse.
     *
     * @return array{0: int, 1: int} La serie y el numero.
     */
    private function documentoDelAcuse(SubmissionReceipt $receipt): array
    {
        $partes = explode('-', $receipt->submissionId);

        if (count($partes) !== 3 || $partes[0].'-' !== self::PREFIJO) {
            throw new RuntimeException(
                'El acuse del canal local no identifica a ningun documento: no se puede asignar el numero de control.'
            );
        }

        return [(int) $partes[1], (int) $partes[2]];
    }

    /**
     * El control YA PUBLICADO del documento, si lo hay.
     */
    private function controlPublicado(int $seriesId, int $number): ?ControlAssignment
    {
        $fila = $this->connection->table('fiscal_documents')
            ->join(
                'fiscal_document_control_assignments',
                'fiscal_document_control_assignments.fiscal_document_id',
                '=',
                'fiscal_documents.id'
            )
            ->where('fiscal_documents.series_id', $seriesId)
            ->where('fiscal_documents.number', $number)
            ->first();

        if ($fila === null) {
            return null;
        }

        return new ControlAssignment(
            controlNumber: (string) $fila->control_number,
            controlRangeFrom: (string) ($fila->control_range_from ?? 0),
            controlRangeTo: (string) ($fila->control_range_to ?? 0),
            assignedAt: $this->instante($fila->control_assigned_at),
            printerId: $fila->printer_id === null ? null : (string) $fila->printer_id,
            printerTaxId: $fila->printer_tax_id === null ? null : (string) $fila->printer_tax_id,
        );
    }

    /**
     * La SERIE DE CONTROL del perfil, en la tienda.
     */
    private function serieDeControl(): int
    {
        $serieId = $this->connection->table('fiscal_series')
            ->where('fiscal_profile_id', $this->fiscalProfileId)
            ->where('store_id', $this->storeId)
            ->where('series_role', 'control')
            ->orderBy('id')
            ->value('id');

        if ($serieId === null) {
            throw new RuntimeException(
                "El perfil fiscal {$this->fiscalProfileId} no tiene serie de control en la tienda "
                ."{$this->storeId}: no se puede asignar el numero de control."
            );
        }

        return (int) $serieId;
    }

    /**
     * Si el perfil EXIGE numero de control.
     */
    private function exigeControl(): bool
    {
        $exige = $this->connection->table('fiscal_profiles')
            ->where('id', $this->fiscalProfileId)
            ->value('requires_control_number');

        return $exige === null ? false : (bool) $exige;
    }

    /**
     * El ESTADO NATIVO que el perfil asocia al estado generico.
     */
    private function estadoNativo(): string
    {
        $nativo = $this->connection->table('fiscal_status_vocabularies')
            ->where('fiscal_profile_id', $this->fiscalProfileId)
            ->where('generic_status', self::ESTADO_EMITIDO)
            ->orderBy('id')
            ->value('native_code');

        if ($nativo === null) {
            throw new RuntimeException(
                "El perfil fiscal {$this->fiscalProfileId} no declara el codigo nativo del estado "
                ."'".self::ESTADO_EMITIDO."' en su vocabulario: no se puede consolidar el documento."
            );
        }

        return (string) $nativo;
    }

    /**
     * El ESQUEMA de la referencia de autoridad, tal como lo declara el perfil.
     */
    private function esquemaDeReferencia(): ?string
    {
        $esquema = $this->connection->table('fiscal_profiles')
            ->where('id', $this->fiscalProfileId)
            ->value('authority_reference_scheme');

        return $esquema === null ? null : (string) $esquema;
    }

    /**
     * El CONTENIDO del QR, si el perfil declara sus tokens.
     *
     * REGLA que fija este metodo: *un QR mal hecho es PEOR que ningun QR*.
     * Sin tokens declarados, el contenido es NULO: el documento dice que no
     * tiene QR, en vez de llevar un QR que nadie podria leer.
     */
    private function contenidoQr(FiscalDocumentDraft $draft, ?ControlAssignment $control): ?string
    {
        $tokens = $this->tokensQr();

        if ($tokens === []) {
            return null;
        }

        $partes = [];

        foreach ($tokens as $token) {
            $valor = $this->valorDelToken($token, $draft, $control);

            if ($valor === null) {
                // Un token declarado que NO se puede resolver NO se inventa:
                // el QR se queda sin hacer.
                return null;
            }

            $partes[] = $token.'='.$valor;
        }

        return implode('&', $partes);
    }

    /**
     * Los TOKENS del QR que declara el perfil.
     *
     * @return list<string>
     */
    private function tokensQr(): array
    {
        $valor = $this->connection->table('fiscal_profile_fields')
            ->where('fiscal_profile_id', $this->fiscalProfileId)
            ->where('key', self::CLAVE_TOKENS_QR)
            ->value('value');

        if (! is_string($valor) || trim($valor) === '') {
            return [];
        }

        $tokens = [];

        foreach (explode(',', $valor) as $token) {
            $token = trim($token);

            if ($token !== '') {
                $tokens[] = $token;
            }
        }

        return $tokens;
    }

    /**
     * El VALOR de un token del QR.
     */
    private function valorDelToken(string $token, FiscalDocumentDraft $draft, ?ControlAssignment $control): ?string
    {
        return match ($token) {
            'document_number' => $draft->seriesPrefix.$draft->documentNumber,
            'document_type' => $draft->documentType,
            'issued_at' => $draft->issuedAt->format('Y-m-d'),
            'currency' => $draft->currencyCode,
            'control_number' => $control?->controlNumber,
            default => null,
        };
    }

    /**
     * Un INSTANTE desde un valor de la base de datos.
     */
    private function instante(mixed $valor): DateTimeImmutable
    {
        if ($valor instanceof DateTimeImmutable) {
            return $valor;
        }

        if ($valor instanceof DateTimeInterface) {
            return DateTimeImmutable::createFromInterface($valor);
        }

        return now()->toDateTimeImmutable();
    }
}
