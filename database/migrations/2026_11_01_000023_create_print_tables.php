<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * M23 — la impresión LAN: `printers` + `print_targets` + `print_agents` +
 * `print_jobs`.
 *
 * El legacy imprimía por USB o por el navegador. El diseño lo descarta: los
 * bytes ESC/POS se generan en el SERVIDOR y el agente LAN sólo los escribe
 * (design D11, ADR-0005). El agente recibe bytes ya generados y NO interpreta
 * el ticket.
 *
 * El modelo es de cuatro piezas:
 *
 * - `printers` es el CATÁLOGO de impresoras: la identidad del modelo, que se
 *   puede reutilizar en varias tiendas.
 * - `print_targets` es un DESTINO: una impresora concreta en una tienda
 *   concreta, con su transporte, su endpoint, su perfil y su codepágina. El
 *   PERFIL DE IMPRESORA es DATO, no código (design D5): lo declara el destino.
 * - `print_agents` es un AGENTE LAN instalado en un terminal. Su token se
 *   guarda SOLO como hash: una fuga acota a una sola caja.
 * - `print_jobs` es un TRABAJO de impresión, con su estado y sus bytes
 *   congelados.
 *
 * El USB DIRECTO no existe como destino: en Windows exige instalar un driver
 * con Zadig, que no es viable en un despliegue desatendido. Lo admiten sólo
 * la impresora de red por TCP 9100 y la impresora compartida de Windows.
 */
