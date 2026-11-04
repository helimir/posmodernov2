<?php

namespace App\Domain\Tax;

use DateTimeImmutable;
use Illuminate\Database\Connection;

/**
 * El resolvedor de la tasa VIGENTE.
 *
 * El motor de impuestos es puro: no lee la base de datos. La tasa que se
 * aplica a una linea la decide la VIGENCIA, y resolverla es una lectura.
 * Este servicio es esa lectura, y su unica regla es que la respuesta sea
 * DETERMINISTA: la misma categoria y la misma fecha devuelven SIEMPRE la
 * misma tasa.
 *
 * La vigencia es `valid_from <= fecha AND (valid_to IS NULL OR valid_to >=
 * fecha)`, con `valid_to` INCLUSIVO: el ultimo dia de una tasa sigue siendo
 * su ultimo dia.
 *
 * Cuando hay varias tasas vigentes, el desempate es `priority` descendente,
 * luego `valid_from` descendente, luego `id` descendente. Sin el, dos ventas
 * del mismo dia podrian tomar tasas distintas segun el plan de ejecucion, y
 * el documento emitido dejaria de ser reconstruible.
 */
final readonly class TaxRateResolver
{
    public function __construct(private Connection $connection) {}

    /**
     * La tasa vigente de una categoria en una fecha.
     *
     * @return array{id: int, tax_category_id: int, percentage: string, evidence_status: string, source_ref: string|null}|null
     *                                                                                                                         La tasa vigente, o `null` si la categoria no tiene ninguna en
     *                                                                                                                         vigor en esa fecha.
     */
    public function resolve(int $taxCategoryId, DateTimeImmutable $date): ?array
    {
        $fecha = $date->format('Y-m-d');

        $row = $this->connection->table('tax_rates')
            ->where('tax_category_id', $taxCategoryId)
            ->where('valid_from', '<=', $fecha)
            ->where(function ($query) use ($fecha): void {
                $query->whereNull('valid_to')
                    ->orWhere('valid_to', '>=', $fecha);
            })
            // El desempate determinista: sin el, dos ventas del mismo dia
            // podrian tomar tasas distintas.
            ->orderByDesc('priority')
            ->orderByDesc('valid_from')
            ->orderByDesc('id')
            ->first();

        if ($row === null) {
            return null;
        }

        return [
            'id' => (int) $row->id,
            'tax_category_id' => (int) $row->tax_category_id,
            // El porcentaje viaja como TEXTO decimal: un `float` no es un
            // porcentaje, y el motor lo vuelve a leer como decimal exacto.
            'percentage' => (string) $row->percentage,
            'evidence_status' => (string) $row->evidence_status,
            'source_ref' => $row->source_ref === null ? null : (string) $row->source_ref,
        ];
    }
}
