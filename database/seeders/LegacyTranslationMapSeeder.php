<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * El MAPA DE TRADUCCION del terminal legacy: el NUCLEO del POS y de la caja.
 *
 * El terminal legacy empuja SQL crudo de MySQL con los nombres de SU
 * esquema (`selling_info`, `selling_item`, `customer_transactions`, ...). El
 * maestro es PostgreSQL con otro esquema, asi que los IDENTIFICADORES se
 * traducen contra un DATO SEMBRADO (`legacy_translation_map`), nunca contra
 * codigo: un `if ($tabla === 'selling_info')` en el traductor seria un
 * defecto (ADR-0006, D15).
 *
 * LA DIRECCION es legacy -> destino. Cada fila dice "esta columna del legacy
 * es ESTA columna de aqui", y `new_table IS NULL` significa "esta columna NO
 * se traslada".
 *
 * LAS REGLAS que decidieron cada fila (son la especificacion):
 *
 *  1. Una fila por COLUMNA que el legacy escribe de verdad (el SQL real de
 *     `_inc/model/invoice.php` y de sus hermanos), no por cada columna de su
 *     esquema: lo que no se escribe no se traslada.
 *  2. Una fila por TABLA con `legacy_column = '*'`: es la regla de la tabla
 *     y su `new_table` es el destino. Sin ella el traductor rechaza el
 *     lote entero.
 *  3. Una tabla SIN destino lleva su fila `'*'` con `new_table` y
 *     `new_column` a NULL: el traductor DESCARTA la operacion en vez de
 *     rechazar el lote, porque rechazarlo dejaria al terminal CONGELADO (el
 *     fallo S1 del ADR-0006).
 *  4. `transform`:
 *     - `rename`: el valor viaja tal cual.
 *     - `resolve_ref:<tabla legacy>`: el legacy manda un identificador de
 *       TEXTO y el destino tiene una FK `bigint`; se resuelve contra la
 *       fila de la tabla de referencia que tiene la MISMA columna legacy.
 *     - `cast_enum:<legacy>:<destino>...`: los pares que traducen el
 *       vocabulario del legacy al del destino.
 *     - `inject_store`: la tienda la decide el CLIENTE del sync
 *       (`sync.client_stores`), no el numero del legacy.
 *     - `inject_business_date`: la fecha de negocio es un DATO del llamante
 *       (la caja abierta), nunca un `DAY()/MONTH()/YEAR()` calculado.
 *  5. NO se traslada una columna cuando el destino la DERIVA (un cache, un
 *     contador) o cuando NO tiene equivalente con el mismo significado.
 *  6. NO se traslada un IDENTIFICADOR DE MAESTRA (el cliente, el producto,
 *     el metodo de pago, la cuenta bancaria, el usuario): aqui los ids son
 *     OTRO espacio de identificadores, y llevar el numero del legacy a una
 *     FK aqui seria enlazar la venta al cliente equivocado sin que nada lo
 *     delate. Es la regla que mas caro sale si se rompe.
 *  7. NO se traslada un VALOR con otra CONVENCION (el signo del importe del
 *     libro del cliente, la direccion de un movimiento bancario): el destino
 *     lo espera al reves, y un importe con el signo cambiado es dinero mal.
 *
 * LO QUE NO SE PUEDE DECIR CON ESTE MAPA (y queda como hueco declarado):
 *
 *  - El destino tiene columnas NOT NULL que el legacy no escribe
 *    (`sale_items.product_id`, `customer_ledger_entries.customer_id`,
 *    `ledger_entries.entry_type_id`, `ledger_entries.business_date`,
 *    `installment_schedules.number`, `gift_cards.store_id`,
 *    `purchase_items.purchase_id`). El mapa no puede ANADIR una columna que
 *    el legacy no manda: hace falta una transformacion de inyeccion, o una
 *    columna que guarde el identificador legacy.
 *  - La cabecera de la compra (`purchase_info`) todavia no esta en el mapa,
 *    asi que la linea de compra no se puede enlazar con su cabecera.
 *  - Las columnas del legacy cuyo nombre tiene dos o tres letras (`id`,
 *    `due`, `tax`, `gst`, `url`) NO se declaran: la regla 0.8.2-T reserva
 *    esos nombres para los codigos de pais y de moneda, y un identificador
 *    del mapa no puede ser un literal de pais. Ninguna de ellas se traslada
 *    igualmente.
 */
