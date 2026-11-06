<?php

namespace App\Sync\Legacy;

use Illuminate\Database\Connection;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * El APLICADOR del lote del terminal legacy.
 *
 * REGLA que fija esta clase: *un lote es TODO o NADA*.
 *
 * El terminal legacy empuja su log de SQL y el maestro lo aplica en UNA
 * transaccion: si una sola operacion falla, no queda nada a medias. Un lote
 * a medias es PEOR que un lote rechazado, porque el terminal solo trunca su
 * log cuando la respuesta fue exitosa: un lote parcialmente aplicado se
 * reenvia y se aplica DOS veces (ADR-0006, hallazgo S1).
 *
 * La IDEMPOTENCIA la da `sync_batches`, con su restriccion unica
 * (`client_id`, `payload_sha256`): un reenvio IDENTICO del mismo lote se
 * reconoce y NO se vuelve a aplicar. El hash es del LOTE (los bytes
 * exactos del string `data` del contrato), NO del cuerpo HTTP: el terminal
 * reenvia el mismo lote con un `nonce` y un `timestamp` nuevos, asi que el
 * cuerpo cambia en cada intento y no serviria como clave.
 *
 * El lote se aplica OPERACION A OPERACION, y cada operacion se traduce en
 * el momento de aplicarla. Es lo que cierra la TENSION que el traductor
 * dejo abierta: el `resolve_ref` busca contra el estado ACTUAL de la base,
 * y dentro de la transaccion del lote ese estado ya incluye lo que se
 * aplico antes. Asi, un lote que CREA una venta y luego la REFERENCIA
 * (sus lineas, sus cobros) se aplica entero.
 *
 * Lo que NO se traslada, por decision (fallo CERRADO del ADR-0006):
 *
 *   - el `WHERE` DINAMICO del legacy (`_inc/ajax.php:154`) y el
 *     `SET col = col + ?`: el reescritor es LIGERO, no un parser SQL
 *     completo. Un lote con esa forma se RECHAZA ENTERO, y el terminal se
 *     queda con el log creciendo: es el precio de no inventar un saldo que
 *     el maestro no sabe calcular.
 *   - el STOCK: `product_stores.quantity_in_stock` es un CACHE DERIVADO de
 *     los lotes en el destino, asi que una operacion del legacy que lo
 *     actualice no tiene destino: se rechaza. El stock se deriva aqui, no
 *     se traslada.
 *
 * Los IDENTIFICADORES vienen del MAPA (dato, no codigo): el traductor ya
 * los valido. Los VALORES van SIEMPRE con parametros (`?`), y los
 * importes viajan como TEXTO decimal, nunca como `float`: un flotante no es
 * dinero, y el motor no lo volveria a leer igual.
 */
