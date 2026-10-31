# Tasks: Fase 0 — Fundaciones

> Contrato: `openspec/config.yaml → rules.tasks` (agrupar por fase · numeración jerárquica · cada tarea en una sesión · el test va junto a la implementación).
> Entrada: `openspec/changes/fase0-fundaciones/proposal.md` (S1–S10) · `specs/*/spec.md` (44 requisitos) · `design.md` (878 líneas) · `research/evidence/` · `docs/adr/0001…0006-*.md`.
> TDD estricto (`strict_tdd: true`): cada `-T` se escribe **primero en rojo**, luego la implementación lo pone en verde. Runner: `./vendor/bin/pest` sobre PostgreSQL 17 (`pos_moderno_v2_test`). Precondición: `npm ci && npm run build` ⇒ `public/build/manifest.json` debe existir.
> Convención de ids: `0.F.N` = implementación · `0.F.N-T` = test gemelo (id propio, con el nombre del test y la regla que fija).
> Rutas marcadas `(read-only)` son referencia: **no** se editan.

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | **~6.740** (rango 6.000–7.500) |
| 400-line budget risk | **High** |
| Chained PRs recommended | **Yes** |
| Suggested split | PR1 cimientos → PR2 capa país/fiscal → PR3 numeración+documentos fiscales → PR4 catálogo/ventas/compras → PR5 stock por lotes → PR6 impuestos+checkout → PR7 contabilidad/caja → PR8 impresión+sync → PR9 seed+suite+CI+docs |
| Delivery strategy | `ask-on-risk` |
| Chain strategy | `pending` |

Decision needed before apply: Yes
Chained PRs recommended: Yes
Chain strategy: pending
400-line budget risk: High

> **El cambio es ~17× el presupuesto de revisión de 400 líneas.** No es un PR: son 9 work units encadenadas. El equipo debe fijar la estrategia de cadena (`stacked-to-main` · `feature-branch-chain` · `size:exception`) antes de `sdd-apply`; por eso `Decision needed before apply: Yes`.

### Desglose de líneas estimadas

| Bloque | Contenido | Líneas |
|---|---|---|
| 27 migraciones | esquema destino: 109 tablas de negocio | ~1.400 |
| 6 seeders | perfil VE + CL/MX/CO/PA, taxonomías, tipos de asiento, mapa sync, saldos | ~450 |
| ~28 modelos Eloquent | casts exactos, relaciones, FKs | ~980 |
| `app/Domain/**` | Tax (6) · Fiscal (8) · Inventory (3) · Accounting (1) · Checkout (2) | ~1.100 |
| Impresión + Sync | renderer ESC/POS, controller, jobs, adaptador v2 | ~700 |
| Config + CI | `config/database.php`, 3 configs, `.env.example`, workflow | ~120 |
| Suite Pest | 44 trazables + 6 transversales + 1 de pureza + T1–T3 | ~1.900 |
| README + verificación ADR | arranque y trazabilidad | ~90 |
| **Total** | | **~6.740** |

### Suggested Work Units

| Unit | Goal | Likely PR | Focused test command | Runtime harness | Rollback boundary |
|---|---|---|---|---|---|
| 1 | Cimientos: deps, `pgsql_b`, interruptores, M04–M07 + M25–M26, modelos base | PR 1 | `./vendor/bin/pest --filter="Store\|Setting"` | `php artisan migrate:fresh --seed` sobre PostgreSQL 17 | PR 1 completo revertible; sin datos de producción |
| 2 | Capa de país/fiscal: M01–M03, contratos, enums, modelos | PR 2 | `./vendor/bin/pest --filter="FiscalProfile\|TaxCategory"` | `php artisan migrate:fresh --seed` + `php artisan tinker` → `TaxRate::where('evidence_status','pending_confirmation')` | PR 2 revertible; catálogo sembrado en PR 9 |
| 3 | Numeración fiscal + documentos fiscales: M21–M22, `NumberSeries`, `FiscalIssuanceService`, T1–T3 | PR 3 | `./vendor/bin/pest tests/Feature/Fiscal` | `php artisan tinker` → 2 reservas seguidas + `psql -c "select next_number from fiscal_series"` | **NUNCA** borrar `fiscal_document_reservations`; rollback = apagar `fiscal.emission_enabled` |
| 4 | Catálogo, clientes, compras y ventas: M08–M13 + modelos | PR 4 | `./vendor/bin/pest --filter="Product\|Sale\|Purchase"` | `php artisan migrate:fresh --seed` | PR 4 revertible |
| 5 | Stock por lotes: M14–M17, `LotConsumer`, `LotReturner`, `StockInvariant` | PR 5 | `./vendor/bin/pest tests/Feature/Inventory` | `php artisan tinker` → venta FIFO sobre 2 lotes | PR 5 revertible; sin `status` mutable ⇒ nada que desincronizar |
| 6 | Motor de impuestos + checkout: `TaxCalculator`, T1 dominio, reintento T2 | PR 6 | `./vendor/bin/pest tests/Unit/Tax tests/Feature/Checkout` | `php artisan tinker` → `CheckoutService` con perfil VE | PR 6 revertible; `sales.status='fiscal_failed'` se reconcilia por reintento |
| 7 | Contabilidad y caja: M18–M20, `LedgerWriter`, `business_date`, saldos | PR 7 | `./vendor/bin/pest tests/Feature/Accounting` | `php artisan ops:check-opening-balances` (debe fallar sin saldos certificados) | PR 7 revertible |
| 8 | Impresión LAN + sync legacy: M23–M24, renderer, controller, adaptador v2 | PR 8 | `./vendor/bin/pest tests/Feature/Print tests/Feature/Sync` | `POST /api/print-agents/register` + `GET /api/print-agents/jobs` con `curl` | Interruptores `print.lan_agent_enabled` / `sync.legacy_adapter_enabled` en `false` |
| 9 | Seed, suite, CI y documentación: M27, 6 seeders, 51 tests, workflow, README | PR 9 | `./vendor/bin/pest` | `php artisan migrate:fresh --seed` + push a `develop` con PostgreSQL 17 en CI | Sin impacto en runtime |

**Base de la cadena**: PR 1 → `develop`; PR 2..9 → PR anterior (feature-branch-chain) o `develop` en orden (stacked-to-main). Cada child PR debe mostrar **sólo** su diff.

---

## Fase 0.1 — Cimientos del proyecto y dominio base

- [ ] **0.1.1** Dependencias de dominio: añadir `brick/math` (`BigDecimal`, D10 — decimal exacto en PHP puro, sin `ext-bcmath` ni `float`) y `mike42/escpos-php` (ADR-0005 — bytes ESC/POS en el servidor) a `composer.json`; regenerar `composer.lock`. Dep: —. Aceptación: `composer show brick/math mike42/escpos-php` resuelve y `composer install` no falla.
- [ ] **0.1.1-T** Test: `test_las_dependencias_de_decimal_exacto_y_de_impresion_estan_instaladas` — regla que fija: *el importe se calcula con decimal exacto y el ESC/POS se genera en el servidor, no en el agente*.
- [ ] **0.1.2** Segunda conexión de pruebas: añadir `pgsql_b` a `config/database.php` con los mismos parámetros que `pgsql` y `DB_DATABASE=pos_moderno_v2_test` (§E.1). Sin esta conexión T2/T3 no reproducen el bloqueo. Dep: 0.1.1. Aceptación: `DB::connection('pgsql_b')` abre contra la misma base que `pgsql`.
- [ ] **0.1.2-T** Test: `test_la_segunda_conexion_pgsql_b_apunta_a_la_misma_base_de_pruebas` — regla que fija: *el bloqueo de fila se prueba con dos sesiones PostgreSQL distintas sobre la misma base*.
- [ ] **0.1.3** Interruptores de módulo: crear `config/fiscal.php` (`emission_enabled`), `config/sync.php` (`legacy_adapter_enabled`), `config/print.php` (`lan_agent_enabled`), los tres en `false` por defecto, y declararlos en `.env.example`. Dep: —. Aceptación: `config('fiscal.emission_enabled') === false` y los tres son `false` en la config por defecto.
- [ ] **0.1.3-T** Test: `test_los_interruptores_de_modulo_estan_apagados_por_defecto` — regla que fija: *ningún módulo emite, sincroniza ni imprime hasta ser validado y activado*.
- [ ] **0.1.4** Migraciones **M04** `2026_11_01_000004_create_stores_table.php` (`stores` sin `preference` → `store_settings` tipada, `country_id` FK, `vat_reg_no` → `store_fiscal_registrations`) y **M05** `2026_11_01_000005_create_roles_permissions_tables.php` (`user_group.permission` (blob) → `permissions` + `role_permissions`; `shortcut_links.permission_slug` → FK; scopes por tienda). Dep: 0.1.1. Aceptación: `down()` en ambas; `stores` sin columna blob; `permissions` normalizada.
- [ ] **0.1.4-T** Test: `test_las_tablas_de_tienda_y_de_permisos_no_guardan_blobs_php_serializados` — regla que fija: *la configuración por tienda y los permisos son filas, no blobs*.
- [ ] **0.1.5** Migraciones **M06** `2026_11_01_000006_edit_users_table_add_user_settings.php` (edita `0001_01_01_000000_create_users_table.php`: `password` argon2id/bcrypt — el legacy guarda MD5—, se elimina `raw_password` y `preference` → `user_settings`, `group_id` → FK `roles.id`, `pass_reset_code`/`reset_code_time` → `password_reset_tokens`) y **M07** `2026_11_01_000007_create_settings_table.php` (ajustes globales tipados `key`/`value`/`type`, sustituyen el KV libre). Dep: 0.1.4. Aceptación: `migrate:fresh` en verde; `users.password` no es MD5; `users.raw_password` no existe.
- [ ] **0.1.5-T** Test: `test_las_contrasenas_se_guardan_con_hash_y_las_preferencias_son_tablas_tipadas` — regla que fija: *la contraseña nunca se guarda en claro ni como MD5, y las preferencias no son un blob*.
- [ ] **0.1.6** Migraciones **M25** `2026_11_01_000025_create_languages_and_messages_table.php` (`languages`, `language_translations`, `sms_messages` como outbox con `status`/`sent_at`, `message_templates`, `sms_providers` con `credential_env_prefix`) y **M26** `2026_11_01_000026_create_audit_logs_table.php` (auditoría de negocio, distinta de la fiscal append-only). Dep: 0.1.1. Aceptación: `sms_providers` no tiene columna de credencial en claro.
- [ ] **0.1.6-T** Test: `test_las_credenciales_de_proveedor_se_guardan_como_referencia_de_entorno_y_no_en_claro` — regla que fija: *ninguna credencial de proveedor vive en la base de datos*.
- [ ] **0.1.7** Modelos Eloquent base con casts exactos y relaciones con FK: `app/Models/Store.php`, `StoreSetting.php`, `StoreFiscalRegistration.php`, `Role.php`, `Permission.php`, `Setting.php`, `UserSetting.php`, `AuditLog.php`, `Language.php`, `LanguageTranslation.php`, `SmsMessage.php`, `SmsProvider.php`, `MessageTemplate.php`; modificar `app/Models/User.php` (hash, sin `raw_password`/`preference`, `group_id` → `roles`). Dep: 0.1.4–0.1.6. Aceptación: los importes se castean a `decimal:4`, el JSON a `array`, las fechas a `date`/`datetime`; cada FK declara su relación.
- [ ] **0.1.7-T** Test: `test_los_modelos_declaran_sus_casts_de_importes_y_de_json` — regla que fija: *un importe se lee como decimal exacto y un JSON como estructura, nunca como texto*.

