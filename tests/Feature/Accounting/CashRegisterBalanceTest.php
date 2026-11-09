<?php

use App\Domain\Accounting\CashRegisterService;
use App\Domain\Accounting\LedgerWriter;
use App\Models\Store;
use Database\Seeders\LedgerEntryTypeSeeder;
use Illuminate\Support\Facades\DB;

/**
 * 0.9.7 — el saldo de caja es por fecha de negocio.
 *
 * Regla que fija: *el saldo de caja por fecha de negocio coincide con los
 * movimientos del periodo*. El saldo de una caja es el saldo de partida MAS
 * los movimientos de SU fecha de negocio, y los movimientos de otro dia no
 * entran en el: el legacy filtraba por `DAY()/MONTH()/YEAR()` calculados, que
 * parten el dia a la medianoche del servidor y no a la del negocio.
 */
test('test_el_saldo_de_caja_por_fecha_de_negocio_coincide_con_los_movimientos_del_periodo', function () {
    $servicio = new CashRegisterService(DB::connection());
    $escritor = new LedgerWriter(DB::connection());

    $tienda = Store::create(['name' => 'Tienda del saldo de caja']);

    // El CATALOGO de tipos de asiento: el `LedgerWriter` lo consulta por
    // codigo, y la prueba no da por supuesto lo que hay sembrado.
    $this->seed(LedgerEntryTypeSeeder::class);

    // 1. La caja del 4 de noviembre, con su saldo de PARTIDA (contado a mano).
    $caja = $servicio->open($tienda->id, new DateTimeImmutable('2026-11-04'), '100.0000');

    // 2. Los MOVIMIENTOS DEL PERIODO: dos asientos del 4 de noviembre.
    foreach (['40.0000', '25.0000'] as $importe) {
        $escritor->write(
            'sale',
            $tienda->id,
            new DateTimeImmutable('2026-11-04T10:00:00-04:00'),
            new DateTimeImmutable('2026-11-04T00:00:00-04:00'),
            [
                ['account' => '1100000', 'debit' => $importe, 'credit' => '0'],
                ['account' => '2100000', 'debit' => '0', 'credit' => $importe],
            ],
        );
    }

    // 3. Y un movimiento del dia SIGUIENTE: no es de este periodo.
    $escritor->write(
        'sale',
        $tienda->id,
        new DateTimeImmutable('2026-11-05T10:00:00-04:00'),
        new DateTimeImmutable('2026-11-05T00:00:00-04:00'),
        [
            ['account' => '1100000', 'debit' => '900.0000', 'credit' => '0'],
            ['account' => '2100000', 'debit' => '0', 'credit' => '900.0000'],
        ],
    );

    /**
     * El saldo de la caja de UNA fecha de negocio: el saldo de partida mas los
     * movimientos de ESA fecha.
     */
    $saldo = function (string $fecha) use ($tienda): string {
        $movimientos = DB::table('ledger_lines')
            ->join('ledger_entries', 'ledger_entries.id', '=', 'ledger_lines.ledger_entry_id')
            ->where('ledger_entries.store_id', $tienda->id)
            ->where('ledger_entries.business_date', $fecha)
            ->where('ledger_lines.account_code', '1100000')
            ->selectRaw('COALESCE(SUM(ledger_lines.debit), 0) AS debe, COALESCE(SUM(ledger_lines.credit), 0) AS haber')
            ->first();

        $neto = bcsub((string) $movimientos->debe, (string) $movimientos->haber, 4);

        return bcadd('100.0000', $neto, 4);
    };

    // 4. El saldo de la caja del 4 coincide con los movimientos de ESE dia:
    //    100 de partida + 40 + 25.
    expect($saldo('2026-11-04'))->toBe('165.0000');

    // 5. Y el del dia siguiente es OTRO: el movimiento del 5 no entra en el
    //    saldo del 4, ni al reves.
    expect($saldo('2026-11-05'))->toBe('1000.0000');

    // 6. Y cada fecha tiene SU registro de caja: el saldo de una fecha no se
    //    guarda en el registro de otra.
    $registroDelDia = $servicio->registerFor($tienda->id, new DateTimeImmutable('2026-11-04'));

    expect((int) $registroDelDia->id)->toBe($caja)
        ->and((string) $registroDelDia->opening_balance)->toBe('100.0000')
        ->and(DB::table('cash_registers')->count())->toBe(1);

    // 7. Y al cerrar la caja, el saldo OBSERVADO es el que dicen los
    //    movimientos: el conteo de una caja y el libro tienen que coincidir.
    $servicio->close($caja, $saldo('2026-11-04'));

    $cerrada = DB::table('cash_registers')->where('id', $caja)->first();

    expect((string) $cerrada->status)->toBe('closed')
        ->and((string) $cerrada->closing_balance)->toBe('165.0000');
});
