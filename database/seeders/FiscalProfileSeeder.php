<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * El PERFIL FISCAL por pais, con EVIDENCIA TRAZABLE.
 *
 * Un perfil fiscal por pais (VE, CL, MX, CO, PA), con sus tipos de documento,
 * su vocabulario de estados, su catalogo de claves, sus categorias de IVA con
 * sus tasas y sus DOS series (la del documento y la del control).
 *
 * LA REGLA CENTRAL DE ESTA SEMILLA: *cada fila sembrada declara su
 * `evidence_status` y su `source_ref`; un dato no verificado se marca como
 * pendiente, no se afirma*.
 *
 * Los dos estados que se siembran y lo que significan:
 *
 *  - `verified`: el dato es una CITA LITERAL de la fuente citada, que se ha
 *    transcrito y cotejado. El token "serie" (art. 20), la frase "Contribuyentes
 *    Formales del IVA" (art. 8.7) y el marcador "(E)" (art. 8.7) estan en el
 *    texto de la providencia transcrita, y la `source_ref` dice de que
 *    articulo sale cada uno.
 *  - `pending_confirmation`: el dato depende de una fuente que NO se ha
 *    alcanzado, o su aplicacion al caso no esta confirmada. La tasa de IVA
 *    del 16 % NO aparece en la providencia (arts. 54 y 57): remiten al
 *    Decreto del IVA (G.O. 5.363 de 1999), que no se ha leido. Se siembra
 *    `pending_confirmation` CON su `source_ref`, para que la fila sea
 *    localizable por la fuente y se pueda confirmar cuando el Decreto este a
 *    mano. Afirmarla como `verified` seria inventar una confirmacion legal.
 *
 * LO QUE NO SE INVENTA (y por que):
 *
 *  - El `schema_version` de Venezuela es NULL: el Manual Tecnico del art. 41
 *    no se ha localizado. El de Chile es '1.0' (lo transcrito), no '2.2' (la
 *    inercia que el research descarta).
 *  - Los `namespace` y `xsd_url` de Colombia y Panama son NULL: sus URIs
 *    estan pendiente de transcripcion. Los de Mexico SI estan transcritos.
 *  - El `id_scheme_pattern` de los cinco paises es NULL: el patron oficial
 *    del identificador fiscal no esta transcrito, y un patron inventado
 *    validaria documentos mal formados.
 *  - El `hash_algorithm` y el `signature_scheme` son NULL salvo el SHA-256 de
 *    Colombia: la providencia venezolana NO fija algoritmo (ADR-0002).
 *  - El vocabulario NATIVO de estados de cada pais no esta transcrito: las
 *    filas sembradas mapean el estado GENERICO a si mismo, y el `native_label`
 *    lo declara. Sembrar los codigos nativos de memoria habria sido inventar
 *    un vocabulario.
 *  - Las categorias de IVA y las tasas solo se siembran para Venezuela: es el
 *    unico perfil cuyo tratamiento fiscal esta transcrito. Panama tiene el
 *    ITBMS al 7 % y Chile distingue por tipo de documento, pero sus
 *    estructuras de detalle no estan transcritas.
 *
 * Las SERIES llevan un rango de DEMOSTRACION: el rango autorizado de verdad lo
 * concede la autoridad o la imprenta digital, y sembrar uno real sin la
 * autorizacion seria afirmar algo que nadie ha verificado. El `credential_ref`
 * es una REFERENCIA (nunca el secreto) y lo dice.
 *
 * Es IDEMPOTENTE: los `upsert` se apoyan en las restricciones unicas de cada
 * tabla. Las series NO tienen ninguna, asi que se buscan por su clave natural
 * (perfil, tienda, rol) antes de escribirse. Y al re-sembrar NO se toca
 * `next_number`: reiniciar el contador repartiria numeros ya emitidos, que el
 * rollback de la numeracion fiscal prohibe (ADR-0001).
 */
class FiscalProfileSeeder extends Seeder
{
    /**
     * Siembra el perfil fiscal de cada pais con sus satelites.
     */
    public function run(): void
    {
        $now = now();

        $tienda = (int) DB::table('stores')->where('code_name', 'DEMO')->value('id');

        foreach ($this->perfiles() as $pais) {
            $perfilId = $this->perfil($pais, $now);

            $this->proveedor($perfilId, $pais, $now);
            $this->tiposDeDocumento($perfilId, $pais, $now);
            $this->vocabularioDeEstados($perfilId, $now);
            $this->campos($perfilId, $pais, $now);
            $this->categoriasYTasas($perfilId, $pais, $now);
            $this->series($perfilId, $tienda, $pais, $now);
        }
    }

