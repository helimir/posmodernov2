<?php

namespace App\Domain\Fiscal;

use App\Domain\Fiscal\ValueObjects\RetentionReceiptDraft;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Documentos derivados del comprobante fiscal.
 *
 * Una factura anulada NO se corrige devolviendo su numero: se corrige con una
 * NOTA, que se emite por el MISMO medio que el original y que lo referencia
 * (fecha, correlativo, numero de control y monto).
 *
 * El comprobante de RETENCION es un documento PROPIO, no una categoria de
 * linea de una venta: el IVA retenido es credito del comprador, lo emite el
 * agente de retencion y tiene numeracion propia de 14 caracteres
 * (`AAAAMMSSSSSSSS`).
 *
 * Los ENUNCIADOS son tokens del perfil fiscal (`fiscal_profile_fields`), no
 * literales del dominio: el nombre visible de cada documento lo declara la
 * norma de cada pais.
 */
final class DerivedDocumentService
{
    /** Enunciado de la nota en el catalogo del perfil. */
    private const NOTE_DENOMINATION_KEYS = [
        'credit_note' => 'credit_note_denomination',
        'debit_note' => 'debit_note_denomination',
    ];

    public function __construct(
        private readonly Connection $connection,
        private readonly NumberSeries $numberSeries,
    ) {}

