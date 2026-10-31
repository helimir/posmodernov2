# Design: Fase 0 — Fundaciones

> **Pasada 2 de 2 — el diseño queda cerrado.** La pasada 1 fijó el **esquema destino** (§A), los **tres flujos transaccionales** (§B) y los **contratos de núcleo** (§C).
> Esta pasada añade: **§D** los 6 ADR · **§E** plan de testing ejecutable · **§F** trazabilidad de los 44 requisitos.
> Reglas aplicadas: `openspec/config.yaml → rules.design` (diagramas de secuencia, decisiones con motivo, escritura atómica con bloqueo de fila, correlativo con `UPDATE … RETURNING`).

## Estado de la fase de diseño

| Aspecto | Estado |
|---|---|
| Pasada | **2 de 2** — esta pasada **cierra** el diseño de `fase0-fundaciones` |
| Secciones | §A–§C fijadas en la pasada 1 · **§D, §E y §F** añadidas en esta pasada |
| Cobertura | **44 / 44 requisitos con diseño** (§F.1) · **0 requisitos sin diseño** |
| Conflictos | **C1 y C5 RESUELTOS** por decisión del usuario · C2, C3, C4, C6 y C7 resueltos en el diseño · **C8, C9 y C10 nuevos**, documentados con propuesta y **no resueltos** |
| Preguntas abiertas | 6, cada una con **recomendación** y motivo; ninguna bloquea `sdd-tasks` |
| Siguiente fase | `sdd-tasks` |

## Enfoque técnico

El legacy **no se porta**: se reemplaza. Fase 0 = **esquema destino + núcleo puro testeable + suite que fija por test las reglas que hoy se ejecutan sin garantías**.

| Pieza | Decisión | Ancla |
|---|---|---|
| Esquema | 27 migraciones Laravel sobre PostgreSQL 17 (**27 migraciones ≠ 27 tablas**: hay **54 tablas nuevas**) · 109 tablas de negocio; `numeric(25,4)`; FKs reales | `exploration.md` §3, `research/estandares-latam.md` §5 |
| Fiscal | **Datos**, no código: perfil → tipos de documento → ambientes → esquemas de impuesto → vocabulario de estado → proveedores → series | `research/estandares-latam.md` §5.4–5.8 |
| Numeración | **Dos** series y **dos** reservas atómicas independientes (`series_role` `document` \| `control`) | SNAT/2026/00093 arts. 8.2, 8.3, 9.1, 27 |
| Motor de impuestos | `TaxCalculator` **puro** (sin I/O), snapshots congelados | `exploration.md` §6 |
| Inventario | Lotes con aritmética (`received − consumed + returned`), FIFO `ORDER BY received_at, id` + `FOR UPDATE SKIP LOCKED` | `exploration.md` §7 |
| Sync | Contrato v2 **intacto**; traducción por mapa **sembrado**; idempotencia por `sync_batches` | `research/*`, `exploration.md` §9 |
| Impresión | Bytes ESC/POS **en el servidor**; el agente sólo escribe; `issued` ≠ impreso | `research/escpos-agente-lan.md` §7.2–7.3 |

```
Legacy 73 tablas ──► traducción 55 conservadas · 17 fusionadas · 1 eliminada ──► destino 109 tablas
                                                   ▲
       54 nuevas = 22 nacidas de la fusión (nombres nuevos) · 19 capa de país/fiscal · 13 plataforma/operativa
                                   + capa de país/fiscal como DATOS (sin `if` por país)
```

## Decisiones de arquitectura

| # | Decisión | Alternativa descartada | Motivo |
|---|---|---|---|
| D1 | **`UPDATE … RETURNING`** para toda reserva de correlativo | `SELECT` + `rowCount()` + `+1` (legacy `_inc/helper/invoice.php:95-109`) | `rowCount()` de un `SELECT` no es nº de filas afectadas; la lectura y la escritura no son atómicas. `RETURNING` devuelve el valor reservado en la misma sentencia. **Regla `rules.design`.** |
| D2 | **Dos series, dos reservas** (`series_role`) | Una sola numeración (`exploration.md` §5) | La norma Francesa distingue *numeración del documento* (emisor, art. 8.2/20) y *Nº de Control* (imprenta, art. 8.3/9.1/27). Dos autoridades ⇒ dos ciclos de vida ⇒ dos reservas. |
| D3 | **El nº de control se reserva localmente como espejo del rango de la imprenta** | Esperar el control de la imprenta para numerar | La imprenta **asigna** el control (art. 42.1), pero el emisor debe garantizar consecutividad sin huecos: el espejo local (`from_number`/`to_number` sembrados del rango autorizado) reserva el cupo de forma atómica y luego **se verifica** contra lo que la imprenta devuelve. Ver *Conflictos C3*. |
| D4 | **Dos transacciones en el checkout**: T1 dominio (números + FIFO + IVA + asientos + pago) · T2 fiscal (emisión) | Una sola transacción con la llamada HTTP del proveedor dentro | Mantener bloqueos de fila (`FOR UPDATE`) durante I/O externo alarga la ventana de bloqueo y puede agotar `lock_timeout` en la caja. La separación mantiene la atomicidad exigida y hace que un fallo de emisión **no** destruya la venta. Ver *Conflictos C5*. |
| D5 | **`inventory_lots.available` como columna GENERATED** | Columna mantenida a mano + test de invariante | `GENERATED ALWAYS AS (quantity_received - quantity_consumed + quantity_returned) STORED` es **exacta por definición**: no puede desincronizarse. El test de invariante sigue aplicando al caché de producto (`product_stores.quantity_in_stock`). |
| D6 | **`quantity_in_stock` cacheado en `product_stores` + test de invariante** | Derivar siempre por `SUM(available)` | La derivación en cada lectura de stock es O(lotes) y bloquea la caja. El requisito permite cachear *si* hay test de invariante. |
| D7 | **`ledger_entries` (cabecera + `entry_type`) + `ledger_lines` (asientos por cuenta)** | Sólo `ledger_entries.amount` | Un movimiento puede reparirse entre varias cuentas; `ledger_lines` lo permite sin inventar un `entry_type` por combinación. `bank_transaction_price` (1:1 legacy) se disuelve en `ledger_entries.amount`. |
| D8 | **Una fila de tipo de movimiento (`entry_type`) sustituye a los 21 flags** | Conservar los flags de `income_sources`/`expense_categorys` | `exploration.md` §3.4 criterio 4: la clasificación **se consulta**, no se desparrama en booleanos. `ledger_entry_types` es el catálogo sembrado. |
| D9 | **El motor fiscal no toca la BD** | `TaxCalculator` que consulta `tax_rates` | Puridad exigida por `motor-impuestos`. Perfiles, categorías y tasas se resuelven **antes** y se inyectan como datos con sus snapshots. |
| D10 | **Decimal exacto con `brick/math` (`BigDecimal`)** | `float` (legacy) · `ext-bcmath` | `float` está prohibido por el esquema; `bcmath` es extensión opcional y no está garantizada en el CI. `brick/math` es PHP puro. Dependencia a añadir. |
| D11 | **Los bytes ESC/POS se generan en el servidor** | El agente los genera (o renderiza una DSL) | El agente se despliega y se mantiene en cada terminal: cuanto menos lógica, menos divergencia de formato. Además el servidor puede **rechazar** un ticket no representable antes de enviarlo. Lo unificable es el **origen del contenido** (modelo de plantilla), no el binario (`research/escpos-agente-lan.md` §7.2). |
| D12 | **El agente siempre inicia la conexión** (pull + long-poll) | Push desde el servidor | Un push exige IP pública, reenvío de puertos o VPN en cada terminal. El pull atraviesa NAT sin reglas. `research/escpos-agente-lan.md` §5.2. |
| D13 | **Token por agente** (no por tienda) + tienda resuelta desde `agent_id` | Token por tienda | Acota la explosión de una fuga a **una caja**. Refinamiento sobre `exploration.md` §8.3. |
| D14 | **Destinos: TCP 9100 o impresora compartida de Windows. USB directo excluido** | USB/libusb en Windows | Exige instalar WinUSB/libusb con **Zadig**: inviable en despliegue desatendido. `research/escpos-agente-lan.md` E13/E14. |
| D15 | **El mapa de traducción del sync es un dato sembrado** (`legacy_translation_map`) | Reimplementación por tabla en código | Contradice la regla "datos, no código" y dispersa la traducción en 18 tablas × código. `exploration.md` §12.3. |
| D16 | **Idempotencia por `sync_batches UNIQUE (client_id, payload_sha256)`** | Cambiar el protocolo | El terminal trunca su log sólo si la respuesta fue exitosa ⇒ reenvío idéntico. Se tapa **en el maestro**, sin tocar el contrato de wire. `exploration.md` §9.3 S1. |
| D17 | **Nonce del sync en Redis (`SET NX EX`)** | Sistema de ficheros `storage/sync-nonces/` | No escala a varios contenedores ni se puede compartir. Mismo contrato, mejor implementación. `exploration.md` §9.3 S2. |
| D18 | **En proyecto verde se edita la migración base de `users` en lugar de encadenar `ALTER`s** | Migración de datos desde la tabla original | No hay datos de producción que migrar; encadenar `ALTER` sobre una tabla recién creada produce ruido sin valor. |
| D19 | **`expenses` es la fuente de verdad del gasto** | El movimiento bancario | Un gasto sin asiento bancario sigue siendo gasto; un movimiento bancario no inventa un gasto. `contabilidad-caja` spec. |
| D20 | **`holding_*` → `held_orders` (pedido en espera del POS); `quotation_*` se divide en `quotations` + `orders`** | Unificar "pedido en espera" y "pedido" | Son entidades distintas (el POS holds una venta a medias; un pedido es un compromiso comercial). `exploration.md` §3.2. |

---

## A) Esquema destino — migración por migración

### A.1 Plan de migraciones

Orden = dependencias de FK. Los nombres siguen la convención Laravel (`YYYY_MM_DD_HHMMSS_`). `down()` en todas.