---

## Fase 0.2 — Capa de país y fiscal

- [ ] **0.2.1** Migración **M01** `2026_11_01_000001_create_countries_currencies_number_formats_table.php`: `countries`, `currencies` (`decimals` + separadores), `number_formats` (formato de número, de fecha y **formato del correlativo** `000-00000000`, P6). Dep: 0.1.4. Aceptación: `down()` presente; los formatos son **datos**, no constantes en código.
- [ ] **0.2.1-T** Test: `test_el_formato_del_correlativo_es_un_dato_sembrado_y_no_una_constante_de_codigo` — regla que fija: *el formato de la numeración se cambia insertando una fila*.
- [ ] **0.2.2** Migración **M02** `2026_11_01_000002_create_fiscal_profiles_table.php`: `fiscal_profiles` (`signer_model` ∈ `authority`/`psp`/`printer`/`legal_signature`, `requires_control_number`, `holes_allowed`, `tax_inclusive_default`, `rounding_rule`, `hash_algorithm`/`signature_scheme` **sin valor por defecto**), `fiscal_profile_document_types` (`tax_treatment`, `exemption_marker`, `requires_parent_reference`), `fiscal_profile_environments` (`role` ∈ `issuer`/`printer`, `provider_id`), `fiscal_profile_fields` (catálogo de claves del `jsonb`, **incluida `series_prefix_required_token` = `serie`** — C4), `fiscal_tax_schemes`, `fiscal_status_vocabularies` (nativo → genérico). Dep: 0.2.1. Aceptación: `fiscal_documents.status` será enum genérico y `native_status_code` guarda el código nativo; **no** hay columna `uuid`.
- [ ] **0.2.2-T** Test: `test_la_referencia_de_verificacion_se_guarda_como_valor_y_esquema_y_no_como_columna_uuid` — regla que fija: *la referencia de cada país es un par valor + esquema*.
- [ ] **0.2.3** Migración **M03** `2026_11_01_000003_create_tax_categories_and_rates_table.php`: `tax_categories` (5 categorías: `gravable`, `exento`, `no_gravado`, `exonerado`, `iva_retenido`, con `is_withholding`) y `tax_rates` (vigencia, `evidence_status`, `source_ref`, `rounding_rule`). Dep: 0.2.1. Aceptación: `products.tax_category_id` referencia la **categoría**, nunca un porcentaje.
- [ ] **0.2.3-T** Test: `test_el_catalogo_de_categorias_distingue_las_cinco_categorias_de_iva` — regla que fija: *el producto apunta a una categoría fiscal, no a un porcentaje*.
- [ ] **0.2.4** Contrato del actor intermedio: crear `app/Domain/Fiscal/Contracts/ElectronicInvoiceProvider.php` con `submit()` · `assignControl()` · `issue()` · `cancel()` · `queryStatus()` · `deliver()` y los value objects `FiscalDocumentDraft`, `SubmissionReceipt`, `ControlAssignment`, `FiscalDocumentData`, `CancellationReason`, `ProviderStatus`, `DeliveryRecipient`, `DeliveryReceipt`. Dep: 0.2.2. Aceptación: `submit()` → `assignControl()` → `issue()` son **tres** pasos; `assignControl()` devuelve `?ControlAssignment` (canal portal no asigna control).
- [ ] **0.2.4-T** Test: `test_el_contrato_del_proveedor_separa_emitir_asignar_control_y_consolidar` — regla que fija: *el emisor no emite solo; hay un actor intermedio*.
- [ ] **0.2.5** Enums genéricos del dominio fiscal en `app/Domain/Fiscal/Enums/`: `ProviderKind` (3), `SignerModel` (4), `EnvironmentRole` (2), `TaxMethod`, `RoundingRule`. **Sin ningún código de país.** Dep: 0.2.4. Aceptación: grep de `app/Domain/` sin `VE`, `USD`, `Bs`, `seniat`, `RIF`.
- [ ] **0.2.5-T** Test: `test_los_enums_del_dominio_fiscal_no_contienen_ningun_codigo_de_pais` — regla que fija: *el dominio ramifica sobre enums genéricos leídos de filas, nunca sobre un país*.
- [ ] **0.2.6** Modelos de la capa: `app/Models/Country.php`, `Currency.php`, `NumberFormat.php`, `FiscalProfile.php`, `FiscalProfileDocumentType.php`, `FiscalProfileEnvironment.php`, `FiscalProfileField.php`, `FiscalTaxScheme.php`, `FiscalStatusVocabulary.php`, `TaxCategory.php`, `TaxRate.php`, `FiscalProvider.php`. Dep: 0.2.1–0.2.3. Aceptación: casts de `evidence_status`/`source_ref`/`rounding_rule`; `TaxRate` expone su `evidence_status`.
- [ ] **0.2.6-T** Test: `test_cada_tasa_sembrada_expone_su_estado_de_evidencia_y_su_fuente` — regla que fija: *un dato no verificado se marca como pendiente, no se afirma*.
- [ ] **0.2.7** `app/Domain/Tax/TaxContext.php`: contenedor de datos resueltos **antes** de la llamada (`roundingRule`, `taxInclusiveDefault`, `currencyDecimals`, `exemptionMarker`, `formalContributorPhrase`, `seriesPrefixRequiredToken`) — C4: el prefijo "serie" se **compara contra el valor leído**, nunca como literal. Dep: 0.2.5. Aceptación: el contexto se construye desde filas; no hay literales normativos en el dominio.
- [ ] **0.2.7-T** Test: `test_el_prefijo_de_serie_se_toma_del_perfil_y_no_del_codigo` — regla que fija: *el token normativo obligatorio es un dato, no un literal del dominio*.

---

## Fase 0.3 — Numeración fiscal y documentos fiscales

