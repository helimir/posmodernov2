<?php

namespace App\Console\Commands;

use App\Domain\Fiscal\FiscalIssuanceRunner;
use App\Domain\Fiscal\ValueObjects\FiscalIssuanceOutcome;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;

/**
 * El comando que REINTENTA la emision fiscal de las ventas pendientes.
 *
 * REGLA que fija este comando: *una venta con el comprobante pendiente se
 * puede reintentar*. La emision fiscal (T2) puede fallar —el canal no
 * responde, el rango de control se agota, el proveedor no esta configurado—
 * y cuando eso pasa la venta NO se pierde: queda en `fiscal_failed`, con su
 * reserva intacta, y este comando la vuelve a emitir.
 *
 * EL REINTENTO NO QUEMA NUMEROS: el coordinador reutiliza la reserva que la
 * venta ya tiene, asi que el correlativo de la serie no avanza y el numero
 * del documento es el MISMO que se reservo la primera vez.
 *
 * Y NO SE EMITE UNA VENTA QUE NO ESTA PENDIENTE: el comando solo trabaja con
 * las ventas en `fiscal_failed`. Una venta ya emitida no se reemite (una
 * doble emision ante la autoridad no se puede deshacer), y una venta
 * cobrada sin comprobante no se emite a escondidas: el interruptor de
 * emision es el que decide eso, y este comando es una orden EXPLICITA del
 * operador sobre las ventas que YA están pendientes.
 */
class EmitFiscalCommand extends Command
{
    /**
     * La firma del comando.
     *
     * La opcion es OPCIONAL: sin ella, el comando reintenta todas las
     * ventas pendientes; con ella, solo esa.
     */
    protected $signature = 'ops:emit-fiscal
                            {--sale= : El identificador de una venta concreta (sin el, todas las pendientes)}';

    /**
     * La descripcion del comando.
     */
    protected $description = 'Reintenta la emision fiscal de las ventas pendientes de comprobante.';

    /**
     * Reintenta la emision y dice que paso con cada venta.
     */
    public function handle(FiscalIssuanceRunner $emision, Connection $connection): int
    {
        $ventaId = $this->option('sale');

        $ventas = $ventaId === null
            ? $this->pendientes($connection)
            : $this->unaVenta($connection, (int) $ventaId);

        if ($ventas === []) {
            $this->info('No hay ventas pendientes de emision fiscal.');

            return self::SUCCESS;
        }

        $fallos = 0;

        foreach ($ventas as $id) {
            $resultado = $emision->issue($id);

            if ($resultado->status === FiscalIssuanceOutcome::EMITIDO) {
                $this->info(sprintf(
                    'Venta #%d: emitida (documento %s, control %s).',
                    $id,
                    $resultado->documentId ?? '-',
                    $resultado->controlNumber ?? '-'
                ));

                continue;
            }

            $fallos++;

            $this->warn(sprintf(
                'Venta #%d: fallo en la emision fiscal — %s',
                $id,
                $resultado->message ?? 'motivo desconocido.'
            ));
        }

        return $fallos === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Las ventas PENDIENTES de comprobante fiscal.
     *
     * @return list<int>
     */
    private function pendientes(Connection $connection): array
    {
        return $connection->table('sales')
            ->where('status', 'fiscal_failed')
            ->orderBy('id')
            ->pluck('id')
            ->map(static fn ($id): int => (int) $id)
            ->all();
    }

    /**
     * UNA venta concreta, si esta pendiente.
     *
     * @return list<int>
     */
    private function unaVenta(Connection $connection, int $saleId): array
    {
        $estado = $connection->table('sales')->where('id', $saleId)->value('status');

        if ($estado === null) {
            $this->warn("La venta {$saleId} no existe.");

            return [];
        }

        if ($estado !== 'fiscal_failed') {
            $this->warn("La venta {$saleId} no esta pendiente de emision fiscal: no se reintenta.");

            return [];
        }

        return [$saleId];
    }
}
