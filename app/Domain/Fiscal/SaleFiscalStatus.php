<?php

namespace App\Domain\Fiscal;

use App\Domain\Fiscal\ValueObjects\FiscalIssuanceOutcome;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El ESTADO FISCAL de una venta, tal como lo ve la pantalla.
 *
 * REGLA que fija esta clase: *el estado fiscal de una venta tiene UN
 * vocabulario, y es el del dominio*: `issued` (emitido), `failed` (fallido,
 * con su motivo) o `not_emitted` (no emitido). No es el estado de PAGO: ese
 * lo dice `sales.status`.
 *
 * Y el estado fiscal se LEE de la venta y de su documento, no se deduce del
 * pago: una venta pagada puede tener su comprobante pendiente, y una venta
 * sin pago puede tenerlo emitido.
 *
 * EL MOTIVO DEL FALLO SOBREVIVE A LA PETICION: la emision que falla pasa en
 * un POST que se responde y se olvida, asi que el motivo queda AUDITADO
 * (`audit_logs`) y se lee de ahi. Un fallo que no se explica no se puede
 * reintentar a ciegas.
 *
 * Y LA CONSULTA ES EN LOTE: la lista de ventas enseña el estado fiscal de
 * cada una, y una consulta por venta en una pagina de cincuenta ventas es
 * cincuenta veces la pregunta que se podia hacer una vez.
 */
final readonly class SaleFiscalStatus
{
    /**
     * La accion de auditoria que deja el motivo de un fallo de emision.
     */
    private const ACCION_FALLO = 'fiscal_issuance_failed';

    /**
     * El tipo de la entidad a la que se atribuye el fallo.
     */
    private const ENTIDAD_VENTA = 'sale';

    public function __construct(private Connection $connection) {}

    /**
     * El estado fiscal de UNA venta.
     *
     * @return array{status: string, document_id: int|null, control_number: string|null, message: string|null}
     *
     * @throws RuntimeException Cuando la venta no existe.
     */
    public function forSale(int $saleId): array
    {
        $estados = $this->forSales([$saleId]);

        if (! array_key_exists($saleId, $estados)) {
            throw new RuntimeException("La venta {$saleId} no existe.");
        }

        return $estados[$saleId];
    }

    /**
     * El estado fiscal de VARIAS ventas, en las consultas que hacen falta.
     *
     * @param  list<int>  $saleIds
     * @return array<int, array{status: string, document_id: int|null, control_number: string|null, message: string|null}>
     */
    public function forSales(array $saleIds): array
    {
        $saleIds = array_values(array_unique(array_map('intval', $saleIds)));

        if ($saleIds === []) {
            return [];
        }

        // 1. Las ventas y el estado de SUS documentos.
        $ventas = $this->connection->table('sales')
            ->whereIn('id', $saleIds)
            ->get(['id', 'status', 'fiscal_document_id']);

        $documentIds = [];

        foreach ($ventas as $venta) {
            if ($venta->fiscal_document_id !== null) {
                $documentIds[] = (int) $venta->fiscal_document_id;
            }
        }

        $estadosDeDocumento = [];

        if ($documentIds !== []) {
            foreach ($this->connection->table('fiscal_documents')
                ->whereIn('id', $documentIds)
                ->get(['id', 'status']) as $documento) {
                $estadosDeDocumento[(int) $documento->id] = (string) $documento->status;
            }
        }

        // 2. Los numeros de control de los documentos emitidos.
        $controles = [];

        if ($documentIds !== []) {
            foreach ($this->connection->table('fiscal_document_control_assignments')
                ->whereIn('fiscal_document_id', $documentIds)
                ->get(['fiscal_document_id', 'control_number']) as $asignacion) {
                $controles[(int) $asignacion->fiscal_document_id] = (string) $asignacion->control_number;
            }
        }

        // 3. El motivo del ultimo fallo de cada venta.
        $motivos = $this->motivos($saleIds);

        // 4. El estado de cada venta, con el MISMO vocabulario que la
        //    respuesta del cobro.
        $estados = [];

        foreach ($ventas as $venta) {
            $saleId = (int) $venta->id;
            $documentId = $venta->fiscal_document_id === null ? null : (int) $venta->fiscal_document_id;

            if ($documentId !== null && ($estadosDeDocumento[$documentId] ?? null) === 'issued') {
                $estados[$saleId] = [
                    'status' => FiscalIssuanceOutcome::EMITIDO,
                    'document_id' => $documentId,
                    'control_number' => $controles[$documentId] ?? null,
                    'message' => null,
                ];

                continue;
            }

            if ((string) $venta->status === 'fiscal_failed') {
                $estados[$saleId] = [
                    'status' => FiscalIssuanceOutcome::FALLIDO,
                    'document_id' => $documentId,
                    'control_number' => null,
                    'message' => $motivos[$saleId] ?? null,
                ];

                continue;
            }

            // No emitido: el interruptor apagado, o una venta a la que todavia
            // no se le ha emitido su comprobante.
            $estados[$saleId] = [
                'status' => FiscalIssuanceOutcome::NO_EMITIDO,
                'document_id' => $documentId,
                'control_number' => null,
                'message' => null,
            ];
        }

        return $estados;
    }

    /**
     * El MOTIVO del ultimo fallo de emision de cada venta.
     *
     * @param  list<int>  $saleIds
     * @return array<int, string>
     */
    private function motivos(array $saleIds): array
    {
        $filas = $this->connection->table('audit_logs')
            ->where('action', self::ACCION_FALLO)
            ->where('entity_type', self::ENTIDAD_VENTA)
            ->whereIn('entity_id', $saleIds)
            ->orderByDesc('id')
            ->get(['entity_id', 'context']);

        $motivos = [];

        foreach ($filas as $fila) {
            $saleId = (int) $fila->entity_id;

            // El PRIMERO es el ultimo: las filas vienen de la mas nueva a la
            // mas vieja, y el motivo que vale es el de la ultima emision.
            if (array_key_exists($saleId, $motivos)) {
                continue;
            }

            $mensaje = $this->mensajeDe($fila->context);

            if ($mensaje !== null) {
                $motivos[$saleId] = $mensaje;
            }
        }

        return $motivos;
    }

    /**
     * El MENSAJE del contexto de auditoria.
     */
    private function mensajeDe(mixed $contexto): ?string
    {
        if (is_string($contexto)) {
            $contexto = json_decode($contexto, true);
        }

        if (! is_array($contexto)) {
            return null;
        }

        $mensaje = $contexto['message'] ?? null;

        return $mensaje === null ? null : (string) $mensaje;
    }
}