| # | Migración | Crea / altera | Propósito |
|---|---|---|---|
| M01 | `2026_11_01_000001_create_countries_currencies_number_formats_table.php` | crea `countries`, `currencies`, `number_formats` | Capa de país: catálogo de países, monedas con `decimals`/separadores, formatos de número/fecha. Sin dependencias. |
| M02 | `2026_11_01_000002_create_fiscal_profiles_table.php` | crea `fiscal_profiles`, `fiscal_profile_document_types`, `fiscal_profile_environments`, `fiscal_profile_fields`, `fiscal_tax_schemes`, `fiscal_status_vocabularies` | Perfil fiscal + satélites: tipos de documento (`tax_treatment`, `exemption_marker`, `requires_parent_reference`), ambientes (cert/prod), catálogo de claves del `jsonb`, esquemas de impuesto, vocabulario de estados nativo→genérico. |
| M03 | `2026_11_01_000003_create_tax_categories_and_rates_table.php` | crea `tax_categories`, `tax_rates` | 5 categorías de IVA (`is_withholding`), tasas con vigencia, `evidence_status`, `source_ref`, `rounding_rule`. |
| M04 | `2026_11_01_000004_create_stores_table.php` | crea `stores`, `store_settings`, `store_fiscal_registrations` | `stores` sin `preference` (→ `store_settings` tipada), `country_id` FK, `vat_reg_no` → `store_fiscal_registrations`. |
| M05 | `2026_11_01_000005_create_roles_permissions_tables.php` | crea `roles`, `permissions`, `role_permissions`, `role_store_scopes`, `login_logs`, `dashboard_shortcuts` | `user_group.permission` (blob) → `permissions` + `role_permissions`; `shortcut_links.permission_slug` → FK `permissions.id`; scopes de tienda por rol. |
| M06 | `2026_11_01_000006_edit_users_table_add_user_settings.php` | **edita** `0001_01_01_000000_create_users_table.php`; crea `password_reset_tokens`, `user_settings` | `password` → hash argon2id/bcrypt (el legacy guarda MD5), se elimina `raw_password`, `preference` (blob) → `user_settings`, `group_id` → FK `roles.id`, `pass_reset_code`/`reset_code_time` → `password_reset_tokens`. |
| M07 | `2026_11_01_000007_create_settings_table.php` | crea `settings` | Ajustes globales tipados (`key`, `value`, `type`), sustituye el KV libre del legacy. |
| M08 | `2026_11_01_000008_create_catalog_tables.php` | crea `products`, `product_images`, `categories`, `brands`, `boxes`, `units`, `pos_templates`, `payment_methods` | Maestro de catálogo. `products.tax_category_id` → FK; `pos_templates.template_content` `ucs2` → `text`/`jsonb`; `payment_methods.country_rules` `jsonb`. |
| M09 | `2026_11_01_000009_create_bridge_tables.php` | crea `product_stores`, `customer_stores`, `supplier_stores`, `brand_stores`, `category_stores`, `box_stores`, `unit_stores`, `currency_stores`, `payment_method_stores`, `pos_template_stores`, `bank_account_stores`, `user_stores` | Patrón maestro/bridge multi-tienda: FKs reales a `stores` + `status` + `sort_order`. `product_stores.purchase_price`/`sell_price` `float` → `numeric(25,4)`; `taxrate_id` → `tax_category_id`; `quantity_in_stock` cacheado (invariante con test). `bank_account_stores` sin `deposit`/`withdraw`/`transfer_*`. `user_stores` **sin** el `JOIN status=1` que bloquea en todas las tiendas. |
| M10 | `2026_11_01_000010_create_customers_suppliers_table.php` | crea `customers`, `suppliers` | `customers.credit_balance` eliminado (duplica `customer_stores.balance`); `customer_country`/`customer_state` → FK; `gtin` → `tax_id`. |
| M11 | `2026_11_01_000011_create_sales_tables.php` | crea `sales`, `sale_items`, `sale_totals`, `sale_payments`, `sale_returns`, `sale_return_items` | Renombra `selling_info`/`_item`/`_price`. `sale_items` separa `tax_rate_snapshot` de `tax_amount` (el legacy guardaba el % en `tax` y el importe en `item_tax`); `sale_totals.rounding_adjustment`. `sale_payments.details` blob → `jsonb`. |
| M12 | `2026_11_01_000012_create_customer_ledger_entries_table.php` | crea `customer_ledger_entries` | Renombra `customer_transactions`; `type` con valores canónicos; `reference_no`/`ref_invoice_id` → FK. |
| M13 | `2026_11_01_000013_create_purchases_tables.php` | crea `purchases`, `purchase_items`, `purchase_totals`, `purchase_payments`, `purchase_returns`, `purchase_return_items` | Renombra `purchase_info`/`_price`; `purchase_items.tax_method` se elimina (lo decide `fiscal_profiles.tax_inclusive_default`); `purchase_payments.invoice_id` → FK `purchases.id`. |
| M14 | `2026_11_01_000014_create_inventory_lots_tables.php` | crea `inventory_lots`, `sale_item_lot_consumptions`, `transfers`, `transfer_items` | **Sin `status` mutable**: `available` GENERATED. Cada `purchase_items` abre **1** `inventory_lots` (1:1 vía `purchase_item_id`); el legacy `purchase_item` se parte en línea + lote. Traza por lote (COGS + devolución al lote exacto). `transfers` = **1 fila por transferencia** (el legacy genera N cabeceras); `transfer_items` + `unit_cost` + `source_lot_id`. |
| M15 | `2026_11_01_000015_create_installments_giftcards_held_orders_table.php` | crea `installment_plans`, `installment_schedules`, `gift_cards`, `gift_card_topups`, `held_orders`, `held_order_items`, `held_order_totals` | `installment_plans.interest_percentage` `int` → `numeric(25,4)`; `gift_cards.created_by` → FK `users.id`; `holding_*` → `held_orders` (pedido en espera del POS ≠ pedido). |
| M16 | `2026_11_01_000016_create_loans_table.php` | crea `loans`, `loan_payments` | `loan_from` → FK; `payable` calculado en servidor; se corrige `lloan_id` → `loan_id`. |
| M17 | `2026_11_01_000017_create_quotations_and_orders_table.php` | crea `quotations`, `quotation_items`, `quotation_totals`, `orders`, `order_items`, `order_totals` | **División** de `quotation_*` por `is_order` (0 → cotización, 1 → pedido). |
| M18 | `2026_11_01_000018_create_ledger_tables.php` | crea `ledger_entry_types`, `ledger_entries`, `ledger_lines` | `bank_transaction_info`/`_price` → `ledger_entries` con `entry_type` + `ledger_lines`. `ledger_entry_types` es el catálogo sembrado que sustituye los 21 flags. |
| M19 | `2026_11_01_000019_create_expenses_cash_registers_opening_balances_table.php` | crea `expenses`, `expense_categories`, `income_sources`, `cash_registers`, `opening_balances` | `expenses` = fuente de verdad del gasto. Se eliminan los 10 flags de `expense_categorys` y los 11 de `income_sources` (20 contables + `is_hide`→`is_active`, que es UI, no contable). `cash_registers` con `business_date` (el legacy filtra por `DAY()/MONTH()/YEAR()`). `opening_balances` con `certified_at` + `certified_by`. |
| M20 | `2026_11_01_000020_create_bank_accounts_table.php` | crea `bank_accounts` | Sin `total_deposit`/`total_withdraw`/`total_transfer_from_other`/`total_transfer_to_other` (derivados). `opening_balance` congelado. |
| M21 | `2026_11_01_000021_create_fiscal_providers_and_series_table.php` | crea `fiscal_providers`, `fiscal_series` | Actor intermedio (`kind` ∈ `printer`/`pac`/`portal`) y las **dos** clases de serie (`series_role`, `assigned_by`, `prefix`, `next_number`, `from_number`, `to_number`, `valid_from`, `valid_to`, `credential_ref`, `control_identifier`, `sequence_length`). |
| M22 | `2026_11_01_000022_create_fiscal_documents_table.php` | crea `fiscal_documents`, `fiscal_document_reservations`, `fiscal_document_taxes`, `fiscal_document_parties`, `fiscal_document_links`, `fiscal_document_events`, `fiscal_document_control_assignments`, `fiscal_periodic_reports`, `fiscal_contingency_forms` | Cabecera fiscal + impuestos multinivel (`line_id` nullable) + partes con `role` + links + **eventos append-only** + asignación de control + reporte periódico + talonarios de contingencia. |
| M23 | `2026_11_01_000023_create_print_tables.php` | crea `printers`, `print_targets`, `print_agents`, `print_jobs` | `printer_to_store` → `print_targets` (`transport` `browser`/`lan_agent`/`server_socket`, `printer_profile`, `char_per_line`, `charset`, `max_qr_payload_bytes`). `print_jobs` con `payload` jsonb + `payload_bytes` bytea + `payload_sha256` + `reason`/`reprint_of_job_id` + `lease_expires_at`. |
| M24 | `2026_11_01_000024_create_sync_tables.php` | crea `sync_batches`, `legacy_translation_map` | Idempotencia (`UNIQUE (client_id, payload_sha256)`) y el **mapa de traducción sembrado** (`legacy_table`, `legacy_column`, `new_table`, `new_column`, `transform`). |
| M25 | `2026_11_01_000025_create_languages_and_messages_table.php` | crea `languages`, `language_translations`, `sms_messages`, `message_templates`, `sms_providers` | `sms_schedule` → `sms_messages` (outbox con `status`/`sent_at`); `sms_setting` (eliminado) → `sms_providers` con `credential_env_prefix` (**nunca credenciales en claro**). |
| M26 | `2026_11_01_000026_create_audit_logs_table.php` | crea `audit_logs` | Auditoría de acciones de negocio (distinta de `fiscal_document_events`, que es la fiscal append-only). |
| M27 | `2026_11_01_000027_add_domain_indexes_and_constraints.php` | altera (índices/CHECKs) | Índice parcial `inventory_lots (product_id, store_id, received_at, id) WHERE available > 0`; `CHECK (quantity_consumed >= 0)`, `CHECK (available >= 0)`; índices en toda columna de filtro y en `store_id`. |
| — | `database/seeders/` (no es migración) | `FiscalProfileSeeder` (VE + CL/MX/CO/PA), `TaxonomySeeder`, `LedgerEntryTypeSeeder`, `LegacyTranslationMapSeeder`, `OpeningBalancesSeeder`, `DemoStoreSeeder` | Semilla del perfil venezolano con `evidence_status`/`source_ref` por fila (16 % → `pending_confirmation`). |

**Fuera de la migración base de Laravel:** `cache`, `cache_locks`, `jobs`, `job_batches`, `failed_jobs`, `sessions` se conservan tal cual (7 tablas de infraestructura).

### A.2 Tipos transversales

| Regla | Aplicación |
|---|---|
| Importe | `numeric(25,4)` **siempre**; `exchange_rate` `numeric(25,8)`; porcentaje de tasa `numeric(25,6)`. **Prohibido `float`.** |
| FK | `ON DELETE` explícito: `RESTRICT` histórico (ventas, lotes, asientos, documentos fiscales), `CASCADE` dependiente (líneas de cabecera), `SET NULL` opcional (usuario que borró). |
| Sin blobs PHP | `stores.preference`→`store_settings`; `users.preference`→`user_settings`; `user_group.permission`→`permissions`+`role_permissions`; `payments.details`→`jsonb`; `product_to_store.preference`→columnas tipadas. |
| Sin contadores derivados | `bank_accounts.total_*` y `bank_account_to_store.deposit/withdraw` **eliminados**; saldos = `SUM(ledger_entries.amount)`; `product_stores.quantity_in_stock` cacheado **con test de invariante**. |
| Sin flags contables | 21 flags fuera; `ledger_entry_types` los sustituye. |
| Índices | Toda columna usada en filtro y todo `store_id` indexado; FKs indexadas. |
| Fechas | `timestamptz` para auditoría; `date` para `business_date`, `issue_date`, `valid_from/to`. |

### A.3 Reconciliación del conteo (ver *Conflictos C1*)

| Bloque | Tablas | Origen |
|---|---|---|
| Conservadas (lineage 1:1) | **55** | `exploration.md` §3.2 |
| Destinos de fusión (17 legacy → **24** tablas destino: 22 de nombre nuevo + 2 ya conservadas) | 24 | `exploration.md` §3.2 |
| Nuevas de otra procedencia (capa de país/fiscal 19 · plataforma/operativa 13) | 32 | M01–M27 |
| **Total negocio** | **109** | +7 de infraestructura Laravel = 116 tablas |

Las 73 del legacy cuadran exacto: **55 + 17 + 1 = 73**.
Desglose de las **54 nuevas**: 22 nacidas de la fusión (nombres nuevos: `sales`, `sale_items`, `sale_totals`, `sale_item_lot_consumptions`, `purchases`, `purchase_items`, `purchase_totals`, `inventory_lots`, `ledger_entries`, `quotations*`, `orders*`, `tax_categories`, `tax_rates`, `currencies`, `fiscal_documents`, `settings`, `store_settings`, `print_targets`) · 19 de la capa de país/fiscal · 13 de plataforma/operativa.

> ⚠ **Aritmética de la tabla (C10):** la columna de la tabla anterior suma 111 (55 + 24 + 32) porque las **2 tablas "ya conservadas"** de la fila de fusión están contadas también dentro de las 55. El total correcto es `55 + 22 + 19 + 13 = 109`. La tabla se deja tal cual hasta que se aplique el delta propuesto en *Conflictos detectados → C10*; no afecta a los dos invariantes que sí son exactos: **73 = 55 + 17 + 1** (legacy) y **109 = 55 + 54** (destino).

---

## B) Diagramas de secuencia

### B.1 Checkout

```
POS │ Dominio (T1)                       │ Fiscal (T2)              │ Impresión
───┼─────────────────────────────────────┼──────────────────────────┼──────────
 1 │ BEGIN                                │                          │
 2 │ UPDATE fiscal_series                 │                          │
   │   SET next_number=next_number+1      │                          │
   │   WHERE id=:serie_doc                │                          │
   │     AND next_number<=to_number       │                          │
   │   RETURNING next_number-1  ──► nº 42 │                          │
 3 │ INSERT fiscal_document_reservations  │                          │
   │   (series_id, number, series_role='document')                    │
 4 │ UPDATE fiscal_series … :serie_ctl    │                          │
   │   RETURNING next_number-1 ──► cupo 1 │                          │
   │   ⚠ si la 2ª reserva falla ⇒ ROLLBACK ⇒ el nº 42 también vuelve│
 5 │ SELECT id, available FROM inventory_lots                         │
   │   WHERE product_id=? AND store_id=? AND available>0              │
   │   ORDER BY received_at, id FOR UPDATE SKIP LOCKED                │
   │   → INSERT sale_item_lot_consumptions (lote, qty, unit_cost)     │
 6 │ TaxCalculator::calculate()  ← puro, sin I/O                      │
 7 │ INSERT sales / sale_items / sale_totals                          │
   │   (tax_rate_snapshot, tax_category_id, evidence_status,         │
   │    rounding_adjustment)                                           │
 8 │ INSERT ledger_entries (entry_type='sale') + ledger_lines         │
 9 │ INSERT sale_payments                                               │
10 │ COMMIT  ──────────────────────────► venta persistida             │
   │                                                                   │
   │                                      11 submit(draft) ──► imprenta│
   │                                      12 assignControl() ─► nº ctrl│
   │                                      13 issue(draft)              │
   │                                      14 INSERT fiscal_documents   │
   │                                      + control_assignments        │
   │                                      + fiscal_document_events     │
   │                                      15 COMMIT                    │
   │                                                                   │
   │                                                      16 INSERT print_jobs
   │                                                         (queued, payload_bytes)
   │                                                      17 agente: escribe ESC/POS
   │                                                      18 print_jobs.status='printed'
   │                                                          ⇒ fiscal_documents intacto
```

| Fallo | Efecto |
|---|---|
| Reserva del nº de control falla (rango agotado) | `ROLLBACK` de T1 ⇒ **el nº de documento también vuelve**. Ningún número se quema. |
| Stock insuficiente (todos los lotes bloqueados) | `ROLLBACK` de T1 ⇒ ni venta, ni consumo de lote, ni números consumidos. |
| `TaxCalculator` lanza | `ROLLBACK` de T1 ⇒ nada persistido. |
| Falla la emisión en T2 | T1 ya está confirmada ⇒ la venta queda `status='fiscal_failed'`; **las reservas NO se liberan**; el reintento **reutiliza la misma reserva** (`fiscal_document_reservations`), no quema números nuevos. |
| Falla la impresión | `print_jobs.status='failed'`; el documento sigue `issued`; la reimpresión crea un trabajo nuevo con `reason='reprint'` y **0 reservas nuevas**. |

> **Secuencing de T2 (decisión C5).** La llamada HTTP al proveedor (`submit` → `assignControl`) ocurre **fuera** de la transacción de base de datos. T2 es la transacción que persiste `fiscal_documents` + `fiscal_document_events` + `fiscal_document_control_assignments` **una vez recibida** la respuesta. Así no se mantiene ningún bloqueo de fila durante I/O externo (motivo de D4) y la unidad de escritura sigue siendo atómica. Si la llamada HTTP falla, **no hay transacción que revertir**: la venta queda `sales.status='fiscal_failed'` y el reintento **reutiliza la misma reserva** (`fiscal_document_reservations`), sin quemar números nuevos.

### B.2 Consumo FIFO

```
Lote A  received_at=10-01  received=10  consumed=0  returned=0  available=10
Lote B  received_at=10-05  received=5   consumed=0  returned=0  available=5

Venta de 12:
  SELECT id, available FROM inventory_lots
   WHERE product_id=? AND store_id=? AND available>0
   ORDER BY received_at ASC, id ASC
   FOR UPDATE SKIP LOCKED
   → A (10) : INSERT sale_item_lot_consumptions(A, 10, unit_cost_A)  → available A = 0
   → B ( 5) : INSERT sale_item_lot_consumptions(B,  2, unit_cost_B)  → available B = 3
  product_stores.quantity_in_stock −= 12
  ✔ INVARIANTE: product_stores.quantity_in_stock == SUM(lots.available)

Concurrencia — 5 disponibles, dos cajas venden 4:
  caja1 → toma el lote (available 5→1)
  caja2 → el lote está bloqueado ⇒ SKIP LOCKED lo salta ⇒ no encuentra lote
          ⇒ error "stock insuficiente" (no sobreventa, no espera)
  ⇒ el total consumido NUNCA excede el stock disponible

Devolución de la línea que consumió 2 del lote B:
  SELECT lot_id, quantity FROM sale_item_lot_consumptions
   WHERE sale_item_id = ?                      ← traza, no "el más antiguo"
  UPDATE inventory_lots SET quantity_returned = quantity_returned + 2
   WHERE id = B                               ← repone el lote EXACTO
  ✗ NO repone el lote más antiguo disponible
  ✗ NO crea un lote nuevo por la devolución

Servicio (products.type='service'):
  → no genera lote, no consume stock, no aparece en la traza
```

### B.3 Reserva fiscal

