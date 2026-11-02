<?php

namespace Tests\Concerns;

/**
 * Los tests de concurrencia fiscal NO pueden envolver el test en una
 * transacción que se revierte al terminar.
 *
 * `RefreshDatabase` abre una transacción en las conexiones listadas y la
 * revierte al final del test. La segunda sesión PostgreSQL (`pgsql_b`) es
 * independiente y NO ve las filas no commiteadas: con esa transacción el
 * `SELECT … FOR UPDATE` de la conexión A no bloquearía a la conexión B, el
 * `SQLSTATE 55P03` no se reproduciría y el test pasaría sin probar nada
 * (design §E.1, `numeracion-fiscal` §"T1–T3 verifican la reserva sin
 * `RefreshDatabase`").
 *
 * Con la lista de conexiones vacía no se abre ninguna transacción; el
 * aislamiento lo da el `TRUNCATE … RESTART IDENTITY CASCADE` del `afterEach`.
 */
trait WithoutWrappingTransaction
{
    /**
     * @var array<int, string>
     */
    protected array $connectionsToTransact = [];
}
