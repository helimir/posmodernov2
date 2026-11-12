<?php

namespace App\Domain\Fiscal;

use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El modelo de plantilla del comprobante.
 *
 * Es el MISMO modelo que usara el renderer ESC/POS: los datos y los tokens
 * normativos viven aqui, y cada renderer (HTML, PDF, ESC/POS) los compone a
 * su manera. Lo que el dominio decide es QUE se imprime; como se imprime es
 * del renderer.
 *
 * Los tokens —el marcador de exento, la frase del contribuyente formal, la
 * denominacion— se LEEN del perfil fiscal (`fiscal_profile_fields`), nunca
 * del codigo: el enunciado de cada documento lo declara la norma de cada
 * pais, y un literal en el dominio seria un fork por pais.
 *
 * EL COMPROBANTE TIENE QUE ESTAR COMPLETO: un comprobante sin emisor, sin
 * receptor y sin lineas no es un comprobante. Por eso la plantilla trae las
 * PARTES (las escribe `FiscalDocumentParties`) y las LINEAS de la venta.
 */
final readonly class ReceiptTemplate
{
    /**
     * @param  array<int, array<string, mixed>>  $parties  Emisor, receptor y terceros, con su rol.
     * @param  array<int, array<string, mixed>>  $lines  Las lineas del comprobante.
     * @param  array<int, array<string, mixed>>  $taxes  Los impuestos, discriminados por alicuota.
     */
    public function __construct(
        public string $denomination,
        public string $documentNumber,
        public ?string $controlNumber,
        public DateTimeImmutable $issuedAt,
        public ?string $currencyCode,
        public ?string $originalCurrencyCode,
        public ?string $exchangeRate,
        public string $totalAmount,
        public array $parties,
        public array $lines,
        public array $taxes,
        public ?string $exemptionMarker,
        public ?string $formalContributorPhrase,
        public ?string $printerName,
        public ?string $printerTaxId,
        public ?string $provisionReference,
        public ?string $qrContent = null,
    ) {}

    /**
     * Arma el modelo desde el documento fiscal ya emitido.
     */
    public static function fromDocument(Connection $connection, int $documentId): self
    {
        $document = $connection->table('fiscal_documents')->where('id', $documentId)->first();

        if ($document === null) {
            throw new RuntimeException("El documento fiscal {$documentId} no existe.");
        }

        $parties = $connection->table('fiscal_document_parties')
            ->where('fiscal_document_id', $documentId)
            ->orderBy('role')
            ->get()
            ->map(fn (object $party): array => [
                'role' => $party->role,
                'name' => $party->name,
                'fiscal_id' => $party->fiscal_id,
                'fiscal_id_kind' => $party->fiscal_id_kind,
                'address' => $party->address,
            ])
            ->all();

        // El desglose por ALICUOTA: el nodo de cabecera del documento (el que
        // lleva el impuesto total y el ajuste de redondeo) NO tiene alicuota,
        // y por eso no es parte del desglose que pide la norma. Su importe ya
        // esta en el total.
        $taxes = $connection->table('fiscal_document_taxes')
            ->where('fiscal_document_id', $documentId)
            ->whereNotNull('tax_rate_snapshot')
            ->orderBy('tax_rate_snapshot')
            ->get()
            ->map(fn (object $tax): array => [
                'tax_type' => $tax->tax_type,
                'tax_rate_snapshot' => $tax->tax_rate_snapshot === null ? null : (string) $tax->tax_rate_snapshot,
                'base_amount' => (string) $tax->base_amount,
                'tax_amount' => (string) $tax->tax_amount,
            ])
            ->all();

        // El control tiene una UNICA sede: la tabla de asignaciones.
        $control = $connection->table('fiscal_document_control_assignments')
            ->where('fiscal_document_id', $documentId)
            ->first();

        $profileId = (int) $document->fiscal_profile_id;

        // La moneda es un DATO: se lee de la tabla de monedas, nunca se
        // escribe un codigo de moneda en el dominio.
        $currencyCode = null;
        if ($document->currency_id !== null) {
            $currencyCode = $connection->table('currencies')
                ->where('id', $document->currency_id)
                ->value('code');
            $currencyCode = $currencyCode === null ? null : (string) $currencyCode;
        }

        return new self(
            // La DENOMINACION es la que declara el PERFIL (art. 8.1: la
            // denominacion del documento). Sin token, se cae al tipo del
            // documento: el nombre crudo no es una denominacion, pero es
            // mejor que no tener nada.
            denomination: self::token($connection, $profileId, 'document_denomination')
                ?? (string) $document->document_type,
            documentNumber: (string) $document->number,
            controlNumber: $control === null ? null : (string) $control->control_number,
            issuedAt: self::instanteDeEmision($connection, $document),
            currencyCode: $currencyCode,
            originalCurrencyCode: null,
            exchangeRate: $document->exchange_rate === null ? null : (string) $document->exchange_rate,
            totalAmount: (string) $document->total_amount,
            parties: $parties,
            lines: self::lineas($connection, $documentId),
            taxes: $taxes,
            exemptionMarker: self::token($connection, $profileId, 'exemption_marker'),
            formalContributorPhrase: self::token($connection, $profileId, 'formal_contributor_phrase'),
            printerName: null,
            printerTaxId: $control === null ? null : $control->printer_tax_id,
            provisionReference: self::token($connection, $profileId, 'provision_reference'),
            // El QR es OPCIONAL por canal: si el documento no lo trae, el
            // comprobante no lleva ninguno.
            qrContent: $document->qr_content === null ? null : (string) $document->qr_content,
        );
    }

    /**
     * Las LINEAS del comprobante: las de la venta que lo origino.
     *
     * La forma es la que el renderer consume (`description`, `quantity`,
     * `amount` y `exempt`), mas los datos que el ticket ESC/POS necesita
     * (precio unitario, descuento e impuesto): el MISMO modelo de plantilla
     * alimenta a los dos renderizadores, y cada uno toma de el lo que usa.
     *
     * @return list<array<string, mixed>>
     */
    private static function lineas(Connection $connection, int $documentId): array
    {
        $saleId = $connection->table('sales')
            ->where('fiscal_document_id', $documentId)
            ->value('id');

        if ($saleId === null) {
            // Un documento que no viene de una venta no tiene lineas.
            return [];
        }

        $filas = $connection->table('sale_items')
            ->join('products', 'products.id', '=', 'sale_items.product_id')
            ->where('sale_items.sale_id', (int) $saleId)
            ->orderBy('sale_items.id')
            ->get([
                'sale_items.quantity as quantity',
                'sale_items.unit_price as unit_price',
                'sale_items.discount as discount',
                'sale_items.base_amount as base_amount',
                'sale_items.tax_amount as tax_amount',
                'sale_items.line_total as line_total',
                'sale_items.tax_rate_snapshot as tax_rate_snapshot',
                'products.name as description',
            ]);

        $lineas = [];

        foreach ($filas as $fila) {
            $lineas[] = [
                'description' => (string) $fila->description,
                'quantity' => (string) $fila->quantity,
                'unit_price' => (string) $fila->unit_price,
                'discount' => (string) $fila->discount,
                'tax_amount' => (string) $fila->tax_amount,
                // El MONTO de la linea: el precio por la cantidad, con el
                // descuento, mas el impuesto.
                'amount' => (string) $fila->line_total,
                // La linea EXENTA: la que no causa impuesto (alicuota cero).
                'exempt' => self::esExenta($fila->tax_rate_snapshot),
            ];
        }

        return $lineas;
    }

    /**
     * ¿La linea esta EXENTA (alicuota cero)?
     */
    private static function esExenta(mixed $alicuota): bool
    {
        if ($alicuota === null) {
            return false;
        }

        return (float) $alicuota === 0.0;
    }

    /**
     * El INSTANTE de emision, en la zona horaria de la TIENDA.
     *
     * REGLA que fija este metodo: *la fecha es la del documento y la hora es
     * la de la emision*. El comprobante pide la fecha de emision (DDMMAAAA)
     * y la hora (HH.MM.SS con a.m/p.m), y la fecha del documento es la de
     * NEGOCIO de la venta: puede NO ser el dia en que se consolido. La hora
     * es la del instante en que el documento salio, en la zona de la tienda,
     * porque el reloj del comprobante es el del negocio.
     *
     * Sin `issued_at` (un documento que todavia no se emitio) la hora es la
     * de medianoche: un borrador no tiene hora de emision.
     */
    private static function instanteDeEmision(Connection $connection, object $document): DateTimeImmutable
    {
        $zona = self::zonaHoraria($connection, (int) $document->store_id);

        $instante = null;

        if ($document->issued_at !== null) {
            $instante = (new DateTimeImmutable((string) $document->issued_at))->setTimezone($zona);
        }

        $fecha = $document->issue_date === null ? null : (string) $document->issue_date;

        if ($fecha === null && $instante !== null) {
            $fecha = $instante->format('Y-m-d');
        }

        if ($fecha === null) {
            $fecha = substr((string) $document->created_at, 0, 10);
        }

        $hora = $instante === null ? '00:00:00' : $instante->format('H:i:s');

        return new DateTimeImmutable($fecha.' '.$hora, $zona);
    }

    /**
     * La ZONA HORARIA de la tienda: el reloj del comprobante es el del
     * negocio, no el del servidor.
     */
    private static function zonaHoraria(Connection $connection, int $storeId): DateTimeZone
    {
        $timezone = $connection->table('store_settings')
            ->where('store_id', $storeId)
            ->value('timezone');

        $nombre = is_string($timezone) ? trim($timezone) : '';

        if ($nombre === '') {
            $nombre = (string) config('app.timezone');
        }

        return new DateTimeZone($nombre);
    }

    /**
     * Un token del catalogo del perfil fiscal.
     */
    private static function token(Connection $connection, int $fiscalProfileId, string $key): ?string
    {
        $value = $connection->table('fiscal_profile_fields')
            ->where('fiscal_profile_id', $fiscalProfileId)
            ->where('key', $key)
            ->value('value');

        return $value === null ? null : (string) $value;
    }
}
