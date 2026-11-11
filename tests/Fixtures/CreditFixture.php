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
     *
     * El nombre y el documento son OPCIONALES: el cliente minimo es el que
     * solo tiene nombre, y los que llevan documento son los que la API
     * busca por el. El LIMITE DE CREDITO tambien es opcional, y vive en el
     * maestro: es un limite, no un saldo.
     *
     * @param  string|null  $nombre  El nombre del cliente.
     * @param  string|null  $taxId  El documento fiscal.
     * @param  string|null  $limite  El limite de credito, como texto decimal.
     */
    public static function customer(int $storeId, ?string $nombre = null, ?string $taxId = null, ?string $limite = null): int
    {
        $customer = Customer::create([
            'name' => $nombre ?? 'Cliente del credito',
            'tax_id' => $taxId,
            'credit_limit' => $limite,
        ]);

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

    /**
     * Un cliente SIN FICHA en ninguna tienda.
     *
     * Su credito no existe en ninguna tienda: es el caso que la API
     * rechaza con un 422.
     */
    public static function clienteSinFicha(string $nombre = 'Cliente sin ficha', ?string $taxId = null): int
    {
        $customer = Customer::create([
            'name' => $nombre,
            'tax_id' => $taxId,
        ]);

        return (int) $customer->id;
    }
}
