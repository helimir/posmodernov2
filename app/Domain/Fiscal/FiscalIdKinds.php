<?php

namespace App\Domain\Fiscal;

use Illuminate\Database\Connection;

/**
 * El VOCABULARIO de los tipos de identificativo fiscal.
 *
 * REGLA que fija esta clase: *los tipos de identificativo fiscal son del
 * ESQUEMA, y el codigo los PREGUNTA a la base de datos en vez de repetirlos*.
 *
 * El nombre de un tipo de identificativo fiscal ES un nombre de pais (el
 * mismo identificativo se llama de una manera y de otra en cada pais), asi
 * que escribirlo en el codigo seria un literal de pais: un fork por pais.
 * Repetirlos seria ademas una segunda verdad: el esquema cambiaria y el
 * codigo seguiria admitting un tipo que la base ya no admite.
 *
 * Y SI EL ESQUEMA NO DECLARA SU VOCABULARIO, NO HAY TIPOS: una tabla sin
 * restriccion, o un esquema que cambia, dejan el hueco a la vista en vez de
 * inventar un tipo.
 */
final readonly class FiscalIdKinds
{
    public function __construct(private Connection $connection) {}

    /**
     * Los tipos de identificativo fiscal que admite el ESQUEMA.
     *
     * @return list<string>
     */
    public function admitidos(): array
    {
        $definicion = $this->connection->selectOne(
            <<<'SQL'
                SELECT pg_get_constraintdef(oid) AS definicion
                FROM pg_constraint
                WHERE conrelid = 'fiscal_document_parties'::regclass
                  AND contype = 'c'
                  AND pg_get_constraintdef(oid) LIKE '%fiscal_id_kind%'
            SQL
        );

        if ($definicion === null) {
            return [];
        }

        // La definicion de la restriccion trae el ARRAY de los valores
        // admitidos: de ahi se leen.
        if (preg_match('/ARRAY\[([^\]]*)\]/', (string) $definicion->definicion, $coincidencia) !== 1) {
            return [];
        }

        preg_match_all("/'([^']*)'/", $coincidencia[1], $valores);

        return array_values(array_unique($valores[1]));
    }

    /**
     * ¿El tipo declarado esta entre los que admite el ESQUEMA?
     */
    public function admite(string $tipo): bool
    {
        return in_array($tipo, $this->admitidos(), true);
    }
}
