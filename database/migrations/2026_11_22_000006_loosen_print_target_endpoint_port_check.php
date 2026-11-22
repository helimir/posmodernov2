<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * M49 — el PUERTO del destino de impresion: el que sea.
 *
 * REGLA que fija esta migracion: *un destino de impresion es una impresora de
 * red en el PUERTO que tenga, o la impresora compartida de Windows*. El
 * 9100 es el RAW de facto del estandar ESC/POS, no una ley: hay impresoras de
 * red en el 9105, en el 515 (LPD) o en el 631 (IPP), y un POS que no puede
 * configurar su impresora es un POS que se queda sin ticket.
 *
 * LO QUE NO SE AFLOJA:
 *
 *  - el USB DIRECTO sigue PROHIBIDO: en Windows exige instalar un driver con
 *    Zadig, y no es viable en un despliegue desatendido. El esquema `usb://`
 *    no es ninguna de las formas admitidas, y el transporte `usb` no existe;
 *  - el esquema `file://` sigue PROHIBIDO: un destino no es un fichero local;
 *  - los transportes FISICOS siguen EXIGIENDO endpoint, y el `browser` sigue
 *    SIN endpoint: el navegador ES el destino.
 *
 * Y EL RANGO DEL PUERTO es el de TCP: de 1 a 65535. El `COALESCE` esta ahi
 * porque un CHECK acepta un `NULL` como "sin infraccion": sin el, un
 * endpoint que no casase con el patron se evaluaria a `NULL` en vez de a
 * `FALSE`, y el USB pasaria el filtro.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE print_targets DROP CONSTRAINT print_targets_endpoint_admitted');

        DB::statement(<<<'SQL'
            ALTER TABLE print_targets ADD CONSTRAINT print_targets_endpoint_admitted CHECK (
                (transport = 'browser' AND endpoint IS NULL)
                OR
                (transport IN ('lan_agent', 'server_socket') AND endpoint IS NOT NULL AND (
                    (
                        endpoint ~ '^tcp://[A-Za-z0-9._-]+:[0-9]{1,5}$'
                        AND COALESCE((substring(endpoint FROM ':([0-9]{1,5})$'))::integer, 0) BETWEEN 1 AND 65535
                    )
                    OR endpoint LIKE 'smb://%'
                    OR (
                        starts_with(endpoint, chr(92) || chr(92))
                        AND position(chr(92) in substring(endpoint FROM 3)) > 0
                    )
                ))
            )
        SQL);

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_targets.endpoint IS
            'Destino fisico de los bytes: tcp://host:<puerto> (impresora de red, cualquier puerto de 1 a 65535) o la impresora compartida de Windows (smb://... o recurso UNC \\HOST\IMPRESORA). Nulo para el transporte browser. Lo valida el CHECK print_targets_endpoint_admitted.'
        SQL);
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE print_targets DROP CONSTRAINT print_targets_endpoint_admitted');

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

        DB::statement(<<<'SQL'
            COMMENT ON COLUMN print_targets.endpoint IS
            'Destino fisico de los bytes: tcp://host:9100 (impresora de red) o la impresora compartida de Windows (smb://... o recurso UNC \\HOST\IMPRESORA). Nulo para el transporte browser. Lo valida el CHECK print_targets_endpoint_admitted.'
        SQL);
    }
};
