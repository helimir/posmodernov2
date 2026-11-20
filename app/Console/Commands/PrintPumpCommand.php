<?php

namespace App\Console\Commands;

use App\Print\PrintPump;
use App\Print\PrintPumpReport;
use App\Print\PrintTransport;
use App\Print\PrintWriteOutcome;
use Illuminate\Console\Command;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * El comando que ESCRIBE los tickets encolados en las impresoras que
 * escribe el servidor.
 *
 * REGLA que fija este comando: *el servidor escribe los tickets de los
 * destinos cuyo transporte es `server_socket`, y NO toca los demás*.
 *
 *  - los destinos `lan_agent` se DEJAN: el agente los reclama por la cola, y
 *    si el servidor los escribiera también, el ticket saldría dos veces;
 *  - los destinos `browser` no son destino físico: no hay bytes que escribir.
 *
 * Y NO HACE NADA EN BUCLE: bombea una vez y sale, con un resumen de lo que
 * hizo. El trabajo en bucle es del planificador (una tarea posterior), y un
 * bombeo en bucle no se puede apagar.
 *
 * Y EL CODIGO DE SALIDA DICE SI ALGO FALLO: si algún trabajo no se pudo
 * escribir, el comando sale con código distinto de cero, porque un ticket
 * que no sale tiene que verse desde fuera.
 */
class PrintPumpCommand extends Command
{
    /**
     * La firma del comando.
     *
     * La opcion es OPCIONAL: sin ella, el comando bombea todos los destinos
     * que escribe el servidor; con ella, sólo ese.
     */
    protected $signature = 'ops:print-pump
                            {--destino= : El identificador de un destino de impresion (sin el, todos los que escribe el servidor)}';

    /**
     * La descripcion que aparece en `php artisan list`.
     */
    protected $description = 'Escribe los tickets encolados en las impresoras que escribe el servidor.';

    /**
     * La ayuda de `--help`.
     */
    protected $help = <<<'HELP'
        Escribe los TICKETS ENCOLADOS en las impresoras que escribe el servidor.

        El servidor escribe los bytes CONGELADOS del trabajo, tal cual los genero: el hash del
        trabajo es el hash de lo que sale por el cable. Y solo escribe en los destinos cuyo
        transporte es <info>server_socket</info>: la impresora de red por TCP 9100 y la impresora
        compartida de Windows.

        Los destinos del <info>agente LAN</info> se DEJAN encolados: los reclama el agente, y si el
        servidor los escribiera tambien, el ticket saldria DOS veces. Los destinos del
        <info>navegador</info> no son un destino fisico: no hay bytes que escribir.

        El comando bombea UNA VEZ y sale, con un resumen de lo que hizo. El trabajo en bucle es del
        planificador, no del bombeo.

        Un trabajo que no se puede imprimir NO se pierde: queda <info>failed</info> con su motivo, y
        se puede reintentar. La venta ya esta confirmada: un ticket que no sale no deshace una
        venta.

        El codigo de salida es 0 cuando todos los tickets salieron, y distinto de cero si alguno
        fallo.
        HELP;

    /**
     * Bombea una vez y dice que hizo.
     */
    public function handle(PrintPump $bombeo, Connection $connection): int
    {
        try {
            $destinoId = $this->destino();
        } catch (RuntimeException $error) {
            $this->error($error->getMessage());

            return self::FAILURE;
        }

        if ($destinoId !== null) {
            $destino = $connection->table('print_targets')->where('id', $destinoId)->first();

            if ($destino === null) {
                $this->error("El destino de impresion {$destinoId} no existe.");

                return self::FAILURE;
            }

            // El destino existe, pero NO lo escribe el servidor: no hay nada
            // que bombear, y el motivo se dice en voz alta.
            if ((string) $destino->transport !== PrintTransport::ServerSocket->value) {
                $this->warn(
                    "El destino de impresion {$destinoId} no lo escribe el servidor "
                    ."(transporte «{$destino->transport}»): no hay nada que bombear."
                );

                return self::SUCCESS;
            }
        }

        $informe = $bombeo->bombear($destinoId);

        $this->pintar($informe, $destinoId);

        return $informe->fallidos() === 0 ? self::SUCCESS : self::FAILURE;
    }

    /**
     * El DESTINO pedido, o `null` si el comando bombea todos.
     *
     * @throws RuntimeException Si la opción no es un identificador.
     */
    private function destino(): ?int
    {
        $valor = $this->option('destino');

        if ($valor === null || trim((string) $valor) === '') {
            return null;
        }

        $id = filter_var($valor, FILTER_VALIDATE_INT);

        if ($id === false || $id <= 0) {
            throw new RuntimeException('La opcion --destino tiene que ser un identificador valido.');
        }

        return (int) $id;
    }

    /**
     * El RESUMEN del bombeo.
     */
    private function pintar(PrintPumpReport $informe, ?int $destinoId): void
    {
        $this->line('');
        $this->line('EL BOMPEO DE IMPRESION');
        $this->line('=====================');
        $this->line('');

        $this->detalle(
            'Destino',
            $destinoId === null ? 'todos los que escribe el servidor' : "#{$destinoId}"
        );
        $this->detalle('Trabajos escritos', (string) $informe->escritos());
        $this->detalle('Trabajos fallidos', (string) $informe->fallidos());
        $this->detalle('Trabajos dejados para el agente LAN', (string) $informe->paraElAgente);
        $this->line('');

        // Y CADA PROBLEMA con su motivo: un fallo sin motivo no se puede
        // reintentar a ciegas.
        foreach ($informe->problemas() as $problema) {
            if ($problema->status === PrintWriteOutcome::FALLIDO) {
                $this->warn("Trabajo #{$problema->jobId}: FALLIDO — {$problema->mensaje}");

                continue;
            }

            $this->line("Trabajo #{$problema->jobId}: no escrito ({$problema->motivo}).");
        }

        if ($informe->fallidos() > 0) {
            $this->line('');
            $this->warn('Los trabajos fallidos quedan en la cola con su motivo: se pueden reintentar.');
        }
    }

    /**
     * Una linea del resumen.
     */
    private function detalle(string $clave, string $valor): void
    {
        $this->line(sprintf('  %-34s %s', $clave, $valor));
    }
}
