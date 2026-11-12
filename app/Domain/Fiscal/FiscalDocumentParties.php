<?php

namespace App\Domain\Fiscal;

use Illuminate\Database\Connection;
use RuntimeException;

/**
 * Las PARTES del comprobante fiscal: quien emite y quien recibe.
 *
 * REGLA que fija esta clase: *un comprobante fiscal sin emisor, sin receptor
 * y sin lineas NO es un comprobante*.
 *
 * La tabla `fiscal_document_parties` existe desde M22, pero NADIE la
 * escribia: el documento se emitia sin saber quien emitia ni quien
 * recibia, y el comprobante no se podia construir. Esta clase la escribe.
 *
 * LAS PARTES SON UN DATO, NO UN CALCULO: el emisor es la TIENDA (su nombre,
 * su domicilio y su inscripcion fiscal primaria) y el receptor es el CLIENTE
 * (su nombre, su documento y su domicilio). Si la venta no tiene cliente, el
 * receptor es el CONSUMIDOR FINAL, y su nombre es un TOKEN DEL PERFIL: si el
 * perfil no lo declara, no hay receptor, porque un nombre inventado es peor
 * que un hueco.
 *
 * Y EL HUECO SE VE: una tienda sin inscripcion fiscal emite un comprobante
 * sin identificativo fiscal, y eso es un dato que falta, no un dato que se
 * pueda rellenar con un literal.
 *
 * LA ESCRITURA ES IDEMPOTENTE: `fiscal_document_parties` tiene UNIQUE en
 * `(fiscal_document_id, role)`, asi que un reintento de emision NO puede
 * duplicar las partes. Se resuelve con el `ON CONFLICT` de PostgreSQL, que
 * actualiza la fila existente en vez de insertar una segunda.
 *
 * LIMITACION CONOCIDA — EL TIPO DEL IDENTIFICATIVO DEL RECEPTOR: el POS NO
 * distingue un RIF de una cedula, porque el cliente tiene un unico campo
 * `tax_id` (su documento). Por eso el tipo del receptor es el que DECLARA EL
 * PERFIL para el identificativo del PAIS, y no uno que se adivine. Distinguir
 * RIF de cedula en el cliente es un CAMPO MAS del cliente, no una regla que se
 * pueda adivinar: adivinarla seria afirmar un dato que nadie ha verificado.
 *
 * EL VOCABULARIO DE LOS TIPOS ES DEL ESQUEMA, Y NO DEL CODIGO: los tipos
 * admitidos estan en la restriccion de la columna, y el codigo los PREGUNTA
 * a la base de datos en vez de repetirlos. Repetirlos seria un literal de
 * pais en el dominio, y el nombre de un tipo de identificativo fiscal ES un
 * nombre de pais (el mismo identificativo se llama de una manera y de otra en
 * cada pais).
 */