- [ ] **0.3.1** Migración **M21** `2026_11_01_000021_create_fiscal_providers_and_series_table.php`: `fiscal_providers` (`kind` ∈ `printer`/`pac`/`portal`) y `fiscal_series` (`series_role` **canónico** ∈ `document`/`control`/`retention_receipt` — `series_kind` queda como alias documentado, **sin columna**—, `assigned_by` ∈ `issuer`/`authority`/`printer`/`psp`, `prefix`, `next_number`, `from_number`, `to_number`, `valid_from`, `valid_to`, `credential_ref`, `control_identifier`, `sequence_length`) + `fiscal_document_reservations` (`series_id`, `number`, `series_role`, `reserved_by`, `reserved_at`, `voided_at`). Dep: 0.2.2, 0.2.6. Aceptación: **no** existe columna `series_kind`; la serie de control guarda el rango de la imprenta (espejo local, C3).
- [ ] **0.3.1-T** Test: `test_la_serie_se_distingue_por_rol_y_por_autoridad_y_no_por_un_alias_de_columna` — regla que fija: *hay dos numeraciones con autoridades distintas*.
- [ ] **0.3.2** Migración **M22** `2026_11_01_000022_create_fiscal_documents_table.php`: `fiscal_documents` (`status` enum genérico, `native_status_code`, `authority_reference` + `authority_reference_scheme`, `qr_content`/`qr_url` **nullable**, `hash_algorithm`/`signature_scheme` parametrizados, `voided_at`), `fiscal_document_taxes` (multinivel: `line_id` **nullable** para el nodo de cabecera), `fiscal_document_parties` (`role`: emisor/receptor/tercero), `fiscal_document_links` (notas, RADIAN, anulación), `fiscal_document_events` (**append-only**: actor, instante, payload), `fiscal_document_control_assignments` (1:1 con el documento: `control_number`, `control_range_from`, `control_range_to`, `printer_id`, `printer_rif`), `fiscal_periodic_reports`, `fiscal_contingency_forms`. Dep: 0.3.1. Aceptación: el control tiene **una única sede** (no se duplica en `fiscal_documents`); `fiscal_document_events` sin `updated_at`/`deleted_at` y con permiso de escritura bloqueado.
- [ ] **0.3.2-T** Test: `test_los_eventos_fiscales_son_append_only_y_no_admiten_borrado` — regla que fija: *toda acción de emisión, modificación o anulación queda auditada*.
- [ ] **0.3.3** `app/Domain/Fiscal/NumberSeries.php`: reserva atómica con `UPDATE fiscal_series SET next_number = next_number + 1 WHERE id = ? AND next_number <= to_number RETURNING next_number - 1` y su escritura en `fiscal_document_reservations` — **nunca** `rowCount()` ni lectura del último registro (el legacy usa `rowCount()` de un `SELECT` en `_inc/helper/invoice.php:95-109`). Dep: 0.3.1. Aceptación: el número reservado se devuelve **en la misma sentencia**; la reserva no usa `SKIP LOCKED` (a diferencia del FIFO) — espera y falla con timeout.
- [ ] **0.3.3-T** Test: `test_la_reserva_devuelve_el_numero_reservado_en_la_misma_sentencia_que_toma_el_bloqueo` — regla que fija: *la reserva es atómica por construcción, no por lectura posterior*.
- [ ] **0.3.4** `app/Domain/Fiscal/FiscalIssuanceService.php`: orquesta **T2** (`submit()` → `assignControl()` → `issue()`), persiste `fiscal_documents` + `_events` + `_control_assignments` en **una** transacción, valida la **invariante del control** (exactamente una asignación si `requires_control_number`, cero si no) **antes** de consolidar, y registra la **discrepancia de correspondencia** (C3) como evento. Dep: 0.3.2, 0.3.3. Aceptación: la llamada HTTP al proveedor ocurre **fuera** de la transacción de BD; un fallo deja `sales.status='fiscal_failed'` y **reutiliza** la reserva existente.
- [ ] **0.3.4-T** Test: `test_la_consolidacion_se_rechaza_si_el_control_no_corresponde_al_perfil` — regla que fija: *exactamente un número de control si el perfil lo exige*.
- [ ] **0.3.5** Generador del Nº de Control: primera asignación = `control_identifier='00'` + secuencial `1`, `sequence_length` de hasta 8, y registro del **rango total** `control_range_from`/`control_range_to` en el documento. Dep: 0.3.4. Aceptación: el rango queda persistido y consultable por el documento.
- [ ] **0.3.5-T** Test: `test_la_primera_asignacion_de_control_usa_identificador_00_y_secuencial_1` — regla que fija: *la primera asignación de control es `00` + `1`*.
- [ ] **0.3.6** Render del comprobante (HTML/PDF) desde el **mismo modelo de plantilla** que el ESC/POS: denominación «Factura», fecha `DDMMAAAA`, hora `HH.MM.SS` con a.m/p.m, RIF y razón social de emisor y receptor (cédula o pasaporte para persona natural), base imponible e IVA **por alícuota**, valor total (en moneda extranjera, ambas cantidades + tipo de cambio), marcador `(E)`, «Contribuyentes Formales del IVA», tipografía ≥ 6 pt y emisor/nº de control ≥ 8 pt, y **mismo nº de documento y de control en todas las páginas**. Dep: 0.3.2. Aceptación: los tokens normativos se leen de `fiscal_profile_fields`, no del código.
- [ ] **0.3.6-T** Test: `test_el_comprobante_renderiza_base_e_iva_por_alicuota_con_el_marcador_exento` — regla que fija: *el art. 8 es obligatorio*.
- [ ] **0.3.7** Documentos derivados: notas de crédito y débito (mismo medio que el original, enunciado «Nota de Crédito»/«Nota de Débito», referencia a fecha, correlativo, nº de control y monto del original vía `fiscal_document_links`) y **comprobante de retención** como documento propio (`document_type='retention_receipt'`, `series_role='retention_receipt'`, numeración `AAAAMMSSSSSSSS` de 14 caracteres, período de imposición, base imponible, impuesto causado y monto retenido). Dep: 0.3.4. Aceptación: la retención **no** es una categoría de línea de un documento de venta.
- [ ] **0.3.7-T** Test: `test_la_nota_referencia_fecha_correlativo_control_y_monto_del_original` — regla que fija: *el IVA retenido es crédito del comprador, con documento propio*.
- [ ] **0.3.8** Anulación ynumerala: el documento anulado conserva `status='void'` + `voided_at`, la reserva queda marcada con `voided_at` y **el número NO se libera** (SNAT/2026/00093 arts. 8.2, 21, 27; SNAT/2024/000102 art. 34). El rollback de una transacción fallida **sí** devuelve el número. Dep: 0.3.4. Aceptación: tras anular el correlativo 42, la siguiente reserva devuelve 43.
- [ ] **0.3.8-T** Test: `test_la_anulacion_consuma_el_correlativo_y_la_siguiente_reserva_devuelve_el_siguiente` — regla que fija: *el número consumido no se reutiliza jamás*.
- [ ] **0.3.9** Contingencia en papel (`fiscal_contingency_forms`): los formatos en papel se usan **sólo** por contingencia, ante causales tasadas, y se mantienen en el establecimiento; al cesar la contingencia los documentos físicos se registran y se remiten **en PDF al día siguiente**. Dep: 0.3.2. Aceptación: existe la conciliación D+1 con la fecha de registro y el PDF remitido.
- [ ] **0.3.9-T** Test: `test_el_papel_de_contingencia_se_concilia_en_pdf_al_dia_siguiente` — regla que fija: *el papel sólo es contingencia y se concilia a D+1*.

---

## Fase 0.4 — Catálogo, clientes, compras y ventas (esquema)

- [ ] **0.4.1** Migración **M08** `2026_11_01_000008_create_catalog_tables.php`: `products` (`tax_category_id` → FK), `product_images`, `categories`, `brands`, `boxes`, `units`, `pos_templates` (`template_content` `ucs2` → `text`/`jsonb`), `payment_methods` (`country_rules` `jsonb`). Dep: 0.2.3. Aceptación: `products` no apunta a un porcentaje de impuesto.
- [ ] **0.4.1-T** Test: `test_el_producto_apunta_a_una_categoria_fiscal_y_no_a_un_porcentaje` — regla que fija: *el tratamiento fiscal del producto es una categoría*.
- [ ] **0.4.2** Migración **M09** `2026_11_01_000009_create_bridge_tables.php`: los 12 bridges multi-tienda (`product_stores`, `customer_stores`, `supplier_stores`, `brand_stores`, `category_stores`, `box_stores`, `unit_stores`, `currency_stores`, `payment_method_stores`, `pos_template_stores`, `bank_account_stores`, `user_stores`) con FK real a `stores` + `status` + `sort_order`; `product_stores.purchase_price`/`sell_price` `float` → `numeric(25,4)`; `quantity_in_stock` cacheado; `bank_account_stores` **sin** `deposit`/`withdraw`/`transfer_*`; `user_stores` **sin** el `JOIN status=1` que en el legacy bloquea el alta en todas las tiendas. Dep: 0.4.1, 0.1.4. Aceptación: ningún `float`; ningún contador derivado en los bridges.
- [ ] **0.4.2-T** Test: `test_los_bridges_de_tienda_no_guardan_contadores_derivados` — regla que fija: *los saldos se derivan, no se cuentan dos veces*.
- [ ] **0.4.3** Migración **M10** `2026_11_01_000010_create_customers_suppliers_table.php`: `customers` (**sin** `credit_balance`, que duplica `customer_stores.balance`; `customer_country`/`customer_state` → FK; `gtin` → `tax_id`) y `suppliers`. Dep: 0.4.2. Aceptación: el saldo del cliente vive en un único sitio.
- [ ] **0.4.3-T** Test: `test_el_saldo_del_cliente_no_esta_duplicado_en_dos_tablas` — regla que fija: *una sola fuente de verdad por dato*.
- [ ] **0.4.4** Migración **M11** `2026_11_01_000011_create_sales_tables.php`: `sales` (renombra `selling_info`, con `status` para `fiscal_failed` y `fiscal_document_id`nullable), `sale_items` (renombra `selling_item`; separa `tax_rate_snapshot` de `tax_amount` — el legacy guardaba el % en `tax` y el importe en `item_tax`), `sale_totals` (renombra `selling_price`, con `rounding_adjustment`), `sale_payments` (`details` blob → `jsonb`), `sale_returns`, `sale_return_items`. Dep: 0.4.2. Aceptación: `sale_totals.rounding_adjustment` es `numeric(25,4)`.
- [ ] **0.4.4-T** Test: `test_la_linea_de_venta_separa_el_porcentaje_congelado_del_importe_del_impuesto` — regla que fija: *el impuesto se persiste, no se recalcula*.
- [ ] **0.4.5** Migración **M12** `2026_11_01_000012_create_customer_ledger_entries_table.php` (`customer_transactions` renombrado, `type` con valores canónicos, `reference_no`/`ref_invoice_id` → FK) y **M13** `2026_11_01_000013_create_purchases_tables.php` (`purchases`, `purchase_items` — se elimina `tax_method`, lo decide `fiscal_profiles.tax_inclusive_default`—, `purchase_totals`, `purchase_payments` con `invoice_id` → FK, `purchase_returns`, `purchase_return_items`). Dep: 0.4.4. Aceptación: `purchase_items` **no** tiene `tax_method`.
- [ ] **0.4.5-T** Test: `test_el_metodo_de_impuesto_de_la_compra_lo_decide_el_perfil_fiscal_y_no_la_linea` — regla que fija: *el motor fiscal es dato*.
- [ ] **0.4.6** Modelos del bloque: `app/Models/Product.php`, `ProductStore.php`, `Category.php`, `Brand.php`, `Box.php`, `Unit.php`, `PosTemplate.php`, `PaymentMethod.php`, `Customer.php`, `CustomerStore.php`, `Supplier.php`, `SupplierStore.php`, `Sale.php`, `SaleItem.php`, `SaleTotal.php`, `SalePayment.php`, `SaleReturn.php`, `SaleReturnItem.php`, `CustomerLedgerEntry.php`, `Purchase.php`, `PurchaseItem.php`, `PurchaseTotal.php`, `PurchasePayment.php`, `PurchaseReturn.php`, `PurchaseReturnItem.php`. Dep: 0.4.1–0.4.5. Aceptación: casts `decimal:4` en importes, `array` en JSON, `date` en fechas de negocio; relaciones FK declaradas.
- [ ] **0.4.6-T** Test: `test_los_modelos_de_venta_y_compra_no_usan_float_para_importes` — regla que fija: *todo importe es `numeric(25,4)`*.