class LegacyTranslationMapSeeder extends Seeder
{
    /**
     * Siembra el mapa.
     *
     * Es IDEMPOTENTE: el `upsert` se apoya en la restriccion unica
     * `(legacy_table, legacy_column)`, asi que sembrar dos veces actualiza
     * las mismas filas y no duplica ninguna.
     */
    public function run(): void
    {
        $now = now();

        $filas = [];

        foreach ($this->mapa() as $fila) {
            $filas[] = [
                'legacy_table' => $fila[0],
                'legacy_column' => $fila[1],
                'new_table' => $fila[2],
                'new_column' => $fila[3],
                'transform' => $fila[4],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('legacy_translation_map')->upsert(
            $filas,
            ['legacy_table', 'legacy_column'],
            ['new_table', 'new_column', 'transform', 'updated_at']
        );
    }

    /**
     * El MAPA: `legacy_table`, `legacy_column`, `new_table`, `new_column`,
     * `transform`.
     *
     * @return list<array{0: string, 1: string, 2: ?string, 3: ?string, 4: ?string}>
     */
    private function mapa(): array
    {
        return [
            // ---------------------------------------------------------------
            // `selling_info` -> `sales`: la cabecera de la venta.
            // ---------------------------------------------------------------
            ['selling_info', '*', 'sales', '*', 'resolve_key'],
            ['selling_info', 'invoice_id', 'sales', 'reference_no', 'rename'],
            ['selling_info', 'store_id', 'sales', 'store_id', 'inject_store'],
            ['selling_info', 'payment_status', 'sales', 'status', 'cast_enum:paid:paid:due:confirmed:partial:confirmed'],
            ['selling_info', 'created_at', 'sales', 'sale_date', 'rename'],
            ['selling_info', 'customer_id', null, null, null],
            ['selling_info', 'customer_mobile', null, null, null],
            ['selling_info', 'invoice_note', null, null, null],
            ['selling_info', 'total_items', null, null, null],
            ['selling_info', 'is_installment', null, null, null],
            ['selling_info', 'created_by', null, null, null],
            ['selling_info', 'pmethod_id', null, null, null],
            ['selling_info', 'checkout_status', null, null, null],

            // ---------------------------------------------------------------
            // `selling_item` -> `sale_items`: las lineas de la venta.
            // ---------------------------------------------------------------
            ['selling_item', '*', 'sale_items', '*', 'rename'],
            ['selling_item', 'invoice_id', 'sale_items', 'sale_id', 'resolve_ref:selling_info'],
            ['selling_item', 'store_id', 'sale_items', 'store_id', 'inject_store'],
            ['selling_item', 'item_price', 'sale_items', 'unit_price', 'rename'],
            ['selling_item', 'item_discount', 'sale_items', 'discount', 'rename'],
            ['selling_item', 'item_tax', 'sale_items', 'tax_amount', 'rename'],
            ['selling_item', 'item_quantity', 'sale_items', 'quantity', 'rename'],
            ['selling_item', 'item_total', 'sale_items', 'line_total', 'rename'],
            ['selling_item', 'item_id', null, null, null],
            ['selling_item', 'category_id', null, null, null],
            ['selling_item', 'brand_id', null, null, null],
            ['selling_item', 'sup_id', null, null, null],
            ['selling_item', 'item_name', null, null, null],
            ['selling_item', 'item_purchase_price', null, null, null],
            ['selling_item', 'tax_method', null, null, null],
            ['selling_item', 'taxrate_id', null, null, null],
            ['selling_item', 'purchase_invoice_id', null, null, null],

            // ---------------------------------------------------------------
            // `selling_price` -> `sale_totals`: los totales de la venta.
            // ---------------------------------------------------------------
            ['selling_price', '*', 'sale_totals', '*', 'rename'],
            ['selling_price', 'invoice_id', 'sale_totals', 'sale_id', 'resolve_ref:selling_info'],
            ['selling_price', 'subtotal', 'sale_totals', 'sub_total', 'rename'],
            ['selling_price', 'discount_amount', 'sale_totals', 'discount', 'rename'],
            ['selling_price', 'item_tax', 'sale_totals', 'tax_total', 'rename'],
            ['selling_price', 'payable_amount', 'sale_totals', 'grand_total', 'rename'],
            ['selling_price', 'store_id', null, null, null],
            ['selling_price', 'discount_type', null, null, null],
            ['selling_price', 'interest_amount', null, null, null],
            ['selling_price', 'interest_percentage', null, null, null],
            ['selling_price', 'order_tax', null, null, null],
            ['selling_price', 'total_purchase_price', null, null, null],
            ['selling_price', 'shipping_type', null, null, null],
            ['selling_price', 'shipping_amount', null, null, null],
            ['selling_price', 'others_charge', null, null, null],
            ['selling_price', 'previous_due', null, null, null],
            ['selling_price', 'paid_amount', null, null, null],
            ['selling_price', 'prev_due_paid', null, null, null],
            ['selling_price', 'profit', null, null, null],
            ['selling_price', 'balance', null, null, null],

            // ---------------------------------------------------------------
            // `payments` -> `sale_payments`: los cobros de la venta.
            // ---------------------------------------------------------------
            ['payments', '*', 'sale_payments', '*', 'rename'],
            ['payments', 'invoice_id', 'sale_payments', 'sale_id', 'resolve_ref:selling_info'],
            ['payments', 'amount', 'sale_payments', 'amount', 'rename'],
            ['payments', 'details', 'sale_payments', 'details', 'rename'],
            ['payments', 'created_at', 'sale_payments', 'created_at', 'rename'],
            ['payments', 'type', null, null, null],
            ['payments', 'store_id', null, null, null],
            ['payments', 'pmethod_id', null, null, null],
            ['payments', 'capital', null, null, null],
            ['payments', 'note', null, null, null],
            ['payments', 'total_paid', null, null, null],
            ['payments', 'pos_balance', null, null, null],
            ['payments', 'created_by', null, null, null],

            // ---------------------------------------------------------------
            // `customer_transactions` -> `customer_ledger_entries`: los
            // movimientos del cliente.
            // ---------------------------------------------------------------
            ['customer_transactions', '*', 'customer_ledger_entries', '*', 'rename'],
            ['customer_transactions', 'reference_no', 'customer_ledger_entries', 'reference_no', 'rename'],
            ['customer_transactions', 'type', 'customer_ledger_entries', 'type', 'cast_enum:purchase:sale:add_balance:payment:substract_balance:payment:due_paid:payment:others:adjustment'],
            ['customer_transactions', 'notes', 'customer_ledger_entries', 'description', 'rename'],
            ['customer_transactions', 'store_id', 'customer_ledger_entries', 'store_id', 'inject_store'],
            ['customer_transactions', 'created_at', 'customer_ledger_entries', 'entry_date', 'inject_business_date'],
            ['customer_transactions', 'customer_id', null, null, null],
            ['customer_transactions', 'pmethod_id', null, null, null],
            ['customer_transactions', 'amount', null, null, null],
            ['customer_transactions', 'balance', null, null, null],
            ['customer_transactions', 'ref_invoice_id', null, null, null],
            ['customer_transactions', 'created_by', null, null, null],

            // ---------------------------------------------------------------
            // `sell_logs`: NO tiene tabla destino. Su fila de tabla dice que
            // la operacion se DESCARTA (no se rechaza el lote).
            // ---------------------------------------------------------------
            ['sell_logs', '*', null, null, null],

            // ---------------------------------------------------------------
            // `customer_to_store` -> `customer_stores`: la ficha del cliente
            // en la tienda. Los saldos son un CACHE y el maestro es el dueno
            // del maestro de clientes: solo se declara lo que el legacy
            // FILTRA, para que la operacion quede inerte en vez de
            // rechazada.
            // ---------------------------------------------------------------
            ['customer_to_store', '*', 'customer_stores', '*', 'rename'],
            ['customer_to_store', 'customer_id', 'customer_stores', 'customer_id', 'rename'],
            ['customer_to_store', 'store_id', 'customer_stores', 'store_id', 'inject_store'],
            ['customer_to_store', 'status', null, null, null],
            ['customer_to_store', 'sort_order', null, null, null],
            ['customer_to_store', 'balance', null, null, null],

            // ---------------------------------------------------------------
            // `product_to_store` -> `product_stores`: lo mismo que la ficha
            // del cliente: el stock es un CACHE derivado de los lotes.
            // ---------------------------------------------------------------
            ['product_to_store', '*', 'product_stores', '*', 'rename'],
            ['product_to_store', 'product_id', 'product_stores', 'product_id', 'rename'],
            ['product_to_store', 'store_id', 'product_stores', 'store_id', 'inject_store'],
            ['product_to_store', 'purchase_price', null, null, null],
            ['product_to_store', 'sell_price', null, null, null],
            ['product_to_store', 'quantity_in_stock', null, null, null],
            ['product_to_store', 'alert_quantity', null, null, null],
            ['product_to_store', 'sup_id', null, null, null],
            ['product_to_store', 'brand_id', null, null, null],
            ['product_to_store', 'box_id', null, null, null],
            ['product_to_store', 'taxrate_id', null, null, null],
            ['product_to_store', 'tax_method', null, null, null],
            ['product_to_store', 'preference', null, null, null],
            ['product_to_store', 'e_date', null, null, null],
            ['product_to_store', 'p_date', null, null, null],
            ['product_to_store', 'status', null, null, null],
            ['product_to_store', 'sort_order', null, null, null],

            // ---------------------------------------------------------------
            // `purchase_item` -> `purchase_items`: la linea de compra que
            // abre el lote.
            // ---------------------------------------------------------------
            ['purchase_item', '*', 'purchase_items', '*', 'rename'],
            ['purchase_item', 'store_id', 'purchase_items', 'store_id', 'inject_store'],
            ['purchase_item', 'item_purchase_price', 'purchase_items', 'unit_price', 'rename'],
            ['purchase_item', 'item_quantity', 'purchase_items', 'quantity', 'rename'],
            ['purchase_item', 'item_tax', 'purchase_items', 'tax_amount', 'rename'],
            ['purchase_item', 'invoice_id', null, null, null],
            ['purchase_item', 'item_id', null, null, null],
            ['purchase_item', 'category_id', null, null, null],
            ['purchase_item', 'brand_id', null, null, null],
            ['purchase_item', 'item_name', null, null, null],
            ['purchase_item', 'item_selling_price', null, null, null],
            ['purchase_item', 'status', null, null, null],
            ['purchase_item', 'item_total', null, null, null],
            ['purchase_item', 'tax_method', null, null, null],
            ['purchase_item', 'total_sell', null, null, null],

            // ---------------------------------------------------------------
            // `bank_transaction_info` -> `ledger_entries`: el movimiento
            // bancario.
            // ---------------------------------------------------------------
            ['bank_transaction_info', '*', 'ledger_entries', '*', 'rename'],
            ['bank_transaction_info', 'store_id', 'ledger_entries', 'store_id', 'inject_store'],
            ['bank_transaction_info', 'invoice_id', 'ledger_entries', 'reference_id', 'resolve_ref:selling_info'],
            ['bank_transaction_info', 'title', 'ledger_entries', 'description', 'rename'],
            ['bank_transaction_info', 'created_at', 'ledger_entries', 'occurred_at', 'rename'],
            ['bank_transaction_info', 'account_id', null, null, null],
            ['bank_transaction_info', 'source_id', null, null, null],
            ['bank_transaction_info', 'exp_category_id', null, null, null],
            ['bank_transaction_info', 'ref_no', null, null, null],
            ['bank_transaction_info', 'transaction_type', null, null, null],
            ['bank_transaction_info', 'details', null, null, null],
            ['bank_transaction_info', 'image', null, null, null],
            ['bank_transaction_info', 'is_substract', null, null, null],
            ['bank_transaction_info', 'created_by', null, null, null],

            // ---------------------------------------------------------------
            // `bank_transaction_price` -> `ledger_lines`: el importe del
            // movimiento. El DEBITO y el CREDITO no se pueden elegir con el
            // mapa (la direccion es una bandera del legacy), asi que el
            // importe no se traslada antes que trasladar un movimiento con
            // la direccion cambiada.
            // ---------------------------------------------------------------
            ['bank_transaction_price', '*', 'ledger_lines', '*', 'rename'],
            ['bank_transaction_price', 'ref_no', 'ledger_lines', 'description', 'rename'],
            ['bank_transaction_price', 'store_id', null, null, null],
            ['bank_transaction_price', 'info_id', null, null, null],
            ['bank_transaction_price', 'amount', null, null, null],

            // ---------------------------------------------------------------
            // `bank_accounts` -> `bank_accounts`: la cuenta bancaria. Se
            // identification por el NUMERO (una clave natural que existe en
            // los dos esquemas).
            // ---------------------------------------------------------------
            ['bank_accounts', '*', 'bank_accounts', '*', 'rename'],
            ['bank_accounts', 'account_name', 'bank_accounts', 'name', 'rename'],
            ['bank_accounts', 'account_no', 'bank_accounts', 'number', 'rename'],
            ['bank_accounts', 'created_at', 'bank_accounts', 'created_at', 'rename'],
            ['bank_accounts', 'account_details', null, null, null],
            ['bank_accounts', 'contact_person', null, null, null],
            ['bank_accounts', 'phone_number', null, null, null],
            ['bank_accounts', 'total_deposit', null, null, null],

            // ---------------------------------------------------------------
            // `bank_account_to_store` -> `bank_account_stores`: el vinculo de
            // la cuenta con la tienda. Los contadores son DERIVADOS del
            // libro.
            // ---------------------------------------------------------------
            ['bank_account_to_store', '*', 'bank_account_stores', '*', 'rename'],
            ['bank_account_to_store', 'account_id', 'bank_account_stores', 'bank_account_id', 'rename'],
            ['bank_account_to_store', 'store_id', 'bank_account_stores', 'store_id', 'inject_store'],
            ['bank_account_to_store', 'status', null, null, null],
            ['bank_account_to_store', 'sort_order', null, null, null],
            ['bank_account_to_store', 'deposit', null, null, null],
            ['bank_account_to_store', 'withdraw', null, null, null],

            // ---------------------------------------------------------------
            // `pos_register` -> `cash_registers`: la caja del dia. La fecha
            // de negocio es la de la CAJA ABIERTA (el dato del llamante), no
            // un `DAY()/MONTH()/YEAR()` del legacy.
            // ---------------------------------------------------------------
            ['pos_register', '*', 'cash_registers', '*', 'rename'],
            ['pos_register', 'store_id', 'cash_registers', 'store_id', 'inject_store'],
            ['pos_register', 'created_at', 'cash_registers', 'business_date', 'inject_business_date'],
            ['pos_register', 'opening_balance', 'cash_registers', 'opening_balance', 'rename'],

            // ---------------------------------------------------------------
            // `installment_orders` -> `installment_plans`: el plan de cuotas.
            // ---------------------------------------------------------------
            ['installment_orders', '*', 'installment_plans', '*', 'resolve_key'],
            ['installment_orders', 'store_id', 'installment_plans', 'store_id', 'inject_store'],
            ['installment_orders', 'invoice_id', 'installment_plans', 'reference_no', 'rename'],
            ['installment_orders', 'installment_count', 'installment_plans', 'installment_count', 'rename'],
            ['installment_orders', 'interest_percentage', 'installment_plans', 'interest_percentage', 'rename'],
            ['installment_orders', 'created_at', 'installment_plans', 'created_at', 'rename'],
            ['installment_orders', 'duration', null, null, null],
            ['installment_orders', 'interval_count', null, null, null],
            ['installment_orders', 'interest_amount', null, null, null],
            ['installment_orders', 'initial_amount', null, null, null],
            ['installment_orders', 'last_installment_date', null, null, null],
            ['installment_orders', 'installment_end_date', null, null, null],

            // ---------------------------------------------------------------
            // `installment_payments` -> `installment_schedules`: las cuotas.
            // El plan se resuelve por el identificador de la venta.
            // ---------------------------------------------------------------
            ['installment_payments', '*', 'installment_schedules', '*', 'rename'],
            ['installment_payments', 'invoice_id', 'installment_schedules', 'installment_plan_id', 'resolve_ref:installment_orders'],
            ['installment_payments', 'payment_date', 'installment_schedules', 'due_date', 'rename'],
            ['installment_payments', 'payable', 'installment_schedules', 'amount', 'rename'],
            ['installment_payments', 'paid', 'installment_schedules', 'paid_amount', 'rename'],
            ['installment_payments', 'payment_status', 'installment_schedules', 'status', 'cast_enum:paid:paid:due:pending:pending:pending:cancel:cancelled'],
            ['installment_payments', 'store_id', null, null, null],
            ['installment_payments', 'created_by', null, null, null],
            ['installment_payments', 'note', null, null, null],
            ['installment_payments', 'capital', null, null, null],
            ['installment_payments', 'interest', null, null, null],

            // ---------------------------------------------------------------
            // `gift_cards` -> `gift_cards`: la tarjeta. Se identifica por el
            // NUMERO (una clave natural), no por un id.
            // ---------------------------------------------------------------
            ['gift_cards', '*', 'gift_cards', '*', 'rename'],
            ['gift_cards', 'card_no', 'gift_cards', 'card_no', 'rename'],
            ['gift_cards', 'value', 'gift_cards', 'value', 'rename'],
            ['gift_cards', 'balance', 'gift_cards', 'balance', 'rename'],
            ['gift_cards', 'expiry', 'gift_cards', 'expires_at', 'rename'],
            ['gift_cards', 'customer_id', null, null, null],
            ['gift_cards', 'created_by', null, null, null],
        ];
    }
}
