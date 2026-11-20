<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * F2-09 — el CANAL DEL AGENTE ENDURECIDO.
 *
 * REGLA que fija esta migracion: *el token del agente es una CONTRASENA, no
 * una huella, y por eso deja de buscarse por su hash*.
 *
 * Lo que cambia:
 *
 * - `token_hash` pasa a guardar un hash de CONTRASENA (argon2id) con su sal y
 *   sus parametros. Un hash de contrasena NO se indexa: la busqueda ya no
 *   puede ser por el hash, y el agente se localiza por su identificador
 *   (`X-Agent-Id`) mientras que su token se verifica con `password_verify`.
 * - `instance_id`: el identificador ESTABLE de la instalacion. Es lo que
 *   permite detectar un terminal REINSTALADO: al registrarse con un
 *   `instance_id` que ya existe en la tienda, la fila se REEMPLAZA y el token
 *   anterior deja de valer. Es UNICO por tienda.
 * - `revoked_at`: la revocacion DEFINITIVA. `is_active` es un interruptor (se
 *   puede volver a encender); `revoked_at` es una sentence: un agente revocado
 *   no habla hasta que un operador la levanta.
 * - `token_hash_previo` y `token_previo_valido_hasta`: la VENTANA DE GRACIA de
 *   la rotacion. El token anterior sigue valiendo un rato, para que el token
 *   nuevo le llegue al agente sin dejar la caja muda.
 * - `printers` y `capabilities`: el INVENTARIO que declara el agente en su
 *   latido, para que el desajuste entre la impresora declarada y la
 *   impresora real sea VISIBLE y no silencioso.
 *
 * Y LOS TOKENS QUE YA EXISTEN DEJAN DE VALIDAR: un hash SHA-256 no se puede
 * convertir en argon2id sin el texto plano. La migracion los invalida y los
 * agentes desplegados hay que VOLVER A REGISTRAR.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('print_agents', function (Blueprint $table) {
            // El identificador ESTABLE de la instalacion del agente. Es lo
            // que permite reconocer un terminal reinstalado y reemplazar su
            // fila en vez de dejar vivo el token viejo.
            $table->string('instance_id', 255)->nullable();

            // La REVOCACION definitiva. Nulo es "no revocado"; con fecha es
            // "revocado", y solo un operador la levanta.
            $table->timestampTz('revoked_at')->nullable();

            // El token PREVIO y el FIN de su ventana de gracia: al rotar, el
            // token que estaba vigente pasa a "previo" con su fecha, y el
            // nuevo entra como vigente.
            $table->string('token_hash_previo', 255)->nullable();
            $table->timestampTz('token_previo_valido_hasta')->nullable();

            // El INVENTARIO que declara el agente: sus impresoras y lo que
            // sabe hacer. Lo guarda el LATIDO.
            $table->jsonb('printers')->nullable();
            $table->jsonb('capabilities')->nullable();
        });

        // El `instance_id` es UNICO POR TIENDA: una instalacion es una fila.
        // En PostgreSQL los NULOS no se consideran iguales entre si, asi que
        // las filas antiguas (sin `instance_id`) no chocan entre ellas.
        DB::statement(
            'CREATE UNIQUE INDEX print_agents_store_id_instance_id_unique '
            .'ON print_agents (store_id, instance_id)'
        );

        // LOS TOKENS QUE YA EXISTEN DEJAN DE VALIDAR. Un hash SHA-256 no se
        // puede convertir en argon2id: hace falta el texto plano, y el texto
        // plano no se guarda. El valor de reemplazo es un MARCADOR por fila
        // (unico, porque la columna tiene indice unico) que ningun token
        // puede satisfacer.
        DB::statement("UPDATE print_agents SET token_hash = 'legacy-sha256:' || id");

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_agents.instance_id IS
            'Identificador ESTABLE de la instalacion del agente, unico por tienda. Al registrarse con un instance_id que ya existe, la fila se REEMPLAZA y el token anterior deja de valer: es lo que detecta un terminal reinstalado.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_agents.revoked_at IS
            'La revocacion DEFINITIVA del agente. is_active es un interruptor; esto es una sentence: un agente revocado no habla aunque su token sea correcto, y hay que levantar la revocacion a mano.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_agents.token_hash_previo IS
            'El hash del token ANTERIOR a la rotacion. Solo vale dentro de la ventana de gracia (token_previo_valido_hasta), para que el token nuevo le llegue al agente sin dejar la caja muda.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_agents.printers IS
            'El INVENTARIO de impresoras que declara el agente en su latido (jsonb). El servidor lo compara con los destinos de la tienda para que el desajuste sea visible y no silencioso.'
        SQL);
    }

    public function down(): void
    {
        DB::statement('DROP INDEX IF EXISTS print_agents_store_id_instance_id_unique');

        Schema::table('print_agents', function (Blueprint $table) {
            $table->dropColumn([
                'instance_id',
                'revoked_at',
                'token_hash_previo',
                'token_previo_valido_hasta',
                'printers',
                'capabilities',
            ]);
        });
    }
};
