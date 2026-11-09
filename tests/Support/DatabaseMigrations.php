<?php

namespace Tests\Support;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * El ARNES de datos de las pruebas de concurrencia (design §E.1).
 *
 * `RefreshDatabase` envuelve cada test en una transacción que se revierte al
 * terminar. La SEGUNDA sesion PostgreSQL (`pgsql_b`) es independiente y NO ve
 * las filas no commiteadas: con esa transaccion el `SELECT … FOR UPDATE` de la
 * sesion A no bloquearia a la sesion B, el `SQLSTATE 55P03` no se reproduciria
 * y el test pasaria sin probar nada.
 *
 * El aislamiento lo da aqui el `TRUNCATE … RESTART IDENTITY CASCADE` de las
 * tablas TOCADAS, en el `afterEach`: las filas quedan commiteadas (las dos
 * sesiones las ven) y la base arranca limpia para la prueba siguiente.
 *
 * La clase es REUTILIZABLE: no sabe las tablas de ninguna prueba concreta.
 * Cada prueba declara las suyas (`tablas('audit_logs', ...)`) y el arnes las
 * vacia. Una tabla que no existe en el esquema se ignora: el arnes no puede
 * fallar por una tabla que todavia no ha sido migrada.
 */
final class DatabaseMigrations
{
    /**
     * Las tablas que hay que vaciar.
     *
     * @var list<string>
     */
    private array $tablas = [];

    /**
     * Declara las tablas que la prueba ha tocado.
     */
    public function tablas(string ...$tablas): self
    {
        foreach ($tablas as $tabla) {
            $tabla = trim($tabla);

            if ($tabla === '' || in_array($tabla, $this->tablas, true)) {
                continue;
            }

            $this->tablas[] = $tabla;
        }

        return $this;
    }

    /**
     * Las tablas declaradas.
     *
     * @return list<string>
     */
    public function tablasDeclaradas(): array
    {
        return $this->tablas;
    }

    /**
     * VACIA las tablas declaradas: `TRUNCATE … RESTART IDENTITY CASCADE`.
     *
     * `RESTART IDENTITY` deja los contadores de identidad como estaban, y
     * `CASCADE` vacia tambien las tablas que dependan de estas. Sin el, una
     * prueba que espera el `id = 1` de una tabla receives el `id = 57` de la
     * prueba anterior.
     */
    public function vaciar(): void
    {
        if ($this->tablas === []) {
            return;
        }

        $existentes = array_values(array_filter(
            $this->tablas,
            static fn (string $tabla): bool => Schema::hasTable($tabla)
        ));

        if ($existentes === []) {
            return;
        }

        DB::statement('TRUNCATE '.implode(', ', $existentes).' RESTART IDENTITY CASCADE');
    }

    /**
     * Se ASEGURA de que el esquema esta: si la base no tiene la tabla de
     * migraciones, todavia no esta migrada, y se migra. Es idempotente y solo
     * se comprueba una vez por proceso.
     */
    public static function asegurarEsquema(): void
    {
        static $asegurado = false;

        if ($asegurado) {
            return;
        }

        $asegurado = true;

        if (! Schema::hasTable('migrations')) {
            Artisan::call('migrate', ['--force' => true]);
        }
    }
}
