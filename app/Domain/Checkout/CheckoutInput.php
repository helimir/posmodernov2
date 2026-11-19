<?php

namespace App\Domain\Checkout;

use App\Domain\Fiscal\Enums\ProviderKind;
use DateTimeImmutable;

/**
 * La venta completa, tal como la pide la caja.
 *
 * `accounts` es el MAPA DE ROL a cuenta contable: `revenue`, `tax_payable`,
 * `cash` y `receivable`. Las cuentas son DATO de la entrada, no literales del
 * dominio: el dominio no inventa un plan de cuentas. Si falta la cuenta que
 * la operacion necesita, la operacion falla con un error claro.
 *
 * `issuedAt` y `businessDate` son DATOS, no calculos: el dominio no pide la
 * hora al reloj. La fecha de negocio es la que manda la caja, y puede no ser
 * el dia de emision.
 *
 * Y `providerKind` es el CANAL por el que emite la tienda: el tipo de
 * proveedor que hay detras. Es DATO de la entrada y no una consulta del
 * dominio, porque el dominio no resuelve el proveedor por su cuenta (eso es
 * del `FiscalProviderResolver`): el canal llega resuelto y el checkout PREGUNTA
 * por el, antes de decidir si reserva el numero de control.
 */
final readonly class CheckoutInput
{
    /**
     * @param  list<CheckoutLineInput>  $lines
     * @param  list<CheckoutPaymentInput>  $payments
     * @param  array<string, string>  $accounts  Rol => codigo de cuenta contable.
     */
    public function __construct(
        public int $storeId,
        /** El cliente de la venta; `null` si es una venta de mostrador. */
        public ?int $customerId,
        /** La serie que reparte el numero del documento. */
        public int $documentSeriesId,
        /** La serie que reparte el numero de control; `null` si no aplica. */
        public ?int $controlSeriesId,
        /** El CANAL fiscal de la tienda: el tipo de proveedor que emite. */
        public ProviderKind $providerKind,
        public array $lines,
        public array $payments,
        public array $accounts,
        /** El instante de emision del documento. */
        public DateTimeImmutable $issuedAt,
        /** La FECHA DE NEGOCIO: la que manda la caja. */
        public DateTimeImmutable $businessDate,
        /** El descuento de documento, prorrateado a las lineas. */
        public string $documentDiscount = '0',
        /** Quien registra la operacion. */
        public ?int $actorId = null,
    ) {}
}
