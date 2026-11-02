<?php

namespace App\Domain\Fiscal;

use App\Domain\Fiscal\Contracts\ElectronicInvoiceProvider;
use App\Domain\Fiscal\ValueObjects\ControlAssignment;
use App\Domain\Fiscal\ValueObjects\FiscalDocumentDraft;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Orquestación de la emisión fiscal (T2).
 *
 * El emisor NO emite solo: el actor intermedio separa el flujo en tres pasos
 * —`submit()` entrega la estructura, `assignControl()` recibe el número de
 * control e `issue()` consolida. Las **tres** llamadas al proveedor ocurren
 * FUERA de la transacción de base de datos: mantener un bloqueo de fila
 * durante I/O externo rompería la unidad atómica que el checkout comparte con
 * la emisión (design §B.1, decisión C5).
 *
 * La persistencia —documento, evento y asignación de control— es **una**
 * transacción: o entra todo, o no entra nada.
 *
 * La invariante del control se valida **antes** de consolidar: si el perfil
 * exige control, el documento tiene exactamente una asignación; si no lo
 * exige, tiene cero.
 */
final class FiscalIssuanceService
{
    public function __construct(
        private readonly Connection $connection,
        private readonly ElectronicInvoiceProvider $provider,
    ) {}

    /**
     * Emite el documento y devuelve su identificador.
     *
     * @param  bool  $requiresControlNumber  Lo declara el perfil fiscal.
     * @param  int|null  $actorId  Quién emite; queda en la auditoría.
     */
    public function issue(
        int $documentId,
        FiscalDocumentDraft $draft,
        bool $requiresControlNumber,
        ?int $actorId = null,
    ): int {
        // 1. El proveedor, FUERA de la transaccion.
        $receipt = $this->provider->submit($draft);
        $control = $this->provider->assignControl($receipt);

        // 2. La invariante del control, ANTES de pedir la consolidacion: si el
        //    perfil exige un control que no llega, no se pide emitir nada.
        $discrepancy = $this->controlDiscrepancy($requiresControlNumber, $control);

        if ($requiresControlNumber && $control === null) {
            throw new RuntimeException(
                'El perfil fiscal exige Numero de Control y el actor intermedio no devolvio ninguno.'
            );
        }

        $data = $this->provider->issue($draft, $control);

        // 3. La persistencia, en UNA transaccion.
        $this->connection->transaction(function () use ($documentId, $data, $control, $actorId, $discrepancy, $receipt): void {
            $this->connection->table('fiscal_documents')->where('id', $documentId)->update([
                'status' => 'issued',
                'native_status_code' => $data->status,
                'authority_reference' => $data->authorityReference,
                'authority_reference_scheme' => $data->authorityReferenceScheme,
                'qr_content' => $data->qrContent,
                'issued_at' => now(),
                'updated_at' => now(),
            ]);

            // El control tiene una UNICA sede: la tabla de asignaciones, 1:1
            // con el documento. No se duplica en la cabecera.
            if ($control !== null && $discrepancy === null) {
                $this->connection->table('fiscal_document_control_assignments')->insert([
                    'fiscal_document_id' => $documentId,
                    'control_number' => $control->controlNumber,
                    'control_range_from' => $this->asNumber($control->controlRangeFrom),
                    'control_range_to' => $this->asNumber($control->controlRangeTo),
                    'control_assigned_at' => $control->assignedAt,
                    'printer_tax_id' => $control->printerTaxId,
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            }

            $this->connection->table('fiscal_document_events')->insert([
                'fiscal_document_id' => $documentId,
                'action' => 'issue',
                'actor' => $actorId,
                'occurred_at' => now(),
                'payload' => json_encode(array_filter([
                    'submission_id' => $receipt->submissionId,
                    'authority_reference' => $data->authorityReference,
                    'control_number' => $control?->controlNumber,
                    'discrepancy' => $discrepancy,
                ], static fn ($value): bool => $value !== null), JSON_UNESCAPED_UNICODE),
                'created_at' => now(),
            ]);
        });

        return $documentId;
    }

    /**
     * La correspondencia entre el control recibido y el perfil.
     *
     * @return string|null La descripción de la discrepancia, o `null` si
     *                     el control corresponde al perfil.
     */
    private function controlDiscrepancy(bool $requiresControlNumber, ?ControlAssignment $control): ?string
    {
        if ($requiresControlNumber && $control === null) {
            return 'control_ausente';
        }

        if (! $requiresControlNumber && $control !== null) {
            return 'control_no_requerido';
        }

        return null;
    }

    /**
     * Los rangos llegan como texto (el proveedor los formatea); la columna
     * es numerica.
     */
    private function asNumber(string $value): ?int
    {
        return is_numeric($value) ? (int) $value : null;
    }
}