    /**
     * El PERFIL: la fila que describe el regimen fiscal de un pais.
     *
     * @param  array<string, mixed>  $pais
     * @param  Carbon  $now
     */
    private function perfil(array $pais, $now): int
    {
        $perfil = $pais['perfil'];

        $fila = [
            'country_id' => DB::table('countries')->where('iso_code', $pais['iso_code'])->value('id'),
            'code' => $perfil['code'],
            'name' => $perfil['name'],
            'document_standard' => $perfil['document_standard'],
            'schema_version' => $perfil['schema_version'],
            'schema_reference_url' => $perfil['schema_reference_url'],
            'xml_namespace' => $perfil['xml_namespace'],
            'xsd_url' => $perfil['xsd_url'],
            'id_scheme_code' => $perfil['id_scheme_code'],
            'id_scheme_pattern' => null,
            'authority_reference_scheme' => $perfil['authority_reference_scheme'],
            'numbering_scheme' => $perfil['numbering_scheme'],
            'requires_control_number' => $perfil['requires_control_number'],
            'authorized_range_required' => $perfil['authorized_range_required'],
            'holes_allowed' => $perfil['holes_allowed'],
            'signer_model' => $perfil['signer_model'],
            'hash_algorithm' => $perfil['hash_algorithm'],
            'signature_scheme' => null,
            'rounding_rule' => 'line',
            'tax_inclusive_default' => false,
            'default_currency_id' => DB::table('currencies')->where('code', $pais['moneda'])->value('id'),
            'effective_from' => $perfil['effective_from'],
            'effective_to' => null,
            'created_at' => $now,
            'updated_at' => $now,
        ];

        DB::table('fiscal_profiles')->upsert(
            [$fila],
            ['country_id', 'code'],
            [
                'name', 'document_standard', 'schema_version', 'schema_reference_url',
                'xml_namespace', 'xsd_url', 'id_scheme_code', 'id_scheme_pattern',
                'authority_reference_scheme', 'numbering_scheme', 'requires_control_number',
                'authorized_range_required', 'holes_allowed', 'signer_model', 'hash_algorithm',
                'signature_scheme', 'rounding_rule', 'tax_inclusive_default',
                'default_currency_id', 'effective_from', 'effective_to', 'updated_at',
            ]
        );

        return (int) DB::table('fiscal_profiles')
            ->where('country_id', $fila['country_id'])
            ->where('code', $perfil['code'])
            ->value('id');
    }

