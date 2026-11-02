<?php

namespace App\Domain\Fiscal;

use App\Domain\Fiscal\Enums\ContingencyCause;
use DateTimeImmutable;
use Illuminate\Database\Connection;
use Illuminate\Support\Carbon;
use RuntimeException;

/**
 * Contingencia en papel: un mecanismo CONCILIADO, no un documento mas.
 *
 * El papel se usa SOLO por contingencia, ante una causa tasada, y se mantiene
 * en el establecimiento. NO compite con el documento digital: al abrir un
 * talonario no se emite ningun documento fiscal ni se consume ningun
 * correlativo (el papel no es un documento paralelo).
 *
 * Al cesar la contingencia, los documentos fisicos se REGISTRAN y se remiten
 * en PDF **al dia siguiente**. Ese plazo es la conciliacion: el papel queda
 * trazado y el sistema digital conserva la verdad.
 */
final class ContingencyService
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * Abre un talonario de contingencia.
     *
     * @param  int  $controlFrom  Primer Numero de Control del talonario.
     * @param  int  $controlTo  Ultimo Numero de Control del talonario.
     * @param  int  $documentFrom  Primer correlativo del talonario.
     * @param  int  $documentTo  Ultimo correlativo del talonario.
     */
    public function open(
        int $storeId,
        int $fiscalProfileId,
        ContingencyCause $cause,
        int $controlFrom,
        int $controlTo,
        int $documentFrom,
        int $documentTo,
        DateTimeImmutable $issuedAt,
    ): int {
        return $this->connection->table('fiscal_contingency_forms')->insertGetId([
            'store_id' => $storeId,
            'fiscal_profile_id' => $fiscalProfileId,
            'control_from' => $controlFrom,
            'control_to' => $controlTo,
            'document_from' => $documentFrom,
            'document_to' => $documentTo,
            'issued_at' => $issuedAt,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    /**
     * Registra los documentos fisicos usados durante la contingencia.
     */
    public function register(int $formId, DateTimeImmutable $registeredAt): void
    {
        $form = $this->form($formId);

        if ($form->registered_at !== null) {
            throw new RuntimeException("El talonario {$formId} ya tiene los documentos fisicos registrados.");
        }

        $this->connection->table('fiscal_contingency_forms')->where('id', $formId)->update([
            'registered_at' => $registeredAt,
            'updated_at' => now(),
        ]);
    }

    /**
     * Remite los PDFs de los documentos fisicos: **al dia siguiente** al
     * registro, ni un dia antes.
     */
    public function remitPdfs(int $formId, DateTimeImmutable $remittedAt): void
    {
        $form = $this->form($formId);

        if ($form->registered_at === null) {
            throw new RuntimeException(
                "El talonario {$formId} no tiene los documentos fisicos registrados: no se puede remitir."
            );
        }

        $registeredOn = Carbon::parse($form->registered_at)->toDateString();

        if ($remittedAt->format('Y-m-d') <= $registeredOn) {
            throw new RuntimeException(
                "La remision en PDF del talonario {$formId} no puede ser el dia del registro: va al dia siguiente."
            );
        }

        $this->connection->table('fiscal_contingency_forms')->where('id', $formId)->update([
            'pdf_remitted_at' => $remittedAt,
            'updated_at' => now(),
        ]);
    }

    /**
     * @return object
     */
    private function form(int $formId)
    {
        $form = $this->connection->table('fiscal_contingency_forms')->where('id', $formId)->first();

        if ($form === null) {
            throw new RuntimeException("El talonario de contingencia {$formId} no existe.");
        }

        return $form;
    }
}