```
Perfil VE: requires_control_number = true, holes_allowed = false
Series:  S_doc  series_role='document' assigned_by='issuer'
         S_ctl  series_role='control'   assigned_by='printer'   (espejo local del rango)

BEGIN
  -- RESERVA 1 · número de documento
  UPDATE fiscal_series SET next_number = next_number + 1
   WHERE id = S_doc AND next_number <= to_number
   RETURNING next_number - 1;                     -- 42
  INSERT INTO fiscal_document_reservations (series_id, number, series_role, reserved_by, reserved_at)
       VALUES (S_doc, 42, 'document', :user, now());

  -- RESERVA 2 · cupo de control  (independiente)
  UPDATE fiscal_series SET next_number = next_number + 1
   WHERE id = S_ctl AND next_number <= to_number
   RETURNING next_number - 1;                     -- 1  →  «00» + «00000001»
  INSERT INTO fiscal_document_reservations (series_id, number, series_role, reserved_by, reserved_at)
       VALUES (S_ctl, 1, 'control', :user, now());

  -- ⚠ Si la RESERVA 2 falla (rango agotado o bloqueo) ⇒ ROLLBACK
  --   ⇒ la RESERVA 1 también revierte: el nº 42 vuelve a estar disponible.
  --   ⇒ No se quema ningún número por un fallo de la segunda reserva.
COMMIT

Primera asignación de control de una serie:
  control_identifier='00'  ·  secuencial=1  ·  sequence_length=8
  → el documento registra el rango total: control_range_from / control_range_to

Anulación posterior del documento 42:
  fiscal_documents.status = 'void';  voided_at = now()
  fiscal_document_reservations.voided_at = now()
  ⇒ la siguiente reserva devuelve 43, NUNCA 42
  ⇒ el número consumido NO se recicla jamás (SNAT/2026/00093 arts. 8.2, 21, 27; SNAT/2024/000102 art. 34)
  ⇒ rollback = APAGAR la emisión, NUNCA renumerar
```

**Invariante:** si `fiscal_profiles.requires_control_number` ⇒ el documento tiene **exactamente una** fila en `fiscal_document_control_assignments`; si no ⇒ **cero**. Se valida **antes** de consolidar la emisión.

---

## C) Contratos de núcleo

### C.1 `ElectronicInvoiceProvider`

```php
namespace App\Domain\Fiscal\Contracts;

interface ElectronicInvoiceProvider
{
    public function submit(FiscalDocumentDraft $draft): SubmissionReceipt;
    public function assignControl(SubmissionReceipt $receipt): ?ControlAssignment;
    public function issue(FiscalDocumentDraft $draft, ?ControlAssignment $control): FiscalDocumentData;
    public function cancel(FiscalDocumentData $document, CancellationReason $reason): CancellationReceipt;
    public function queryStatus(FiscalDocumentData $document): ProviderStatus;
    public function deliver(FiscalDocumentData $document, DeliveryRecipient $recipient): DeliveryReceipt;
}
```

| Elemento | Dónde vive | Tipo | Nota |
|---|---|---|---|
| Modelo de firma | `fiscal_profiles.signer_model` | `authority` \| `psp` \| `printer` \| `legal_signature` | **Dato**. Selecciona la *responsabilidad*, no el país. Sin valor por defecto asumido para `hash_algorithm`/`signature_scheme` (ausentes en la norma). |
| Actor intermedio | `fiscal_providers.kind` | `printer` \| `pac` \| `portal` | **Dato**. `fiscal_providers` = el actor; `fiscal_profile_environments.provider_id` = el canal concreto. |
| Rol del despliegue | `fiscal_profile_environments.role` | `issuer` \| `printer` | Permite que el sistema sea emisor, imprenta o ambos (F1 art. 39) sin bifurcación de código. |
| Asignación de control | `fiscal_document_control_assignments` | 1:1 con el documento | `control_number`, `control_range_from/to`, `control_assigned_at`, `printer_id`, `printer_rif`. **Única** sede del dato (no se duplica en `fiscal_documents`). |
| Numeración | `fiscal_series.series_role` | `document` \| `control` \| `retention_receipt` | `assigned_by` ∈ `issuer` \| `authority` \| `printer` \| `psp`. El comprobante de retención usa `series_role='retention_receipt'` con numeración `AAAAMMSSSSSSSS` (14) y serie propia. |
| QR | `fiscal_documents.qr_content`/`qr_url` | nullable | **Ausente de la norma VE** ⇒ opcional por canal, nunca exigido por el contrato. |
| Referencia | `authority_reference` + `authority_reference_scheme` | par | `CUFE`/`UUID`/`TED`/`VE.PENDING`. Nunca una columna `uuid`. |

**Regla de despach** (anti-país): el dominio **sólo** ramifica sobre `ProviderKind` (3 valores) y `SignerModel` (4 valores), leídos de filas. Un código de país **nunca** aparece en `app/Domain/`. Test de arquitectura: escanea `app/Domain/` y falla ante `'VE'`, `'Venezuela'`, `'USD'`, `'Bs'`, `seniat`, `RIF`.

**`issued` no depende de la impresión.** `fiscal_documents.status='issued'` lo fija `issue()`. `print_jobs` son 0..N e independientes. Invariante (test): un `print_job` en `printed` **no** ha modificado `fiscal_documents` **ni** ha creado filas en `fiscal_document_reservations`. Una reimpresión = `print_job` nuevo con `reason='reprint'` y `reprint_of_job_id`; **0** reservas nuevas.

### C.2 `TaxCalculator`

```php
namespace App\Domain\Tax;

final readonly class TaxCalculator
{
    public function __construct(private TaxRounding $rounding) {}

    /** @param list<TaxLineInput> $lines */
    public function calculate(TaxDocumentInput $input): TaxDocumentResult;
}
```

