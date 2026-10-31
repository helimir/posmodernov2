# Design: Fase 0 — Fundaciones

> **Pasada 1 de 2.** Esta pasada fija el **esquema destino**, los **tres flujos transaccionales** y los **contratos de núcleo**.
> La pasada 2 añade: **D.** los 6 ADR · **E.** plan de testing ejecutable · **F.** trazabilidad de los 44 requisitos.
> Reglas aplicadas: `openspec/config.yaml → rules.design` (diagramas de secuencia, decisiones con motivo, escritura atómica con bloqueo de fila, correlativo con `UPDATE … RETURNING`).

## Enfoque técnico

El legacy **no se porta**: se reemplaza. Fase 0 = **esquema destino + núcleo puro testeable + suite que fija por test las reglas que hoy se ejecutan sin garantías**.

| Pieza | Decisión | Ancla |
|---|---|---|
| Esquema | 27 migraciones Laravel sobre PostgreSQL 17; 109 tablas de negocio; `numeric(25,4)`; FKs reales | `exploration.md` §3, `research/estandares-latam.md` §5 |
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

## Estrategia de testing (resumen — el plan ejecutable es la pasada 2, §E)

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

## Decisiones que serán ADR en la pasada 2

Seccionadas; cada una con **motivo** y **alternativa descartada** (las 20 filas de la tabla de decisiones son la fuente).

| ADR | Título | Motivo (una línea) | Alternativa descartada |
|---|---|---|---|
| ADR-0001 | Numeración fiscal de dos series con reserva atómica | La norma tiene dos autoridades de numeración; una sola reserva no garantiza nada | Una sola serie (`exploration.md` §5) |
| ADR-0002 | Actor intermedio emisor / imprenta digital | El emisor no emite solo (F2 art. 18.4/18.6) | `issue()` único sin proveedor |
| ADR-0003 | Capa de país/fiscal como datos, sin `if` por país | La Fase 2 debe ser "insertar filas" | `if ($country === 'VE')` en el dominio |
| ADR-0004 | Stock por lotes sin `status` mutable + `FOR UPDATE SKIP LOCKED` | La aritmética no puede desincronizarse; `SKIP LOCKED` da concurrencia sin sobreventa | `status` enum + `UPDATE` sin `id` (defecto legacy P0) |
| ADR-0005 | Bytes ESC/POS en el servidor + agente en pull + USB descartado | Un solo formateador; el agente agnóstico; Zadig es inviable en desatendido | Render en el agente · push · USB directo |
| ADR-0006 | Adaptador sync v2: wire intacto, traducción por datos, idempotencia | El terminal sigue siendo legacy y reenvía; cambiar el contrato lo rompería | Reescribir el protocolo · traducir en código · cambiar el wire |

**Decisiones adicionales de esta pasada que también serán ADR** (menor): D7 (`ledger_lines`), D10 (`brick/math`), D11 (bytes en servidor, ya en ADR-0005), D13 (token por agente), D17 (Redis para nonces), D18 (editar la migración base en verde).

## Requisitos sin diseño en esta pasada

| Capacidad | Requisito | Motivo |
|---|---|---|
| `calidad-continua` | CI con PostgreSQL, lint, typecheck, tests y build | Diseño de alto nivel en *Ficheros afectados*; el **gate ejecutable** (orden, caché, matriz de servicios) va en la pasada 2 §E. |
| `calidad-continua` | Suite Pest del dominio | Las invariantes están diseñadas; el **plan de tests caso por caso** va en la pasada 2 §E. |
| `calidad-continua` | Cada test nombra la regla de negocio que fija | Convención de nombrado fijada; los **44 nombres** van en la pasada 2 §E. |
| `calidad-continua` | Seis ADRs y README de arranque | Los 6 ADR están **seccionados** arriba; el texto completo va en la pasada 2 §D. |
| `calidad-continua` | Baseline verde sin fallos preexistentes | Depende de la ejecución de la suite (pasada 2 §E). |

Los otros **39 requisitos** tienen diseño en esta pasada (A/B/C). La tabla de trazabilidad requisito→diseño es la §F de la pasada 2.

## Conflictos detectados

