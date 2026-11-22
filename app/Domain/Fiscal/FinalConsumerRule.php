<?php

namespace App\Domain\Fiscal;

use App\Support\Pos\PosAmount;
use App\Support\Store\StoreThresholds;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Database\Connection;
use RuntimeException;

/**
 * La REGLA del CONSUMIDOR FINAL: cuando una venta sin cliente necesita el
 * documento de quien compra.
 *
 * REGLA que fija esta clase: *una venta SIN CLIENTE que supera el umbral de la
 * tienda NO se cobra sin el documento del consumidor final*.
 *
 * Y EL UMBRAL ES DE LA TIENDA: lo declara su ficha (`store_settings`), y su
 * `null` significa que NO SE EXIGE NUNCA (que es el comportamiento de
 * siempre). No hay un umbral global: un umbral global obligaria a las tiendas
 * que no lo han pedido a pedir un documento que sus clientes no tienen.
 *
 * Y CON CLIENTE NO HACE FALTA: el cliente YA es el receptor del comprobante,
 * y el documento del consumidor final no se pide, no se escribe y no estorba.
 *
 * Y EL TIPO DEL DOCUMENTO NO SE DEDUCE: lo declara el llamante, y se
 * comprueba contra el VOCABULARIO DEL ESQUEMA (el de la columna
 * `fiscal_id_kind`), que es donde vive la lista de tipos admitidos.
 */
final readonly class FinalConsumerRule
{
    public function __construct(
        private Connection $connection,
        private StoreThresholds $umbrales,
    ) {}

    /**
     * El documento del consumidor final de la venta, o `null` si no hace
     * falta.
     *
     * @param  int  $storeId  La tienda que cobra.
     * @param  string  $total  El total de la venta. Texto decimal.
     * @param  int|null  $customerId  El cliente de la venta; `null` si no hay.
     * @param  FinalConsumerDocument|null  $documentado  Lo que declara el
     *                                                   llamante, si viene.
     *
     * @throws RuntimeException Cuando la venta supera el umbral y el
     *                          documento no viene.
     */
    public function documento(
        int $storeId,
        string $total,
        ?int $customerId,
        ?FinalConsumerDocument $documentado,
    ): ?FinalConsumerDocument {
        // CON CLIENTE, el receptor es el cliente: el documento del consumidor
        // final no se pide ni se escribe.
        if ($customerId !== null) {
            return null;
        }

        $umbral = $this->umbrales->umbralDeConsumidorFinal($storeId);

        // SIN UMBRAL, no se exige NUNCA: es el comportamiento de siempre.
        if ($umbral === null) {
            return $documentado;
        }

        $supera = BigDecimal::of($total)
            ->toScale(PosAmount::SCALE, RoundingMode::HALF_UP)
            ->compareTo(BigDecimal::of($umbral)) > 0;

        // POR DEBAJO DEL UMBRAL el documento es OPCIONAL: y si viene, se
        // escribe igual.
        if (! $supera) {
            return $documentado;
        }

        if ($documentado === null) {
            throw new RuntimeException(
                'La venta de '.PosAmount::legible($total).' supera el umbral de '
                .PosAmount::legible($umbral).' de la tienda '.$storeId.': '
                .'sin cliente, hace falta el documento del consumidor final (la cedula o el pasaporte).'
            );
        }

        return $documentado;
    }

    /**
     * El documento DECLARADO por el llamante, con su tipo comprobado contra
     * el esquema.
     *
     * Y EL DOCUMENTO Y SU TIPO VIENEN JUNTOS: un documento sin tipo no dice
     * que es, y un tipo sin documento no identifica a nadie.
     *
     * @throws RuntimeException Cuando el documento o su tipo no valen.
     */
    public function declarado(?string $document, ?string $kind): ?FinalConsumerDocument
    {
        $texto = $document === null ? '' : trim($document);
        $tipo = $kind === null ? '' : trim($kind);

        if ($texto === '' && $tipo === '') {
            return null;
        }

        if ($texto === '') {
            throw new RuntimeException(
                'El documento del consumidor final viene sin el documento: '
                .'el documento y su tipo se declaran juntos.'
            );
        }

        if ($tipo === '') {
            throw new RuntimeException(
                'El documento del consumidor final viene sin su tipo: '
                .'el tipo lo declara quien cobra, y no se deduce del formato.'
            );
        }

        $admitidos = (new FiscalIdKinds($this->connection))->admitidos();

        // El esquema que no declara su vocabulario no restringe: el tipo
        // declarado se acepta tal cual, y el hueco se ve en el comprobante.
        if ($admitidos !== [] && ! in_array($tipo, $admitidos, true)) {
            throw new RuntimeException(
                "El tipo «{$tipo}» del documento del consumidor final no es uno de los que admite el esquema: "
                .'los admitidos son: '.implode(', ', $admitidos).'.'
            );
        }

        return new FinalConsumerDocument($texto, $tipo);
    }
}