| Regla | Implementación |
|---|---|
| **Puro** | Sin BD, sin red, sin sistema de ficheros, sin locale, sin `now()` (la fecha va en la entrada). Test: misma entrada ⇒ mismo `hash` de resultado. |
| **Perfiles resueltos antes** | `TaxContext` inyectado: `roundingRule`, `taxInclusiveDefault`, `currencyDecimals`, `exemptionMarker`, `formalContributorPhrase`. `TaxLineInput` lleva `taxCategoryId`, `taxRateId`, **`taxRateSnapshot`** (porcentaje), `evidenceStatus`, `method`, `unitPrice`, `quantity`, `discount`. |
| **Snapshots congelados** | El documento persiste `tax_rate_snapshot` (porcentaje), `tax_category_id`, `tax_rate_id` y `evidence_status` **por línea y por cabecera**. Un cambio posterior de la tasa vigente **no** altera un documento emitido. |
| **Base explícita** | `net = unitPrice × quantity − lineDiscount`; impuesto = `net × rate` (exclusive) o está dentro del precio (inclusive), según `TaxContext.taxInclusiveDefault`. El precio base es el **cobrado**, no el de catálogo (defecto legacy P0 #13). |
| **Descuento de documento** | Prorrateado proporcionalmente a la base imponible; el método queda **declarado** en la entrada. |
| **Redondeo** | `roundingRule='line'` ⇒ se redondea cada línea; `'total'` ⇒ se redondea el total. `roundingAdjustment` = `total redondeado − Σ líneas`, persistido y visible: `Σ líneas + roundingAdjustment = total`. Modo: `half-up` por defecto (dato del perfil). |
| **Precisión** | `Brick\Math\BigDecimal` con escala 6 en el cálculo y 4 en la persistencia. **Prohibido `float`.** |
| **Multinivel** | El resultado alimenta `fiscal_document_taxes` con `line_id` nullable: cabecera (`line_id IS NULL`) y línea. `tax_type='transfer'` \| `'withholding'`. |
| **IVA retenido** | **No** es una categoría de línea de un documento de venta: es un **documento fiscal propio** (`document_type='retention_receipt'`), con numeración 14, período de imposición y referencia al documento de origen. El motor sólo lo marca como `withholding`. |

### C.3 Agente de impresión — contrato de API

| Método | Ruta | Cuerpo | Respuesta |
|---|---|---|---|
| Registro | `POST /api/print-agents/register` | `{agent_id, name, version, capabilities[], token}` | `200 {status:'registered', agent_id, server_time, poll_after_ms}` · `409 {status:'conflict'}` |
| Heartbeat | `POST /api/print-agents/heartbeat` | `{agent_id, instance_id, version, capabilities[], printers[]}` | `200 {status:'ok', pending_jobs, poll_after_ms, server_time}` |
| Trabajo | `GET /api/print-agents/jobs?limit=N` (long-poll, máx 30 s) | — | `200 {jobs:[{job_id, target_id, printer_key, payload_b64, payload_sha256, copies, charset, paper_width, char_per_line}]}` |
| Resultado | `POST /api/print-jobs/{id}/result` | `{agent_id, job_id, payload_sha256, status:'done'\|'error', error?, printed_at, bytes_written}` | `200 {status:'ok'}` (idempotente) · `422 {errorMsg}` |
| Rotación | `POST /api/print-agents/rotate` | `{agent_id, current_token}` | `200 {status:'ok', token, token_valid_until}` |

| Regla | Detalle |
|---|---|
| **Auth** | `Authorization: Bearer <agent_token>` + `X-Agent-Id` en todas. El servidor **fuerza** el scope: el `agent_id` del token debe coincidir con el del body/path. |
| **Tienda** | Se resuelve **desde el `agent_id`**, nunca desde el cuerpo → un token no imprime en otra tienda. |
| **Token** | Por **agente** (no por tienda); sólo `token_hash` (argon2id/bcrypt) en `print_agents`; el token en claro se muestra una vez. TLS obligatorio. |
| **Long-poll** | El agente **siempre inicia**. El long-poll también refresca `last_heartbeat_at` → un solo campo, dos puntos de refresco. Umbral de "desconectado" = **parámetro** (3× intervalo), no literal. |
| **Idempotencia** | Servidor: un `result` repetido sobre un trabajo ya `printed` es no-op. Agente: journal local (`job_id` + `payload_sha256`) → no reimprime ⇒ semántica *efectivamente-una-vez*. |
| **Destinos** | `tcp://host:9100` o impresora compartida de Windows (`smb://`, `LPT1` mapeado). **USB directo excluido** (exige Zadig). |
| **Perfil = dato** | `print_targets.printer_profile`, `char_per_line`, `charset` (CP850 por defecto: **CP858 no es codificable** en `escpos-php`), `max_qr_payload_bytes`. Se **congelan** en el trabajo para poder reintentar sin regenerar. |
| **QR** | Si el perfil no soporta QR ⇒ **fallo en el servidor** con error explícito (nunca un ticket mudo). El **límite de payload lo impone el servidor**; ninguna librería lo valida. |
| **Bytes en el servidor** | El servidor genera el ESC/POS con `mike42/escpos-php`; el agente sólo **escribe** los bytes. Motivo: un único formateador ⇒ mismo ticket en LAN y en servidor; el agente queda **agnóstico al lenguaje** (puede ser Node); y el servidor puede rechazar un ticket no representable **antes** de enviarlo. |
| **Transporte `browser`** | Render **HTML/PDF** desde el **mismo modelo de plantilla**. Lo unificable es el **origen del contenido**, no el binario. |

**Máquina de estados de `print_jobs`:**

```
queued ──► claimed ──► printing ──► printed
   ▲          │            │
   │          │            └──► failed   (attempts agotados; reintentable desde la UI)
   │          │
   └── lease_expires_at (agente muerto) ◄┘        ──► cancelled  (cancelado desde la UI)
```

| Estado | Quién lo pone | Notas |
|---|---|---|
| `queued` | Servidor | Estado inicial. Vuelve de `claimed` si expira el lease (`attempts++`). |
| `claimed` | Agente (en la respuesta del long-poll) | `lease_expires_at = now + X`. |
| `printing` | Agente | Diagnóstico. |
| `printed` | Servidor (tras el `result`) | `printed_at`, `bytes_written`. Terminal. |
| `failed` | Servidor | `last_error`, `attempts`. No es terminal en sentido de negocio. |
| `cancelled` | Servidor | Terminal. |

---

## Flujo de datos

```
POS ──► CheckoutService (T1) ──► sales / sale_items / sale_totals / sale_payments
      │                      ├──► inventory_lots (FOR UPDATE SKIP LOCKED)
      │                      ├──► sale_item_lot_consumptions
      │                      └──► ledger_entries + ledger_lines
      └──► TaxCalculator (puro, en memoria) ──► snapshots a sale_items/sale_totals

POS ──► FiscalIssuanceService (T2) ──► ElectronicInvoiceProvider
                                        ├─ submit()      → SubmissionReceipt
                                        ├─ assignControl()→ ControlAssignment
                                        └─ issue()       → fiscal_documents (+_taxes, _parties,
                                                             _links, _events, _control_assignments)

POS ──► print_jobs (queued) ──► DispatchPrintJob ──► agente LAN ──► impresora (TCP 9100 / compartida)
                                                   └─ transport=browser → HTML/PDF al navegador

Terminales legacy ──► POST sync.php (wire v2 intacto) ──► LegacySyncVerifier
                                                       └─► LegacyStatementTranslator (legacy_translation_map)
                                                       └─► LegacyBatchApplier (1 transacción, idempotente por sync_batches)
```

## Ficheros afectados

| Ruta | Acción | Qué |
|---|---|---|
| `database/migrations/2026_11_01_000001..000027_*.php` | Crear | 27 migraciones (A.1). `down()` en todas. |
| `database/migrations/0001_01_01_000000_create_users_table.php` | Modificar | `password` con hash, sin `raw_password`, `group_id`→FK `roles.id`, sin `preference`. |
| `database/seeders/` | Crear | `FiscalProfileSeeder`, `TaxonomySeeder`, `LedgerEntryTypeSeeder`, `LegacyTranslationMapSeeder`, `OpeningBalancesSeeder`, `DemoStoreSeeder`. |
| `app/Domain/Fiscal/Contracts/ElectronicInvoiceProvider.php` | Crear | Contrato + value objects (`FiscalDocumentDraft`, `SubmissionReceipt`, `ControlAssignment`, `FiscalDocumentData`). |
| `app/Domain/Fiscal/NumberSeries.php` | Crear | Reserva atómica (`UPDATE … RETURNING`) + `fiscal_document_reservations`. |
| `app/Domain/Fiscal/FiscalIssuanceService.php` | Crear | Orquesta T2 (`submit` → `assignControl` → `issue`) + invariante del control. |
| `app/Domain/Tax/{TaxCalculator,TaxContext,TaxLineInput,TaxDocumentInput,TaxDocumentResult}.php` | Crear | Motor puro + snapshots. |
| `app/Domain/Inventory/{LotConsumer,LotReturner,StockInvariant}.php` | Crear | FIFO con `SKIP LOCKED`, devolución al lote exacto, invariante. |
| `app/Domain/Accounting/LedgerWriter.php` | Crear | `entry_type` + `ledger_lines`. |
| `app/Sync/Legacy/{LegacySyncVerifier,LegacyStatementTranslator,LegacyBatchApplier}.php` | Crear | Adaptador v2 (§9.4 de `exploration.md`). |
| `app/Models/*.php` | Crear/Modificar | Modelos Eloquent con FKs reales y casts `decimal`/`json`/`date`. |
| `app/Http/Controllers/PrintAgentController.php` | Crear | 5 endpoints del contrato C.3. |
| `app/Jobs/{DispatchPrintJob,ApplyLegacySyncBatch}.php` | Crear | Fuera del ciclo de la request. |
| `tests/Unit/{Tax,Fiscal,Inventory,Accounting}/` · `tests/Feature/{Fiscal,Inventory,Sync,Print}/` | Crear | Suite del dominio + T1–T3 de concurrencia (sin `RefreshDatabase`). |
| `tests/Architecture/NoCountryLiteralsTest.php` | Crear | Test anti-país. |
| `docs/adr/0001..0006-*.md` | Crear | 6 ADR (**pasada 2**). |
| `.github/workflows/tests.yml` | Modificar | Job con PostgreSQL 17: `npm ci && npm run build` → `npm run lint` → `npm run typecheck` → `vendor/bin/pint --test` → `./vendor/bin/pest`. `typecheck` entra en el gate. |
| `README.md` | Crear | Arranque, precondiciones, credenciales del seed. |

## Estrategia de testing (resumen — el plan ejecutable es la §E)

| Capa | Qué | Cómo |
|---|---|---|
| Unit | `TaxCalculator` puro (2 perfiles sembrados, mismo motor), redondeo, `roundingAdjustment`, `LedgerWriter`, traducción del sync | Pest, `tests/Unit`, sin BD |
| Integration | FIFO (orden, multi-lote, devolución al lote exacto, invariante), reserva fiscal, idempotencia del sync, `print_jobs` | Pest + `RefreshDatabase` **salvo** los de concurrencia |
| Concurrencia | **T1** secuencia sin huecos · **T2** bloqueo real → SQLSTATE `55P03` · **T3** rollback devuelve el número | **Sin `RefreshDatabase`**: 2 conexiones, `SET LOCAL lock_timeout='250ms'`, `DatabaseMigrations` + truncado |
| Arquitectura | Cero literales de país en `app/Domain/`; `typecheck` en el gate de CI | Escaneo de ficheros + job de CI |

## Threat Matrix

**No aplicable** — ninguna de las fronteras de la matriz se ve afectada por este cambio.

| Frontera | Aplicabilidad | Motivo |
|---|---|---|
| Rutas tipo documentación | `N/A` | No se clasifica ni se ejecuta ningún fichero por tipo de nombre. |
| Selección de repositorio git | `N/A` | El cambio no ejecuta ni automatiza git. |
| Estado del commit (staged/index) | `N/A` | Sin automatización de commits. |
| Estado del push (tracking/refspec) | `N/A` | Sin automatización de push. |
| Comandos de PR | `N/A` | Sin automatización de PR. |

**Fuera de esta matriz pero con borde de seguridad propio:** el adaptador de sync **ejecuta SQL remoto**. Ese borde no pertenece a la matriz y está cubierto por su spec (`adaptador-sync-legacy`): sólo `INSERT|UPDATE|DELETE`, sin sentencias apiladas, una transacción por lote, mapa sembrado, idempotencia por `sync_batches`, y validación que **rechaza el lote entero** si una operación no trae `sql`/`args` válidos.

## Migración / rollout

Proyecto **verde**: no hay datos de producción que migrar.

| Aspecto | Plan |
|---|---|
| Esquema | `php artisan migrate:fresh --seed` en verde sobre PostgreSQL 17. Cada migración declara `down()`. |
| Numeración fiscal | **"Apagar, nunca renumerar"**: un rollback **no** borra `fiscal_document_reservations`; desactiva la emisión (`config('fiscal.emission_enabled')`) y conserva la traza. Desde el primer documento emitido la numeración es irrecuperable por diseño. |
| Stock / FIFO | Sin `status` mutable ⇒ no hay estado desincronizable que revertir. Sin datos de producción, el reset es `migrate:fresh`. |
| Mapa de traducción | **Dato sembrado**: truncar filas y re-sembrar. Nunca afecta al esquema. |
| Interruptores | `fiscal.emission_enabled` · `sync.legacy_adapter_enabled` · `print.lan_agent_enabled` — todos por defecto **off** hasta que el módulo esté validado. |
| Saldos de apertura | Dependencia **operativa**: `opening_balances` con `certified_at` + `certified_by`; un comando `ops:check-opening-balances` bloquea el arranque operativo si no hay saldos certificados (P9). |

## D) Los 6 ADR

Seccionados; cada uno con **contexto, decisión, consecuencias y alternativas descartadas**. Los ficheros canónicos están en `docs/adr/`; el **mecanismo** (esquema, diagramas, contratos, plan de tests) queda en §A–§C y §E de este documento.

| ADR | Fichero | Decisión | Motivo (una línea) | Alternativa descartada |
|---|---|---|---|---|
| ADR-0001 | `docs/adr/0001-numeracion-fiscal-doble-serie.md` | Numeración fiscal de dos series con reserva atómica | La norma tiene dos autoridades de numeración; una sola reserva no garantiza nada | Una sola serie (`exploration.md` §5) |
| ADR-0002 | `docs/adr/0002-actor-intermedio-imprenta-digital.md` | Actor intermedio emisor / imprenta digital | El emisor no emite solo (F2 art. 18.4/18.6) | `issue()` único sin proveedor |
| ADR-0003 | `docs/adr/0003-capa-pais-fiscal-como-datos.md` | Capa de país/fiscal como datos, sin `if` por país | La Fase 2 debe ser "insertar filas" | `if ($country === 'VE')` en el dominio |
| ADR-0004 | `docs/adr/0004-stock-por-lotes-sin-status-mutable.md` | Stock por lotes sin `status` mutable + `FOR UPDATE SKIP LOCKED` | La aritmética no puede desincronizarse; `SKIP LOCKED` da concurrencia sin sobreventa | `status` enum + `UPDATE` sin `id` (defecto legacy P0) |
| ADR-0005 | `docs/adr/0005-bytes-escpos-en-el-servidor.md` | Bytes ESC/POS en el servidor + agente en pull + USB descartado | Un solo formateador; el agente agnóstico; Zadig es inviable en desatendido | Render en el agente · push · USB directo |
| ADR-0006 | `docs/adr/0006-adaptador-sync-v2-wire-intacto.md` | Adaptador sync v2: wire intacto, traducción por datos, idempotencia | El terminal sigue siendo legacy y reenvía; cambiar el contrato lo rompería | Reescribir el protocolo · traducir en código · cambiar el wire |

**Decisiones adicionales que también serán ADR** (menor, no en esta tanda): D7 (`ledger_lines`), D10 (`brick/math`), D11 (bytes en servidor → ya en ADR-0005), D13 (token por agente → ADR-0005 §Consecuencias), D17 (Redis para nonces → ADR-0006 §Decisión), D18 (editar la migración base en verde).

### D.1 ADR-0001 · Numeración fiscal de dos series con reserva atómica

**Contexto.** SNAT/2026/00093 distingue **dos** numeraciones con autoridades distintas: la del **documento**, consecutiva y única, a cargo del emisor (art. 8.2), precedida de la palabra "serie" si no hay sistema centralizado (art. 20); y el **Nº de Control**, consecutivo y único por emisor, a cargo de la **imprenta digital** (art. 8.3, 9.1, 27; SNAT/2024/000102 art. 30). El número consumido **no se libera** (art. 8.2, 21, 27; F2 art. 34). El legacy no reserva nada: `generate_invoice_id()` (`_inc/helper/invoice.php:139-172`) deriva del "último `invoice_id`" leído y le suma 1; `unique_transaction_ref_no()` (`:95-109`) usa `rowCount()` de un `SELECT`, que en PDO no es el nº de filas afectadas. Con dos cajas simultáneas, dos documentos reciben el mismo número.

**Decisión.**
1. `fiscal_series` se distingue por `series_role` (`document` | `control` | `retention_receipt`) y `assigned_by` (`issuer` | `authority` | `printer` | `psp`).
2. Cada documento fiscal tiene **dos reservas atómicas independientes**, una por serie, dentro de la misma transacción que el documento: `UPDATE fiscal_series SET next_number = next_number + 1 WHERE id = ? AND next_number <= to_number RETURNING next_number - 1`.
3. El **Nº de Control se reserva localmente como espejo** del rango autorizado de la imprenta (`from_number`/`to_number`/`credential_ref` sembrados) y se **verifica** contra lo que la imprenta devuelve; la discrepancia se registra como evento en `fiscal_document_events`.
4. La traza vive en `fiscal_document_reservations` (`series_id`, `number`, `series_role`, `reserved_by`, `reserved_at`, `voided_at`).
5. **La anulación no libera el número** (`status='void'` + `voided_at`); **el rollback sí lo devuelve** (la transacción fallida revierte el contador).
6. El rollback operativo de la numeración es **apagar, nunca renumerar**: desde el primer documento emitido la numeración es irrecuperable por diseño.

**Consecuencias.** *Positivas:* unicidad correcta por construcción; las dos autoridades con rango, vigencia y ciclo de vida propios; número quemado auditable y defendible; verificable con T1–T3. *Costes:* doble superficie de bloqueo (dos filas de `fiscal_series`); el emisor necesita el rango de control sembrado antes de operar; irreversibilidad desde el primer documento emitido. *Riesgo residual:* la divergencia entre el control reservado y el devuelto por la imprenta se detecta **después** de emitir (T2) ⇒ evento de discrepancia + `sales.status='fiscal_failed'` con reutilización de la reserva.

**Alternativas descartadas.** Una sola serie (`exploration.md` §5) · `SELECT` + `rowCount()` + `+1` (no atómico, prohibido por `rules.design`) · `SELECT … FOR UPDATE` en vez de `RETURNING` (no devuelve el valor reservado en la misma sentencia) · esperar el control de la imprenta antes de numerar (rompe la consecutividad sin huecos y bloquea la caja) · reciclar el correlativo tras anulación (prohibido) · liberar la reserva cuando falla T2 (el número puede haberse publicado: se **reutiliza**, no se devuelve).

### D.2 ADR-0002 · Actor intermedio obligatorio: emisor e imprenta digital

**Contexto.** El emisor **no emite solo**: F2 art. 18.4 obliga a contratar una **imprenta digital autorizada**; el art. 18.6 exige remitirle la estructura para su validación; el art. 29 y F1 art. 42.1 establecen que la **imprenta** asigna el Nº de Control y valida estructura y autenticidad ⇒ el control es **entrada**, no salida. F1 art. 4 enumera los medios (Portal/App del SENIAT, imprenta digital o proveedor autorizado, máquinas fiscales) y el art. 39 permite que el propio sistema sea **imprenta digital**, con obligaciones propias de registro automatizado (art. 44).

**Decisión.**
1. Toda emisión atraviesa un **actor intermedio** por `ElectronicInvoiceProvider`, que separa fases: `submit(FiscalDocumentDraft): SubmissionReceipt` → `assignControl(SubmissionReceipt): ?ControlAssignment` → `issue(FiscalDocumentDraft, ?ControlAssignment): FiscalDocumentData`, más `cancel()`, `queryStatus()` y `deliver()`.
2. El actor es **dato**: `fiscal_providers.kind` ∈ `printer` | `pac` | `portal`; el canal concreto es `fiscal_profile_environments` con `provider_id`.
3. La responsabilidad de la firma es **dato**: `fiscal_profiles.signer_model` ∈ `authority` | `psp` | `printer` | `legal_signature`; sin valor por defecto para `hash_algorithm`/`signature_scheme` (no hay algoritmo en la norma).
4. El papel del despliegue es **dato**: `fiscal_profile_environments.role` ∈ `issuer` | `printer`.
5. El control tiene **una única sede**: `fiscal_document_control_assignments` (1:1 con el documento).
6. El dominio ramifica **sólo** sobre `ProviderKind` (3 valores) y `SignerModel` (4 valores), leídos de filas.

**Consecuencias.** *Positivas:* un solo flujo cubre emisor, imprenta y portal (añadir un canal es una fila); el rol dual queda disponible sin reescribir el núcleo; la fase de emisión es auditable antes de que exista documento; QR, hash y firma dejan de ser supuestos. *Costes:* sin canal configurado no se emite; `assignControl()` puede no devolver control (canal portal) ⇒ retorno nullable e invariante validada por perfil; el control llega **después** del documento ⇒ la numeración de control no puede depender de la respuesta de la imprenta (ADR-0001); el adaptador real está bloqueado por el Manual Técnico del art. 41. *Riesgo residual:* un canal mal configurado falla en T2, no en el alta.

**Alternativas descartadas.** `issue()` único sin proveedor (contradice F2 18.4/18.6 y F1 42.1) · emisión directa al SENIAT como camino único (excluye la imprenta digital) · actor y rol como columnas en `fiscal_profiles` (un despliegue puede tener varios canales) · `if ($kind === 'printer')` (bifurca el núcleo) · duplicar el control en `fiscal_documents` y en `fiscal_document_control_assignments` (dos fuentes de verdad).

### D.3 ADR-0003 · Capa de país/fiscal como datos, sin `if` por país

**Contexto.** `openspec/config.yaml → context` fija la regla de oro (el motor fiscal, de impuestos, de numeración y de formatos es **dato, no código**) y la regla de control (*cambiar de país = insertar filas*). La comparación CL/MX/CO/PA/VE (`research/estandares-latam.md` §4.3) registra **12 divergencias** que obligan a diseño de modelo: impuestos multinivel (D1), doble ubicación del tratamiento fiscal (D2), vocabularios de estado incomparables (D3), cuatro formas de referencia (D4), tres roles de parte (D5), cuatro dueños del sello (D6), tres formas de autorizar el rango (D7), cuatro naturalezas de anulación (D8), QR obligatorio sólo en CO (D9), doble numeración (D10), actor intermedio (D11), RIF opcional para persona natural (D12). En el legacy el prefijo de país vive en **16 sitios** (`get_preference('invoice_view') == 'indian_gst'`), no en un `switch`: la fuga ya es difusa.

**Decisión.**
1. La capa de país/fiscal es un conjunto de **catálogos**: `countries`, `currencies`, `number_formats`, `fiscal_profiles`, `fiscal_profile_document_types`, `fiscal_profile_environments`, `fiscal_profile_fields`, `fiscal_tax_schemes`, `fiscal_status_vocabularies`, `tax_categories`, `tax_rates`, `fiscal_providers`.
2. El dominio **sólo** ramifica sobre enums genéricos leídos de filas: `ProviderKind`, `SignerModel`, `EnvironmentRole`, `TaxMethod`, `RoundingRule`. **Nunca** sobre un código de país.
3. Todo parámetro normativo lleva `evidence_status` y `source_ref`: una tasa no confirmada se siembra `pending_confirmation`.
4. Frases y tokens obligatorios (prefijo "serie" del art. 20, "Contribuyentes Formales del IVA", marcador `(E)`) se siembran en `fiscal_profile_fields`; el dominio **compara contra el valor leído**.
5. Control **ejecutable**: `tests/Architecture/NoCountryLiteralsTest.php` escanea `app/Domain/` y falla ante cualquier literal de país, moneda o autoridad, informando fichero, línea, literal y categoría (§E.3).

**Consecuencias.** *Positivas:* la Fase 2 se reduce a sembrar filas; un solo motor sirve todos los perfiles; D1–D12 tienen sede propia en el esquema; cada dato sembrado es rastreable hasta la norma. *Costes:* un país nuevo exige sembrar muchas filas (coste de **datos**, no de código); el test anti-país es un **proxy** (no detecta forks sin literal); el vocabulario genérico es más pobre que el nativo (el código nativo vive en `native_status_code`). *Riesgo residual:* un adaptador de país colocado por error **dentro** de `app/Domain/` puede reintroducir el fork sin que el test lo detecte ⇒ los adaptadores viven fuera (`app/Providers/Fiscal/`) y el test verifica la ruta.

**Alternativas descartadas.** `if ($country === 'VE')` / `switch` de país · una clase de dominio por país · configuración por `config/` (no auditable ni versionable como dato) · interfaces por país (`TaxCalculatorVE`, `TaxCalculatorCL`) · detectar el país por heurística (moneda, decimales, formato) · sin test anti-país (la convención sola ya se erosionó en el legacy).

### D.4 ADR-0004 · Stock por lotes sin `status` mutable + `FOR UPDATE SKIP LOCKED`

**Contexto.** El FIFO del legacy tiene tres defectos verificados en `_inc/model/invoice.php`: (1) el `SELECT` de lotes (`:237`, `:244`) **no tiene `ORDER BY`** ⇒ FIFO no determinista; (2) el `UPDATE` de consumo (`:262-263`) es `UPDATE purchase_item SET total_sell = total_sell + … WHERE store_id = ? AND item_id = ? AND status = ?` **sin `id = ?`** ⇒ con varios lotes activos el consumo se contabiliza **en todos** (hallazgo H1, ausente del catálogo del mapa); (3) cantidades interpoladas en el SQL (`:262`, `:294`). La tríada `qty_in`/`qty_out`/`status` codifica "disponible" con un enum que hay que sincronizar, y la devolución actualiza `purchase_item` con dos claves distintas (`_inc/sell_return.php:142-153`).

**Decisión.**
1. `inventory_lots` **no tiene `status` mutable**: `available = quantity_received − quantity_consumed + quantity_returned`, como `GENERATED ALWAYS AS (…) STORED` ⇒ exacta por definición.
2. Todo consumo deja traza en `sale_item_lot_consumptions` (`sale_item_id`, `lot_id`, `quantity`, `unit_cost`).
3. Consumo `ORDER BY received_at ASC, id ASC` con `FOR UPDATE SKIP LOCKED`.
4. La devolución repone **el lote exacto** desde la traza: no repone el más antiguo disponible ni crea un lote nuevo.
5. `product_stores.quantity_in_stock` es **caché** (el requisito lo permite con test de invariante), con índice parcial `inventory_lots (product_id, store_id, received_at, id) WHERE available > 0`.
6. Un producto de servicio no genera lote ni consume stock.

**Consecuencias.** *Positivas:* no hay estado que pueda desincronizarse; FIFO determinista y auditable; COGS desde la traza y devolución al coste correcto; `SKIP LOCKED` ⇒ dos cajas venden a la vez sin sobreventa; el defecto H1 es estructuralmente imposible. *Costes:* `SKIP LOCKED` puede devolver "stock insuficiente" **aunque exista stock** si el único lote disponible está bloqueado (se acepta: preferible a la sobreventa, y el error es reintentable ⇒ hay que distinguirlo en el mensaje); `available` GENERATED no se indexa por sí sola ⇒ índice parcial; el caché exige un **test de invariante permanente**; `quantity_returned` es una tercera aritmética que el legacy no tenía. *Riesgo residual:* el test de invariante comprueba el caché, no el conteo físico.

**Alternativas descartadas.** `status` enum + `UPDATE` (el defecto del legacy) · `FOR UPDATE` sin `SKIP LOCKED` (serializa las cajas, riesgo de `lock_timeout` en el punto de venta) · reponer el lote "más antiguo disponible" (rompe la traza de coste) · crear un lote nuevo por devolución (infla los lotes y pierde la correspondencia) · derivar el stock siempre por `SUM(available)` (O(lotes) en cada lectura) · `quantity_available` mantenida a mano (reintroduce el estado desincronizable).

### D.5 ADR-0005 · Bytes ESC/POS en el servidor + agente en modo pull; USB directo descartado

**Contexto.** El legacy genera el ESC/POS **en el servidor** (`_inc/print.php:30-31`) pero el endpoint está roto (`dd($data)` en `:29` y un segundo `dd($printer)` en `_inc/lib/escpos.php:32`); su modo "navegador" imprimía **HTML**, no ESC/POS (`PosController.js:881-890`): el binario nunca fue denominador común. En la nube el servidor deja de alcanzar la impresora de la LAN (NAT, firewall, IP privada) ⇒ hace falta un proceso en la LAN. Dos restricciones verificadas condicionan el diseño: **Windows + USB directo exige Zadig** (la ruta `libusb` de `node-escpos/driver` no puede separar el kernel driver en Windows: *"This functionality is not available on Windows"*) y **CP858 no es codificable** en `mike42/escpos-php` (`capabilities.json` declara la tabla 19 sin `iconv`; `CodePage::isEncodable()` devuelve `false`).

**Decisión.**
1. El **servidor genera los bytes ESC/POS** con `mike42/escpos-php` y los congela en `print_jobs.payload_bytes` + `payload_sha256`. El agente **sólo escribe**.
2. El agente trabaja en **pull** con long-poll: siempre inicia la conexión (registro, heartbeat, `GET /api/print-agents/jobs`).
3. **Destinos admitidos**: `tcp://host:9100` o **impresora compartida de Windows** (`smb://`, `LPT1` mapeado). **USB directo excluido**.
4. **Token por agente** (no por tienda), sólo `token_hash` (argon2id/bcrypt), con `Authorization: Bearer` + `X-Agent-Id`; el servidor fuerza la coincidencia del `agent_id` y **resuelve la tienda desde el `agent_id`**, nunca desde el cuerpo.
5. El **perfil de impresora es dato** de `print_targets` (`printer_profile`, `charset` con CP850 por defecto, `char_per_line`, `max_qr_payload_bytes`), congelado en el trabajo; si el perfil no soporta QR, la generación **falla en el servidor** con error explícito, y el límite de payload del QR lo impone el servidor (ninguna librería lo valida).
6. `fiscal_documents.status='issued'` es **independiente** de `print_jobs.status`; una reimpresión crea un trabajo nuevo (`reason='reprint'`, `reprint_of_job_id`), con **0 reservas nuevas** y **0** modificación del documento.

**Consecuencias.** *Positivas:* un único formateador ⇒ el mismo ticket en LAN, servidor y `browser` (que renderiza HTML/PDF desde el **mismo modelo de plantilla**); el agente es **agnóstico al lenguaje** (puede ser Node); el servidor puede **rechazar un ticket no representable antes de enviarlo** (el error aparece en el backoffice, no como un `?` en el papel); el token por agente acota una fuga a **una caja**; `issued` no depende de la impresora. *Costes:* el servidor carga con el render ESC/POS y con la librería; hay que dar de alta las impresoras con su perfil correcto; `escpos-php` no trae composición de ticket; el instalador del agente es **entregable de Fase 1** (riesgo R9); el long-poll mantiene una petición abierta por agente y `poll_after_ms` es un parámetro. *Riesgo residual:* el límite real de payload del QR es el buffer del modelo y no es verificable en este entorno ⇒ valor conservador en `print_targets.max_qr_payload_bytes`, calibrado en Fase 1.

**Alternativas descartadas.** Renderizar el ticket en el agente (tres implementaciones ⇒ tres formatos) · render desde una DSL (el agente vuelve a depender de una librería) · push desde el servidor (exige IP pública, EXP o VPN por terminal; un "push" sobre la conexión abierta por el agente es pull con otra forma) · USB directo (Zadig; para una impresora USB en terminal Windows la salida es la **impresora compartida**, sin driver adicional) · token por tienda (un terminal comprometido de la tienda A podría suplantar a la B) · que el navegador genere ESC/POS (el navegador no habla ESC/POS) · CP858 por defecto (no codificable).

### D.6 ADR-0006 · Adaptador del protocolo sync v2: wire intacto, traducción por datos sembrados, idempotencia por `sync_batches`

**Contexto.** Los terminales offline **siguen siendo legacy** durante la transición (decisión de producto P1): el nuevo sistema reemplaza al legacy y `sync.php` viejo se apaga el mismo día. El payload es **SQL crudo de MySQL con nombres del legacy**: `_inc/lib/database.php:79` escribe `sql . '|' . serialize($args)` en `storage/logs/sql.txt` y `ReadSyncData()` (`_inc/model/cron.php`) lo arma como `[{"sql": …, "args": […]}]`. El maestro nuevo es PostgreSQL 17 con esquema de nombres nuevos ⇒ hay que **traducir identificadores**, no sólo verificar firmas. Dos fallos operacionales verificados que el maestro debe tapar **sin tocar el contrato**: **S1 — no es idempotente** (el maestro confirma en `sync.php:141-148` y el cliente sólo trunca su log si la respuesta fue exitosa ⇒ una respuesta perdida provoca un reenvío idéntico ⇒ violación de PK ⇒ el terminal queda congelado; hallazgo H3, el riesgo operativo nº 1 de la ventana de corte) y **S2 — nonces en sistema de ficheros** (`storage/sync-nonces/`, que no escala a varios contenedores; H4). Además `ReadSyncData()` parte el log con `explode('|')`: un `|` en un literal pierde la operación en silencio (H7).

**Decisión.**
1. **El contrato de wire queda intacto**: mismas claves JSON, mismo canonizado (`username \n action \n timestamp \n nonce \n sha256(data)`), misma firma `hash_hmac('sha256', …)`, mismo `SYNC_MAX_AGE`, mismo orden de verificación — cliente conocido → timestamp → `hash_equals` de la firma → **sólo después** consumo del nonce — y misma forma de respuesta.
2. **La traducción de identificadores es un dato sembrado**: `legacy_translation_map` (`legacy_table`, `legacy_column`, `new_table`, `new_column`, `transform`), dirección **legacy → destino**, cubriendo las ~18 tablas del terminal. El reescritor es ligero: sólo identificadores, no un parser SQL completo.
3. **El lote se aplica en una única transacción**, sólo `INSERT|UPDATE|DELETE`, sin sentencias apiladas; una operación sin `sql`/`args` válidos ⇒ **se rechaza el lote entero**.
4. **Idempotencia en el maestro**: `sync_batches` con `UNIQUE (client_id, payload_sha256)`; un reenvío con el mismo contenido responde **éxito** sin reaplicar.
5. **Los nonces viven en Redis** (`SET NX EX`): mismo contrato, mejor implementación; la creación es atómica y el consumo sigue ocurriendo **después** de validar la firma.
6. El des-escapeado de los valores que el legacy HTML-escapea (`Request`) se normaliza **una vez, en la frontera** del adaptador.

**Consecuencias.** *Positivas:* el terminal legacy **no cambia** (cero coste de despliegue y cero riesgo de congelamiento en la ventana de corte); el fallo S1 desaparece sin tocar el protocolo (es una **adición de servidor**); el mapa es auditable y ajustable sin desplegar; la traducción **falla cerrada**. *Costes:* hay que **mantener el mapa sincronizado con el esquema** (un mapa desalineado rompe el lote ⇒ test que compara el mapa con las tablas y columnas destino); el reescritor no es un parser SQL (una forma no contemplada ⇒ el lote se rechaza; se acepta: el legacy verificado no usa `ON DUPLICATE KEY UPDATE` ni `REPLACE INTO`, y PostgreSQL acepta los backticks como delimitadores); Redis pasa a ser dependencia del sync; la idempotencia por hash cubre reenvíos **idénticos**, no lotes con el mismo contenido y distinto `client_id` (correcto: son clientes distintos). *Riesgo residual:* la traducción es el punto de mayor riesgo de la fase (R4) ⇒ tests de traslación con casos reales del log del terminal y rechazo del lote completo ante cualquier operación no reconocida.

**Alternativas descartadas.** Reescribir el protocolo v2 (los terminales son legacy: cambiarlo los deja sin sincronizar en la ventana de corte) · traducir por tabla en código (18 tablas × código; contradice "datos, no código") · tablas o vistas espejo del legacy (duplican el modelo; `INSERT` sobre vista no es válido) · normalizar el log en el terminal (exige tocar producción) · resolver la idempotencia en el cliente (modifica el terminal, no el maestro) · nonces en ficheros (no escala) · consumir el nonce antes de validar la firma (permite que una petición no autenticada queme nonces ajenos).


## E) Plan de testing ejecutable

### E.1 T1–T3: mecanismo concreto, sin `RefreshDatabase`

**Por qué no `RefreshDatabase`.** `RefreshDatabase` envuelve cada test en una transacción que se revierte al terminar. La **segunda conexión** es independiente y **no ve las filas no commiteadas** ⇒ el `SELECT … FOR UPDATE` de A no bloquearía a B ⇒ el test pasaría sin probar el bloqueo. Por eso `numeracion-fiscal` §"T1–T3 verifican la reserva sin `RefreshDatabase`" lo exige explícitamente.

| Aspecto | Con `RefreshDatabase` | Con `DatabaseMigrations` + `TRUNCATE` |
|---|---|---|
| Aislamiento de datos | Sí — transaction + rollback | Manual: `TRUNCATE … RESTART IDENTITY CASCADE` de las tablas tocadas en `afterEach` |
| Estado del esquema | Sí | Sí (migra y **commitea**) |
| Visibilidad entre 2 conexiones | **No** | **Sí** — ambas ven el estado commiteado |
| Bloqueo de fila reproducible | **No** | **Sí** |
| `SQLSTATE 55P03` reproducible | **No** | **Sí** |
| Rollback que devuelve el número | **No verificable** (el rollback de Laravel es al final del test, no el de la reserva) | **Sí** |

**Segunda conexión.** Se declara en `config/database.php`:

```php
'pgsql_b' => [
    'driver'         => 'pgsql',
    'host'           => env('DB_HOST', '127.0.0.1'),
    'port'           => env('DB_PORT', '5432'),
    'database'       => env('DB_DATABASE', 'pos_moderno_v2_test'),
    'username'       => env('DB_USERNAME', 'pos'),
    'password'       => env('DB_PASSWORD', 'pos'),
    'charset'        => 'utf8',
    'prefix'         => '',
    'prefix_indexes' => true,
    'search_path'    => 'public',
    'sslmode'        => 'prefer',
],
```

Se abre con `DB::connection('pgsql_b')` (Laravel la mantiene viva durante el test y la cierra al destruirla). Debe abrirse **fuera** de la transacción del test: son **dos conexiones PDO distintas** al mismo servidor ⇒ **dos sesiones PostgreSQL distintas** ⇒ los bloqueos de fila son reales, no simulados.

**`SET LOCAL lock_timeout`.** `SET LOCAL` aplica **sólo** a la transacción en curso y se descarta al hacer `COMMIT`/`ROLLBACK`; `SET` persistiría en la sesión y contaminaría los tests siguientes. Por eso va **dentro** de la transacción de B:

```php
$b = DB::connection('pgsql_b');
$b->beginTransaction();
$b->statement("SET LOCAL lock_timeout = '250ms'");
```

**T1 — secuencia sin huecos** · `tests/Feature/Fiscal/NumberReservationSequenceTest.php`
1. Una conexión. Serie con `next_number = 100`, `to_number = 200`.
2. 50 reservas consecutivas con `NumberSeries::reserve($seriesId)` (que ejecuta `UPDATE … RETURNING`).
3. Aserciones: los 50 números devueltos son `100…149` **en orden** (sin huecos, sin repeticiones); `fiscal_series.next_number == 150`; hay 50 filas en `fiscal_document_reservations` con `series_role='document'`.
4. T1 **no** necesita dos conexiones: verifica la corrección del contador, no el aislamiento.

**T2 — bloqueo real → SQLSTATE `55P03`** · `tests/Feature/Fiscal/NumberReservationLockTest.php`
1. Conexión A: `DB::connection('pgsql')->beginTransaction()` + `SELECT id FROM fiscal_series WHERE id = :id FOR UPDATE` ⇒ la fila queda bloqueada.
2. Conexión B: `beginTransaction()` + `SET LOCAL lock_timeout = '250ms'`.
3. Conexión B intenta la reserva (el mismo `UPDATE … RETURNING`).
4. Aserciones:
   - se lanza `Illuminate\Database\QueryException`;
   - `$e->getCode() === '55P03'` (SQLSTATE) y el mensaje de PostgreSQL contiene `lock_not_available`;
   - `fiscal_series.next_number` **no** cambió;
   - la espera efectiva está entre **250 ms y 3 s** (acota el test: si el `lock_timeout` no se aplicó, falla rápido en vez de colgarse).
5. `rollBack()` de B y de A en `finally`.

> **Distinción que la regla fija**: la reserva fiscal **no** usa `SKIP LOCKED` (a diferencia del FIFO de stock, §B.2). Aquí se **espera** y se falla con timeout; saltarse la fila convertiría un bloqueo en un correlativo repartido al azar.

**T3 — el rollback devuelve el número** · `tests/Feature/Fiscal/NumberReservationRollbackTest.php`
1. Conexión A: `beginTransaction()` → reserva (devuelve 42) → `INSERT` en `fiscal_document_reservations` → **fallo forzado** (FK inexistente en `sales` o excepción explícita) → `rollBack()`.
2. Aserciones:
   - `fiscal_series.next_number` vuelve al valor previo;
   - la siguiente reserva devuelve **42** (el mismo número);
   - no queda ninguna fila en `fiscal_document_reservations` con ese número ⇒ el rollback deshace también la traza;
   - **verificación desde la conexión B** (`SELECT next_number FROM fiscal_series WHERE id = :id`): devuelve el valor original ⇒ el número volvió **de verdad**, no sólo en la vista de la sesión A.

### E.2 Suite del dominio (Pest)

| Área | Regla que fija | Tests |
|---|---|---|
| Checkout | toda escritura es una unidad atómica; el fallo intermedio no deja cabecera ni stock | `test_un_fallo_intermedio_no_deja_cabecera_ni_cambio_de_stock_persistidos` |
| FIFO | orden, multi-lote, devolución al lote exacto, invariante, servicio | los 4 tests de `inventario-lotes` + `test_un_producto_de_servicio_no_genera_lote_ni_consume_stock` |
| IVA | dos perfiles con un motor; snapshot congelado; redondeo; retenido propio | los 4 tests de `motor-impuestos` |
| Crédito | el saldo del cliente se deriva de sus movimientos; un cobro reduce deuda | `test_un_cobro_reduce_la_deuda_del_cliente_y_el_saldo_se_deriva_de_sus_movimientos` |
| Cuota | interés simple sobre el payable, calculado en el servidor y repartido entre cuotas | `test_la_cuota_se_calcula_en_el_servidor_con_interes_simple_sobre_el_payable_y_se_reparte_entre_cuotas` |
| Transferencia | una sola cabecera; el stock sale del lote origen y entra en el destino | `test_la_transferencia_mueve_el_stock_desde_el_lote_origen_y_registra_una_sola_cabecera` |
| Reserva fiscal | dos series, dos reservas, sin huecos, bloqueo real, rollback | T1, T2, T3 (§E.1) |
| Caja | saldo por fecha de negocio, no por día/mes/año | `test_el_saldo_de_caja_por_fecha_de_negocio_coincide_con_los_movimientos_del_periodo` |
| Invariantes | caché de stock == `SUM(available)` · saldo de cuenta == `SUM(asientos)` · `roundingAdjustment` cuadra · control ↔ perfil | tests de `inventario-lotes`, `contabilidad-caja` y `numeracion-fiscal` |
| Documento fiscal | el mismo nº de documento y de control en todas las páginas | `test_un_documento_multipagina_conserva_el_mismo_numero_de_documento_y_de_control` |

**6 tests transversales.** Los de la última columna no son trazables a un requisito individual: los exige `calidad-continua` §"Suite Pest del dominio" (*el checkout, el FIFO, los impuestos, la reserva fiscal, las cuotas, el saldo de caja y la devolución que repone stock*). **Total de la suite: 44 trazables + 6 transversales = 50 tests con nombre.**

### E.3 Test de arquitectura anti-país

| Aspecto | Definición |
|---|---|
| Fichero | `tests/Architecture/NoCountryLiteralsTest.php` |
| Qué escanea | Recursivamente `app/Domain/**/*.php`. **No** escanea `tests/`, `config/`, `database/seeders/` ni `app/Providers/Fiscal/` (los adaptadores de canal legítimamente contienen literales). |
| Qué busca | **1) Códigos ISO-3166 alpha-2** en posición de comparación o de `match`/`switch`/`case`: `VE`, `CL`, `MX`, `CO`, `PA`, `AR`, `PE`, `EC`, `BO`, `UY`, `GT`, `CR`, `PY`, `DO`, `PR`, `BR`. **2) Nombres de país** en literales: `Venezuela`, `Chile`, `Mexico`/`México`, `Colombia`, `Panama`/`Panamá`, `Argentina`, `Peru`/`Perú`, `Bolivia`, `Ecuador`, `Uruguay`, `Guatemala`, `Costa Rica`, `Paraguay`, `Dominicana`, `Brazil`/`Brasil`. **3) Códigos de moneda y símbolos** como literal: `USD`, `VES`, `MXN`, `CLP`, `COP`, `PAB`, `Bs`, `Bs.`. **4) Autoridades y organismos**: `seniat`, `sii`, `sat`, `dian`, `dgi`, `RIF`, `RUC`, `NIT`. **5) Tokens normativos locales** como literal: `serie` (prefijo del art. 20), `Contribuyentes Formales del IVA`, `(E)`. |
| Dónde **no** busca | Nombres de clase, método o constante (`VenezuelaFiscalProfile` no es un literal). Sólo **literales de cadena** y **comparaciones**: evita falsos positivos y hace la regla precisa. |
| Qué falla | `expect($hallazgos)->toBeEmpty()`: falla ante **cualquier** hallazgo. Sin lista blanca — un literal de país en `app/Domain/` es un defecto por definición. |
| Con qué mensaje | `Fallo de arquitectura: app/Domain/ contiene N literales de país.` + una línea por hallazgo `ruta:línea  categoría=…  literal=…` + el cierre `El motor fiscal, de impuestos, de numeración y de formatos es DATO, no código: cambiar de país debe consistir sólo en insertar filas.` |
| Categorías | `country-code` · `country-name` · `currency` · `authority` · `locale-token` |
| Enforcement de **C4** | El token `serie` del art. 20 es `fiscal_profile_fields.value`; su aparición como literal en `app/Domain/` es categoría `locale-token` ⇒ el test **hace ejecutable** la resolución de C4. |
| Control complementario | `test_el_motor_de_impuestos_no_consulta_la_base_de_datos_ni_la_red` (pureza del `TaxCalculator`, §C.2): un motor que no puede leer el país no puede ramificar por él. |

