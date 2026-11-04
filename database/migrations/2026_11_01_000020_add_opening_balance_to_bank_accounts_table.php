<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * M20 — el saldo de partida de la cuenta bancaria.
 *
 * `bank_accounts` y su vinculo con la tienda (`bank_account_stores`) ganan
 * `opening_balance`: el saldo de PARTIDA, CONGELADO, que se carga a mano
 * antes de arrancar (B1). No es un contador: es un dato. El saldo EN CURNO
 * se deriva de los asientos (`ledger_entries`), nunca de una columna que se
 * reescribe con cada movimiento.
 *
 * `bank_accounts` PIERDE `account_type_id`. Era una FK a una tabla
 * `account_types` que NO EXISTE en el repositorio: una FK a una tabla
 * inexistente es un agujero, porque no la vigila nadie. La spec
 * `esquema-dominio` exige que toda relacion tenga FK REAL, y una columna que
 * apunta a la nada no es una relacion. El tipo de cuenta no lo necesita el
 * modelo actual, asi que no se sustituye por nada: se elimina.
 *
 * `bank_account_stores` NO lleva `deposit` ni `withdraw`. Se COMPROBO que M09
 * no los creo (su propio docblock lo dice: los contadores globales por
 * cuenta duplicaban el saldo real y lo cambiaban al borrar una operacion), y
 * la spec `contabilidad-caja` prohibe expresamente esos contadores. Por eso
 * aqui no se elimina nada: solo se anade el saldo de partida del vinculo,
 * que es por cuenta Y TIENDA, porque el saldo es de la cuenta en esa tienda.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El saldo de partida de la cuenta.
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->decimal('opening_balance', 25, 4)->default(0);
        });

        // La columna que apuntaba a una tabla inexistente.
        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropColumn('account_type_id');
        });

        // El saldo de partida de la cuenta EN ESA TIENDA.
        Schema::table('bank_account_stores', function (Blueprint $table): void {
            $table->decimal('opening_balance', 25, 4)->default(0);
        });
    }

    public function down(): void
    {
        Schema::table('bank_account_stores', function (Blueprint $table): void {
            $table->dropColumn('opening_balance');
        });

        Schema::table('bank_accounts', function (Blueprint $table): void {
            $table->dropColumn('opening_balance');

            // La columna vuelve tal y como M10 la creo: nullable e indexada.
            // La FK a `account_types` NO vuelve: esa tabla nunca existio.
            $table->foreignId('account_type_id')->nullable()->index();
        });
    }
};
