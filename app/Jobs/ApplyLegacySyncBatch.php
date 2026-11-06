<?php

namespace App\Jobs;

use App\Sync\Legacy\LegacyBatchApplier;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * La APLICACION de un lote del terminal legacy, en la COLA.
 *
 * El lote se aplica en UNA transaccion y es TODO o NADA, asi que el job
 * entrega el lote ENTERO al aplicador y no lleva estado propio: si el lote
 * falla, se reintenta el lote entero, nunca un trozo.
 *
 * El `data` viaja TAL COMO VIAJA (los bytes exactos que se firmaron): el
 * hash de idempotencia es el de esos bytes, y re-codificar el lote en el
 * job cambiaria el hash y haria que un reenvio se aplicara DOS veces.
 *
 * El endpoint del sync NO usa este job: el terminal legacy espera la
 * respuesta en la misma peticion (el legacy responde 200 o 500 segun el
 * resultado de aplicar). Este job es la via asincrona para el trabajo que
 * no espera al terminal.
 */
final class ApplyLegacySyncBatch implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * @param  string  $clientId  El cliente que empuja el lote.
     * @param  string  $rawData  El string `data` del lote, tal como viaja.
     * @param  array<string, mixed>  $context  El contexto del lote: `store_id`, `business_date` y los demas.
     */
    public function __construct(
        public readonly string $clientId,
        public readonly string $rawData,
        public readonly array $context = [],
    ) {}

    public function handle(LegacyBatchApplier $applier): void
    {
        $applier->apply($this->clientId, $this->rawData, $this->context);
    }
}