return new class extends Migration
{
    public function up(): void
    {
        // El CATÁLOGO de impresoras. La identidad del modelo vive aquí; el
        // estado por tienda vive en `print_targets`.
        Schema::create('printers', function (Blueprint $table) {
            $table->id();
            // El código es la clave del catálogo: se consulta, no se escribe
            // como literal en el destino.
            $table->string('code', 100)->unique();
            $table->string('name', 255);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Un DESTINO de impresión: una impresora en una tienda.
        Schema::create('print_targets', function (Blueprint $table) {
            $table->id();
            $table->foreignId('printer_id')->index()->constrained('printers')->restrictOnDelete();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->string('name', 255);

            // El TRANSPORTE. Sólo tres, y deliberadamente NO hay `usb`: el
            // acceso USB directo en Windows exige Zadig (design D5, ADR-0005).
            // - `browser`: renderiza HTML/PDF desde el MISMO modelo de
            //   plantilla; no consume ESC/POS y no tiene endpoint.
            // - `lan_agent`: el agente de la LAN escribe los bytes.
            // - `server_socket`: el propio servidor escribe los bytes.
            $table->enum('transport', ['browser', 'lan_agent', 'server_socket']);

            // El ENDPOINT: `tcp://host:9100` o la impresora compartida de
            // Windows. Nulo para el transporte `browser`.
            $table->string('endpoint', 255)->nullable();

            // El PERFIL de impresora es DATO, no código: el destino declara
            // qué sabe hacer la impresora (design D5).
            $table->string('printer_profile', 100);

            // El ancho, en caracteres, es del perfil: no lo decide el código.
            $table->integer('char_per_line')->default(32);

            // La codepágina. CP850 por defecto porque CP858 NO es codificable
            // en la librería de impresión (el destino que la pida se rechaza
            // en el servidor, no se imprime un ticket mudo).
            $table->string('charset', 20)->default('CP850');

            // El límite de payload del QR lo impone el SERVIDOR: ninguna
            // librería lo valida. Es un dato del destino, calibrado en Fase 1.
            $table->integer('max_qr_payload_bytes')->default(512);

            // Si el perfil sabe con QR. Cuando es falso, la generación falla
            // en el servidor con un error explícito.
            $table->boolean('supports_qr')->default(true);

            $table->boolean('is_active')->default(true);
            $table->timestamps();

            // El nombre del destino es único dentro de la tienda: dos
            // destinos con el mismo nombre en la misma tienda son el mismo
            // destino escrito dos veces.
            $table->unique(['store_id', 'name']);
        });

        // El CHECK del endpoint. Es la regla que descarta el USB directo:
        // el esquema no ADMITE el destino, no es una preferencia del código.
        //
        // - `browser`: sin endpoint, porque renderiza HTML/PDF.
        // - `lan_agent` y `server_socket`: con endpoint, y sólo de una de
        //   estas dos formas:
        //     * `tcp://host:9100` — la impresora de red;
        //     * `smb://...` o un recurso UNC (`\\HOST\IMPRESORA`) — la
        //       impresora compartida de Windows.
        //
        // La barra invertida del recurso UNC se busca con `chr(92)` y no con
        // una barra en la sentencia: así el CHECK no depende de cómo
        // PostgreSQL interprete las barras invertidas de la cadena.
        DB::statement(<<<'SQL'
            ALTER TABLE print_targets ADD CONSTRAINT print_targets_endpoint_admitted CHECK (
                (transport = 'browser' AND endpoint IS NULL)
                OR
                (transport IN ('lan_agent', 'server_socket') AND endpoint IS NOT NULL AND (
                    endpoint ~ '^tcp://[A-Za-z0-9._-]+:9100$'
                    OR endpoint LIKE 'smb://%'
                    OR (
                        starts_with(endpoint, chr(92) || chr(92))
                        AND position(chr(92) in substring(endpoint FROM 3)) > 0
                    )
                ))
            )
        SQL);

        // Un AGENTE LAN instalado en un terminal.
        Schema::create('print_agents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->string('name', 255);

            // SOLO el hash del token. El token en claro NUNCA se persiste:
            // una fuga acota a una sola caja, no a una tienda (design D5).
            $table->string('token_hash', 255)->unique();

            // El último latido del agente. El UMBRAL de desconexión NO es una
            // columna: es un parámetro de `config/print.php`
            // (`agent_disconnect_threshold_seconds`), porque cambia con la
            // operación y no con el esquema.
            $table->timestampTz('last_heartbeat_at')->nullable();

            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // Un TRABAJO de impresión.
        Schema::create('print_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('store_id')->index()->constrained('stores')->restrictOnDelete();
            $table->foreignId('print_target_id')->index()->constrained('print_targets')->restrictOnDelete();

            // El agente que toma el trabajo. Es nulo mientras el trabajo está
            // en la cola: lo toma el agente, no el servidor.
            $table->foreignId('agent_id')->nullable()->index()->constrained('print_agents')->nullOnDelete();

            // El ESTADO del trabajo. Es INDEPENDIENTE del estado del
            // documento fiscal: emitir no depende de imprimir (design D5).
            $table->enum('status', ['queued', 'claimed', 'printing', 'printed', 'failed', 'cancelled'])->default('queued');

            // El MODELO del ticket: lo que se imprime. Viaja como jsonb para
            // que se pueda consultar y auditar sin releer los bytes.
            $table->jsonb('payload')->nullable();

            // Los BYTES ESC/POS ya generados, CONGELADOS. El agente no
            // interpreta el ticket: los recibe hechos (design D11).
            $table->binary('payload_bytes')->nullable();

            // El hash de esos bytes: detecta corrupción y manipulación sin
            // cifrar nada extra.
            $table->string('payload_sha256', 64)->nullable();

            // Por qué existe el trabajo: `reprint` para una reimpresión.
            $table->string('reason', 255)->nullable();

            // Una REIMPRESIÓN es un trabajo NUEVO contra el mismo documento:
            // apunta al trabajo original y NO consume correlativo ni modifica
            // el documento fiscal.
            $table->foreignId('reprint_of_job_id')->nullable()->index()->constrained('print_jobs')->nullOnDelete();

            // El ALQUILER del trabajo: mientras expira, el agente lo tiene.
            $table->timestampTz('lease_expires_at')->nullable();

            $table->integer('attempts')->default(0);
            $table->text('last_error')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            // La cola se consulta por estado y antigüedad.
            $table->index(['status', 'created_at']);
        });

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_targets.transport IS
            'Como se escriben los bytes: browser (HTML/PDF desde el mismo modelo de plantilla), lan_agent (el agente de la LAN los escribe) o server_socket (los escribe el servidor). NO existe usb: el acceso USB directo en Windows exige Zadig.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_targets.endpoint IS
            'Destino fisico de los bytes: tcp://host:9100 (impresora de red) o la impresora compartida de Windows (smb://... o recurso UNC \\HOST\IMPRESORA). Nulo para el transporte browser. Lo valida el CHECK print_targets_endpoint_admitted.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_targets.printer_profile IS
            'Perfil ESC/POS de la impresora. Es DATO, no codigo: el servidor consulta sus capacidades y falla en el servidor si el destino no soporta lo pedido.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_targets.charset IS
            'Codepagina de la impresora. CP850 por defecto porque CP858 NO es codificable en la libreria de impresion usada.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_targets.max_qr_payload_bytes IS
            'Limite del payload del QR. Lo impone el SERVIDOR: ninguna libreria lo valida. Dato del destino, calibrado en Fase 1.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_agents.token_hash IS
            'SOLO el hash del token del agente. El token en claro nunca se persiste: una fuga acota a una sola caja. El umbral de desconexion NO es una columna: es config/print.php (agent_disconnect_threshold_seconds).'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_jobs.payload IS
            'El MODELO del ticket (jsonb): lo que se imprime. Se consulta y se audita sin releer los bytes.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_jobs.payload_bytes IS
            'Los BYTES ESC/POS ya generados, congelados por el servidor. El agente no interpreta el ticket: los recibe hechos (design D11).'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_jobs.payload_sha256 IS
            'Hash sha256 de payload_bytes: detecta corrupcion y manipulacion sin cifrar nada extra.'
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_jobs.reprint_of_job_id IS
            'Trabajo original de una reimpresion. Una reimpresion es un trabajo NUEVO con reason=reprint: no consume correlativo ni modifica el documento fiscal.'
        SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('print_jobs');
        Schema::dropIfExists('print_agents');
        Schema::dropIfExists('print_targets');
        Schema::dropIfExists('printers');
    }
};