---

## Fase 0.5 — Stock por lotes y soporte transaccional

- [ ] **0.5.1** Migración **M14** `2026_11_01_000014_create_inventory_lots_tables.php`: `inventory_lots` (**sin** columna `status` mutable; `available` como `GENERATED ALWAYS AS (quantity_received - quantity_consumed + quantity_returned) STORED`; `purchase_item_id` 1:1), `sale_item_lot_consumptions` (`sale_item_id`, `lot_id`, `quantity`, `unit_cost`), `transfers` (**1** cabecera por transferencia, no N como en el legacy), `transfer_items` (`unit_cost`, `source_lot_id`). Dep: 0.4.4, 0.4.5. Aceptación: `available` es GENERATED (no se puede desincronizar); no existe `status` en `inventory_lots`.
- [ ] **0.5.1-T** Test: `test_la_disponibilidad_del_lote_es_la_aritmetica_recibido_menos_consumido_mas_devuelto` — regla que fija: *el estado del lote se deriva, no se codifica*.
- [ ] **0.5.2** Migraciones **M15** `2026_11_01_000015_create_installments_giftcards_held_orders_table.php` (`installment_plans.interest_percentage` `int` → `numeric(25,4)`; `gift_cards.created_by` → FK `users.id`; `holding_*` → `held_orders` + `_items` + `_totals`: el pedido en espera del POS **no** es un pedido comercial) y **M16** `2026_11_01_000016_create_loans_table.php` (`loans.loan_from` → FK; `payable` calculado en servidor; se corrige `lloan_id` → `loan_id`; `loan_payments`). Dep: 0.4.2. Aceptación: `loan_from` es FK, no texto libre; `payable` no se almacena desnormalizado.
- [ ] **0.5.2-T** Test: `test_el_payable_del_prestamo_se_calcula_en_el_servidor_y_no_se_guarda_desnormalizado` — regla que fija: *los importes derivados se calculan, no se duplican*.
- [ ] **0.5.3** Migración **M17** `2026_11_01_000017_create_quotations_and_orders_table.php`: división de `quotation_*` por `is_order` (0 → `quotations` + `_items` + `_totals`; 1 → `orders` + `_items` + `_totals`). Dep: 0.4.2. Aceptación: cotización y pedido son entidades distintas.
- [ ] **0.5.3-T** Test: `test_una_cotizacion_y_un_pedido_son_entidades_distintas` — regla que fija: *el pedido en espera del POS no es un pedido comercial*.
- [ ] **0.5.4** `app/Domain/Inventory/LotConsumer.php`: consumo FIFO con `ORDER BY received_at ASC, id ASC` + `FOR UPDATE SKIP LOCKED`, multi-lote, y traza por lote en `sale_item_lot_consumptions`. Cierra el defecto **H1** del legacy: el `UPDATE purchase_item … WHERE store_id AND item_id AND status` **sin `id`** (`_inc/model/invoice.php:262-263`) contabiliza el consumo en **todos** los lotes activos. Dep: 0.5.1. Aceptación: el criterio de selección es determinista; el `SKIP LOCKED` distingue "lote bloqueado" de "no hay stock" en el mensaje de error.
- [ ] **0.5.4-T** Test: `test_el_consumo_toma_el_lote_mas_antiguo_y_registra_un_consumo_por_lote` — regla que fija: *el FIFO ordena por antigüedad y por id*.
- [ ] **0.5.5** `app/Domain/Inventory/LotReturner.php`: la devolución repone **el lote exacto** del que salió la línea, leyendo `sale_item_lot_consumptions`; **no** repone el lote más antiguo disponible ni crea un lote nuevo. Dep: 0.5.4. Aceptación: la reposición se hace por `lot_id` de la traza.
- [ ] **0.5.5-T** Test: `test_la_devolucion_repone_el_lote_exacto_del_que_salio_la_linea` — regla que fija: *la devolución repone el lote exacto*.
- [ ] **0.5.6** `app/Domain/Inventory/StockInvariant.php`: comprobación permanente de `product_stores.quantity_in_stock == SUM(inventory_lots.available)` del producto en la tienda, y actualización del caché en la misma transacción del consumo. Dep: 0.5.4. Aceptación: la invariante se puede ejecutar como aserción en cualquier test y en el job de reconciliación.
- [ ] **0.5.6-T** Test: `test_el_stock_en_cache_iguala_la_suma_de_lotes_y_no_se_sobrevende_bajo_concurrencia` — regla que fija: *`quantity_in_stock` es igual a la suma de la disponibilidad de los lotes*.
- [ ] **0.5.7** Servicio sin lote: un producto de tipo `service` no genera `inventory_lots`, no consume stock y no aparece en la traza de consumos; y la transferencia mueve el stock del lote origen al destino registrando **una sola** cabecera. Dep: 0.5.4, 0.5.3. Aceptación: la transferencia tiene 1 cabecera y `transfer_items.source_lot_id` apunta al lote de salida.
- [ ] **0.5.7-T** Test: `test_un_producto_de_servicio_no_genera_lote_ni_consume_stock` — regla que fija: *sólo los productos con existencias consumen stock*.
- [ ] **0.5.8** Test de concurrencia de stock: 5 unidades disponibles y dos ventas concurrentes de 4 con `SKIP LOCKED` ⇒ una sola consume y la otra recibe **error de stock insuficiente** (no sobreventa, no espera). Requiere `pgsql_b` (0.1.2). Dep: 0.5.4, 0.5.6. Aceptación: el test usa dos conexiones y `SET LOCAL lock_timeout`; no usa `RefreshDatabase`.
- [ ] **0.5.8-T** Test: `test_dos_ventas_concurrentes_sobre_stock_limitado_no_se_sobrevenden` — regla que fija: *el total consumido nunca excede el stock disponible*.

---

## Fase 0.6 — Motor de impuestos, ventas y checkout

- [ ] **0.6.1** `app/Domain/Tax/` (puro, sin I/O): `TaxCalculator` (`final readonly`, `Brick\Math\BigDecimal` con escala 6 en el cálculo y 4 en la persistencia), `TaxContext`, `TaxLineInput`, `TaxDocumentInput`, `TaxDocumentResult`. Perfiles, categorías y tasas se resuelven **antes** y se inyectan como datos (D9). Dep: 0.2.7, 0.1.1. Aceptación: sin BD, sin red, sin sistema de ficheros, sin locale y sin `now()` (la fecha va en la entrada).
- [ ] **0.6.1-T** Test: `test_el_motor_de_impuestos_no_consulta_la_base_de_datos_ni_la_red` — regla que fija: *un motor que no puede leer el país no puede ramificar por él*.
- [ ] **0.6.2** Redondeo por línea con `rounding_rule` **dato** (`line` | `total`, `half-up` por defecto) y `roundingAdjustment` **visible**: `Σ líneas + roundingAdjustment = total redondeado`. La base es el precio **cobrado** (`net = unitPrice × quantity − lineDiscount`), no el de catálogo. Dep: 0.6.1. Aceptación: el ajuste se persiste en `sale_totals.rounding_adjustment`.
- [ ] **0.6.2-T** Test: `test_la_suma_de_lineas_mas_el_ajuste_de_redondeo_iguala_el_total_redondeado` — regla que fija: *el ajuste de redondeo es visible en el total*.
- [ ] **0.6.3** Snapshots congelados: el documento persiste `tax_rate_snapshot`, `tax_category_id`, `tax_rate_id` y `evidence_status` **por línea y por cabecera**; un cambio posterior de la tasa vigente **no** altera un documento emitido. Dep: 0.6.1, 0.4.4. Aceptación: el estado de evidencia viaja congelado al documento.
- [ ] **0.6.3-T** Test: `test_un_documento_emitido_conserva_la_tasa_la_categoria_y_el_estado_de_evidencia_congelados` — regla que fija: *el impuesto se persiste, no se recalcula*.
- [ ] **0.6.4** IVA retenido como **documento propio** (`document_type='retention_receipt'`, numeración de 14 caracteres, período de imposición, referencia al documento de origen, base imponible, impuesto causado y monto retenido); el motor lo marca `tax_type='withholding'` y **no** lo mezcla con el impuesto trasladado. Dep: 0.3.7, 0.6.1. Aceptación: `fiscal_document_taxes.line_id` nullable ⇒ cabecera (`line_id IS NULL`) y línea.
- [ ] **0.6.4-T** Test: `test_el_iva_retenido_se_emite_como_documento_propio_y_no_como_linea_de_la_venta` — regla que fija: *el IVA retenido es crédito del comprador, con documento propio*.
- [ ] **0.6.5** `app/Domain/Checkout/CheckoutService.php` — **T1 dominio**, una única transacción: reserva del nº de documento y del nº de control (`NumberSeries`), consumo FIFO, `TaxCalculator`, `sales`/`sale_items`/`sale_totals`/`sale_payments`, asientos contables y pago. Dep: 0.3.3, 0.5.4, 0.6.2, 0.7.4. Aceptación: si la segunda reserva (control) falla, **el nº de documento también vuelve** (`ROLLBACK`); ningún número se quema.
- [ ] **0.6.5-T** Test: `test_un_fallo_intermedio_no_deja_cabecera_ni_cambio_de_stock_persistidos` — regla que fija: *toda escritura es una unidad atómica con bloqueo de fila*.
- [ ] **0.6.6** **T2 fiscal** y reintento (C5): la llamada HTTP al proveedor (`submit` → `assignControl`) ocurre **fuera** de la transacción de BD; si falla, la venta queda `sales.status='fiscal_failed'` y el reintento **reutiliza la misma reserva** (`fiscal_document_reservations`), sin quemar números nuevos. Dep: 0.3.4, 0.6.5. Aceptación: ningún número se libera tras un fallo de emisión (el número puede haberse publicado).
- [ ] **0.6.6-T** Test: `test_un_reintento_de_emision_reutiliza_la_reserva_y_no_quema_numeros_nuevos` — regla que fija: *el número reservado no se libera ni se duplica por un fallo de emisión*.
- [ ] **0.6.7** Dominio de crédito, cuotas y transferencias: saldo del cliente derivado de `customer_ledger_entries` (un cobro reduce deuda), cuota con interés simple sobre el `payable` calculada en servidor y repartida entre `installment_schedules`, y transferencia con 1 cabecera. Dep: 0.5.2, 0.4.5. Aceptación: el saldo nunca se almacena desnormalizado.
- [ ] **0.6.7-T** Test: `test_un_cobro_reduce_la_deuda_del_cliente_y_el_saldo_se_deriva_de_sus_movimientos` — regla que fija: *el saldo del cliente se deriva de sus movimientos*.
- [ ] **0.6.8** Test de la cuota: interés simple sobre el `payable`, calculado en el servidor y repartido entre las cuotas. Dep: 0.6.7. Aceptación: la suma de las cuotas reproduce el total con el redondeo declarado.
- [ ] **0.6.8-T** Test: `test_la_cuota_se_calcula_en_el_servidor_con_interes_simple_sobre_el_payable_y_se_reparte_entre_cuotas` — regla que fija: *el interés se calcula en el servidor, no en la interfaz*.
- [ ] **0.6.9** Test de transferencia: el stock sale del **lote origen** y entra en el destino, con **una sola** cabecera. Dep: 0.5.7. Aceptación: `transfer_items.source_lot_id` referencia el lote de salida.
- [ ] **0.6.9-T** Test: `test_la_transferencia_mueve_el_stock_desde_el_lote_origen_y_registra_una_sola_cabecera` — regla que fija: *una transferencia es un movimiento, no N cabeceras*.

