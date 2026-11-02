<?php

namespace App\Domain\Fiscal;

use DateTimeImmutable;
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

        $taxes = $connection->table('fiscal_document_taxes')
            ->where('fiscal_document_id', $documentId)
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
            denomination: $document->document_type,
            documentNumber: (string) $document->number,
            controlNumber: $control === null ? null : (string) $control->control_number,
            issuedAt: new DateTimeImmutable((string) $document->issue_date),
            currencyCode: $currencyCode,
            originalCurrencyCode: null,
            exchangeRate: $document->exchange_rate === null ? null : (string) $document->exchange_rate,
            totalAmount: (string) $document->total_amount,
            parties: $parties,
            lines: [],
            taxes: $taxes,
            exemptionMarker: self::token($connection, $profileId, 'exemption_marker'),
            formalContributorPhrase: self::token($connection, $profileId, 'formal_contributor_phrase'),
            printerName: null,
            printerTaxId: $control === null ? null : $control->printer_tax_id,
            provisionReference: self::token($connection, $profileId, 'provision_reference'),
        );
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