### E.4 Puerta de calidad (CI)

| Etapa | Comando | Estado verificado en `.github/workflows/tests.yml` |
|---|---|---|
| Build (precondición de la suite) | `npm ci && npm run build` | ✅ presente (`:49-53`) |
| Typecheck | `npm run typecheck` | ✅ **ya está en el gate** (`:67-68`) — el hueco que la pasada 1 daba por abierto **ya no existe** (ver C8) |
| Lint JS | `npm run lint:check` | ✅ presente (`:69-71`). Ojo: `npm run lint` es `eslint . --fix` (**muta**); en CI debe usarse `lint:check` |
| Lint PHP | `vendor/bin/pint --test` | ❌ **falta** en el workflow → tarea de `sdd-tasks` |
| Suite | `./vendor/bin/pest` | ✅ presente (`:64-65`), **después** del build |
| Precondición explícita | `public/build/manifest.json` debe existir | ⚠ implícita: hoy falla con `ViteManifestNotFoundException` (HTTP 500) en vez de un error de precondición ⇒ añadir un paso que verifique el manifiesto antes de la suite |

Servicio: `postgres:17-alpine` con `POSTGRES_DB=pos_moderno_v2_test`, `POSTGRES_USER=pos` (`:19-31`) ⇒ coincide con `phpunit.xml` (`DB_CONNECTION=pgsql`, `DB_DATABASE=pos_moderno_v2_test`).