---

## Fase 0.7 — Contabilidad y caja

- [ ] **0.7.1** Migración **M18** `2026_11_01_000018_create_ledger_tables.php`: `ledger_entry_types` (catálogo sembrado que sustituye los **21 flags** de `income_sources`/`expense_categorys`), `ledger_entries` (`entry_type` explícito; absorbe `bank_transaction_info`/`_price`; `bank_transaction_price` 1:1 se disuelve en `ledger_entries.amount`) y `ledger_lines` (asientos por cuenta, permite reparar un movimiento entre varias cuentas). Dep: 0.4.4, 0.4.5. Aceptación: **ninguna** tabla de catálogo expone banderas booleanas contables.
- [ ] **0.7.1-T** Test: `test_cada_movimiento_contable_tiene_un_unico_entry_type_y_ninguna_bandera_dispersa` — regla que fija: *un movimiento contable tiene un tipo, no once banderas*.
- [ ] **0.7.2** Migración **M19** `2026_11_01_000019_create_expenses_cash_registers_opening_balances_table.php`: `expenses` (**fuente de verdad del gasto**), `expense_categories` (se eliminan los 10 flags contables; `is_hide` → `is_active`, que es UI), `income_sources` (se eliminan los 11 flags contables), `cash_registers` (apertura/cierre por tienda con **`business_date`** — el legacy filtra por `DAY()/MONTH()/YEAR()`, trampa 62) y `opening_balances` (`certified_at` + `certified_by`). Dep: 0.1.4. Aceptación: `cash_registers.business_date` es `date`; `opening_balances` exige `certified_at` y `certified_by` para el arranque.
- [ ] **0.7.2-T** Test: `test_el_gasto_nace_en_expenses_y_el_movimiento_bancario_no_lo_inventa` — regla que fija: *el gasto nace en `expenses`, no en el banco*.
- [ ] **0.7.3** Migración **M20** `2026_11_01_000020_create_bank_accounts_table.php`: `bank_accounts` **sin** `total_deposit`/`total_withdraw`/`total_transfer_from_other`/`total_transfer_to_other` (derivados) y con `opening_balance` congelado. Dep: 0.7.2. Aceptación: los saldos de cuenta se derivan de `ledger_entries`.
- [ ] **0.7.3-T** Test: `test_el_saldo_de_cuenta_se_deriva_de_los_asientos_y_no_de_un_contador_almacenado` — regla que fija: *los saldos de cuenta se derivan, no se cuentan dos veces*.
- [ ] **0.7.4** `app/Domain/Accounting/LedgerWriter.php`: escribe `ledger_entries` (`entry_type` del catálogo sembrado) + `ledger_lines` en una sola transacción, y es el único pathway que genera el asiento de un gasto. Dep: 0.7.1. Aceptación: ningún asiento se crea sin `entry_type`.
- [ ] **0.7.4-T** Test: `test_el_asiento_de_un_gasto_se_genera_desde_el_gasto_y_no_desde_el_movimiento_bancario` — regla que fija: *un gasto registrado sin movimiento bancario sigue siendo gasto*.
- [ ] **0.7.5** Caja por fecha de negocio: `app/Domain/Accounting/CashRegisterService.php` abre/cierra caja por tienda con `business_date` y asocia cada movimiento al registro de caja de **su** fecha de negocio. Dep: 0.7.2. Aceptación: una operación registrada cerca del cambio de día queda en el registro correcto; **no** se filtra por día/mes/año calculados.
- [ ] **0.7.5-T** Test: `test_el_movimiento_cerca_del_cambio_de_dia_queda_en_el_registro_de_caja_de_su_fecha_de_negocio` — regla que fija: *la caja se filtra por fecha de negocio, no por día/mes/año*.
- [ ] **0.7.6** Comando `php artisan ops:check-opening-balances`: **bloquea el arranque operativo** si no hay saldos de apertura certificados (stock por tienda y producto; saldo y deuda por cliente y proveedor; saldo de caja por cuenta y tienda) con `certified_at` + `certified_by`. Dep: 0.7.2. Aceptación: el comando sale con código ≠ 0 y mensaje explícito mientras falte la certificación.
- [ ] **0.7.6-T** Test: `test_el_arranque_operativo_queda_bloqueado_hasta_que_exista_un_saldo_certificado` — regla que fija: *sin saldos de apertura firmados no hay cifras defendibles*.
- [ ] **0.7.7** Modelos contables: `app/Models/LedgerEntryType.php`, `LedgerEntry.php`, `LedgerLine.php`, `Expense.php`, `ExpenseCategory.php`, `IncomeSource.php`, `CashRegister.php`, `OpeningBalance.php`, `BankAccount.php`. Dep: 0.7.1–0.7.3. Aceptación: casts exactos; `entry_type` es FK al catálogo sembrado.
- [ ] **0.7.7-T** Test: `test_el_tipo_de_asiento_se_toma_del_catalogo_y_no_de_un_literal` — regla que fija: *la clasificación se consulta, no se desparrama en el código*.

---

## Fase 0.8 — Impresión LAN y sincronización legacy

