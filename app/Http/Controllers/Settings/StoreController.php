<?php

namespace App\Http\Controllers\Settings;

use App\Http\Controllers\Controller;
use App\Models\Store;
use App\Support\Pos\PosAmount;
use App\Support\Pos\PosContext;
use App\Support\Store\StoreThresholds;
use Illuminate\Database\Connection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;
use RuntimeException;

/**
 * La CONFIGURACION de la TIENDA: los umbrales que la tienda decide.
 *
 * REGLA que fija esta clase: *la configuracion de la tienda es de la TIENDA
 * ACTIVA, la misma que usa la caja*. La pantalla no elige tienda: la resuelve
 * el contexto (`PosContext`), el mismo que resuelve el plan de cuentas y las
 * series del cobro. Editar los umbrales de otra tienda que no es la que
 * operas es escribir donde no se opera.
 *
 * Y LOS UMBRALES SON POR TIENDA, CON UN GLOBAL DE DEFECTO:
 *
 *  - el UMBRAL DEL DESCUADRE: si la tienda no declara ninguno, aplica el
 *    GLOBAL (`config/cash.php`), que es el comportamiento de siempre. El
 *    hueco se ve en la pantalla: se ven los dos numeros, el de la tienda y el
 *    global, y cual de los dos manda;
 *  - el UMBRAL DEL CONSUMIDOR FINAL: si la tienda no declara ninguno, NO se
 *    exige el documento nunca. No hay global: un umbral global obligaria a
 *    tiendas que no lo han pedido.
 *
 * Y LOS IMPORTES VIAJAN COMO TEXTO DECIMAL: un `float` no es un dinero.
 */
class StoreController extends Controller
{
    public function __construct(private readonly Connection $connection) {}

    /**
     * La PANTALLA de la configuracion de la tienda.
     *
     * El CONTRATO de los props es el de
     * `resources/js/pages/settings/store.tsx`:
     *
     *  - `store`: `{ id, name, code_name }`, la tienda activa;
     *  - `difference_reason_threshold`: el umbral DEL DESCUADRE que declara
     *    la tienda, o `null` si no declara ninguno;
     *  - `final_consumer_id_threshold`: el umbral del CONSUMIDOR FINAL que
     *    declara la tienda, o `null` si no declara ninguno;
     *  - `global_difference_reason_threshold`: el umbral GLOBAL del
     *    descuadre, el que aplica cuando la tienda no declara ninguno;
     *  - `effective_difference_reason_threshold`: el umbral del descuadre que
     *    APLICA de verdad: el de la tienda si declara, el global si no.
     *
     * @return array{difference_reason_threshold: string|null, final_consumer_id_threshold: string|null}
     */
    public function edit(Request $request): Response
    {
        $store = $this->tienda($request);

        $umbrales = new StoreThresholds($this->connection);

        $propio = $this->umbralesPropios((int) $store->id);

        return Inertia::render('settings/store', [
            'store' => [
                'id' => (int) $store->id,
                'name' => (string) $store->name,
                'code_name' => $store->code_name,
            ],
            'difference_reason_threshold' => $propio['difference_reason_threshold'],
            'final_consumer_id_threshold' => $propio['final_consumer_id_threshold'],
            'global_difference_reason_threshold' => PosAmount::text(
                config('cash.difference_reason_threshold', '0.0100')
            ),
            'effective_difference_reason_threshold' => $umbrales->umbralDeDescuadre((int) $store->id),
        ]);
    }

    /**
     * GUARDA los umbrales de la tienda.
     *
     * Y LOS IMPORTES NORMALIZADOS: lo que llega es TEXTO decimal, y lo que se
     * guarda es TEXTO decimal a la escala de la columna. Un `null` es "la
     * tienda no lo declara", y es un dato con significado, no un importe
     * vacio: vaciar el campo es volver al global.
     */
    public function update(Request $request): RedirectResponse
    {
        $store = $this->tienda($request);

        $datos = $request->validate([
            'difference_reason_threshold' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
            'final_consumer_id_threshold' => ['nullable', 'numeric', 'decimal:0,4', 'min:0'],
        ]);

        $descuadre = $this->importe($datos['difference_reason_threshold'] ?? null);
        $consumidor = $this->importe($datos['final_consumer_id_threshold'] ?? null);

        DB::table('store_settings')->updateOrInsert(
            ['store_id' => (int) $store->id],
            [
                'difference_reason_threshold' => $descuadre,
                'final_consumer_id_threshold' => $consumidor,
                'updated_at' => now(),
            ]
        );

        return to_route('store.edit');
    }

    /**
     * Los UMBRALES que declara la tienda, tal cual.
     *
     * @return array{difference_reason_threshold: string|null, final_consumer_id_threshold: string|null}
     */
    private function umbralesPropios(int $storeId): array
    {
        $fila = $this->connection->table('store_settings')->where('store_id', $storeId)->first();

        return [
            'difference_reason_threshold' => $this->texto($fila->difference_reason_threshold ?? null),
            'final_consumer_id_threshold' => $this->texto($fila->final_consumer_id_threshold ?? null),
        ];
    }

    /**
     * El texto de un umbral declarado, o `null` si la tienda no declara
     * ninguno.
     */
    private function texto(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        return $texto === '' ? null : $texto;
    }

    /**
     * Un importe como TEXTO DECIMAL normalizado, o `null`.
     */
    private function importe(mixed $valor): ?string
    {
        if ($valor === null) {
            return null;
        }

        $texto = trim((string) $valor);

        if ($texto === '') {
            return null;
        }

        return PosAmount::declare($texto, 'el umbral de la tienda');
    }

    /**
     * La TIENDA ACTIVA del usuario: la que usa la caja.
     *
     * @throws RuntimeException Cuando no hay ninguna tienda activa.
     */
    private function tienda(Request $request): Store
    {
        $contexto = new PosContext($this->connection, $request->user()?->id);

        try {
            return $contexto->store();
        } catch (RuntimeException $fallo) {
            abort(409, $fallo->getMessage());
        }
    }
}
