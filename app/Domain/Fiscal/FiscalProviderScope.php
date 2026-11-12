<?php

namespace App\Domain\Fiscal;

use RuntimeException;

/**
 * El AMBITO del canal fiscal en curso.
 *
 * El proveedor fiscal se RESUELVE por perfil y por tienda, y el perfil no se
 * conoce hasta que se sabe que VENTA se esta emitiendo. Este objeto es el
 * modo de que el binding del contenedor lo sepa sin adivinar: la emision
 * abre su ambito, el binding lo lee, y el ambito se cierra al terminar.
 *
 * El ambito lleva las DOS cosas que la emision necesita: la serie del
 * documento (para resolver el canal) y la VENTA (para reutilizar su numero
 * de control).
 *
 * Es un objeto de AMBITO, no un estado de negocio: no decide nada, solo
 * dice a que emission pertenece el canal que se resuelve.
 */
final class FiscalProviderScope
{
    /**
     * La serie del documento de la emision en curso.
     */
    private ?int $documentSeriesId = null;

    /**
     * La venta de la emision en curso.
     */
    private ?int $saleId = null;

    /**
     * Abre el ambito de una emision.
     *
     * @param  int  $documentSeriesId  La serie del documento que se emite.
     * @param  int  $saleId  La venta a la que pertenece esa emision.
     */
    public function dentroDe(int $documentSeriesId, int $saleId): void
    {
        $this->documentSeriesId = $documentSeriesId;
        $this->saleId = $saleId;
    }

    /**
     * La serie del documento de la emision en curso.
     *
     * @throws RuntimeException Cuando no hay ninguna emision abierta: el
     *                          proveedor no se puede resolver sin saber de
     *                          que venta es.
     */
    public function documentSeriesId(): int
    {
        if ($this->documentSeriesId === null) {
            throw new RuntimeException(
                'No hay ninguna emision fiscal en curso: el canal fiscal no se puede resolver.'
            );
        }

        return $this->documentSeriesId;
    }

    /**
     * La VENTA de la emision en curso.
     *
     * El canal la necesita porque el numero de control es de la VENTA: el
     * documento fiscal reutiliza la reserva que la venta ya hizo, y no
     * reserva otro numero.
     */
    public function saleId(): ?int
    {
        return $this->saleId;
    }

    /**
     * Cierra el ambito.
     */
    public function limpiar(): void
    {
        $this->documentSeriesId = null;
        $this->saleId = null;
    }
}
