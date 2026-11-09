<?php

namespace Tests\Concerns;

use Illuminate\Database\Connection;
use Illuminate\Support\Facades\DB;
use Tests\Support\DatabaseMigrations;
use Throwable;

/**
 * El ARNES de concurrencia, reutilizable (design §E.1).
 *
 * Da tres cosas:
 *
 *  1. la **SEGUNDA sesion** de PostgreSQL (`pgsql_b`): dos conexiones PDO
 *     distintas al mismo servidor ⇒ dos sesiones de verdad. El bloqueo de fila
 *     solo es real entre sesiones distintas;
 *  2. un helper para el **`SET LOCAL lock_timeout`** DENTRO de la transaccion
 *     de la sesion B: `SET LOCAL` se descarta al hacer `COMMIT`/`ROLLBACK`, y
 *     un `SET` a pelo contaminaria la sesion y con ella los tests siguientes;
 *  3. la **limpieza entre pruebas**: el `TRUNCATE … RESTART IDENTITY CASCADE`
 *     de las tablas que la prueba haya tocado.
 *
 * El arnes NO sabe las tablas de ninguna prueba concreta: cada una declara
 * las suyas con `marcarTablas()`.
 *
 * Los tests de concurrencia que ya existen (T1, T2 y T3) usan su propio arnes
 * y NO se tocan: este es para los tests NUEVOS.
 */
trait ConcurrencyHarness
{
    /**
     * El arnes de datos de esta prueba.
     */
    protected ?DatabaseMigrations $migraciones = null;

    /**
     * La SEGUNDA sesion de PostgreSQL: otra conexion PDO al mismo servidor.
     */
    protected function segundaSesion(): Connection
    {
        return DB::connection('pgsql_b');
    }

    /**
     * Ejecuta el callback en la sesion B, DENTRO de una transaccion con
     * `SET LOCAL lock_timeout`.
     *
     * El `lock_timeout` se aplica SOLO a esa transaccion: si el callback
     * falla, la transaccion se revierte y el limite desaparece con ella.
     *
     * @template T
     *
     * @param  callable(Connection): T  $callback
     * @return T
     */
    protected function enLaSegundaSesion(callable $callback, string $lockTimeout = '250ms'): mixed
    {
        $b = $this->segundaSesion();

        $b->beginTransaction();
        $b->statement("SET LOCAL lock_timeout = '{$lockTimeout}'");

        try {
            $resultado = $callback($b);
        } catch (Throwable $error) {
            $b->rollBack();

            throw $error;
        }

        $b->commit();

        return $resultado;
    }

    /**
     * Declara las tablas que la prueba ha tocado: se vacian en el
     * `afterEach`.
     */
    protected function marcarTablas(string ...$tablas): void
    {
        $this->migraciones()->tablas(...$tablas);
    }

    /**
     * La LIMPIEZA entre pruebas: vacia las tablas declaradas.
     */
    protected function limpiarBase(): void
    {
        $this->migraciones()->vaciar();
    }

    /**
     * El arnes de datos de esta prueba.
     */
    protected function migraciones(): DatabaseMigrations
    {
        DatabaseMigrations::asegurarEsquema();

        return $this->migraciones ??= new DatabaseMigrations;
    }
}
