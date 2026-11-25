<?php

namespace App\Domain\Sales;

/**
 * El ESTADO de una solicitud de anulacion.
 *
 * REGLA que fija este enum: *la solicitud tiene TRES estados, y el cuarto
 * desenlace no es un estado suyo*.
 *
 * Los tres son los que puede cerrar la peticion:
 *
 *  - `pending`: la peticion espera. Quien pidio la anulacion no la ejecuta;
 *    la ejecuta quien puede deshacer una venta.
 *  - `rejected`: alguien con `sales.void` ha dicho que NO, con un motivo.
 *    El rechazo es "no lo hago".
 *  - `cancelled`: quien pidio la peticion la ha retirado, con un motivo. La
 *    cancelacion es "ya no la pido".
 *
 * Y EL CUARTO DESENLACE —la peticion ATENDIDA, con la venta anulada— NO es
 * un estado de la solicitud: es `sales.status`. Anular una venta es un HECHO
 * de la venta, y por eso vive en la venta: si la peticion guardara tambien
 * ese hecho, habria dos fuentes de verdad para el mismo, y se
 * desincronizarian.
 *
 * ASI QUE UNA PETICION `pending` DE UNA VENTA ANULADA NO ES UNA CONTRADICCION:
 * la peticion no fue rechazada ni retirada, y la venta quedo anulada. Quien
 * lo lee deriva el cuarto estado de la venta, y no lo inventa.
 */
enum SaleVoidRequestStatus: string
{
    /** La peticion espera a que quien puede anular la atienda. */
    case Pending = 'pending';

    /** Alguien con `sales.void` ha dicho que no, con su motivo. */
    case Rejected = 'rejected';

    /** Quien la pidio la ha retirado, con su motivo. */
    case Cancelled = 'cancelled';

    /**
     * El estado EFECTIVO de una peticion, tal como lo ve la pantalla.
     *
     * REGLA que fija este metodo: *el estado que se ENSENA no es el que se
     * GUARDA*. La peticion guardada dice si fue rechazada o retirada; si no,
     * esta pendiente. Y si la VENTA quedo anulada, la peticion esta ATENDIDA:
     * eso lo dice la venta, no la peticion.
     *
     * @param  bool  $ventaAnulada  Si la venta quedo anulada.
     */
    public function efectivo(bool $ventaAnulada): string
    {
        if ($this === self::Pending && $ventaAnulada) {
            return 'done';
        }

        return $this->value;
    }
}