    /**
     * Emite una NOTA de credito o debito sobre un documento original.
     *
     * @param  string  $noteType  `credit_note` | `debit_note`.
     */
    public function issueNote(
        int $originalDocumentId,
        string $noteType,
        DateTimeImmutable $issuedAt,
        ?int $actorId = null,
    ): int {
        if (! isset(self::NOTE_DENOMINATION_KEYS[$noteType])) {
            throw new RuntimeException("El tipo de nota {$noteType} no existe.");
        }

        $original = $this->connection->table('fiscal_documents')->where('id', $originalDocumentId)->first();

        if ($original === null) {
            throw new RuntimeException("El documento fiscal {$originalDocumentId} no existe.");
        }

        // La nota se numera en la MISMA serie que el documento: la numeracion
        // del emisor es una sola.
        $number = $this->numberSeries->reserve((int) $original->series_id);

        // El enunciado lo declara el perfil, no el codigo.
        $denomination = $this->profileToken(
            (int) $original->fiscal_profile_id,
            self::NOTE_DENOMINATION_KEYS[$noteType]
        );

        return $this->connection->transaction(function () use ($original, $originalDocumentId, $noteType, $number, $issuedAt, $actorId, $denomination): int {
            $noteId = $this->connection->table('fiscal_documents')->insertGetId([
                'fiscal_profile_id' => $original->fiscal_profile_id,
                'store_id' => $original->store_id,
                // El MISMO medio que el original.
                'provider_id' => $original->provider_id,
                'series_id' => $original->series_id,
                'number' => $number,
                'document_type' => $noteType,
                'status' => 'issued',
                'issue_date' => $issuedAt->format('Y-m-d'),
                'issued_at' => $issuedAt,
                'subtotal' => $original->subtotal,
                'tax_total' => $original->tax_total,
                'total_amount' => $original->total_amount,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // La referencia al original: correlativo y monto, y el vinculo
            // estructurado.
            $this->connection->table('fiscal_document_links')->insert([
                'fiscal_document_id' => $noteId,
                'link_type' => 'corrects',
                'parent_document_id' => $originalDocumentId,
                'parent_reference' => (string) $original->number,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->connection->table('fiscal_document_events')->insert([
                'fiscal_document_id' => $noteId,
                'action' => 'issue',
                'actor' => $actorId,
                'occurred_at' => $issuedAt,
                'payload' => json_encode([
                    'denomination' => $denomination,
                    'source_document_id' => (int) $originalDocumentId,
                    'source_number' => (string) $original->number,
                    'source_total' => (string) $original->total_amount,
                    'source_date' => (string) $original->issue_date,
                    'source_control_number' => $this->controlNumber($originalDocumentId),
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            return $noteId;
        });
    }

    /**
     * Emite el comprobante de retencion: un documento fiscal PROPIO.
     *
     * @param  int  $retentionSeriesId  Serie con `series_role='retention_receipt'`.
     */
    public function issueRetentionReceipt(
        int $retentionSeriesId,
        RetentionReceiptDraft $draft,
        DateTimeImmutable $issuedAt,
        ?int $actorId = null,
    ): int {
        $series = $this->connection->table('fiscal_series')->where('id', $retentionSeriesId)->first();

        if ($series === null) {
            throw new RuntimeException("La serie de retencion {$retentionSeriesId} no existe.");
        }

        $source = $this->connection->table('fiscal_documents')->where('id', $draft->sourceDocumentId)->first();

        if ($source === null) {
            throw new RuntimeException("El documento fiscal {$draft->sourceDocumentId} no existe.");
        }

        // El secuencial de la serie, reservado de forma atomica.
        $sequential = $this->numberSeries->reserve($retentionSeriesId);

        // Catorce caracteres: AAAAMM + ocho digitos del secuencial.
        $number = $issuedAt->format('Ym').str_pad((string) $sequential, 8, '0', STR_PAD_LEFT);

        return $this->connection->transaction(function () use ($series, $retentionSeriesId, $source, $draft, $number, $issuedAt, $actorId): int {
            $documentId = $this->connection->table('fiscal_documents')->insertGetId([
                'fiscal_profile_id' => $series->fiscal_profile_id,
                'store_id' => $series->store_id,
                'series_id' => $retentionSeriesId,
                'number' => $number,
                'document_type' => 'retention_receipt',
                'status' => 'issued',
                'issue_date' => $issuedAt->format('Y-m-d'),
                'issued_at' => $issuedAt,
                // El periodo de imposicion es un dato DEL DOCUMENTO.
                'period_from' => $draft->periodFrom,
                'period_to' => $draft->periodTo,
                'subtotal' => $draft->baseAmount,
                'tax_total' => $draft->taxRetained,
                'total_amount' => $draft->taxRetained,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            // El impuesto retenido es un nodo de CABECERA del documento.
            $this->connection->table('fiscal_document_taxes')->insert([
                'fiscal_document_id' => $documentId,
                'line_id' => null,
                'tax_type' => 'withholding',
                'base_amount' => $draft->baseAmount,
                'tax_amount' => $draft->taxRetained,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $this->connection->table('fiscal_document_events')->insert([
                'fiscal_document_id' => $documentId,
                'action' => 'issue',
                'actor' => $actorId,
                'occurred_at' => $issuedAt,
                'payload' => json_encode([
                    'source_document_id' => (int) $source->id,
                    'source_number' => (string) $source->number,
                    'source_control_number' => $this->controlNumber((int) $source->id),
                    'period_from' => $draft->periodFrom,
                    'period_to' => $draft->periodTo,
                    'base_amount' => $draft->baseAmount,
                    'tax_caused' => $draft->taxCaused,
                    'tax_retained' => $draft->taxRetained,
                ], JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);

            return $documentId;
        });
    }

    /**
     * El numero de control de un documento, si lo tiene.
     *
     * El control tiene una UNICA sede: la tabla de asignaciones.
     */
    private function controlNumber(int $documentId): ?string
    {
        $value = $this->connection->table('fiscal_document_control_assignments')
            ->where('fiscal_document_id', $documentId)
            ->value('control_number');

        return $value === null ? null : (string) $value;
    }

    /**
     * Un token del catalogo del perfil fiscal.
     */
    private function profileToken(int $fiscalProfileId, string $key): ?string
    {
        $value = $this->connection->table('fiscal_profile_fields')
            ->where('fiscal_profile_id', $fiscalProfileId)
            ->where('key', $key)
            ->value('value');

        return $value === null ? null : (string) $value;
    }
}
