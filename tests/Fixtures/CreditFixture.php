<?php

namespace Tests\Fixtures;

use App\Models\Customer;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * El contexto minimo del credito: una tienda y un cliente con su ficha en
 * esa tienda.
 *
 * La ficha del cliente en la tienda (`customer_stores`) es donde vive el
 * CACHE del saldo: sin ella, el credito del cliente en esa tienda no existe.
 */
final class CreditFixture
{
    /**
     * Una tienda.
     */
    public static function store(): int
    {
        return (int) Store::create(['name' => 'Tienda del credito'])->id;
    }

    /**
     * Un cliente con su ficha en la tienda.
     */
    public static function customer(int $storeId): int
    {
        $customer = Customer::create(['name' => 'Cliente del credito']);

        DB::table('customer_stores')->insert([
            'customer_id' => $customer->id,
            'store_id' => $storeId,
            'status' => 'active',
            'sort_order' => 0,
            // El cache arranca en cero: lo que no esta movido, no se debe.
            'balance' => '0.0000',
            'due' => '0.0000',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return (int) $customer->id;
    }
}