final readonly class FiscalDocumentParties
{
    /**
     * La clave del token del perfil que declara el nombre del CONSUMIDOR
     * FINAL: el receptor de una venta sin cliente.
     */
    public const TOKEN_CONSUMIDOR_FINAL = 'final_consumer_name';

    /**
     * El tipo que significa "un identificativo de un tipo que no se
     * reconoce". No es un tipo de pais: es el comodin del esquema.
     */
    private const TIPO_DESCONOCIDO = 'other';

    public function __construct(private Connection $connection) {}

    /**
     * Escribe las PARTES del documento, de forma IDEMPOTENTE.
     *
     * @param  int  $documentId  El documento fiscal.
     * @param  int  $saleId  La venta que lo origino.
     *
     * @throws RuntimeException Cuando el documento o la venta no existen.
     */
    public function write(int $documentId, int $saleId): void
    {
        $document = $this->connection->table('fiscal_documents')->where('id', $documentId)->first();

        if ($document === null) {
            throw new RuntimeException("El documento fiscal {$documentId} no existe.");
        }

        $sale = $this->connection->table('sales')->where('id', $saleId)->first();

        if ($sale === null) {
            throw new RuntimeException("La venta {$saleId} no existe.");
        }

        // 1. El EMISOR: la tienda y su inscripcion fiscal primaria.
        $this->escribe($documentId, 'issuer', $this->emisor($document));

        // 2. El RECEPTOR: el cliente, o el consumidor final si la venta no
        //    tiene cliente. Sin nombre no hay receptor: el hueco se ve.
        $receptor = $this->receptor($document, $sale);

        if ($receptor !== null) {
            $this->escribe($documentId, 'receiver', $receptor);
        }
    }

    /**
     * El EMISOR: la tienda, con su identificativo fiscal y su domicilio.
     *
     * @return array{name: string, fiscal_id: string|null, fiscal_id_kind: string|null, address: string|null}
     */
    private function emisor(object $document): array
    {
        $storeId = (int) $document->store_id;

        $store = $this->connection->table('stores')->where('id', $storeId)->first();

        if ($store === null) {
            throw new RuntimeException("La tienda {$storeId} del documento fiscal no existe.");
        }

        $inscripcion = $this->connection->table('store_fiscal_registrations')
            ->where('store_id', $storeId)
            ->where('is_primary', true)
            ->orderBy('id')
            ->first();

        return [
            'name' => (string) $store->name,
            // Sin inscripcion NO hay identificativo fiscal: el hueco se ve.
            'fiscal_id' => $inscripcion === null || $inscripcion->tax_id === null
                ? null
                : (string) $inscripcion->tax_id,
            'fiscal_id_kind' => $inscripcion === null
                ? null
                : $this->tipoDeIdentificativo($inscripcion->tax_id_scheme),
            'address' => $store->address === null ? null : (string) $store->address,
        ];
    }

    /**
     * El RECEPTOR: el cliente de la venta, o el consumidor final.
     *
     * @return array{name: string, fiscal_id: string|null, fiscal_id_kind: string|null, address: string|null}|null
     */
    private function receptor(object $document, object $sale): ?array
    {
        if ($sale->customer_id !== null) {
            $customer = $this->connection->table('customers')->where('id', $sale->customer_id)->first();

            if ($customer === null) {
                throw new RuntimeException(
                    "El cliente {$sale->customer_id} de la venta no existe: no se puede emitir su comprobante."
                );
            }

            $documento = $customer->tax_id === null ? null : (string) $customer->tax_id;

            return [
                'name' => (string) $customer->name,
                'fiscal_id' => $documento,
                // El POS NO distingue un RIF de una cedula: el cliente tiene
                // un unico campo de documento. Es una LIMITACION, y se dice
                // aqui para que no se lea como una regla del pais. El tipo
                // que se declara es el que declara el PERFIL para el
                // identificativo del pais: se dice lo que el sistema sabe,
                // y no lo que no sabe.
                'fiscal_id_kind' => $documento === null
                    ? null
                    : $this->tipoDeIdentificativo($this->esquemaDelPerfil($document)),
                'address' => $customer->address === null ? null : (string) $customer->address,
            ];
        }

        // Sin cliente, el receptor es el CONSUMIDOR FINAL, y su nombre es un
        // token del perfil. Sin token no hay receptor: un nombre inventado
        // es peor que un hueco.
        $nombre = $this->token($document, self::TOKEN_CONSUMIDOR_FINAL);

        if ($nombre === null) {
            return null;
        }

        return [
            'name' => $nombre,
            'fiscal_id' => null,
            'fiscal_id_kind' => null,
            'address' => null,
        ];
    }

    /**
     * El TIPO del identificativo fiscal, tal como lo declara el ESQUEMA de
     * la inscripcion.
     *
     * El esquema es un DATO de la tienda (`tax_id_scheme`), y puede venir
     * como el tipo pelado o como un esquema con prefijo. Se comparan las
     * PARTES del esquema, no el esquema entero, contra el VOCABULARIO que
     * admite la columna, y lo que no se reconoce es el tipo DESCONOCIDO: un
     * tipo desconocido se declara como desconocido, no se adivina.
     */
    private function tipoDeIdentificativo(mixed $esquema): ?string
    {
        if (! is_string($esquema) || trim($esquema) === '') {
            return null;
        }

        $vocabulario = $this->vocabularioDeTipos();

        if ($vocabulario === []) {
            return null;
        }

        $normalizado = mb_strtolower(trim($esquema));

        foreach ($vocabulario as $tipo) {
            if ($tipo !== self::TIPO_DESCONOCIDO && str_contains($normalizado, mb_strtolower($tipo))) {
                return $tipo;
            }
        }

        return in_array(self::TIPO_DESCONOCIDO, $vocabulario, true)
            ? self::TIPO_DESCONOCIDO
            : null;
    }

    /**
     * El VOCABULARIO de los tipos de identificativo fiscal.
     *
     * Es el del ESQUEMA: la restriccion de la columna declara los tipos
     * admitidos, y el codigo los PREGUNTA a la base de datos en vez de
     * repetirlos. Repetirlos seria un literal de pais en el dominio, y el
     * nombre de un tipo de identificativo fiscal ES un nombre de pais (el
     * mismo identificativo se llama de una manera y de otra en cada pais).
     *
     * Si el esquema no declara su vocabulario (una tabla sin restriccion, o
     * un esquema que cambia), no hay tipos: el hueco se ve y el tipo queda
     * en `null`.
     *
     * @return list<string>
     */
    private function vocabularioDeTipos(): array
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
     * El ESQUEMA del identificativo fiscal que declara el PERFIL.
     *
     * Es el dato del perfil que dice de que tipo de identificador es el del
     * pais, y es la unica fuente admisible para el tipo del receptor: el
     * cliente tiene un unico campo de documento, y el sistema no puede
     * distinguir un tipo de otro.
     */
    private function esquemaDelPerfil(object $document): ?string
    {
        $esquema = $this->connection->table('fiscal_profiles')
            ->where('id', (int) $document->fiscal_profile_id)
            ->value('id_scheme_code');

        return $esquema === null ? null : (string) $esquema;
    }

    /**
     * Un TOKEN del perfil fiscal.
     */
    private function token(object $document, string $clave): ?string
    {
        $valor = $this->connection->table('fiscal_profile_fields')
            ->where('fiscal_profile_id', (int) $document->fiscal_profile_id)
            ->where('key', $clave)
            ->value('value');

        return $valor === null ? null : (string) $valor;
    }

    /**
     * Escribe UNA parte, de forma IDEMPOTENTE.
     *
     * El `ON CONFLICT` es lo que hace idempotente la escritura: la UNIQUE
     * `(fiscal_document_id, role)` no deja insertar una segunda parte del
     * mismo rol, y el conflicto actualiza la que ya hay. Un reintento de
     * emision NO puede dejar dos emisores.
     *
     * @param  array{name: string, fiscal_id: string|null, fiscal_id_kind: string|null, address: string|null}  $parte
     */
    private function escribe(int $documentId, string $rol, array $parte): void
    {
        $this->connection->statement(
            <<<'SQL'
                INSERT INTO fiscal_document_parties
                    (fiscal_document_id, role, name, fiscal_id, fiscal_id_kind, address, created_at, updated_at)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT (fiscal_document_id, role) DO UPDATE SET
                    name = EXCLUDED.name,
                    fiscal_id = EXCLUDED.fiscal_id,
                    fiscal_id_kind = EXCLUDED.fiscal_id_kind,
                    address = EXCLUDED.address,
                    updated_at = EXCLUDED.updated_at
            SQL,
            [
                $documentId,
                $rol,
                $parte['name'],
                $parte['fiscal_id'],
                $parte['fiscal_id_kind'],
                $parte['address'],
                now(),
                now(),
            ]
        );
    }
}
