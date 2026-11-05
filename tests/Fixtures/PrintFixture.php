<?php

namespace Tests\Fixtures;

use App\Models\Printer;
use App\Models\PrintTarget;
use App\Models\Store;
use Illuminate\Support\Facades\DB;

/**
 * Los datos minimos de la impresion para las pruebas: una tienda, una
 * impresora, un destino y un trabajo de impresion.
 *
 * No hay factories: la siembra es EXPLICITA, con `Model::create()` y
 * `DB::table()->insertGetId()`, como en el resto de la suite.
 */
final class PrintFixture
{
    /**
     * Una tienda con su impresora y su destino.
     *
     * @return array{store_id: int, printer_id: int, target_id: int}
     */
    public static function contexto(string $transport = 'lan_agent'): array
    {
        $store = Store::create(['name' => 'Tienda de impresion '.uniqid('IMP', true)]);

        return array_merge(
            ['store_id' => (int) $store->id],
            self::destino((int) $store->id, $transport)
        );
    }

    /**
     * La impresora y el destino de una tienda que ya existe.
     *
     * @return array{printer_id: int, target_id: int}
     */
    public static function destino(int $storeId, string $transport = 'lan_agent'): array
    {
        $printer = Printer::create([
            'code' => 'IMP-'.strtoupper(substr(md5(uniqid('', true)), 0, 10)),
            'name' => 'Impresora de prueba',
        ]);

        $target = PrintTarget::create([
            'printer_id' => $printer->id,
            'store_id' => $storeId,
            'name' => 'Destino de prueba',
            'transport' => $transport,
            'endpoint' => $transport === 'browser' ? null : 'tcp://192.168.1.50:9100',
            'printer_profile' => 'default',
        ]);

        return [
            'printer_id' => (int) $printer->id,
            'target_id' => (int) $target->id,
        ];
    }

    /**
     * Un AGENTE LAN de la tienda, con su token en claro y su hash guardado.
     *
     * @return array{agent_id: int, token: string}
     */
    public static function agente(int $storeId, string $name = 'Agente de prueba'): array
    {
        $token = bin2hex(random_bytes(32));

        $agentId = (int) DB::table('print_agents')->insertGetId([
            'store_id' => $storeId,
            'name' => $name,
            // Solo el hash: el token en claro no se persiste.
            'token_hash' => hash('sha256', $token),
            'last_heartbeat_at' => null,
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return ['agent_id' => $agentId, 'token' => $token];
    }

    /**
     * El SECRETO DE REGISTRO de una tienda, en claro, con su hash guardado.
     *
     * La siembra escribe SOLO el hash (SHA-256), que es lo unico que admite
     * `stores.print_agent_registration_secret`: el secreto en claro no cabe
     * en la columna. El secreto devuelto es el que va en la cabecera
     * `X-Registration-Secret`.
     */
    public static function secretoDeRegistro(int $storeId): string
    {
        $secreto = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');

        DB::table('stores')->where('id', $storeId)->update([
            'print_agent_registration_secret' => hash('sha256', $secreto),
        ]);

        return $secreto;
    }

    /**
     * Un trabajo de impresion con sus bytes CONGELADOS.
     *
     * @param  array<string, mixed>  $atributos
     */
    public static function trabajo(int $storeId, int $targetId, array $atributos = []): int
    {
        $bytes = (string) ($atributos['payload_bytes'] ?? "\x1b\x40TICKET DE PRUEBA\x1d\x56\x41\x03");

        return (int) DB::table('print_jobs')->insertGetId(array_merge([
            'store_id' => $storeId,
            'print_target_id' => $targetId,
            'agent_id' => null,
            'status' => 'queued',
            'payload' => json_encode([
                'title' => 'TICKET',
                'lines' => [['text' => 'TICKET DE PRUEBA']],
                'qr_content' => null,
            ], JSON_UNESCAPED_UNICODE),
            // El `bytea` se escribe en hexadecimal: el constructor de
            // consultas trunca cualquier cadena con un byte nulo.
            'payload_bytes' => DB::raw("decode('".bin2hex($bytes)."', 'hex')"),
            'payload_sha256' => hash('sha256', $bytes),
            'reason' => null,
            'reprint_of_job_id' => null,
            'lease_expires_at' => null,
            'attempts' => 0,
            'last_error' => null,
            'created_by' => null,
            'created_at' => now(),
            'updated_at' => now(),
        ], $atributos));
    }
}
