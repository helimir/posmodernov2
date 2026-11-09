<?php

namespace Tests\Support;

/**
 * Los NOMBRES CANONICOS de la suite: el test es la documentacion de la regla
 * (design §E.5, §E.2 y la spec `calidad-continua` §"Cada test nombra la regla
 * de negocio que fija").
 *
 * Este fichero es la COPIA DE TRABAJO del design: los 44 nombres de §E.5 (un
 * test por requisito) y los 6 transversales de §E.2. El barrido
 * (`tests/Architecture/CanonicalTestNamesTest.php`) comprueba que cada uno
 * exista EXACTAMENTE una vez en la suite.
 *
 * Vive aqui y no en el test que barre porque un nombre canonico escrito en el
 * propio barrido se contaria a si mismo: la lista tiene que estar FUERA del
 * arbol que se cuenta.
 */
final class CanonicalTestNames
{
    /**
     * Los 44 nombres canonicos de §E.5: un test por requisito.
     *
     * @return array<int, string>
     */
    public static function canonicos(): array
    {
        return [
            'test_el_esquema_destino_se_construye_solo_por_migraciones_sin_float_ni_blobs_php',
            'test_los_saldos_y_totales_se_derivan_y_los_catalogos_no_exponen_banderas_contables',
            'test_un_fallo_intermedio_no_deja_cabecera_ni_cambio_de_stock_persistidos',
            'test_ningun_literal_de_pais_sobrevive_en_app_domain',
            'test_un_pais_nuevo_se_anade_sembrando_filas_sin_tocar_el_motor_de_impuestos',
            'test_la_emision_atraviesa_un_actor_intermedio_que_asigna_el_control_antes_de_consolidar',
            'test_cada_pais_guarda_su_referencia_como_valor_y_esquema_sin_columna_uuid',
            'test_la_tasa_de_iva_sembrada_figura_pendiente_de_confirmacion_con_su_fuente',
            'test_el_comprobante_renderiza_base_e_iva_por_alicuota_con_el_marcador_exento',
            'test_la_nota_referencia_fecha_correlativo_control_y_monto_del_original',
            'test_la_anulacion_deja_evento_y_no_altera_el_evento_de_emision',
            'test_el_papel_de_contingencia_se_concilia_en_pdf_al_dia_siguiente',
            'test_un_documento_reserva_un_numero_de_documento_y_otro_de_control_en_reservas_distintas',
            'test_la_anulacion_consuma_el_correlativo_y_la_siguiente_reserva_devuelve_el_siguiente',
            'test_la_primera_asignacion_de_control_usa_identificador_00_y_secuencial_1',
            'test_la_consolidacion_se_rechaza_si_el_control_no_corresponde_al_perfil',
            'test_la_reserva_bloqueada_falla_con_sqlstate_55p03_y_el_rollback_devuelve_el_numero',
            'test_el_mismo_motor_calcula_el_resultado_de_dos_perfiles_sin_ramificar_por_pais',
            'test_un_documento_emitido_conserva_la_tasa_la_categoria_y_el_estado_de_evidencia_congelados',
            'test_la_suma_de_lineas_mas_el_ajuste_de_redondeo_iguala_el_total_redondeado',
            'test_el_iva_retenido_se_emite_como_documento_propio_y_no_como_linea_de_la_venta',
            'test_la_disponibilidad_del_lote_es_la_aritmetica_recibido_menos_consumido_mas_devuelto',
            'test_el_consumo_toma_el_lote_mas_antiguo_y_registra_un_consumo_por_lote',
            'test_la_devolucion_repone_el_lote_exacto_del_que_salio_la_linea',
            'test_el_stock_en_cache_iguala_la_suma_de_lotes_y_no_se_sobrevende_bajo_concurrencia',
            'test_el_agente_recibe_bytes_ya_generados_y_no_interpreta_el_ticket',
            'test_el_agente_trae_trabajos_desde_la_lan_privada_y_la_tienda_se_resuelve_desde_el_agent_id',
            'test_un_destino_usb_directo_se_rechaza_y_exige_tcp_9100_o_impresora_compartida',
            'test_un_qr_sobre_un_perfil_sin_soporte_falla_en_el_servidor_antes_de_enviarlo',
            'test_reimprimir_crea_un_trabajo_nuevo_sin_consumir_correlativo_ni_alterar_el_documento',
            'test_un_lote_firmado_con_los_vectores_dorados_se_acepta_con_la_misma_respuesta',
            'test_la_traduccion_resuelve_tabla_y_columnas_contra_el_mapa_sembrado',
            'test_un_reenvio_identico_responde_exito_sin_aplicar_el_lote_dos_veces',
            'test_el_terminal_reenvia_tras_perder_la_respuesta_y_el_maestro_lo_acepta_sin_congelarse',
            'test_cada_movimiento_contable_tiene_un_unico_entry_type_y_ninguna_bandera_dispersa',
            'test_un_gasto_sin_movimiento_bancario_sigue_siendo_gasto_con_su_asiento',
            'test_el_movimiento_cerca_del_cambio_de_dia_queda_en_el_registro_de_caja_de_su_fecha_de_negocio',
            'test_el_saldo_de_cuenta_se_deriva_de_los_asientos_y_no_de_un_contador_almacenado',
            'test_el_arranque_operativo_queda_bloqueado_hasta_que_exista_un_saldo_certificado',
            'test_la_puerta_de_calidad_declara_typecheck_y_build_como_etapas_del_gate',
            'test_la_suite_del_dominio_cubre_checkout_fifo_impuestos_reserva_fiscal_cuotas_caja_y_devolucion',
            'test_cada_test_de_la_suite_declara_la_regla_de_negocio_que_fija',
            'test_el_repositorio_contiene_los_seis_adr_y_el_readme_de_arranque',
            'test_la_suite_no_deja_fallos_preexistentes_sin_aislar',
        ];
    }

    /**
     * Los 6 tests transversales de §E.2: no son trazables a un requisito
     * individual, pero los exige `calidad-continua` §"Suite Pest del dominio".
     *
     * @return array<int, string>
     */
    public static function transversales(): array
    {
        return [
            'test_un_producto_de_servicio_no_genera_lote_ni_consume_stock',
            'test_un_cobro_reduce_la_deuda_del_cliente_y_el_saldo_se_deriva_de_sus_movimientos',
            'test_la_cuota_se_calcula_en_el_servidor_con_interes_simple_sobre_el_payable_y_se_reparte_entre_cuotas',
            'test_la_transferencia_mueve_el_stock_desde_el_lote_origen_y_registra_una_sola_cabecera',
            'test_el_saldo_de_caja_por_fecha_de_negocio_coincide_con_los_movimientos_del_periodo',
            'test_un_documento_multipagina_conserva_el_mismo_numero_de_documento_y_de_control',
        ];
    }

    /**
     * Los 50 nombres que la suite debe llevar: los 44 canonicos y los 6
     * transversales.
     *
     * @return array<int, string>
     */
    public static function todos(): array
    {
        return array_merge(self::canonicos(), self::transversales());
    }
}
