<?php

namespace App\Domain\Tax;

use App\Domain\Fiscal\Enums\RoundingRule;
use App\Domain\Fiscal\Enums\TaxMethod;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;

/**
 * El motor de impuestos.
 *
 * Es PURO: no lee la base de datos, ni la red, ni el sistema de ficheros, ni
 * el locale, ni el reloj. El perfil, las categorias y las tasas se resuelven
 * ANTES y entran como datos (`TaxContext`, `TaxLineInput`). La misma entrada
 * da siempre el mismo resultado.
 *
 * La aritmetica es de `BigDecimal` con escala 6 en el calculo y la que
 * pida el contexto en la persistencia. `float` no es un dinero.
 *
 * La base es el precio COBRADO (`net = unitPrice x quantity - lineDiscount`),
 * no el de catalogo: el legacy multiplicaba el impuesto por el precio de
 * catalogo, y si el POS habia cobrado otro precio, el impuesto era
 * incorrecto (defecto P0 #13 del mapa funcional).
 */
final readonly class TaxCalculator
{
    /** La escala del calculo: se persiste con la del contexto. */
    private const CALCULATION_SCALE = 6;

    /** La escala de persistencia: las columnas son `numeric(25,4)`. */
    private const PERSISTENCE_SCALE = 4;

    public function __construct(private TaxContext $context) {}

    public function calculate(TaxDocumentInput $input): TaxDocumentResult
    {
        $scale = self::CALCULATION_SCALE;
        $decimals = $this->context->currencyDecimals;

        // 1. La base y el impuesto de cada linea, SIN redondear todavia.
        $bases = [];
        $taxes = [];
        $baseTotal = BigDecimal::zero()->toScale($scale);

        foreach ($input->lines as $line) {
            $quantity = BigDecimal::of($line->quantity)->toScale($scale);
            $unitPrice = BigDecimal::of($line->unitPrice)->toScale($scale);
            $discount = BigDecimal::of($line->discount)->toScale($scale);
            $rate = BigDecimal::of($line->taxRateSnapshot)->toScale($scale);

            // La base es el precio COBRADO.
            $net = $unitPrice->multipliedBy($quantity)->minus($discount)->toScale($scale);

            if ($line->method === TaxMethod::Inclusive) {
                // El precio unitario YA incluye el impuesto: la base es el
                // precio sin impuesto, y el impuesto la diferencia.
                $divisor = BigDecimal::one()->plus($rate->dividedBy('100', $scale))->toScale($scale);
                $base = $net->dividedBy($divisor, $scale);
                $tax = $net->minus($base)->toScale($scale);
            } else {
                $base = $net;
                $tax = $base->multipliedBy($rate)->dividedBy('100', $scale)->toScale($scale);
            }

            $bases[] = $base;
            $taxes[] = $tax;
            $baseTotal = $baseTotal->plus($base)->toScale($scale);
        }

        // 2. El descuento de DOCUMENTO se prorratea a las lineas, en
        //    proporcion a su base imponible.
        $documentDiscount = BigDecimal::of($input->documentDiscount)->toScale($scale);

        if ($documentDiscount->isZero() || $baseTotal->isZero()) {
            $documentDiscount = BigDecimal::zero()->toScale($scale);
        }

        $lines = [];
        $sumLineTotals = BigDecimal::zero()->toScale($scale);
        $grandTotalRaw = BigDecimal::zero()->toScale($scale);
        $subtotal = BigDecimal::zero()->toScale($scale);
        $taxTotal = BigDecimal::zero()->toScale($scale);
        $discountTotal = BigDecimal::zero()->toScale($scale);

        foreach ($input->lines as $index => $line) {
            $base = $bases[$index];
            $tax = $taxes[$index];

            $lineDiscount = $documentDiscount->multipliedBy($base)->dividedBy($baseTotal, $scale)->toScale($scale);
            $netBase = $base->minus($lineDiscount)->toScale($scale);

            $rawTotal = $netBase->plus($tax)->toScale($scale);

            // El redondeo depende de la regla del perfil: por linea o el
            // total. `line` es el modo por defecto.
            $lineTotal = $this->context->roundingRule === RoundingRule::Line
                ? $rawTotal->toScale($decimals, RoundingMode::HALF_UP)
                : $rawTotal;

            $lines[] = [
                'tax_category_id' => $line->taxCategoryId,
                'tax_rate_id' => $line->taxRateId,
                // Los snapshots congelados: el documento emitido es autonomo.
                'tax_rate_snapshot' => $line->taxRateSnapshot,
                'evidence_status' => $line->evidenceStatus,
                // El tipo de impuesto (trasladado o retenido) NO es de la linea:
                // el IVA retenido es un documento fiscal PROPIO, con su
                // numeracion y su serie (0.6.4).
                'base_amount' => $this->persist($netBase),
                'tax_amount' => $this->persist($tax),
                'line_total' => $this->persist($lineTotal),
            ];

            $sumLineTotals = $sumLineTotals->plus($lineTotal)->toScale(self::PERSISTENCE_SCALE);
            $grandTotalRaw = $grandTotalRaw->plus($rawTotal)->toScale($scale);
            $subtotal = $subtotal->plus($netBase)->toScale($scale);
            $taxTotal = $taxTotal->plus($tax)->toScale($scale);
            $discountTotal = $discountTotal->plus($lineDiscount)->toScale($scale);
        }

        // 3. El total redondeado y el ajuste VISIBLE: la suma de las lineas
        //    mas el ajuste da el total, EXACTO.
        $grandTotal = $grandTotalRaw->toScale($decimals, RoundingMode::HALF_UP);
        $adjustment = $grandTotal->minus($sumLineTotals)->toScale(self::PERSISTENCE_SCALE);

        return new TaxDocumentResult(
            lines: $lines,
            subtotal: $this->persist($subtotal),
            taxTotal: $this->persist($taxTotal),
            discountTotal: $this->persist($discountTotal),
            grandTotal: $this->persist($grandTotal),
            roundingAdjustment: $this->persist($adjustment),
            issuedAt: $input->issuedAt,
        );
    }

    /**
     * El importe con la escala de persistencia, como texto.
     */
    private function persist(BigDecimal $value): string
    {
        return (string) $value->toScale(self::PERSISTENCE_SCALE, RoundingMode::HALF_UP);
    }
}