- [ ] **0.8.1** Migración **M23** `2026_11_01_000023_create_print_tables.php`: `printers`, `print_targets` (`transport` ∈ `browser`/`lan_agent`/`server_socket`, `printer_profile`, `char_per_line`, `charset`, `max_qr_payload_bytes`), `print_agents` (token **por agente**, sólo `token_hash`; `last_heartbeat_at`; umbral de desconexión **parametrizado**) y `print_jobs` (`payload` jsonb + `payload_bytes` bytea + `payload_sha256`, `reason`, `reprint_of_job_id`, `lease_expires_at`, `attempts`, `last_error`). Dep: 0.1.4, 0.3.2. Aceptación: **no** hay USB directo; el destino exige `tcp://host:9100` o impresora compartida de Windows.
- [ ] **0.8.1-T** Test: `test_un_destino_usb_directo_se_rechaza_y_exige_tcp_9100_o_impresora_compartida` — regla que fija: *el USB directo se descarta por requisito de Zadig*.
- [ ] **0.8.2** Migración **M24** `2026_11_01_000024_create_sync_tables.php`: `sync_batches` con `UNIQUE (client_id, payload_sha256)` y `legacy_translation_map` (`legacy_table`, `legacy_column`, `new_table`, `new_column`, `transform`), dirección **legacy → destino**, cubriendo las ~18 tablas del terminal. Dep: 0.1.4. Aceptación: el mapa es **dato**, no código; la restricción única de `sync_batches` existe.
- [ ] **0.8.2-T** Test: `test_la_traduccion_resuelve_tabla_y_columnas_contra_el_mapa_sembrado` — regla que fija: *el mapa de traducción es dato auditable, no código*.
- [ ] **0.8.3** `app/Print/EscposRenderer.php`: generación de los bytes ESC/POS **en el servidor** con `mike42/escpos-php`; `charset` CP850 por defecto (CP858 no es codificable en la librería); el límite de payload del QR lo impone el **servidor**; si el perfil no soporta QR, la generación **falla en el servidor** con error explícito (nunca un ticket mudo). El transporte `browser` renderiza HTML/PDF desde el **mismo modelo de plantilla**. Dep: 0.1.1, 0.8.1. Aceptación: el payload se congela en `print_jobs.payload_bytes` con su `payload_sha256`.
- [ ] **0.8.3-T** Test: `test_un_qr_sobre_un_perfil_sin_soporte_falla_en_el_servidor_antes_de_enviarlo` — regla que fija: *el límite de payload del QR lo impone el servidor*.
- [ ] **0.8.4** `app/Http/Controllers/PrintAgentController.php`: los 5 endpoints del contrato (§C.3) — `POST /api/print-agents/register`, `POST /api/print-agents/heartbeat`, `GET /api/print-agents/jobs` (long-poll ≤ 30 s), `POST /api/print-jobs/{id}/result`, `POST /api/print-agents/rotate`. Auth: `Authorization: Bearer <token>` + `X-Agent-Id`; el servidor **fuerza** la coincidencia del `agent_id` y **resuelve la tienda desde el `agent_id`**, nunca desde el cuerpo; la respuesta a un `result` repetido es **idempotente**. Dep: 0.8.1. Aceptación: un token de la tienda A no imprime en la tienda B.
- [ ] **0.8.4-T** Test: `test_el_agente_trae_trabajos_desde_la_lan_privada_y_la_tienda_se_resuelve_desde_el_agent_id` — regla que fija: *el agente siempre inicia la conexión*.
- [ ] **0.8.5** `app/Jobs/DispatchPrintJob.php` + máquina de estados de `print_jobs` (`queued` → `claimed` → `printing` → `printed`; `failed` reintentable desde la UI; `cancelled` terminal; `lease_expires_at` devuelve a `queued` con `attempts++`) y **reimpresión**: un trabajo nuevo con `reason='reprint'` y `reprint_of_job_id`, con **0** reservas nuevas y **0** modificación de `fiscal_documents`. Dep: 0.8.3, 0.8.4, 0.3.2. Aceptación: `fiscal_documents.status='issued'` es **independiente** de `print_jobs.status`.
- [ ] **0.8.5-T** Test: `test_reimprimir_crea_un_trabajo_nuevo_sin_consumir_correlativo_ni_alterar_el_documento` — regla que fija: *reimprimir no es reemitir*.
- [ ] **0.8.6** `app/Sync/Legacy/LegacySyncVerifier.php`: contrato de wire v2 **intacto** — mismas claves, mismo canonizado (`username \n action \n timestamp \n nonce \n sha256(data)`), misma firma `hash_hmac('sha256', …)`, mismo `SYNC_MAX_AGE`, mismo orden de verificación (cliente conocido → timestamp → `hash_equals` de la firma → **sólo después** consumo del nonce) y misma forma de respuesta; nonces en **Redis** (`SET NX EX`, D17). Dep: 0.8.2. Aceptación: una firma inválida **no** consume el nonce (así una petición no autenticada no quema nonces ajenos).
- [ ] **0.8.6-T** Test: `test_un_lote_firmado_con_los_vectores_dorados_se_acepta_con_la_misma_respuesta` — regla que fija: *el contrato de wire no cambia*.
- [ ] **0.8.7** `app/Sync/Legacy/LegacyStatementTranslator.php`: reescritor **ligero** de identificadores (sólo tablas y columnas, no un parser SQL completo) contra `legacy_translation_map`; des-escapeo de los valores que el legacy HTML-escapea **una sola vez, en la frontera**. Dep: 0.8.2. Aceptación: una forma no contemplada ⇒ **rechazo del lote entero**, nunca aplicación parcial.
- [ ] **0.8.7-T** Test: `test_un_lote_con_una_operacion_no_reconocible_se_rechaza_entero_y_no_se_aplica_parcialmente` — regla que fija: *un lote es todo o nada*.
- [ ] **0.8.8** `app/Sync/Legacy/LegacyBatchApplier.php` + `app/Jobs/ApplyLegacySyncBatch.php`: aplica el lote en **una** transacción, sólo `INSERT|UPDATE|DELETE`, **sin sentencias apiladas**; idempotencia por `sync_batches` (`UNIQUE (client_id, payload_sha256)`): un reenvío idéntico responde **éxito** sin reaplicar. Dep: 0.8.6, 0.8.7. Aceptación: el terminal que perdió la respuesta reenvía y **no** queda congelado en la ventana de corte.
- [ ] **0.8.8-T** Test: `test_un_reenvio_identico_responde_exito_sin_aplicar_el_lote_dos_veces` — regla que fija: *el reenvío no viola la clave primaria ni duplica efectos*.
- [ ] **0.8.9** Modelos de impresión y sync: `app/Models/Printer.php`, `PrintTarget.php`, `PrintAgent.php`, `PrintJob.php`, `SyncBatch.php`, `LegacyTranslationMap.php`. Dep: 0.8.1, 0.8.2. Aceptación: `PrintAgent` no guarda el token en claro, sólo `token_hash`.
- [ ] **0.8.9-T** Test: `test_el_token_del_agente_se_guarda_solo_como_hash` — regla que fija: *una fuga de token acota a una sola caja*.

---

## Fase 0.9 — Seed, suite, CI y documentación