final readonly class LegacyBatchApplier
{
    public function __construct(
        private Connection $connection,
        private LegacyStatementTranslator $translator,
    ) {}

    /**
     * Aplica el LOTE del terminal legacy.
     *
     * @param  string  $clientId  El cliente que empuja el lote (el `username` del protocolo).
     * @param  string  $rawData  El string `data` TAL COMO VIAJA: los bytes exactos que se firmaron.
     * @param  array<string, mixed>  $context  El contexto del lote: `store_id`, `business_date` y los demas.
     * @param  int|null  $actorId  El responsable de la operacion. El esquema `sync_batches` no
     *                             tiene columna de actor y este trabajo no inventa una: el
     *                             parametro se acepta por simetria con el resto del dominio y
     *                             no se persiste.
     * @return int El identificador del lote aplicado, o el del lote YA APLICADO cuando es un
     *             reenvio identico.
     *
     * @throws RuntimeException Cuando el lote no se puede aplicar. En ese caso no queda NADA
     *                          escrito: ni las operaciones, ni la fila de `sync_batches`.
     */
    public function apply(string $clientId, string $rawData, array $context = [], ?int $actorId = null): int
    {
        // El hash del LOTE, no el del cuerpo HTTP.
        $hash = hash('sha256', $rawData);

        // El REENVIO IDENTICO: el lote ya esta, asi que no se aplica nada
        // y se responde con EXITO. Sin esto, el terminal que perdio la
        // respuesta se queda CONGELADO en la ventana de corte.
        $yaAplicado = $this->loteYaAplicado($clientId, $hash);

        if ($yaAplicado !== null) {
            Log::info('sync: el lote ya estaba aplicado y no se vuelve a aplicar', [
                'client_id' => $clientId,
                'payload_sha256' => $hash,
                'batch_id' => $yaAplicado,
            ]);

            return $yaAplicado;
        }

        try {
            return $this->connection->transaction(function () use ($clientId, $rawData, $context, $hash): int {
                $aplicadas = 0;

                // Una operacion, una traduccion, una aplicacion: en ORDEN.
                foreach ($this->operacionesDe($rawData) as $indice => $operacion) {
                    $traducida = $this->traducir((int) $indice, $operacion, $context);

                    // Una tabla que NO se traslada no produce operacion: no
                    // hay destino al que escribir.
                    if ($traducida === null) {
                        continue;
                    }

                    if ($this->aplicar($traducida)) {
                        $aplicadas++;
                    }
                }

                // La TRAZA del lote: es la clave de idempotencia y nace en la
                // MISMA transaccion que las operaciones. Si el lote falla, no
                // queda traza: un lote a medias no puede reconocerse como
                // aplicado.
                return (int) $this->connection->table('sync_batches')->insertGetId([
                    'client_id' => $clientId,
                    'payload_sha256' => $hash,
                    'statement_count' => $aplicadas,
                    'applied_at' => now(),
                    'created_at' => now(),
                    'updated_at' => now(),
                ]);
            });
        } catch (QueryException $e) {
            // La CARRERA: dos envios IDENTICOS del mismo lote a la vez. La
            // restriccion unica hace que uno gane y el otro se lié: el
            // perdedor NO se aplica dos veces, y su respuesta es el EXITO
            // del lote que ya esta. Sin esto, el terminal veria un fallo
            // por un lote que SI se aplico.
            $existente = $this->loteYaAplicado($clientId, $hash);

            if ($existente !== null) {
                Log::info('sync: el lote se aplico en paralelo y ya estaba aplicado', [
                    'client_id' => $clientId,
                    'payload_sha256' => $hash,
                    'batch_id' => $existente,
                ]);

                return $existente;
            }

            throw $e;
        }
    }

    /**
     * El LOTE: el `data` del contrato, decodificado.
     *
     * @return array<int, mixed> Las operaciones del lote, en orden.
     */
    private function operacionesDe(string $rawData): array
    {
        $lote = json_decode($rawData, true);

        if (! is_array($lote)) {
            throw new RuntimeException('El lote del terminal legacy no es una lista de operaciones.');
        }

        return array_values($lote);
    }

    /**
     * Traduce UNA operacion, con el indice del lote en el mensaje.
     *
     * @return array<string, mixed>|null NULL cuando la tabla del legacy NO se traslada.
     */
    private function traducir(int $indice, mixed $operacion, array $contexto): ?array
    {
        if (! is_array($operacion)) {
            throw new RuntimeException("La operacion {$indice} del lote no es una operacion.");
        }

        try {
            $traducida = $this->translator->translateOne($operacion, $contexto);
        } catch (RuntimeException $e) {
            throw new RuntimeException(
                "La operacion {$indice} del lote no se puede aplicar: ".$e->getMessage(),
                0,
                $e
            );
        }

        if (($traducida['type'] ?? null) === LegacyStatementTranslator::DESCARTE) {
            return null;
        }

        return $traducida;
    }

    /**
     * Aplica UNA operacion ya traducida.
     *
     * @param  array<string, mixed>  $operacion  La operacion ESTRUCTURADA del destino.
     * @return bool Si la operacion se aplico (o `false` cuando no tenia nada que escribir).
     */
    private function aplicar(array $operacion): bool
    {
        $tabla = (string) ($operacion['table'] ?? '');

        if ($tabla === '') {
            throw new RuntimeException('La operacion traducida no trae tabla de destino.');
        }

        $query = $this->connection->table($tabla);

        return match ((string) ($operacion['type'] ?? '')) {
            'insert' => $this->aplicarInsert($query, $operacion),
            'update' => $this->aplicarUpdate($query, $operacion),
            'delete' => $this->aplicarDelete($query, $operacion),
            default => throw new RuntimeException(
                "La operacion traducida trae un tipo que no se sabe aplicar: '".($operacion['type'] ?? '')."'."
            ),
        };
    }

    /**
     * Un `INSERT`: las columnas y sus valores, en paralelo.
     */
    private function aplicarInsert(Builder $query, array $operacion): bool
    {
        $columnas = array_values((array) ($operacion['columns'] ?? []));
        $valores = array_values((array) ($operacion['values'] ?? []));

        // TODAS las columnas del legacy se descartan: no hay dato que
        // viajar. No es un fallo: es una operacion sin contenido.
        if ($columnas === []) {
            Log::warning('sync: la operacion del legacy no tiene ninguna columna que trasladar', [
                'tabla_destino' => $operacion['table'] ?? null,
            ]);

            return false;
        }

        if (count($columnas) !== count($valores)) {
            throw new RuntimeException('La operacion traducida trae mas columnas que valores.');
        }

        $query->insert($this->valoresDe($columnas, $valores));

        return true;
    }

    /**
     * Un `UPDATE`: las asignaciones y el filtro.
     */
    private function aplicarUpdate(Builder $query, array $operacion): bool
    {
        $asignaciones = (array) ($operacion['assignments'] ?? []);

        // Ninguna asignacion viaja: no hay nada que actualizar.
        if ($asignaciones === []) {
            return false;
        }

        $this->filtrar($query, (array) ($operacion['where'] ?? []));
        $query->update($asignaciones);

        return true;
    }

    /**
     * Un `DELETE`: el filtro.
     */
    private function aplicarDelete(Builder $query, array $operacion): bool
    {
        $where = (array) ($operacion['where'] ?? []);

        // Un `DELETE` sin filtro borra la tabla entera. El traductor ya
        // rechaza un `WHERE` vacio, pero el filtro que se pierde aqui
        // tambien seria un borrado masivo: se falla CERRADO.
        if ($where === []) {
            throw new RuntimeException('La operacion traducida borra sin filtro: no se aplica.');
        }

        $this->filtrar($query, $where);
        $query->delete();

        return true;
    }

    /**
     * El FILTRO de la operacion: columna de destino => valor.
     *
     * Los valores van con PARAMETRO: el identificador viene del mapa (dato)
     * y el valor viaja ligado.
     */
    private function filtrar(Builder $query, array $where): void
    {
        foreach ($where as $columna => $valor) {
            $query->where((string) $columna, $valor);
        }
    }

    /**
     * Las columnas de destino con sus valores, en un mapa.
     *
     * @param  list<string>  $columnas
     * @param  list<mixed>  $valores
     * @return array<string, mixed>
     */
    private function valoresDe(array $columnas, array $valores): array
    {
        $mapa = [];

        foreach ($columnas as $posicion => $columna) {
            $mapa[$columna] = $valores[$posicion] ?? null;
        }

        return $mapa;
    }

    /**
     * El LOTE ya aplicado para ese cliente, o `null`.
     */
    private function loteYaAplicado(string $clientId, string $payloadSha256): ?int
    {
        $id = $this->connection->table('sync_batches')
            ->where('client_id', $clientId)
            ->where('payload_sha256', $payloadSha256)
            ->value('id');

        return $id === null ? null : (int) $id;
    }
}