Orden propuesto (cumple `rules.design` y `calidad-continua`): `npm ci` → `npm run build` → `vendor/bin/pint --test` → `npm run lint:check` → `npm run typecheck` → `./vendor/bin/pest`.

**Baseline verde.** `calidad-continua` §"Baseline verde sin fallos preexistentes" exige que la puerta pase sin fallos ajenos al cambio. La suite actual (`tests/Feature/Auth/*`, `DashboardTest.php`) **no toca el dominio**, de modo que el baseline es verde antes de la Fase 0 ⇒ cualquier fallo de la Fase 0 es atribuible al cambio. Un fallo ajeno (p. ej. la precondición de Vite) se corrige o se aísla con justificación antes de declarar verde la fase.

### E.5 Los 44 tests con la regla que fija cada uno

*Un test por requisito; el nombre es la documentación de la regla (§10.6 del mapa funcional: el test es la documentación).*

| # | Test | Regla que fija | Requisito (§F.1) |
|---|---|---|---|
| 1 | `test_el_esquema_destino_se_construye_solo_por_migraciones_sin_float_ni_blobs_php` | el esquema se entrega por migraciones, no por scripts | `esquema-dominio` · Esquema destino por migraciones |
| 2 | `test_los_saldos_y_totales_se_derivan_y_los_catalogos_no_exponen_banderas_contables` | el tipo de movimiento sustituye a las banderas booleanas | `esquema-dominio` · Una sola fuente de verdad por dato |
| 3 | `test_un_fallo_intermedio_no_deja_cabecera_ni_cambio_de_stock_persistidos` | toda escritura es una unidad atómica con bloqueo de fila | `esquema-dominio` · Atomicidad de toda escritura |
| 4 | `test_ningun_literal_de_pais_sobrevive_en_app_domain` | cambiar de país es insertar filas, no bifurcar código | `esquema-dominio` · El dominio no contiene literales de país |
| 5 | `test_un_pais_nuevo_se_anade_sembrando_filas_sin_tocar_el_motor_de_impuestos` | el motor de impuestos es puro y agnóstico | `capa-pais-fiscal` · El motor fiscal es dato |
| 6 | `test_la_emision_atraviesa_un_actor_intermedio_que_asigna_el_control_antes_de_consolidar` | el emisor no emite solo; hay un actor intermedio | `capa-pais-fiscal` · Actores y modelo de firma como dato |
| 7 | `test_cada_pais_guarda_su_referencia_como_valor_y_esquema_sin_columna_uuid` | la referencia de verificación es un par valor + esquema | `capa-pais-fiscal` · Vocabularios de estado y referencias de autoridad por perfil |
| 8 | `test_la_tasa_de_iva_sembrada_figura_pendiente_de_confirmacion_con_su_fuente` | un dato no verificado se marca como pendiente, no se afirma | `capa-pais-fiscal` · Semilla del perfil venezolano con evidencia trazable |
| 9 | `test_el_comprobante_renderiza_base_e_iva_por_alicuota_con_el_marcador_exento` | el art. 8 es obligatorio | `documentos-fiscales` · Contenido y render normados del comprobante |
| 10 | `test_la_nota_referencia_fecha_correlativo_control_y_monto_del_original` | el IVA retenido es documento propio | `documentos-fiscales` · Documentos derivados que referencian el original |
| 11 | `test_la_anulacion_deja_evento_y_no_altera_el_evento_de_emision` | toda acción queda auditada | `documentos-fiscales` · Evidencia e integridad del comprobante |
| 12 | `test_el_papel_de_contingencia_se_concilia_en_pdf_al_dia_siguiente` | el papel sólo es contingencia y se concilia a D+1 | `documentos-fiscales` · Contingencia exclusivamente en papel |
| 13 | `test_un_documento_reserva_un_numero_de_documento_y_otro_de_control_en_reservas_distintas` | hay dos numeraciones con autoridades distintas | `numeracion-fiscal` · Dos series con dos reservas atómicas independientes |
| 14 | `test_la_anulacion_consuma_el_correlativo_y_la_siguiente_reserva_devuelve_el_siguiente` | el número consumido no se reutiliza jamás | `numeracion-fiscal` · El número no retrocede |
| 15 | `test_la_primera_asignacion_de_control_usa_identificador_00_y_secuencial_1` | la primera asignación de control es `00` + `1` | `numeracion-fiscal` · Primera asignación de control |
| 16 | `test_la_consolidacion_se_rechaza_si_el_control_no_corresponde_al_perfil` | exactamente un número de control si el perfil lo exige | `numeracion-fiscal` · Invariante del número de control |
| 17 | `test_la_reserva_bloqueada_falla_con_sqlstate_55p03_y_el_rollback_devuelve_el_numero` | el bloqueo de fila es real, no simulado | `numeracion-fiscal` · T1–T3 verifican la reserva sin `RefreshDatabase` |
| 18 | `test_el_mismo_motor_calcula_el_resultado_de_dos_perfiles_sin_ramificar_por_pais` | el motor de impuestos es puro y agnóstico | `motor-impuestos` · `TaxCalculator` puro |
| 19 | `test_un_documento_emitido_conserva_la_tasa_la_categoria_y_el_estado_de_evidencia_congelados` | el impuesto se persiste, no se recalcula | `motor-impuestos` · Cinco categorías de IVA y snapshots congelados |
| 20 | `test_la_suma_de_lineas_mas_el_ajuste_de_redondeo_iguala_el_total_redondeado` | el ajuste de redondeo es visible en el total | `motor-impuestos` · Redondeo por línea con ajuste visible |
| 21 | `test_el_iva_retenido_se_emite_como_documento_propio_y_no_como_linea_de_la_venta` | el IVA retenido es crédito del comprador, con documento propio | `motor-impuestos` · El IVA retenido es documento propio |
| 22 | `test_la_disponibilidad_del_lote_es_la_aritmetica_recibido_menos_consumido_mas_devuelto` | el estado del lote se deriva, no se codifica | `inventario-lotes` · Aritmética de lotes sin estado mutable |
| 23 | `test_el_consumo_toma_el_lote_mas_antiguo_y_registra_un_consumo_por_lote` | el FIFO ordena por antigüedad y por id | `inventario-lotes` · FIFO determinista con bloqueo por salto |
| 24 | `test_la_devolucion_repone_el_lote_exacto_del_que_salio_la_linea` | la devolución repone el lote exacto | `inventario-lotes` · Devolución repone el lote exacto |
| 25 | `test_el_stock_en_cache_iguala_la_suma_de_lotes_y_no_se_sobrevende_bajo_concurrencia` | `quantity_in_stock` es igual a la suma de la disponibilidad de los lotes | `inventario-lotes` · Invariante de stock y no sobreventa |
| 26 | `test_el_agente_recibe_bytes_ya_generados_y_no_interpreta_el_ticket` | lo unificable es el origen del contenido, no el binario | `impresion-lan` · Un origen de contenido, binario por transporte |
| 27 | `test_el_agente_trae_trabajos_desde_la_lan_privada_y_la_tienda_se_resuelve_desde_el_agent_id` | el agente siempre inicia la conexión | `impresion-lan` · Agente en pull con long-poll |
| 28 | `test_un_destino_usb_directo_se_rechaza_y_exige_tcp_9100_o_impresora_compartida` | el USB directo se descarta por requisito de Zadig | `impresion-lan` · Destinos de impresión admitidos |
| 29 | `test_un_qr_sobre_un_perfil_sin_soporte_falla_en_el_servidor_antes_de_enviarlo` | el límite de payload del QR lo impone el servidor | `impresion-lan` · El perfil de impresora es dato |
| 30 | `test_reimprimir_crea_un_trabajo_nuevo_sin_consumir_correlativo_ni_alterar_el_documento` | reimprimir no es reemitir | `impresion-lan` · La emisión fiscal no depende de la impresión |
| 31 | `test_un_lote_firmado_con_los_vectores_dorados_se_acepta_con_la_misma_respuesta` | el contrato de wire no cambia | `adaptador-sync-legacy` · Contrato de wire v2 intacto |
| 32 | `test_la_traduccion_resuelve_tabla_y_columnas_contra_el_mapa_sembrado` | el mapa de traducción es dato auditable, no código | `adaptador-sync-legacy` · Traducción por mapa sembrado |
| 33 | `test_un_reenvio_identico_responde_exito_sin_aplicar_el_lote_dos_veces` | el reenvío no viola la clave primaria ni duplica efectos | `adaptador-sync-legacy` · Aplicación idempotente del lote |
| 34 | `test_el_terminal_reenvia_tras_perder_la_respuesta_y_el_maestro_lo_acepta_sin_congelarse` | la idempotencia del maestro desactiva la falla operativa del reenvío | `adaptador-sync-legacy` · Truncado del log sólo con respuesta exitosa |
| 35 | `test_cada_movimiento_contable_tiene_un_unico_entry_type_y_ninguna_bandera_dispersa` | un movimiento contable tiene un tipo, no once banderas | `contabilidad-caja` · Asientos con tipo explícito |
| 36 | `test_un_gasto_sin_movimiento_bancario_sigue_siendo_gasto_con_su_asiento` | el gasto nace en `expenses`, no en el banco | `contabilidad-caja` · `expenses` es la fuente de verdad del gasto |
| 37 | `test_el_movimiento_cerca_del_cambio_de_dia_queda_en_el_registro_de_caja_de_su_fecha_de_negocio` | la caja se filtra por fecha de negocio, no por día/mes/año | `contabilidad-caja` · Caja por fecha de negocio |
| 38 | `test_el_saldo_de_cuenta_se_deriva_de_los_asientos_y_no_de_un_contador_almacenado` | los saldos de cuenta se derivan, no se cuentan dos veces | `contabilidad-caja` · Sin contadores derivados |
| 39 | `test_el_arranque_operativo_queda_bloqueado_hasta_que_exista_un_saldo_certificado` | sin saldos de apertura firmados no hay cifras defendibles | `contabilidad-caja` · Saldos de apertura congelados |
| 40 | `test_la_puerta_de_calidad_declara_typecheck_y_build_como_etapas_del_gate` | el typecheck está en el gate, no fuera | `calidad-continua` · CI con PostgreSQL, lint, typecheck, tests y build |
| 41 | `test_la_suite_del_dominio_cubre_checkout_fifo_impuestos_reserva_fiscal_cuotas_caja_y_devolucion` | el dominio se prueba sin interfaz | `calidad-continua` · Suite Pest del dominio |
| 42 | `test_cada_test_de_la_suite_declara_la_regla_de_negocio_que_fija` | el test es la documentación de la regla | `calidad-continua` · Cada test nombra la regla de negocio que fija |
| 43 | `test_el_repositorio_contiene_los_seis_adr_y_el_readme_de_arranque` | cada decisión estructural queda documentada | `calidad-continua` · Seis ADRs y README de arranque |
| 44 | `test_la_suite_no_deja_fallos_preexistentes_sin_aislar` | la suite debe estar en verde sin fallos preexistentes | `calidad-continua` · Baseline verde sin fallos preexistentes |