- [ ] **0.9.1** Migración **M27** `2026_11_01_000027_add_domain_indexes_and_constraints.php`: índice parcial `inventory_lots (product_id, store_id, received_at, id) WHERE available > 0`; `CHECK (quantity_consumed >= 0)`, `CHECK (available >= 0)`; índices en **toda** columna de filtro y en **todo** `store_id`; FKs indexadas; y las **FKs tardías** que cierran el ciclo `sales ↔ fiscal_documents` (`fiscal_documents.sale_id` nullable → `sales.id`, `sales.fiscal_document_id` nullable → `fiscal_documents.id`), para no crear un ciclo de FKs entre M11 y M22. Dep: 0.3.2, 0.4.4. Aceptación: `migrate:fresh` en verde; ninguna FK sin índice.
- [ ] **0.9.1-T** Test: `test_toda_llave_foranea_tiene_indice_y_los_lotes_disponibles_estan_indexados_parcialmente` — regla que fija: *toda relación tiene FK real e indexada*.
- [ ] **0.9.2** Seeders: `database/seeders/FiscalProfileSeeder.php` (VE + CL/MX/CO/PA), `TaxonomySeeder.php`, `LedgerEntryTypeSeeder.php` (catálogo que sustituye los 21 flags), `LegacyTranslationMapSeeder.php` (~18 tablas del terminal), `OpeningBalancesSeeder.php`, `DemoStoreSeeder.php`; enganchar todos en `DatabaseSeeder.php`. Dep: 0.9.1. Aceptación: `php artisan migrate:fresh --seed` en verde sobre PostgreSQL 17.
- [ ] **0.9.2-T** Test: `test_la_semilla_completa_el_esquema_y_los_catalogos_sin_banderas_contables` — regla que fija: *el esquema se entrega por migraciones y datos sembrados, no por scripts*.
- [ ] **0.9.3** Semilla del perfil venezolano con evidencia trazable: cada fila declara `evidence_status` y `source_ref`; la tasa de IVA del 16 % queda **`pending_confirmation`** hasta confirmarse contra el Decreto del IVA (G.O. 5.363/1999, O3); tipos de documento, categorías de IVA, vocabulario de estados, series (documento + control con su rango) y `fiscal_profile_fields` (incluido `series_prefix_required_token='serie'`, C4). Dep: 0.9.2. Aceptación: la fila de la tasa se puede localizar por `source_ref`.
- [ ] **0.9.3-T** Test: `test_la_tasa_de_iva_sembrada_figura_pendiente_de_confirmacion_con_su_fuente` — regla que fija: *un dato no verificado se marca como pendiente, no se afirma*.
- [ ] **0.9.4** `tests/Architecture/NoCountryLiteralsTest.php`: escanea recursivamente `app/Domain/**/*.php` (**no** escanea `tests/`, `config/`, `database/seeders/` ni `app/Providers/Fiscal/`) y falla ante: códigos ISO-3166 alpha-2 en posición de comparación, nombres de país en literales, códigos/símbolos de moneda (`USD`, `VES`, `MXN`, `CLP`, `COP`, `PAB`, `Bs`), autoridades (`seniat`, `sii`, `sat`, `dian`, `dgi`, `RIF`, `RUC`, `NIT`) y tokens normativos locales (`serie`, «Contribuyentes Formales del IVA», `(E)`). Mensaje: `Fallo de arquitectura: app/Domain/ contiene N literales de país.` + una línea por hallazgo `ruta:línea  categoría=…  literal=…` + el cierre de la regla de oro. Categorías: `country-code` · `country-name` · `currency` · `authority` · `locale-token`. Dep: 0.2.5. Aceptación: sin lista blanca; sólo literales de cadena y comparaciones (no nombres de clase ni constantes).
- [ ] **0.9.4-T** Test: `test_ningun_literal_de_pais_sobrevive_en_app_domain` — regla que fija: *cambiar de país es insertar filas, no bifurcar código*.
- [ ] **0.9.5** Arnés de concurrencia de la reserva fiscal — **T1, T2 y T3** (§E.1), **sin `RefreshDatabase`**: `DatabaseMigrations` + `TRUNCATE … RESTART IDENTITY CASCADE` en `afterEach`; `DB::connection('pgsql_b')` para la segunda sesión; `SET LOCAL lock_timeout = '250ms'` **dentro** de la transacción de B. T1 = 50 reservas consecutivas sin huecos (`tests/Feature/Fiscal/NumberReservationSequenceTest.php`); T2 = bloqueo real → `QueryException` con `getCode() === '55P03'` y `lock_not_available`, `next_number` sin cambios, espera entre 250 ms y 3 s (`NumberReservationLockTest.php`); T3 = el rollback devuelve el número y **se verifica desde la conexión B** (`NumberReservationRollbackTest.php`). Dep: 0.1.2, 0.3.3. Aceptación: la reserva fiscal **no** usa `SKIP LOCKED` — espera y falla con timeout.
- [ ] **0.9.5-T** Test: `test_la_reserva_bloqueada_falla_con_sqlstate_55p03_y_el_rollback_devuelve_el_numero` — regla que fija: *el bloqueo de fila es real, no simulado*.
- [ ] **0.9.6** Barrido de la suite: comprobar que los **44 nombres** de §E.5 existen **exactamente una vez** y que los **6 transversales** de §E.2 existen; escribir los que falten y unificar los nombres divergentes con los canónicos. Distribución: `tests/Unit/{Tax,Fiscal,Accounting,Sync}/`, `tests/Feature/{Fiscal,Inventory,Sync,Print,Accounting,Checkout}/`. Dep: 0.2.1–0.8.9. Aceptación: cada nombre enuncia la regla que fija y es legible sin abrir el cuerpo; **ningún** test queda sin nombre canónico.
- [ ] **0.9.6-T** Test: `test_cada_test_de_la_suite_declara_la_regla_de_negocio_que_fija` — regla que fija: *el test es la documentación de la regla*.
- [ ] **0.9.7** Meta-tests de la suite: test que comprueba que la suite cubre checkout, FIFO, impuestos, reserva fiscal, cuotas, saldo de caja y devolución que repone stock; y **baseline verde** sin fallos preexistentes sin aislar. Dep: 0.9.6. Aceptación: `./vendor/bin/pest` en verde.
- [ ] **0.9.7-T** Test: `test_la_suite_del_dominio_cubre_checkout_fifo_impuestos_reserva_fiscal_cuotas_caja_y_devolucion` — regla que fija: *el dominio se prueba sin interfaz*.
- [ ] **0.9.8** CI: `.github/workflows/tests.yml` — orden `npm ci` → `npm run build` → `vendor/bin/pint --test` (**hueco real, C8**) → `npm run lint:check` → `npm run typecheck` (**ya en el gate: no duplicarlo**) → `./vendor/bin/pest`, sobre PostgreSQL 17; y un paso explícito que **verifique `public/build/manifest.json` antes de la suite** y falle con un error de precondición (no con un `ViteManifestNotFoundException`). Dep: 0.9.6. Aceptación: el workflow corre `pint --test` y falla con mensaje de precondición si falta el manifiesto.
- [ ] **0.9.8-T** Test: `test_la_puerta_de_calidad_declara_typecheck_y_build_como_etapas_del_gate` — regla que fija: *el typecheck está en el gate, no fuera*.
- [ ] **0.9.9** Documentación: crear `README.md` de arranque (precondiciones `npm ci && npm run build` + `php artisan migrate:fresh --seed`, credenciales del seed, PostgreSQL 17 + Redis 7, interruptores de módulo) y **verificar** que los 6 ADR existentes en `docs/adr/0001…0006-*.md` (read-only) exponen decisión, motivo y consecuencias — el test es la existencia de los 6 ficheros + el README, no una reescritura. Dep: 0.9.8. Aceptación: `README.md` existe y los 6 ADR están `Aceptado`.
- [ ] **0.9.9-T** Test: `test_el_repositorio_contiene_los_seis_adr_y_el_readme_de_arranque` — regla que fija: *cada decisión estructural queda documentada*.
- [ ] **0.9.10** Alineación de artefactos del change (**aplicado en `sdd-tasks`**, verificar que sigue consistente): **O1** `proposal.md` S1 = "54 nuevas → 109 tablas de negocio" (editado); **C10** `design.md` §A.3 = fila de fusión con **22** nuevas y total `55 + 22 + 19 + 13 = 109` (editado); **C9** delta `## MODIFIED Requirements` de `esquema-dominio` §"Atomicidad de toda escritura" registrado en `design.md` §F.2, que acepta `UPDATE … RETURNING` como mecanismo de bloqueo de fila (editado). **Las specs no se reescriben**: el conteo sigue siendo 44 requisitos en 10 capacidades y los deltas se aplican en `sdd-archive`. Dep: —. Aceptación: `proposal.md`, `design.md` y las 10 specs son consistentes entre sí.
- [ ] **0.9.10-T** Test: `test_los_artefactos_del_change_declaran_el_mismo_conteo_de_tablas_nuevas` — regla que fija: *el change no se contradice a sí mismo entre propuesta, diseño y specs*.
- [ ] **0.9.11** Tests trazables de §E.5 **sin gemela en su fase** (se escriben aquí, en rojo, contra la implementación ya verde): `test_un_pais_nuevo_se_anade_sembrando_filas_sin_tocar_el_motor_de_impuestos` (#5, `capa-pais-fiscal`), `test_el_agente_recibe_bytes_ya_generados_y_no_interpreta_el_ticket` (#26, `impresion-lan`), `test_el_terminal_reenvia_tras_perder_la_respuesta_y_el_maestro_lo_acepta_sin_congelarse` (#34, `adaptador-sync-legacy`), `test_la_suite_no_deja_fallos_preexistentes_sin_aislar` (#44, `calidad-continua`). Dep: 0.9.6. Aceptación: los 4 nombres existen con su regla enunciada; con esto las **44** pruebas de §E.5 tienen nombre canónico en el repositorio.
- [ ] **0.9.11-T** Test: `test_la_suite_no_deja_fallos_preexistentes_sin_aislar` — regla que fija: *la suite debe estar en verde sin fallos preexistentes*.

---

## Cobertura de los 44 requisitos

`#` = nº de la tabla §F.1 del diseño. `T` = tarea de test gemela. **Huecos: 0.**

| # | Capacidad · Requisito | Tareas de implementación | Test |
|---|---|---|---|
| 1 | `esquema-dominio` · Esquema destino por migraciones | 0.1.4–0.1.6, 0.4.1–0.4.5, 0.5.1–0.5.3, 0.7.1–0.7.3, 0.8.1–0.8.2, 0.9.1 | 0.9.2-T |
| 2 | `esquema-dominio` · Una sola fuente de verdad por dato | 0.4.2, 0.4.3, 0.5.2, 0.7.1, 0.7.3, 0.9.2 | 0.4.3-T · 0.7.3-T |
| 3 | `esquema-dominio` · Atomicidad de toda escritura | 0.3.3, 0.5.4, 0.6.5, 0.9.5 | 0.6.5-T · 0.9.5-T |
| 4 | `esquema-dominio` · El dominio no contiene literales de país | 0.2.5, 0.9.4 | 0.9.4-T |
| 5 | `capa-pais-fiscal` · El motor fiscal es dato | 0.2.1–0.2.3, 0.2.6, 0.2.7, 0.6.1, 0.9.11 | 0.2.1-T · 0.6.1-T · 0.9.11-T |
| 6 | `capa-pais-fiscal` · Actores y modelo de firma como dato | 0.2.4, 0.3.1, 0.3.4 | 0.2.4-T · 0.3.4-T |
| 7 | `capa-pais-fiscal` · Vocabularios de estado y referencias de autoridad | 0.2.2, 0.3.2 | 0.2.2-T |
| 8 | `capa-pais-fiscal` · Semilla del perfil venezolano con evidencia | 0.2.3, 0.9.3 | 0.9.3-T |
| 9 | `documentos-fiscales` · Contenido y render normados | 0.3.6 | 0.3.6-T |
| 10 | `documentos-fiscales` · Documentos derivados que referencian el original | 0.3.7, 0.6.4 | 0.3.7-T · 0.6.4-T |
| 11 | `documentos-fiscales` · Evidencia e integridad del comprobante | 0.3.2, 0.3.8 | 0.3.2-T · 0.3.8-T |
| 12 | `documentos-fiscales` · Contingencia exclusivamente en papel | 0.3.9 | 0.3.9-T |
| 13 | `numeracion-fiscal` · Dos series con dos reservas atómicas | 0.3.1, 0.3.3 | 0.3.1-T · 0.3.3-T |
| 14 | `numeracion-fiscal` · El número no retrocede | 0.3.8 | 0.3.8-T |
| 15 | `numeracion-fiscal` · Primera asignación de control | 0.3.5 | 0.3.5-T |
| 16 | `numeracion-fiscal` · Invariante del número de control | 0.3.4 | 0.3.4-T |
| 17 | `numeracion-fiscal` · T1–T3 sin `RefreshDatabase` | 0.1.2, 0.9.5 | 0.9.5-T |
| 18 | `motor-impuestos` · `TaxCalculator` puro | 0.6.1, 0.2.7 | 0.6.1-T |
| 19 | `motor-impuestos` · Cinco categorías y snapshots congelados | 0.2.3, 0.6.3, 0.4.4 | 0.6.3-T |
| 20 | `motor-impuestos` · Redondeo por línea con ajuste visible | 0.6.2 | 0.6.2-T |
| 21 | `motor-impuestos` · El IVA retenido es documento propio | 0.6.4, 0.3.7 | 0.6.4-T |
| 22 | `inventario-lotes` · Aritmética sin estado mutable | 0.5.1 | 0.5.1-T |
| 23 | `inventario-lotes` · FIFO determinista con bloqueo por salto | 0.5.4 | 0.5.4-T |
| 24 | `inventario-lotes` · Devolución repone el lote exacto | 0.5.5 | 0.5.5-T |
| 25 | `inventario-lotes` · Invariante de stock y no sobreventa | 0.5.6, 0.5.8 | 0.5.6-T · 0.5.8-T |
| 26 | `impresion-lan` · Un origen de contenido, binario por transporte | 0.8.3, 0.9.11 | 0.8.3-T · 0.9.11-T |
| 27 | `impresion-lan` · Agente en pull con long-poll | 0.8.4 | 0.8.4-T |
| 28 | `impresion-lan` · Destinos de impresión admitidos | 0.8.1 | 0.8.1-T |
| 29 | `impresion-lan` · El perfil de impresora es dato | 0.8.1, 0.8.3 | 0.8.3-T |
| 30 | `impresion-lan` · La emisión fiscal no depende de la impresión | 0.8.5 | 0.8.5-T |
| 31 | `adaptador-sync-legacy` · Contrato de wire v2 intacto | 0.8.6 | 0.8.6-T |
| 32 | `adaptador-sync-legacy` · Traducción por mapa sembrado | 0.8.2, 0.8.7, 0.9.2 | 0.8.2-T · 0.8.7-T |
| 33 | `adaptador-sync-legacy` · Aplicación idempotente del lote | 0.8.8 | 0.8.8-T |
| 34 | `adaptador-sync-legacy` · Truncado del log sólo con respuesta exitosa | 0.8.8, 0.9.11 | 0.8.8-T · 0.9.11-T |
| 35 | `contabilidad-caja` · Asientos con tipo explícito | 0.7.1, 0.7.4, 0.7.7 | 0.7.1-T |
| 36 | `contabilidad-caja` · `expenses` es la fuente de verdad del gasto | 0.7.2, 0.7.4 | 0.7.4-T |
| 37 | `contabilidad-caja` · Caja por fecha de negocio | 0.7.2, 0.7.5 | 0.7.5-T |
| 38 | `contabilidad-caja` · Sin contadores derivados | 0.4.2, 0.7.3 | 0.7.3-T |
| 39 | `contabilidad-caja` · Saldos de apertura congelados | 0.7.2, 0.7.6 | 0.7.6-T |
| 40 | `calidad-continua` · CI con PostgreSQL, lint, typecheck, tests y build | 0.9.8 | 0.9.8-T |
| 41 | `calidad-continua` · Suite Pest del dominio | 0.9.6, 0.9.7 | 0.9.7-T |
| 42 | `calidad-continua` · Cada test nombra la regla de negocio que fija | 0.9.6 | 0.9.6-T |
| 43 | `calidad-continua` · Seis ADRs y README de arranque | 0.9.9 | 0.9.9-T |
| 44 | `calidad-continua` · Baseline verde sin fallos preexistentes | 0.9.7, 0.9.11 | 0.9.7-T · 0.9.11-T |

**Cobertura: 44 / 44 requisitos con tarea de implementación y con test gemelo. 0 huecos.**
**Tests nombrados: 44 trazables (§E.5) + 6 transversales (§E.2) + 1 de pureza (`0.6.1-T`) = 51**, repartidos en 73 tareas de test gemela (`-T`).

---

## Orden de ejecución y puntos de bloqueo

```
0.1 ──► 0.2 ──► 0.3 ──┐
  │        │           ├──► 0.6 ──┐
  └────────┴──► 0.4 ──► 0.5 ────┤         ├──► 0.9
                └──────► 0.7 ────┼──► 0.8 ┘
```

| Hito | Quién desbloquea a quién | Condición |
|---|---|---|
| 0.1 → todo | `pgsql_b` + interruptores + schema base | 0.1.2 es requisito de 0.9.5 (T2/T3) |
| 0.2 → 0.3, 0.6 | contratos, enums y `TaxContext` | 0.3.4 necesita `ElectronicInvoiceProvider`; 0.6.1 necesita `TaxContext` |
| 0.3 → 0.6 | `NumberSeries` y `FiscalIssuanceService` | 0.6.5 reserva números; 0.6.6 reintenta T2 |
| 0.4 → 0.5 | `sale_items`, `purchase_items` | M14 referencia `sale_items` y `purchase_items` |
| 0.5 → 0.6 | FIFO y caché de stock | 0.6.5 consume lotes dentro de T1 |
| 0.7 → 0.6 | `LedgerWriter` | el checkout escribe asientos dentro de T1 |
| 0.8 → 0.9 | suite de impresión y sync | se ejecuta en el gate |
| 0.9 → cierre | las 51 pruebas + CI | `migrate:fresh --seed` + `./vendor/bin/pest` en verde |

### Puntos de bloqueo que necesitan un dato o una decisión externa

| # | Bloqueo | Tipo | Quién lo resuelve | Bloquea |
|---|---|---|---|---|
| **B1** | **Saldos de apertura** (stock por tienda y producto; saldo y deuda por cliente y proveedor; saldo de caja por cuenta y tienda) con `certified_at` + `certified_by` | **Operativo** — lo certifica una **persona** con fecha y responsable nominal | Dirección financiera del cliente | **El arranque operativo**, no el código (tarea 0.7.6 ya lo bloquea con `ops:check-opening-balances`) |
| **B2** | **Tasa de IVA 16 %** (Decreto del IVA, G.O. 5.363/1999) | Dato externo | Área legal/fiscal del cliente | La **confirmación** del documento fiscal; no el esquema (queda `pending_confirmation` con `source_ref`) |
| **B3** | **Transcripción de SNAT/2026/00093** (texto de terceros) validada contra el PDF de la G.O. 43.484 | Dato externo | Legal del cliente | La **evidencia** (`source_ref` → PDF oficial); no el código |
| **B4** | **Listado de imprentas digitales autorizadas** (O6) | Dato externo | Legal del cliente | El **actor del canal** (`fiscal_providers.kind`); no el esquema |
| **B5** | **Manual Técnico del art. 41** | Dato externo | SENIAT | El **adaptador real** de Fase 1; **no** Fase 0 |
| **B6** | **Estrategia de cadena de PRs** (`stacked-to-main` · `feature-branch-chain` · `size:exception`) | Decisión del equipo | Maintainer | **El inicio de `sdd-apply`** (`Decision needed before apply: Yes`) |
| **B7** | **Formato del correlativo** (`000-00000000`, P6) y **serie propia de compras** (P7) | Decisión de producto, ya recomendada | Producto | Nada: son **datos sembrados** (`number_formats`, `fiscal_series`) |
| **B8** | Rol dual **emisor + imprenta** (P32/D1) | Decisión de producto, ya recomendada | Producto | Nada en Fase 0: se siembran ambos roles y se activa sólo `issuer` |

**No bloquea `sdd-apply`**: B1–B5 y B7–B8 son dependencias de datos y decisiones de producto; B6 es la única decisión que bloquea el **arranque de la implementación**.

---

## Plan de rollback por bloque

Fase 0 es **proyecto verde**: no hay datos de producción que migrar ⇒ el rollback es de **código y esquema**, no de datos.

| Bloque | Tareas | Rollback |
|---|---|---|
| Esquema base | 0.1, 0.4, 0.7 | `git revert` + `php artisan migrate:fresh --seed`. Todas las migraciones declaran `down()`. El riesgo real es heredar un esquema erróneo ⇒ el gate (`migrate:fresh` + suite en verde) es la condición de merge. |
| Capa de país/fiscal | 0.2, 0.9.2–0.9.3 | `git revert` de código + **truncar y re-sembrar** las filas de la capa (es dato, no esquema). La semilla VE se puede re-sembrar con `evidence_status` intacto. |
| **Numeración fiscal** | 0.3 | **Apagar, nunca renumerar.** `fiscal.emission_enabled=false`. El rollback **no** borra `fiscal_document_reservations` ni `fiscal_series`: desde el primer documento emitido la numeración es irrecuperable por diseño (SNAT/2026/00093 arts. 8.2, 21, 27; SNAT/2024/000102 art. 34). Los documentos anulados conservan `void` + `voided_at`; el número quemado queda auditable. |
| Impuestos y checkout | 0.6 | `git revert` de código. Las ventas en `sales.status='fiscal_failed'` **se reconcilian** con el reintento (reutiliza la reserva) **o** se anulan; en ningún caso se libera ni se renumera una reserva. Sin datos de producción ⇒ el reset es `migrate:fresh`. |
| Stock / FIFO | 0.5 | Sin `status` mutable ⇒ no hay estado desincronizable que revertir: el consumo es aritmética (`received − consumed + returned`) y `available` es GENERATED. Sin datos de producción, el reset es `migrate:fresh`. |
| Contabilidad y caja | 0.7 | `git revert` + `migrate:fresh`. `opening_balances` conserva `certified_at`/`certified_by` como evidencia; si el rollback borra la certificación, hay que **re-certificar** (B1) antes de arrancar. |
| Impresión LAN | 0.8 | `print.lan_agent_enabled=false`; los `print_jobs` pendientes se cancelan (`cancelled`); `fiscal_documents` **no** se tocan (`issued` es independiente de `printed`). |
| Sincronización legacy | 0.8 | `sync.legacy_adapter_enabled=false` + truncar y re-sembrar `legacy_translation_map` (**dato**, nunca esquema). El maestro deja de aceptar lotes; el terminal legacy **no** queda congelado (queda en pausa, no en error). |
| Seed, suite, CI y docs | 0.9 | Sin impacto en runtime. `git revert` de los ficheros de CI y del README; las filas sembradas se truncan con `migrate:fresh --seed`. |
| Artefactos del change | 0.9.10 | `git revert` de `proposal.md` y `design.md`. Sin efecto en runtime. |

> **Punto de no retorno**: desde el primer documento fiscal emitido, el rollback de la numeración es **"apagar", nunca "renumerar"**.