| # | Conflicto | Impacto | Resolución propuesta |
|---|---|---|---|
| **C1** | **El requisito de `esquema-dominio` fija "27 tablas nuevas"; el diseño necesita 54.** `exploration.md` §3.3 (pre-research) lista **26**, no 27. La investigación añadió **13** (capa fiscal de documentos: `fiscal_profile_document_types`, `_environments`, `_fields`, `fiscal_tax_schemes`, `fiscal_status_vocabularies`, `fiscal_providers`, `fiscal_document_taxes`, `_parties`, `_links`, `_events`, `_control_assignments`, `fiscal_periodic_reports`, `fiscal_contingency_forms`); el encargo añadió **3** operativas (`ledger_lines`, `legacy_translation_map`, `sms_providers`); y la fusión de las 17 legacy genera **12** destinos con nombre nuevo (`sales*`, `purchases*`, `purchase_items`, `inventory_lots`, `ledger_entries`, `quotations*`, `orders*`). **26 + 13 + 3 + 12 = 54.** | El requisito, tal como está escrito, **es inviable**: incumplirlo obligaría a borrar tablas que exigen `capa-pais-fiscal` y `documentos-fiscales`. La disposición del legacy 73 **sí** cuadra exacto (55+17+1). | En la pasada 2 §F, corregir el requisito a **"55 conservadas · 17 fusionadas · 1 eliminada · 54 nuevas (109 tablas de negocio)"**. La regla de fondo (FKs reales, `numeric(25,4)`, sin blobs, sin flags, sin contadores) **se cumple íntegra**. |
| **C2** | **`fiscal_series.series_role` vs `series_kind`.** El encargo dice `series_role` ∈ `document`/`control`; la investigación propone `series_kind` ∈ `document_number`/`control_number`/`retention_receipt`. | Nomenclatura, no semántica. | Se adopta **`series_role`** (encargo) con valores `document` \| `control` \| `retention_receipt`, más `assigned_by`. Documentar el alias en la §F. |
| **C3** | **La invariante "exactamente un `control_number`" y la doble reserva vs. la norma**: la norma dice que el control lo **asigna la imprenta** (F1 art. 42.1), pero `numeracion-fiscal` exige **dos reservas atómicas independientes**. | Sin diseño, o se quema un número, o se duplica. | Diseño D3: **espejo local** del rango de control de la imprenta (`from_number`/`to_number`/`credential_ref` sembrados del rango autorizado) + reserva atómica local + **verificación de correspondencia** con lo que la imprenta devuelve. La discrepancia se registra como evento en `fiscal_document_events`. |
| **C4** | **El prefijo obligatorio "serie" (F1 art. 20) es un literal de país dentro del dominio**, y el test anti-país prohíbe literales de país en `app/Domain/`. | Un `str_contains($prefix, 'serie')` en el dominio rompe el test anti-país. | El token exigido **es un dato**: fila en `fiscal_profile_fields` (`key='series_prefix_required_token'`, `value='serie'`). El dominio compara contra el valor leído. **Cumple las dos reglas.** |
| **C5** | **Atomicidad vs. I/O externo**: el checkout debe ser atómico, pero `submit()`/`assignControl()` son llamadas HTTP al proveedor. | Una sola transacción mantiene bloqueos de fila durante I/O → riesgo de `lock_timeout` en la caja. | Diseño D4: dos transacciones (T1 dominio / T2 fiscal), con `sales.status='fiscal_failed'` y **reutilización de la reserva** en el reintento. La atomicidad exigida por el requisito se aplica a **cada** unidad de escritura; la atomicidad cross-proveedor es imposible por naturaleza y se sustituye por un estado explícito. **Requiere confirmación del usuario.** |
| **C6** | **`quantity_in_stock` cacheado vs. "MUST derivarse"**. | El requisito lo permite *si* hay test de invariante; el diagrama B.2 exige la invariante. | Diseño D5+D6: `inventory_lots.available` es **GENERATED** (exacta por definición); `product_stores.quantity_in_stock` es caché **con test de invariante** en la pasada 2 §E. |
| **C7** | **`documents-fiscales` está al límite de palabras** (647/650 según el brief) y la capa fiscal añade requisitos nuevos (control, actor intermedio, retención). | Una spec nueva o un delta podría desbordar el presupuesto. | No se añade requisito en esta pasada; las ampliaciones van como **delta de spec** en la §F de la pasada 2, no dentro del fichero actual. |

## Preguntas abiertas

- [ ] **C5** (dos transacciones en el checkout): ¿se acepta el estado `fiscal_failed` como sustituto de la atomicidad cross-proveedor, o se exige emisión síncrona bloqueante en la caja?
- [ ] ¿El formato del correlativo fiscal es `000-00000000` (prefijo de 3 + 8 dígitos) o el del legacy? (P6) — el dato queda en `fiscal_series.prefix` + `number_formats`, pero **el valor sembrado** es una decisión de producto.
- [ ] ¿Las **compras** consumen serie fiscal propia? (P7) — el esquema lo permite (`fiscal_profile_document_types.direction='purchase'`); falta la decisión.
- [ ] ¿Fase 0 exige **T4** (dos procesos reales compitiendo por el mismo correlativo) o basta con T1–T3? (P5)
- [ ] ¿Qué saldos de apertura se congelan, con qué fecha y quién los certifica? (P9) — dependencia operativa, no de código.
- [ ] ¿El sistema opera como **imprenta digital** además de emisor? (D1 de la investigación) — el esquema lo soporta (`fiscal_profile_environments.role`); falta la decisión.
- [ ] ¿La entrega del comprobante al usuario final es por **correo**, por **consulta pública**, o ambas? (D5) — el esquema soporta ambas; falta la decisión.