## F) Trazabilidad requisito → diseño

### F.1 Los 44 requisitos de las 10 specs

| # | Capacidad | Requisito | Sección del diseño que lo satisface |
|---|---|---|---|
| 1 | `esquema-dominio` | Esquema destino por migraciones | §A.1 · §A.2 · §A.3 · §D.3 · §E.4 · **§F.2 (delta C1)** |
| 2 | `esquema-dominio` | Una sola fuente de verdad por dato | §A.2 ("Sin contadores derivados", "Sin flags contables") · §D.4 · §E.2 (invariantes) |
| 3 | `esquema-dominio` | Atomicidad de toda escritura | §B.1 · §D.1 · §D.4 · §E.1 |
| 4 | `esquema-dominio` | El dominio no contiene literales de país | §C.1 (regla de despach) · §D.3 · §E.3 |
| 5 | `capa-pais-fiscal` | El motor fiscal es dato | §A.1 (M01–M03) · §C.2 · §D.3 |
| 6 | `capa-pais-fiscal` | Actores y modelo de firma como dato | §C.1 · §D.2 · §B.1 (pasos 11–15) |
| 7 | `capa-pais-fiscal` | Vocabularios de estado y referencias de autoridad por perfil | §C.1 · §A.1 (M02) · §D.3 |
| 8 | `capa-pais-fiscal` | Semilla del perfil venezolano con evidencia trazable | §A.1 (seeders) · §C.2 (snapshots + `evidence_status`) · §E.5 (#8) |
| 9 | `documentos-fiscales` | Contenido y render normados del comprobante | §C.1 · §C.2 · §A.1 (M22) · §D.2 |
| 10 | `documentos-fiscales` | Documentos derivados que referencian el original | §C.1 (`retention_receipt`) · §B.3 · §A.1 (M22 `fiscal_document_links`) · §D.2 |
| 11 | `documentos-fiscales` | Evidencia e integridad del comprobante | §A.1 (M22 `fiscal_document_events`) · §Migración / rollout (retención 10 años) · §D.2 |
| 12 | `documentos-fiscales` | Contingencia exclusivamente en papel | §A.1 (M22 `fiscal_contingency_forms`) · §Migración / rollout · §B.1 (tabla de fallos) |
| 13 | `numeracion-fiscal` | Dos series con dos reservas atómicas independientes | §B.3 · §D.1 · §A.1 (M21) · §C.1 |
| 14 | `numeracion-fiscal` | El número no retrocede | §B.3 · §D.1 · §Migración / rollout ("apagar, nunca renumerar") |
| 15 | `numeracion-fiscal` | Primera asignación de control | §B.3 · §C.1 (`control_identifier`, `sequence_length`) · §A.1 (M21) |
| 16 | `numeracion-fiscal` | Invariante del número de control | §B.3 (invariante) · §C.1 · §D.2 |
| 17 | `numeracion-fiscal` | T1–T3 verifican la reserva sin `RefreshDatabase` | §E.1 · §E.5 (#17) · §D.1 |
| 18 | `motor-impuestos` | `TaxCalculator` puro | §C.2 · §D.3 · §E.2 |
| 19 | `motor-impuestos` | Cinco categorías de IVA y snapshots congelados | §C.2 · §A.1 (M03) · §E.5 (#19) |
| 20 | `motor-impuestos` | Redondeo por línea con ajuste visible | §C.2 · §E.5 (#20) |
| 21 | `motor-impuestos` | El IVA retenido es documento propio | §C.2 · §C.1 (`retention_receipt`) · §D.2 |
| 22 | `inventario-lotes` | Aritmética de lotes sin estado mutable | §B.2 · §A.1 (M14, M27) · §D.4 |
| 23 | `inventario-lotes` | FIFO determinista con bloqueo por salto | §B.2 · §D.4 · §E.2 |
| 24 | `inventario-lotes` | Devolución repone el lote exacto | §B.2 · §D.4 · §E.5 (#24) |
| 25 | `inventario-lotes` | Invariante de stock y no sobreventa | §B.2 · §D.4 · §E.2 · §E.5 (#25) |
| 26 | `impresion-lan` | Un origen de contenido, binario por transporte | §C.3 · §D.5 · §A.1 (M23) |
| 27 | `impresion-lan` | Agente en pull con long-poll | §C.3 · §D.5 |
| 28 | `impresion-lan` | Destinos de impresión admitidos | §C.3 · §D.5 |
| 29 | `impresion-lan` | El perfil de impresora es dato | §C.3 · §D.5 · §E.5 (#29) |
| 30 | `impresion-lan` | La emisión fiscal no depende de la impresión | §C.3 (`issued` + máquina de estados) · §D.5 · §B.1 (pasos 16–18) |
| 31 | `adaptador-sync-legacy` | Contrato de wire v2 intacto | §D.6 · §A.1 (M24) · §Flujo de datos |
| 32 | `adaptador-sync-legacy` | Traducción por mapa sembrado | §D.6 · §A.1 (M24 + `LegacyTranslationMapSeeder`) · §E.5 (#32) |
| 33 | `adaptador-sync-legacy` | Aplicación idempotente del lote | §D.6 · §A.1 (M24 `sync_batches`) |
| 34 | `adaptador-sync-legacy` | Truncado del log sólo con respuesta exitosa | §D.6 · §E.5 (#34) |
| 35 | `contabilidad-caja` | Asientos con tipo explícito | §A.1 (M18) · D8 · §E.5 (#35) |
| 36 | `contabilidad-caja` | `expenses` es la fuente de verdad del gasto | §A.1 (M19) · D19 · §Ficheros afectados (`LedgerWriter`) |
| 37 | `contabilidad-caja` | Caja por fecha de negocio | §A.1 (M19 `cash_registers.business_date`) · §E.5 (#37) |
| 38 | `contabilidad-caja` | Sin contadores derivados | §A.2 · §A.1 (M20) · §E.2 (invariantes) |
| 39 | `contabilidad-caja` | Saldos de apertura congelados | §A.1 (M19 `opening_balances`) · §Migración / rollout (`ops:check-opening-balances`) · §F.3 (P9: dependencia operativa) |
| 40 | `calidad-continua` | CI con PostgreSQL, lint, typecheck, tests y build | §E.4 · §Ficheros afectados (`.github/workflows/tests.yml`) |
| 41 | `calidad-continua` | Suite Pest del dominio | §E.2 · §E.5 · §Ficheros afectados (`tests/`) |
| 42 | `calidad-continua` | Cada test nombra la regla de negocio que fija | §E.5 (los 44 nombres) · §F.1 |
| 43 | `calidad-continua` | Seis ADRs y README de arranque | §D · `docs/adr/0001…0006` · §Ficheros afectados |
| 44 | `calidad-continua` | Baseline verde sin fallos preexistentes | §E.4 · §Migración / rollout |

**Cobertura: 44 / 44 requisitos con diseño. 0 requisitos sin diseño.**

### F.2 Delta de spec (C1) y nota de nomenclatura (C2)

**Delta — `esquema-dominio` §"Esquema destino por migraciones".** Se registra aquí; **no** se aplica a `openspec/specs/esquema-dominio/spec.md` (el store OpenSpec versiona la spec; el delta se aplica en `sdd-archive`).

```markdown
## MODIFIED Requirements

### Requirement: Esquema destino por migraciones

El esquema MUST construirse sólo por migraciones: 55 tablas conservadas, 17 fusionadas, 1 eliminada y 54 nuevas, para un total de 109 tablas de negocio. Cada relación MUST usar clave foránea real con `ON DELETE` explícito (`RESTRICT` histórico, `CASCADE` dependiente, `SET NULL` opcional). Todo importe MUST ser `numeric(25,4)`; el sistema MUST NOT usar `float` ni columnas con blobs PHP serializado.

**Trazabilidad:** S1 · Criterio C1 (`migrate:fresh --seed` en verde sobre PostgreSQL 17) · research/estandares-latam.md §5

#### Scenario: Esquema íntegro y reproducible

- GIVEN un checkout limpio con PostgreSQL 17
- WHEN se ejecuta `php artisan migrate:fresh --seed`
- THEN el esquema queda completo, sin `float` monetario ni blobs serializados
- AND Regla fijada: el esquema se entrega por migraciones, no por scripts

#### Scenario: Integridad referencial

- GIVEN una tabla hija con clave foránea a su padre
- WHEN se inserta una fila cuyo padre no existe
- THEN la base rechaza la inserción
- AND Regla fijada: toda relación tiene FK real
```

**Cambio único:** `27 nuevas` → `54 nuevas (109 tablas de negocio)`. La disposición del legacy (**73 = 55 + 17 + 1**) **no** cambia; la regla de fondo (FKs reales, `numeric(25,4)`, sin blobs, sin flags, sin contadores) se cumple íntegra.

**Nota de nomenclatura (C2) — sin delta.** `fiscal_series.series_role` es el nombre canónico, con valores `document` | `control` | `retention_receipt`, y `assigned_by` ∈ `issuer` | `authority` | `printer` | `psp` distingue la autoridad. `series_kind` (propuesto por la investigación) queda como **alias documentado**, no como columna: **no** se crea ninguna columna `series_kind`.

**Ampliaciones de `documentos-fiscales` (C7) — sin delta en esta pasada.** La spec está en **647/650** palabras. Las ampliaciones derivadas (actor intermedio, control, comprobante de retención) **no** se añaden al fichero: quedan registradas aquí como delta pendiente para la fase que lo solicite.

```markdown
## MODIFIED Requirements

### Requirement: Documentos derivados que referencian el original

<texto íntegro del requisito vigente> + precisiones sobre `fiscal_document_control_assignments`
(1:1 con el documento) y sobre `fiscal_series.series_role = 'retention_receipt'` con numeración de 14 caracteres.
```

*(Bloque pendiente de redactar: no se redacta aquí para no desbordar el presupuesto de palabras de la spec.)*

### F.3 Observaciones

| # | Observación | Impacto | Propuesta |
|---|---|---|---|
| O1 | El `proposal.md` (S1) arrastra el mismo número: "55 conservadas, 17 fusionadas, 1 eliminada, **27 nuevas**". | Inconsistencia entre artefactos del mismo change. | Corregir S1 en el mismo change, junto con el delta de §F.2. **No aplicado en esta pasada** (no formaba parte del encargo). |
| O2 | `calidad-continua` §"CI…" exige `typecheck` en el gate: **ya está** en `.github/workflows/tests.yml:67-68`. El hueco real es `vendor/bin/pint --test` y el check explícito del manifiesto de Vite. | La fila de "Ficheros afectados" de la pasada 1 describe un cambio ya hecho a medias. | Ver **C8**. |
| O3 | P9 (saldos de apertura) es **dependencia operativa**, no de código: el esquema y el comando de bloqueo están diseñados (§A.1 M19, §Migración / rollout), pero el dato lo certifica una persona. | Bloquea el arranque, no la fase de tareas. | Ver §Preguntas abiertas (P9). |
| O4 | La §A.3 enumera 22 + 19 + 13 = 54, pero la columna de su tabla suma 111 (55 + 24 + 32) porque las 2 tablas "ya conservadas" de la fila de fusión están contadas dos veces. El total correcto es `55 + 22 + 19 + 13 = 109`. | Un revisor que sume la columna encuentra una contradicción. | Ver **C10**. |


## Requisitos sin diseño

**Ninguno.** Los **44 requisitos** de las 10 specs tienen diseño (§F.1): 39 en §A–§C (pasada 1) y 5 de `calidad-continua`, que esta pasada cierra en §D (los 6 ADR), §E (gate ejecutable + suite) y §F (trazabilidad). Los 5 requisitos de `calidad-continua` que la pasada 1 dejó abiertos quedan así:

| Capacidad | Requisito | Dónde queda cubierto |
|---|---|---|
| `calidad-continua` | CI con PostgreSQL, lint, typecheck, tests y build | §E.4 (etapas, orden, servicio PostgreSQL, precondición de Vite) + *Ficheros afectados* (`.github/workflows/tests.yml`) |
| `calidad-continua` | Suite Pest del dominio | §E.2 (áreas y reglas) + §E.5 (los 44 nombres + los 6 transversales) + *Ficheros afectados* (`tests/`) |
| `calidad-continua` | Cada test nombra la regla de negocio que fija | §E.5 — la columna "Regla que fija" es la documentación de cada test |
| `calidad-continua` | Seis ADRs y README de arranque | §D (texto completo) + `docs/adr/0001…0006-*.md` + *Ficheros afectados* |
| `calidad-continua` | Baseline verde sin fallos preexistentes | §E.4 (baseline + regla de aislamiento y corrección de fallos ajenos) |

## Conflictos detectados

| # | Conflicto | Impacto | Estado y resolución |
|---|---|---|---|
| **C1** | **El requisito de `esquema-dominio` fija "27 tablas nuevas"; el diseño necesita 54.** `exploration.md` §3.3 (pre-research) lista **26**, no 27. La investigación añadió **13** (capa fiscal de documentos: `fiscal_profile_document_types`, `_environments`, `_fields`, `fiscal_tax_schemes`, `fiscal_status_vocabularies`, `fiscal_providers`, `fiscal_document_taxes`, `_parties`, `_links`, `_events`, `_control_assignments`, `fiscal_periodic_reports`, `fiscal_contingency_forms`); el encargo añadió **3** operativas (`ledger_lines`, `legacy_translation_map`, `sms_providers`); y la fusión de las 17 legacy genera **12** destinos con nombre nuevo (`sales*`, `purchases*`, `purchase_items`, `inventory_lots`, `ledger_entries`, `quotations*`, `orders*`). **26 + 13 + 3 + 12 = 54.** | El requisito, tal como estaba escrito, **era inviable**: incumplirlo obligaría a borrar tablas que exigen `capa-pais-fiscal` y `documentos-fiscales`. La disposición del legacy 73 **sí** cuadra exacto (55 + 17 + 1). | **RESUELTO (decisión del usuario, 2026-10-31).** El requisito se corrige a **"55 conservadas · 17 fusionadas · 1 eliminada · 54 nuevas · 109 tablas de negocio"**. Se registra como **delta de spec** en §F.2; la spec existente **no** se reescribe (el store OpenSpec la versiona; el delta se aplica en `sdd-archive`). El conteo del legacy se mantiene exacto. |
| **C2** | **`fiscal_series.series_role` vs `series_kind`.** El encargo dice `series_role` ∈ `document`/`control`; la investigación propone `series_kind` ∈ `document_number`/`control_number`/`retention_receipt`. | Nomenclatura, no semántica. | **RESUELTO.** Se adopta **`series_role`** con valores `document` \| `control` \| `retention_receipt`, más `assigned_by`. `series_kind` queda como **alias documentado**, **no** como columna. Nota de nomenclatura en §F.2. |
| **C3** | **La invariante "exactamente un `control_number`" y la doble reserva vs. la norma**: la norma dice que el control lo **asigna la imprenta** (F1 art. 42.1), pero `numeracion-fiscal` exige **dos reservas atómicas independientes**. | Sin diseño, o se quema un número, o se duplica. | **RESUELTO (D3).** **Espejo local** del rango de control de la imprenta (`from_number`/`to_number`/`credential_ref` sembrados del rango autorizado) + reserva atómica local + **verificación de correspondencia** con lo que la imprenta devuelve. La discrepancia se registra como evento en `fiscal_document_events`. |
| **C4** | **El prefijo obligatorio "serie" (F1 art. 20) es un literal local dentro del dominio**, y el test anti-país prohíbe literales de país en `app/Domain/`. | Un `str_contains($prefix, 'serie')` en el dominio rompe el test anti-país. | **RESUELTO (D4).** El token exigido **es un dato**: fila en `fiscal_profile_fields` (`key='series_prefix_required_token'`, `value='serie'`). El dominio compara contra el valor leído. El test anti-país lo hace ejecutable (categoría `locale-token`, §E.3). **Cumple las dos reglas.** |
| **C5** | **Atomicidad vs. I/O externo**: el checkout debe ser atómico, pero `submit()`/`assignControl()` son llamadas HTTP al proveedor. | Una sola transacción mantiene bloqueos de fila durante I/O → riesgo de `lock_timeout` en la caja. | **RESUELTO (decisión del usuario, 2026-10-31).** **Dos transacciones**: **T1** (dominio: números + FIFO + IVA + asientos + pago) y **T2** (fiscal: emisión). Si el proveedor falla, la venta queda con `sales.status='fiscal_failed'` y el **reintento reutiliza la misma reserva** (`fiscal_document_reservations`), sin quemar números. La atomicidad exigida por el requisito se aplica a **cada** unidad de escritura; la atomicidad cross-proveedor es **imposible por naturaleza** y se sustituye por ese estado explícito. Secuencing en §B.1 (nota) · §D.1 · §D.6. |
| **C6** | **`quantity_in_stock` cacheado vs. "MUST derivarse"**. | El requisito lo permite *si* hay test de invariante; el diagrama B.2 exige la invariante. | **RESUELTO (D5 + D6).** `inventory_lots.available` es **GENERATED** (exacta por definición); `product_stores.quantity_in_stock` es caché **con test de invariante** (test #25, §E.5). |
| **C7** | **`documentos-fiscales` está al límite de palabras** (647/650 según el brief) y la capa fiscal añadiría requisitos nuevos (control, actor intermedio, retención). | Una spec nueva o un delta podría desbordar el presupuesto. | **RESUELTO sin tocar la spec.** No se añade **ningún** requisito dentro de `documentos-fiscales`. Las ampliaciones quedan como **delta pendiente** registrado en §F.2, para la fase que lo solicite. |
| **C8** | **El hueco de `typecheck` en CI ya no existe.** La pasada 1 (§Ficheros afectados) y `exploration.md` §11.1 R10 dan por hecho que `npm run typecheck` **no** está en el gate; `.github/workflows/tests.yml:67-68` **ya lo ejecuta**. El hueco real es otro: falta `vendor/bin/pint --test` y el fallo por manifiesto de Vite ausente es un HTTP 500, no un error de precondición. | La tarea de CI tal como está descrita en la pasada 1 sería un no-op en su parte principal. | **NUEVO — no resuelto.** Propuesta: corregir la fila de `.github/workflows/tests.yml` en *Ficheros afectado* para que describa el estado real y concrete el trabajo pendiente: (a) añadir `vendor/bin/pint --test` al gate; (b) añadir un paso que verifique `public/build/manifest.json` **antes** de la suite y falle con un error de precondición explícito; (c) fijar el orden `build → pint → lint:check → typecheck → pest`. Sin cambiar requisitos. Detalle en §E.4. |
| **C9** | **La spec `esquema-dominio` exige bloqueo de fila "(`SELECT … FOR UPDATE`)"**, pero la reserva fiscal se hace con `UPDATE … RETURNING` (D1, §B.3). `openspec/config.yaml → rules.design` admite **las dos** ("`UPDATE … RETURNING` **o** `SELECT … FOR UPDATE`"); el texto de la spec sólo menciona la segunda. | Un implementador que siga la spec al pie de la letra rompería la reserva atómica, o un revisor encontraría una divergencia entre spec y diseño. | **NUEVO — no resuelto.** Propuesta: delta menor de `esquema-dominio` §"Atomicidad de toda escritura" que acepte `UPDATE … RETURNING` como mecanismo equivalente de bloqueo de fila (el `UPDATE` toma el lock de la fila en la misma sentencia). **No se aplica en esta pasada**: tocar la spec excede el encargo, que reservaba el delta de §F.2 para C1. El diseño y la spec son coherentes en la práctica; lo que falta es el texto. |
| **C10** | **La §A.3 suma 111 en la columna y declara 109 en el total**: las 2 tablas "ya conservadas" de la fila de fusión (17 legacy → 24 destinos) están contadas también dentro de las 55. La aritmética correcta es `55 + 22 + 19 + 13 = 109`. | Un revisor que sume la columna encuentra una contradicción en el documento que fija el conteo de tablas. | **NUEVO — no resuelto.** Propuesta: cambiar la fila "Destinos de fusión" a `24 (**22** nuevas + 2 ya conservadas)` y anotar la aritmética del total como `55 + 22 + 19 + 13 = 109`; los dos invariantes exactos (73 = 55 + 17 + 1 y 109 = 55 + 54) **no** cambian. Nota de aviso añadida en §A.3. |

## Preguntas abiertas

**Cerradas en esta pasada:** **C1** (el conteo se corrige a 54; delta en §F.2) y **C5** (dos transacciones, `fiscal_failed` y reutilización de la reserva). Ninguna queda bloqueando `sdd-tasks`.

| # | Pregunta | Recomendación | Motivo |
|---|---|---|---|
| **P6** | ¿El formato del correlativo fiscal es `000-00000000` (prefijo de 3 + 8 dígitos) o el del legacy? | **`000-00000000`**, sembrado en `fiscal_series.prefix` + `number_formats.invoice_number_format` + `sequence_length`, **nunca como código**. | Es el formato factual que cita el mapa funcional §8.5.1, y el legacy **no** lo produce (genera `prefijo + store_id + 8 dígitos`). Al ser un **dato**, cambiarlo no cuesta migración ni código. **No bloquea.** |
| **P7** | ¿Las **compras** consumen serie fiscal propia? | **Sí**: serie propia por tienda (`fiscal_profile_document_types.direction='purchase'`), **sembrada pero sin emisión** en Fase 0. | El esquema ya lo soporta y el correlativo de compra del legacy es `prefijo + referencia escrita por el usuario`, **sin reserva atómica** (hallazgo H10) — un hueco que este diseño cierra. No amplía el alcance: sólo la fila y el tipo de documento. **No bloquea.** |
| **P5** | ¿Fase 0 exige **T4** (dos procesos reales compitiendo por el mismo correlativo) o basta con T1–T3? | **T1–T3 en Fase 0; T4a en Fase 1** (comando Artisan + `Symfony\Process`). | `UPDATE … RETURNING` dentro de una transacción **serializa la fila**: la unicidad es correcta por construcción y T1–T3 la certifican sobre PostgreSQL real. T4 certificaría el **escenario de negocio**, no la invariancia, y cuesta un proceso paralelo propenso a *flaky*. **No bloquea.** |
| **P9** | ¿Qué saldos de apertura se congelan, con qué fecha y quién los certifica? | Congelar **stock por tienda y producto**, **saldo y deuda por cliente y proveedor** y **saldo de caja por cuenta y tienda**, con fecha de corte y responsable nominal, **antes** de la primera operación. | **Dependencia operativa, no de código**: el esquema (`opening_balances` con `certified_at`/`certified_by`) y el comando `ops:check-opening-balances` que bloquea el arranque ya están diseñados (§A.1 M19, §Migración / rollout). Sin cifras certificadas el arranque no es defendible. **No bloquea el código; bloquea el arranque operativo.** |
| **D1** | ¿El sistema opera como **imprenta digital** además de emisor? | **Sembrar ambos roles** (`fiscal_profile_environments.role ∈ {issuer, printer}`) y **activar sólo `issuer`** en Fase 0. | El rol **ya es dato** (§C.1, §D.2), así que sembrarlo no cuesta código. Activar el rol `printer` implica obligaciones propias de la imprenta (registro automatizado mensual, art. 44; causas de revocación, art. 34) que son de Fase 1. **No bloquea.** |
| **D5** | ¿La entrega del comprobante al usuario final es por **correo**, por **consulta pública**, o ambas? | **Ambas**: la entrega se modela como `ElectronicInvoiceProvider::deliver()` con un registro por documento. | F2 art. 18.10 ofrece la web **propia** del emisor como alternativa a la de la imprenta, y el transporte `browser` ya entrega la representación. El esquema no bloquea ninguna de las dos; el método `deliver()` ya está en el contrato (§C.1). **No bloquea.** |

**Ninguna de las 6 preguntas bloquea la fase de tareas**: todas tienen una recomendación con motivo y todas son cambios de **datos sembrados** o de **alcance de una fase posterior**, no de esquema ni de contratos.