    /**
     * El ACTOR INTERMEDIO (`fiscal_providers`), solo donde la norma lo exige.
     *
     * En Venezuela el emisor NO emite por su cuenta: la imprenta digital
     * autorizada valida la estructura y asigna el Numero de Control (ADR-0002,
     * D11), asi que la serie de control tiene que apuntar a un actor real. En
     * los otros cuatro paises el canal llega con su adaptador (Fase 2), y
     * sembrar un proveedor de demostracion afirmaria un canal que nadie ha
     * verificado.
     *
     * @param  array<string, mixed>  $pais
     * @param  Carbon  $now
     */
    private function proveedor(int $perfilId, array $pais, $now): void
    {
        if ($pais['proveedor'] === null) {
            return;
        }

        $proveedor = $pais['proveedor'];

        DB::table('fiscal_providers')->upsert([
            [
                'fiscal_profile_id' => $perfilId,
                'code' => $proveedor['code'],
                'name' => $proveedor['name'],
                'kind' => $proveedor['kind'],
                'base_url' => null,
                'auth_scheme' => null,
                'credentials_ref' => null,
                'is_active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
        ], ['fiscal_profile_id', 'code'], ['name', 'kind', 'base_url', 'auth_scheme', 'credentials_ref', 'is_active', 'updated_at']);
    }

    /**
     * Los CUATRO TIPOS DE DOCUMENTO: la factura, la nota de credito, la nota
     * de debito y el comprobante de retencion.
     *
     * El comprobante de retencion es un DOCUMENTO PROPIO, no una linea de la
     * venta: es el credito del comprador, con su numeracion (14 caracteres) y
     * su referencia al documento de origen (art. 18).
     *
     * @param  array<string, mixed>  $pais
     * @param  Carbon  $now
     */
    private function tiposDeDocumento(int $perfilId, array $pais, $now): void
    {
        $filas = [];

        foreach ($this->tiposDeDocumentoCatalogo() as $tipo) {
            $filas[] = [
                'fiscal_profile_id' => $perfilId,
                'code' => $tipo['code'],
                'name' => $tipo['name'],
                'direction' => $tipo['direction'],
                'tax_treatment' => $tipo['tax_treatment'],
                'requires_authorized_range' => $pais['perfil']['authorized_range_required'],
                'allows_cancellation' => $tipo['allows_cancellation'],
                'requires_parent_reference' => $tipo['requires_parent_reference'],
                'exemption_marker' => $pais['exemption_marker'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('fiscal_profile_document_types')->upsert(
            $filas,
            ['fiscal_profile_id', 'code'],
            [
                'name', 'direction', 'tax_treatment', 'requires_authorized_range',
                'allows_cancellation', 'requires_parent_reference', 'exemption_marker', 'updated_at',
            ]
        );
    }

    /**
     * El VOCABULARIO DE ESTADOS: el codigo nativo mapeado al estado generico.
     *
     * @param  Carbon  $now
     */
    private function vocabularioDeEstados(int $perfilId, $now): void
    {
        $filas = [];

        foreach ($this->vocabularioDeEstadosCatalogo() as $estado) {
            $filas[] = [
                'fiscal_profile_id' => $perfilId,
                'native_code' => $estado['native_code'],
                'native_label' => $estado['native_label'],
                'generic_status' => $estado['generic_status'],
                'is_final' => $estado['is_final'],
                'is_cancellation' => $estado['is_cancellation'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('fiscal_status_vocabularies')->upsert(
            $filas,
            ['fiscal_profile_id', 'native_code'],
            ['native_label', 'generic_status', 'is_final', 'is_cancellation', 'updated_at']
        );
    }

    /**
     * El CATALOGO DE CLAVES (`fiscal_profile_fields`): las frases y los tokens
     * obligatorios, con su estado de evidencia y su fuente.
     *
     * @param  array<string, mixed>  $pais
     * @param  Carbon  $now
     */
    private function campos(int $perfilId, array $pais, $now): void
    {
        if ($pais['campos'] === []) {
            return;
        }

        $filas = [];

        foreach ($pais['campos'] as $campo) {
            $filas[] = [
                'fiscal_profile_id' => $perfilId,
                'key' => $campo['key'],
                'data_type' => $campo['data_type'],
                'label' => $campo['label'],
                'value' => $campo['value'],
                'required' => $campo['required'],
                'applies_to' => $campo['applies_to'],
                'evidence_status' => $campo['evidence_status'],
                'source_ref' => $campo['source_ref'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('fiscal_profile_fields')->upsert(
            $filas,
            ['fiscal_profile_id', 'key'],
            ['data_type', 'label', 'value', 'required', 'applies_to', 'evidence_status', 'source_ref', 'updated_at']
        );
    }

    /**
     * Las CATEGORIAS DE IVA y sus TASAS, con su estado de evidencia.
     *
     * @param  array<string, mixed>  $pais
     * @param  Carbon  $now
     */
    private function categoriasYTasas(int $perfilId, array $pais, $now): void
    {
        if ($pais['categorias'] === []) {
            return;
        }

        $filas = [];

        foreach ($pais['categorias'] as $categoria) {
            $filas[] = [
                'fiscal_profile_id' => $perfilId,
                'code' => $categoria['code'],
                'name' => $categoria['name'],
                'is_withholding' => $categoria['is_withholding'],
                'applies_to' => 'both',
                'evidence_status' => $categoria['evidence_status'],
                'source_ref' => $categoria['source_ref'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('tax_categories')->upsert(
            $filas,
            ['fiscal_profile_id', 'code'],
            ['name', 'is_withholding', 'applies_to', 'evidence_status', 'source_ref', 'updated_at']
        );

        $ids = DB::table('tax_categories')
            ->where('fiscal_profile_id', $perfilId)
            ->pluck('id', 'code');

        $tasas = [];

        foreach ($pais['tasas'] as $tasa) {
            $tasas[] = [
                'tax_category_id' => $ids[$tasa['categoria']],
                // El PORCENTAJE entra como TEXTO: un `float` de 16 no es
                // exactamente 16 en la aritmetica binaria, y un impuesto
                // redondeado por un error de coma flotante es dinero mal.
                'percentage' => $tasa['percentage'],
                'rounding_rule' => null,
                'valid_from' => $tasa['valid_from'],
                'valid_to' => null,
                'priority' => $tasa['priority'],
                'evidence_status' => $tasa['evidence_status'],
                'source_ref' => $tasa['source_ref'],
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        DB::table('tax_rates')->upsert(
            $tasas,
            ['tax_category_id', 'valid_from'],
            ['percentage', 'rounding_rule', 'valid_to', 'priority', 'evidence_status', 'source_ref', 'updated_at']
        );
    }

    /**
     * LAS DOS SERIES: la del DOCUMENTO y la del CONTROL.
     *
     * La del documento la numera el EMISOR (art. 8.2). La del control la
     * concede la autoridad que autoriza el rango: en Venezuela, la imprenta
     * digital (art. 42.1); en Chile, el SII con el CAF; en Mexico, el SAT con
     * los folios; en Colombia, la DIAN con la resolucion; en Panama, la DGI o
     * el PAC (ADR-0001, D7 y D10).
     *
     * @param  array<string, mixed>  $pais
     * @param  Carbon  $now
     */
    private function series(int $perfilId, int $tienda, array $pais, $now): void
    {
        $proveedor = $pais['proveedor'] === null
            ? null
            : DB::table('fiscal_providers')
                ->where('fiscal_profile_id', $perfilId)
                ->where('code', $pais['proveedor']['code'])
                ->value('id');

        foreach ($pais['series'] as $serie) {
            $fila = [
                'fiscal_profile_id' => $perfilId,
                'store_id' => $tienda,
                'provider_id' => $proveedor,
                'series_role' => $serie['series_role'],
                'assigned_by' => $serie['assigned_by'],
                'prefix' => $serie['prefix'],
                'next_number' => $serie['from_number'],
                'from_number' => $serie['from_number'],
                'to_number' => $serie['to_number'],
                'valid_from' => $pais['perfil']['effective_from'],
                'valid_to' => null,
                'credential_ref' => $serie['credential_ref'],
                'control_identifier' => $serie['control_identifier'],
                'sequence_length' => $serie['sequence_length'],
                'created_at' => $now,
                'updated_at' => $now,
            ];

            $existente = DB::table('fiscal_series')
                ->where('fiscal_profile_id', $perfilId)
                ->where('store_id', $tienda)
                ->where('series_role', $serie['series_role'])
                ->value('id');

            if ($existente === null) {
                DB::table('fiscal_series')->insert($fila);

                continue;
            }

            // El CONTADOR NO SE TOCA: `next_number` es el estado de la
            // numeracion, y reiniciarlo repartiria numeros ya emitidos.
            DB::table('fiscal_series')->where('id', $existente)->update([
                'provider_id' => $fila['provider_id'],
                'assigned_by' => $fila['assigned_by'],
                'prefix' => $fila['prefix'],
                'from_number' => $fila['from_number'],
                'to_number' => $fila['to_number'],
                'valid_from' => $fila['valid_from'],
                'valid_to' => $fila['valid_to'],
                'credential_ref' => $fila['credential_ref'],
                'control_identifier' => $fila['control_identifier'],
                'sequence_length' => $fila['sequence_length'],
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Los CINCO PERFILES, con sus satelites.
     *
     * @return list<array<string, mixed>>
     */
    private function perfiles(): array
    {
        return [
            $this->perfilVenezolano(),
            $this->perfilChileno(),
            $this->perfilMexicano(),
            $this->perfilColombiano(),
            $this->perfilPanameno(),
        ];
    }

    /**
     * El perfil VENEZOLANO: el unico con la providencia transcrita.
     *
     * @return array<string, mixed>
     */
    private function perfilVenezolano(): array
    {
        return [
            'iso_code' => 'VE',
            'moneda' => 'VES',
            'perfil' => [
                'code' => 'VE_MEDIOS_DIGITALES',
                'name' => 'Medios digitales (SENIAT)',
                'document_standard' => 'seniat_means',
                // El Manual Tecnico del art. 41 no se ha localizado: la
                // version del esquema queda a NULL hasta transcribirlo.
                'schema_version' => null,
                'schema_reference_url' => null,
                'xml_namespace' => null,
                'xsd_url' => null,
                'id_scheme_code' => 'VE.RIF',
                'authority_reference_scheme' => 'VE.PENDING',
                'numbering_scheme' => 'serie_emisor+control',
                'requires_control_number' => true,
                'authorized_range_required' => true,
                'holes_allowed' => false,
                'signer_model' => 'printer',
                'hash_algorithm' => null,
                // El regimen de medios digitales vigente (G.O. 43.032 del
                // 19/12/2024) y, durante el solapamiento, el nuevo.
                'effective_from' => '2024-12-19',
            ],
            'exemption_marker' => '(E)',
            'proveedor' => [
                'code' => 'imprenta_digital',
                'name' => 'Imprenta digital autorizada',
                'kind' => 'printer',
            ],
            'series' => [
                [
                    'series_role' => 'document',
                    'assigned_by' => 'issuer',
                    'prefix' => 'serie-001',
                    'from_number' => 1,
                    'to_number' => 999999,
                    'control_identifier' => null,
                    'sequence_length' => null,
                    'credential_ref' => 'Numeracion propia del emisor (SNAT/2026/00093 art. 8.2); rango de DEMOSTRACION, sin autorizacion real',
                ],
                [
                    'series_role' => 'control',
                    'assigned_by' => 'printer',
                    'prefix' => null,
                    'from_number' => 1,
                    'to_number' => 99999999,
                    // La primera asignacion empieza en '00' con el secuencial
                    // 1 (arts. 8.3, 9.1 y 27).
                    'control_identifier' => '00',
                    'sequence_length' => 8,
                    'credential_ref' => 'Rango de control de DEMOSTRACION (sin autorizacion real); referencia: SNAT/2026/00093 art. 42.1 (G.O. 43.484)',
                ],
            ],
            'campos' => [
                [
                    'key' => 'series_prefix_required_token',
                    'data_type' => 'string',
                    'label' => 'Token obligatorio del prefijo de serie',
                    'value' => 'serie',
                    'required' => true,
                    'applies_to' => 'document',
                    'evidence_status' => 'verified',
                    'source_ref' => 'SNAT/2026/00093 art. 20 (G.O. 43.484)',
                ],
                [
                    'key' => 'formal_contributor_phrase',
                    'data_type' => 'string',
                    'label' => 'Frase del contribuyente formal del IVA',
                    'value' => 'Contribuyentes Formales del IVA',
                    'required' => false,
                    'applies_to' => 'document',
                    'evidence_status' => 'verified',
                    'source_ref' => 'SNAT/2026/00093 art. 8.7 (G.O. 43.484)',
                ],
                [
                    'key' => 'exemption_marker',
                    'data_type' => 'string',
                    'label' => 'Marcador del bien exento, exonerado o no gravado',
                    'value' => '(E)',
                    'required' => false,
                    'applies_to' => 'line',
                    'evidence_status' => 'verified',
                    'source_ref' => 'SNAT/2026/00093 art. 8.7 (G.O. 43.484)',
                ],
            ],
            'categorias' => [
                [
                    'code' => 'gravable',
                    'name' => 'Gravable',
                    'is_withholding' => false,
                    'evidence_status' => 'pending_confirmation',
                    'source_ref' => 'SNAT/2026/00093 art. 8.9 (G.O. 43.484); alicuota: G.O. 5.363/1999',
                ],
                [
                    'code' => 'exento',
                    'name' => 'Exento',
                    'is_withholding' => false,
                    'evidence_status' => 'pending_confirmation',
                    'source_ref' => 'SNAT/2026/00093 art. 8.7 (G.O. 43.484); alicuota: G.O. 5.363/1999',
                ],
                [
                    'code' => 'no_gravado',
                    'name' => 'No gravado',
                    'is_withholding' => false,
                    'evidence_status' => 'pending_confirmation',
                    'source_ref' => 'SNAT/2026/00093 art. 8.7 (G.O. 43.484); alicuota: G.O. 5.363/1999',
                ],
                [
                    'code' => 'exonerado',
                    'name' => 'Exonerado',
                    'is_withholding' => false,
                    'evidence_status' => 'pending_confirmation',
                    'source_ref' => 'SNAT/2026/00093 art. 8.7 (G.O. 43.484); alicuota: G.O. 5.363/1999',
                ],
                [
                    'code' => 'iva_retenido',
                    'name' => 'IVA retenido',
                    'is_withholding' => true,
                    'evidence_status' => 'pending_confirmation',
                    'source_ref' => 'SNAT/2026/00093 art. 18 (G.O. 43.484); retencion: G.O. 5.363/1999',
                ],
            ],
            'tasas' => [
                [
                    'categoria' => 'gravable',
                    'percentage' => '16.000000',
                    'valid_from' => '1999-11-25',
                    'priority' => 0,
                    'evidence_status' => 'pending_confirmation',
                    'source_ref' => 'G.O. 5.363/1999',
                ],
                [
                    'categoria' => 'exento',
                    'percentage' => '0.000000',
                    'valid_from' => '1999-11-25',
                    'priority' => 0,
                    'evidence_status' => 'pending_confirmation',
                    'source_ref' => 'G.O. 5.363/1999',
                ],
                [
                    'categoria' => 'no_gravado',
                    'percentage' => '0.000000',
                    'valid_from' => '1999-11-25',
                    'priority' => 0,
                    'evidence_status' => 'pending_confirmation',
                    'source_ref' => 'G.O. 5.363/1999',
                ],
                [
                    'categoria' => 'exonerado',
                    'percentage' => '0.000000',
                    'valid_from' => '1999-11-25',
                    'priority' => 0,
                    'evidence_status' => 'pending_confirmation',
                    'source_ref' => 'G.O. 5.363/1999',
                ],
            ],
        ];
    }

    /**
     * El perfil CHILENO: el DTE del SII.
     *
     * @return array<string, mixed>
     */
    private function perfilChileno(): array
    {
        return [
            'iso_code' => 'CL',
            'moneda' => 'CLP',
            'perfil' => [
                'code' => 'CL_DTE',
                'name' => 'Documento Tributario Electrónico (DTE)',
                'document_standard' => 'sii_dte',
                // '1.0' es lo transcrito; '2.2' era la inercia que el research
                // descarta (O3).
                'schema_version' => '1.0',
                'schema_reference_url' => 'https://www.sii.cl/factura_electronica/factura_mercado/formato_dte_202602.pdf',
                'xml_namespace' => 'http://www.sii.cl/SiiDte',
                'xsd_url' => null,
                'id_scheme_code' => 'CL.RUT',
                'authority_reference_scheme' => 'CL.TED',
                'numbering_scheme' => 'tipo_folio_caf',
                'requires_control_number' => false,
                'authorized_range_required' => true,
                'holes_allowed' => false,
                'signer_model' => 'authority',
                'hash_algorithm' => null,
                'effective_from' => null,
            ],
            'exemption_marker' => null,
            'proveedor' => null,
            'series' => [
                [
                    'series_role' => 'document',
                    'assigned_by' => 'issuer',
                    'prefix' => '33',
                    'from_number' => 1,
                    'to_number' => 999999,
                    'control_identifier' => null,
                    'sequence_length' => null,
                    'credential_ref' => 'CAF de DEMOSTRACION (sin autorizacion real)',
                ],
                [
                    'series_role' => 'control',
                    'assigned_by' => 'authority',
                    'prefix' => null,
                    'from_number' => 1,
                    'to_number' => 999999,
                    'control_identifier' => null,
                    'sequence_length' => null,
                    'credential_ref' => 'CAF de DEMOSTRACION (sin autorizacion real); referencia: SII (DTE)',
                ],
            ],
            'campos' => [],
            'categorias' => [],
            'tasas' => [],
        ];
    }

    /**
     * El perfil MEXICANO: el CFDI 4.0 del SAT.
     *
     * @return array<string, mixed>
     */
    private function perfilMexicano(): array
    {
        return [
            'iso_code' => 'MX',
            'moneda' => 'MXN',
            'perfil' => [
                'code' => 'MX_CFDI40',
                'name' => 'Comprobante Fiscal Digital por Internet 4.0 (CFDI)',
                'document_standard' => 'sat_cfdi_4_0',
                'schema_version' => '4.0',
                'schema_reference_url' => null,
                'xml_namespace' => 'http://www.sat.gob.mx/cfd/4',
                'xsd_url' => 'http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd',
                'id_scheme_code' => 'MX.RFC',
                'authority_reference_scheme' => 'MX.UUID',
                'numbering_scheme' => 'serie_folio',
                'requires_control_number' => false,
                // El rango autorizado del SAT no esta verificado.
                'authorized_range_required' => false,
                // El motivo de cancelacion '01' exige sustituto: los huecos no
                // son reutilizables.
                'holes_allowed' => false,
                'signer_model' => 'psp',
                'hash_algorithm' => null,
                'effective_from' => null,
            ],
            'exemption_marker' => null,
            'proveedor' => null,
            'series' => [
                [
                    'series_role' => 'document',
                    'assigned_by' => 'issuer',
                    'prefix' => 'NMX',
                    'from_number' => 1,
                    'to_number' => 999999,
                    'control_identifier' => null,
                    'sequence_length' => null,
                    'credential_ref' => 'Folio de DEMOSTRACION (sin autorizacion real)',
                ],
                [
                    'series_role' => 'control',
                    'assigned_by' => 'authority',
                    'prefix' => null,
                    'from_number' => 1,
                    'to_number' => 999999,
                    'control_identifier' => null,
                    'sequence_length' => null,
                    'credential_ref' => 'Folio de DEMOSTRACION (sin autorizacion real); referencia: SAT (folios)',
                ],
            ],
            'campos' => [],
            'categorias' => [],
            'tasas' => [],
        ];
    }

    /**
     * El perfil COLOMBIANO: el DIAN 2.1 sobre UBL 2.1.
     *
     * @return array<string, mixed>
     */
    private function perfilColombiano(): array
    {
        return [
            'iso_code' => 'CO',
            'moneda' => 'COP',
            'perfil' => [
                'code' => 'CO_DIAN21',
                'name' => 'Factura electronica DIAN 2.1 (UBL 2.1)',
                'document_standard' => 'dian_ubl_2_1',
                'schema_version' => '2.1',
                'schema_reference_url' => null,
                // Las URIs del namespace estan pendiente de transcripcion.
                'xml_namespace' => null,
                'xsd_url' => null,
                'id_scheme_code' => 'CO.NIT',
                'authority_reference_scheme' => 'CO.CUFE',
                'numbering_scheme' => 'prefijo_rango',
                'requires_control_number' => false,
                'authorized_range_required' => true,
                // El tratamiento de los huecos no esta verificado: queda el
                // valor por defecto del esquema.
                'holes_allowed' => true,
                'signer_model' => 'authority',
                'hash_algorithm' => 'SHA-256',
                'effective_from' => null,
            ],
            'exemption_marker' => null,
            'proveedor' => null,
            'series' => [
                [
                    'series_role' => 'document',
                    'assigned_by' => 'issuer',
                    'prefix' => 'CFE',
                    'from_number' => 1,
                    'to_number' => 999999,
                    'control_identifier' => null,
                    'sequence_length' => null,
                    'credential_ref' => 'Resolucion DIAN de DEMOSTRACION (sin autorizacion real)',
                ],
                [
                    'series_role' => 'control',
                    'assigned_by' => 'authority',
                    'prefix' => null,
                    'from_number' => 1,
                    'to_number' => 999999,
                    'control_identifier' => null,
                    'sequence_length' => null,
                    'credential_ref' => 'Resolucion DIAN de DEMOSTRACION (sin autorizacion real); referencia: DIAN (resolucion)',
                ],
            ],
            'campos' => [],
            'categorias' => [],
            'tasas' => [],
        ];
    }

    /**
     * El perfil PANAMENO: la factura electronica de la DGI.
     *
     * Es SEMBRABLE EN ESTRUCTURA, NO EN DETALLE: los namespace, los XSD, el
     * formato del RUC, los codigos de estado y la estructura del CUFE quedan a
     * NULL hasta transcribir la Ficha Tecnica (research 5.7).
     *
     * @return array<string, mixed>
     */
    private function perfilPanameno(): array
    {
        return [
            'iso_code' => 'PA',
            'moneda' => 'PAB',
            'perfil' => [
                'code' => 'PA_FE',
                'name' => 'Factura electronica de Panama (FEPA)',
                'document_standard' => 'dgi_fepa',
                'schema_version' => '1.10',
                'schema_reference_url' => null,
                'xml_namespace' => null,
                'xsd_url' => null,
                'id_scheme_code' => 'PA.RUC',
                'authority_reference_scheme' => 'PA.CUFE',
                'numbering_scheme' => 'secuencial_cufe',
                'requires_control_number' => false,
                // El rango autorizado no esta transcrito.
                'authorized_range_required' => false,
                'holes_allowed' => true,
                // El sello lo aporta el marco legal de firma electronica
                // (Ley 51), no la autoridad ni un tercero pagador.
                'signer_model' => 'legal_signature',
                'hash_algorithm' => null,
                'effective_from' => null,
            ],
            'exemption_marker' => null,
            'proveedor' => null,
            'series' => [
                [
                    'series_role' => 'document',
                    'assigned_by' => 'issuer',
                    'prefix' => 'FE',
                    'from_number' => 1,
                    'to_number' => 999999,
                    'control_identifier' => null,
                    'sequence_length' => null,
                    'credential_ref' => 'Rango de DEMOSTRACION (sin autorizacion real)',
                ],
                [
                    'series_role' => 'control',
                    'assigned_by' => 'psp',
                    'prefix' => null,
                    'from_number' => 1,
                    'to_number' => 999999,
                    'control_identifier' => null,
                    'sequence_length' => null,
                    'credential_ref' => 'Rango de DEMOSTRACION (sin autorizacion real); referencia: DGI / PAC',
                ],
            ],
            'campos' => [],
            'categorias' => [],
            'tasas' => [],
        ];
    }

    /**
     * Los CUATRO TIPOS DE DOCUMENTO, comunes a los cinco perfiles.
     *
     * @return list<array<string, mixed>>
     */
    private function tiposDeDocumentoCatalogo(): array
    {
        return [
            [
                'code' => 'invoice',
                'name' => 'Factura',
                'direction' => 'sale',
                'tax_treatment' => 'taxable',
                'allows_cancellation' => true,
                'requires_parent_reference' => false,
            ],
            [
                'code' => 'credit_note',
                'name' => 'Nota de credito',
                'direction' => 'credit_note',
                'tax_treatment' => 'taxable',
                'allows_cancellation' => false,
                'requires_parent_reference' => true,
            ],
            [
                'code' => 'debit_note',
                'name' => 'Nota de debito',
                'direction' => 'debit_note',
                'tax_treatment' => 'taxable',
                'allows_cancellation' => false,
                'requires_parent_reference' => true,
            ],
            [
                'code' => 'retention_receipt',
                'name' => 'Comprobante de retencion',
                'direction' => 'sale',
                'tax_treatment' => 'taxable',
                'allows_cancellation' => false,
                'requires_parent_reference' => true,
            ],
        ];
    }

    /**
     * El VOCABULARIO DE ESTADOS generico.
     *
     * El codigo NATIVO de cada pais no esta transcrito (Chile `E`/`A`/`R`/`X`,
     * Mexico `Estado` + `EsCancelable` + `EstatusCancelacion`, Colombia
     * `IsValid` + avisos + RADIAN, Venezuela documentos de correccion, Panama
     * documento de anulacion): sembrarlo de memoria seria inventar un
     * vocabulario. Las filas mapean el estado generico a si mismo y el
     * `native_label` lo declara, para que el mapeo exista sin afirmar nada.
     *
     * @return list<array<string, mixed>>
     */
    private function vocabularioDeEstadosCatalogo(): array
    {
        return [
            [
                'native_code' => 'draft',
                'native_label' => 'Borrador (estado generico; el codigo nativo del pais esta pendiente de transcripcion)',
                'generic_status' => 'draft',
                'is_final' => false,
                'is_cancellation' => false,
            ],
            [
                'native_code' => 'issued',
                'native_label' => 'Emitido (estado generico; el codigo nativo del pais esta pendiente de transcripcion)',
                'generic_status' => 'issued',
                'is_final' => false,
                'is_cancellation' => false,
            ],
            [
                'native_code' => 'void',
                'native_label' => 'Anulado (estado generico; el codigo nativo del pais esta pendiente de transcripcion)',
                'generic_status' => 'void',
                'is_final' => true,
                'is_cancellation' => true,
            ],
        ];
    }
}
