# Exploración: Fase 0 — Fundaciones (`fase0-fundaciones`)

> **Respuesta corta.** La Fase 0 no es "migrar 73 tablas": es **diseñar el esquema destino con la capa de país/fiscal dentro**, **cerrar la numeración fiscal atómica**, y **dejar fijadas por test las reglas de negocio** que el legacy ejecuta hoy sin garantías. El 80 % del valor de esta fase está en tres piezas: (a) el esquema con FKs reales, (b) `fiscal_series`/`fiscal_documents`/`fiscal_document_reservations` con reserva transaccional, y (c) la suite de tests del dominio sobre PostgreSQL.
>
> **El hallazgo que reencuadra la fase:** el adaptador de sincronización no es "firmar y aplicar". El protocolo v2 que el legacy empuja al maestro son **sentencias SQL crudas de MySQL con nombres de tabla del legacy** (`_inc/model/cron.php` → `ReadSyncData()` lee `sql|serialize(args)` de `storage/logs/sql.txt`, que escribe `_inc/lib/database.php:79`). El maestro nuevo es **PostgreSQL 17 con esquema nuevo**. Por lo tanto el adaptador **necesita traducción de identificadores**, y esa traducción es un entregable de Fase 0, no un detalle de Fase 2. Ver §9.

**Cómo leer este documento**

| Si quieres… | Ve a… |
|---|---|
| Qué hay que construir en Fase 0 | §2 (entregables) |
| Cómo queda el esquema destino | §3 |
| La capa de país / IVA venezolano | §4 |
| La numeración fiscal y cómo probarla | §5 |
| El contrato del `TaxCalculator` | §6 |
| El FIFO y la concurrencia de stock | §7 |
| Impresión LAN + separación `issued`/impresión | §8 |
| El adaptador de sincronización | §9 |
| Cola y trabajos fuera del ciclo de la request | §10 |
| Lo que falta decidir (preguntas al usuario) | §11 |

**Convenciones.** `§N` = sección del documento de referencia `D:\htdocs\pos_moderno\Documentacion\mapa-funcional.md`. `ruta:línea` = código del legacy, verificado en esta exploración. Los nombres de tabla, columna, comando y ruta van **literalmente**. `⚠ HUEGO` marca algo que **el documento de referencia no cubre** y que hay que decidir.

---

## 1. Estado actual

### 1.1 Lo que ya está construido (repo `D:\htdocs\pos-moderno-v2`)

Verificado en el repo, no de memoria:

| Pieza | Estado real |
|---|---|
| Stack | Laravel 12.70.2 + Inertia v2 + React 19 + TS 5.7 (`strict: true`) + Tailwind v4 + Vite 6. `composer.json`, `package.json`, `vite.config.js` |
| Datos | PostgreSQL 17 + Redis 7 en `compose.yaml` (`postgres:17-alpine`, `redis:7-alpine`), `predis/predis`, `QUEUE_CONNECTION=redis`, `CACHE_STORE=redis` en `.env.example` |
| Tests | Pest 3.8 + `pestphp/pest-plugin-laravel` 3.2. `phpunit.xml` **ya fuerza `DB_CONNECTION=pgsql`** y `DB_DATABASE=pos_moderno_v2_test`, con el comentario explícito de que SQLite no vale para probar bloqueo de fila ni la reserva atómica de correlativo |
| Migraciones | Sólo las 3 de Laravel 12 (`users`, `cache`, `jobs`). **Cero migraciones de dominio** |
| CI | `.github/workflows/tests.yml` + `lint.yml`. **No hay `typecheck` en CI** — hueco abierto de `sdd-init` (id 157) |
| Docs | **No existe `docs/`** → no hay todavía `docs/adr/` (exigible E8 de §11.3 del mapa) |
| OpenSpec | `openspec/config.yaml` (`strict_tdd: true`, `rules:` por fase) + `openspec/changes/archive/`. **No hay `openspec/specs/` con contenido** |

**Conclusión de Fase 0 punto de partida:** el esqueleto y la infraestructura están; **todo el dominio está por escribir**. La Fase 0 es, essencialmente, un ejercicio de diseño de esquema + una batería de tests de dominio que fijan las reglas antes de que exista interfaz.

### 1.2 Lo que el legacy entrega (verificado)

| Hecho | Evidencia |
|---|---|
| 73 tablas exactas | `grep -oP 'CREATE TABLE (IF NOT EXISTS )?\`?\K[A-Za-z_0-9]+' "BASE DE DATOS/pos_moderno.sql"` → **73** (verificado en esta exploración) |
| El checkout escribe 8+ tablas sin transacción | §1 (resumen), §6 trampa P0 #1, `_inc/model/invoice.php:92-397` |
| El FIFO no es determinista | `_inc/model/invoice.php:237` y `:244` — `SELECT * FROM purchase_item WHERE store_id = ? AND item_id = ? AND status = 'active' AND item_quantity > total_sell` **sin `ORDER BY`** |
| El correlativo no se reserva | `_inc/helper/invoice.php:139-172` (`generate_invoice_id`) deriva del último `invoice_id`; `:95-109` (`unique_transaction_ref_no`) usa `rowCount()` de un `SELECT` |
| El prefijo de país vive en 16 sitios | `get_preference('invoice_view') == 'indian_gst'` en `_inc/template/*.php` (11 ocurrencias) + `admin/*.php` (5 ocurrencias) |
| La impresión del legacy está **rota** | `_inc/print.php:29` → `dd($data);` y `dd()` = `echo "<pre>".print_r($data,true)."</pre>"; exit;` (`_inc/helper/common.php:2-5`) → **el endpoint vuelca el JSON y sale antes de generar ESC/POS** |
| El protocolo de sync v2 | `_inc/helper/sync.php` (490 líneas), `sync.php` (161 líneas) |

### 1.3 Decisiones ya tomadas (no se re-preguntan)

Se taken como base, tal como las fijó el usuario (§9 del mapa y las 11 decisiones del encargo):

1. Pedidos = módulo real → entidad de primera clase. 2. Impresión: LAN **y** servidor. 3. Sync offline→online: sí, protocolo v2 como **contrato fijo**. 4. Facturación electrónica: sí (Venezuela). 5. Datos **verdes** (sin histórico; saldos de apertura congelados). 6. Multi-tienda: sí (maestro/bridge con FKs reales). 7. Cuotas: se conservan (interés simple `interest_percentage` sobre el payable). 8. SMS/WhatsApp: sí (gateway existente, credenciales a env). 9. El nuevo **reemplaza** al legacy; `sync.php` viejo se apaga el **mismo día**. 10. Equipo nuevo → CI + tests + docs son requisito del día 1. 11. Multipaís: Venezuela por defecto, el motor fiscal/impuestos/numeración/formatos es **datos, no código**.

---

## 2. Áreas afectadas

### 2.1 En el repo nuevo (`D:\htdocs\pos-moderno-v2`)

| Ruta | Qué se toca y por qué |
|---|---|
| `database/migrations/` | **Todas las migraciones de dominio**. Hoy sólo hay 3 de Laravel 12. Aquí vive el esquema destino (§3) y la capa de país/fiscal (§4) |
| `app/Models/` | Modelos Eloquent del dominio. Hoy sólo `User.php`. FKs reales, `decimal(25,4)`, casts de `json`/`decimal` |
| `app/Domain/` (nuevo) | Núcleo testeable, independiente de HTTP (§8.1 pto. 6 del mapa): `Tax/`, `Fiscal/`, `Inventory/`, `Accounting/`, `Installments/` |
| `app/Sync/Legacy/` (nuevo) | Adaptador de sincronización v2 (§9). Aislado del resto del dominio |
| `app/Jobs/` (nuevo) | Trabajos fuera del ciclo de la request (§10). La tabla `jobs` ya existe (migración Laravel `0001_01_01_000002_create_jobs_table.php`) |
| `database/seeders/` | Seed del perfil fiscal venezolano + catálogos + tienda + ejemplos (E5 de §11.3) |
| `tests/Unit/`, `tests/Feature/` | Tests del dominio (E6 de §11.3) + test de concurrencia fiscal (§5.3) |
| `docs/adr/` (nuevo) | ADRs de las decisiones estructurales (E8 de §11.3). **No existe todavía** |
| `openspec/config.yaml` | Posible ajuste de `rules:` si la Fase 0 introduce reglas nuevas (p. ej. prohibition explícita de SQL crudo por país) |
| `.github/workflows/tests.yml` | Añadir `typecheck` (hueco abierto) y, si se adopta, el job de concurrencia |

### 2.2 En el legacy (`D:\htdocs\pos_moderno`, **sólo lectura**)

| Ruta | Por qué se lee |
|---|---|
| `_inc/model/invoice.php` | Checkout y FIFO (§7). `createInvoice()` `:92-397`; FIFO `:233-282`; cuotas `:376-391` |
| `_inc/helper/invoice.php` | Correlativo (`:95-172`), datos de plantilla de factura (`:525-550`, `:688-715`), prefijo de compra `:111-115` |
| `_inc/helper/sync.php` | Contrato v2 completo: canonización, firma, nonce, verificación, config (§9) |
| `sync.php` | Servidor de sync: interruptores, límites, validación DML, transacción del lote (§9) |
| `_inc/model/cron.php` | Cliente de sync: `PushSqlToRemoteServer()` y `ReadSyncData()` — **define el payload real** (§9) |
| `_inc/lib/database.php` | `:62-83` — qué SQL entra al log de sync (§9) |
| `_inc/print.php` | Contrato de impresión del legacy (`:28-31`) — y el `dd()` que lo rompe (§8) |
| `_inc/template/user_group_form.php` | Matriz de ~190 slugs (§5 del mapa) — insumo del modelo de permisos |
| `_inc/place_order.php`, `_inc/sell_return.php`, `_inc/purchase.php`, `_inc/transfer.php` | Semántica de devolución, compra y transferencia que los tests deben fijar |
| `BASE DE DATOS/pos_moderno.sql` | Esquema real: 73 tablas (§3) |

---

## 3. El esquema destino

### 3.1 Principio de traducción

El mapa (§8.3) ya fija el criterio: **mismo patrón, nombres legibles y FKs reales**. La tríada "cabecera / línea / dinero" se conserva porque es sólida (§7.1). Lo que cambia es (a) los nombres, (b) las FKs, (c) los tipos, (d) los blobs, (e) los contadores derivados.

**Regla de este apartado:** *cada tabla del destino tiene **una** fuente de verdad para cada dato; todo lo que se puede derivar, se deriva.*

### 3.2 Disposición de las 73 tablas

**Conservar (55)** — misma entidad, nombre nuevo o corregido, FKs reales:

| Legacy | Destino | Cambio clave |
|---|---|---|
| `users` | `users` | `password` MD5 → `hashed` (argon2id/bcrypt); **eliminar `raw_password`**; `preference` blob → `user_settings`; `pass_reset_code`/`reset_code_time` → `password_reset_tokens` de Laravel; `group_id` → FK `roles.id` |
| `user_group` | `roles` | `permission` blob → `permissions` + `role_permissions` (§5 del mapa) |
| `user_to_store` | `user_stores` | FKs reales `users.id`/`stores.id`; **quit ar el `JOIN u2s.status=1` que bloquea en todas las tiendas** (§3.19) |
| `login_logs` | `login_logs` | FK `users.id` nullable |
| `shortcut_links` | `dashboard_shortcuts` | `permission_slug` → FK `permissions.id` |
| `stores` | `stores` | `preference` blob → `store_settings`; `country` → FK `countries.iso_code`; `vat_reg_no` → `store_fiscal_registrations`; `receipt_printer`/`order_printers`/`remote_printing`/`auto_print`/`local_printers`/`printer` → `print_targets` + `print_settings` |
| `product_to_store` | `product_stores` | `purchase_price`/`sell_price` son **`float`** → `decimal(25,4)`; `taxrate_id`/`tax_method` → `tax_category_id` + política del perfil fiscal |
| `customer_to_store` | `customer_stores` | `balance`/`due` `decimal(25,4)` (ya lo son) |
| `supplier_to_store` | `supplier_stores` | `balance` |
| `brand_to_store`, `category_to_store`, `box_to_store`, `unit_to_store`, `currency_to_store`, `pmethod_to_store`, `pos_template_to_store` | `*_stores` | FKs reales + `status`/`sort_order` |
| `bank_account_to_store` | `bank_account_stores` | **Eliminar** `deposit`/`withdraw`/`transfer_from_other`/`transfer_to_other` (contadores derivados, §10.3); queda `opening_balance` congelado |
| `products` | `products` | `p_type` → `type`; `hsn_code` (sólo `indian_gst`) → `tax_categories.code` (datos) |
| `product_images` | `product_images` | FK `products.id` real |
| `customers` | `customers` | **Eliminar `customers.credit_balance`** (duplica `customer_to_store.balance`); `customer_country`/`customer_state` → FK; `gtin` → `tax_id` |
| `suppliers` | `suppliers` | `gtin` → `tax_id` |
| `categorys` | `categories` | Corregir el nombre irregular (§2.3); `parent_id` FK a sí misma |
| `brands`, `boxes`, `units` | igual | `box_details`/`unit_details` → `jsonb` |
| `pmethods` | `payment_methods` | Reglas de validación por país → tabla de reglas (§4.2) |
| `printers` | `printers` | Catálogo; el destino (`path`/`ip_address`/`port`) vive hoy en `printer_to_store` |
| `pos_templates` | `pos_templates` | `template_content` es `ucs2` → `text`/`jsonb`; el parser `Lex` → parser propio o Blade |
| `payments` | `sale_payments` | `details` blob → `jsonb`; `type` → `payment_kind`; `pos_balance` → `change_amount` |
| `returns` / `return_items` | `sale_returns` / `sale_return_items` | `invoice_id` → FK `sales.id` |
| `customer_transactions` | `customer_ledger_entries` | `type` con valores canónicos; `reference_no`/`ref_invoice_id` → FK |
| `gift_cards` | `gift_cards` | `created_by` **varchar(55)** → FK `users.id`; eliminar `customer` (texto duplicado) |
| `gift_card_topups` | `gift_card_topups` | `card_id` (texto `card_no`) → FK `gift_cards.id` |
| `installment_orders` | `installment_plans` | FK `sales.id`; `interest_percentage` `decimal(25,4)` (hoy `int`) |
| `installment_payments` | `installment_schedules` | FK `installment_plans.id` (hoy `invoice_id` textual) |
| `holding_info` / `holding_item` / `holding_price` | `held_orders` / `held_order_items` / `held_order_totals` | **Pedido en espera del POS ≠ pedido**. FK a `customers`/`stores`/`users` |
| `purchase_payments` | `purchase_payments` | `invoice_id` → FK `purchases.id`; `type` (`purchase`/`due_paid`/`transfer`) → `payment_kind` |
| `purchase_returns` / `purchase_return_items` | igual | FK `purchases.id` |
| `transfers` | `transfers` | **1 fila por transferencia** (§3.13: el bug actual genera N cabeceras, `_inc/transfer.php:169-275`) |
| `transfer_items` | `transfer_items` | Añadir `unit_cost` y `source_lot_id` (hoy no guarda precios) |
| `bank_accounts` | `bank_accounts` | **Eliminar** `total_deposit`/`total_withdraw`/`total_transfer_from_other`/`total_transfer_to_other` (derivados) |
| `expenses` | `expenses` | **Pasa a ser la fuente de verdad del gasto** (§7.2): el gasto nace aquí y genera un `ledger_entry` |
| `expense_categorys` | `expense_categories` | **Eliminar los 10 flags contables** (`sell_return`, `sell_delete`, `loan_delete`, `loan_payment`, `giftcard_sell_delete`, `topup_delete`, `product_purchase`, `stock_transfer`, `due_paid`, `is_hide`) → pasan a tipos de movimiento |
| `income_sources` | `income_sources` | **Eliminar los 11 flags** (`for_sell`, `for_purchase_return`, `for_due_collection`, `for_loan`, `for_giftcard_sell`, `for_topup`, `for_stock_transfer`, `for_purchase_delete`, `for_expense_delete`, `profitable`, `show_in_income`) y `type` (`credit`/`debit`) → tipo de movimiento |
| `pos_register` | `cash_registers` | Apertura/cierre por tienda **con fecha de negocio** (el legacy filtra por `DAY()/MONTH()/YEAR()` → §6 trampa #62) |
| `loans` | `loans` | `loan_from` → FK a `suppliers`/`customers`; `payable` calculado en servidor (§3.16) |
| `loan_payments` | `loan_payments` | **Corregir `lloan_id` → `loan_id`** (§7.2) |
| `languages` | `languages` | — |
| `language_translations` | `language_translations` | FK `languages.id`; `lang_value` nullable |
| `sms_schedule` | `sms_messages` | Outbox de SMS; `process_status`/`delivery_status` → `status`/`sent_at` |
| `mail_sms_tag` | `message_templates` | Tags `{{…}}` conservados |

**Fusionar (17)** — la tabla desaparece; su contenido vive en otra(s):

| Legacy | Se fusiona en | Por qué |
|---|---|---|
| `selling_info` | `sales` + `fiscal_documents` | Cabecera: identidad de la venta en `sales`, documento fiscal en `fiscal_documents` (§8.5.3 pto. 3) |
| `selling_item` | `sale_items` + `sale_item_lot_consumptions` | `purchase_invoice_id` (lote) → FK a `inventory_lots`; **`tax`/`gst` guardan el porcentaje y `item_tax` el importe** → separar (`tax_rate_snapshot` vs `tax_amount`); `cgst`/`sgst`/`igst` → eliminar (India) |
| `selling_price` | `sale_totals` | `interest_amount`/`interest_percentage` → `installment_plans`; `cgst`/`sgst`/`igst` → eliminar; `previous_due`/`prev_due_paid` → `customer_ledger_entries`; `total_purchase_price`/`profit` → derivables de `sale_item_lot_consumptions` |
| `sell_logs` | `sale_payments` | Duplica `payments` (bitácora de cobro) |
| `purchase_info` | `purchases` | `inv_type` enum(`purchase`,`transfer`) → las transferencias salen a su propia tabla (§3.13) |
| `purchase_item` | `inventory_lots` | El soporte del FIFO pasa a ser un lote explícito con `qty_in`/`qty_out` y `ORDER BY received_at, id` (§8.3) |
| `purchase_price` | `purchase_totals` | Mismo patrón cabecera/línea/dinero |
| `purchase_logs` | `purchase_payments` | Duplica `purchase_payments` |
| `bank_transaction_info` | `ledger_entries` | **Tipo de movimiento explícito** en vez de flags booleanos dispersos (§8.3) |
| `bank_transaction_price` | `ledger_entries.amount` | Es 1:1 con la cabecera; el importe es un dato, no una tabla |
| `quotation_info` | `quotations` + `orders` | **División**: `is_order = 0` → cotización; `is_order = 1` → pedido (`_inc/order.php:408` lo confirma) |
| `quotation_item` | `quotation_items` + `order_items` | Misma división |
| `quotation_price` | `quotation_totals` + `order_totals` | Misma división |
| `printer_to_store` | `print_targets` | `path`/`ip_address`/`port`/`status` → destino de impresión con `transport` |
| `taxrates` | `tax_categories` + `tax_rates` | La tasa del legacy (`taxrate` decimal, sin vigencia, sin perfil de país) no puede modelar el IVA venezolano ni la exento/no gravado/exonerado (§4) |
| `currency` | `currencies` | Se enriquece con `decimals`, `symbol_position`, `thousand_separator`, `decimal_separator` (§8.5.2) |
| `settings` | `settings` tipada + `store_settings` | "Ajustes globales" no es un KV libre; y `stores.preference`/`users.preference` pasan a tabla `*_settings` (§7.2) |

**Eliminar (1)**

| Legacy | Por qué |
|---|---|
| `sms_setting` | Credenciales de gateway **en claro** (§6 trampa P1 #31). Se sustituye por `sms_providers` (datos) + credenciales en variables de entorno / secret store (§8.5.2) |

> ⚠ **Corrección al encargo**: `installment_payment` (singular) **no es una tabla**. El dump tiene `installment_orders` y `installment_payments`. Lo duplicado es el **handler** `_inc/installment_payment.php`, que es copia literal de `_inc/installment.php` (§3.8, §6 trampa #69). En el destino no hay tabla duplicada: `installment_plans` + `installment_schedules`.

### 3.3 Tablas nuevas (no existen en el legacy)

**Capa de país/fiscal** (§4): `countries`, `fiscal_profiles`, `fiscal_series`, `fiscal_documents`, `fiscal_document_reservations`, `tax_categories`, `tax_rates`, `currencies`, `number_formats`, `store_fiscal_registrations`.

**Plataforma**: `permissions`, `role_permissions`, `role_store_scopes`, `store_settings`, `user_settings`, `settings`, `ledger_entries`, `ledger_entry_types`, `inventory_lots`, `sale_item_lot_consumptions`, `print_jobs`, `print_targets`, `print_agents`, `sync_batches`, `audit_logs`, `opening_balances`, `job_batches` (Laravel), `failed_jobs` (Laravel).

### 3.4 Criterios transversales del esquema

1. **Money**: `decimal(25,4)` en PostgreSQL = `numeric(25,4)`. **Nunca `float`** (el legacy usa `float` en `product_to_store.purchase_price`/`sell_price` — se corrige).
2. **FKs reales** con `ON DELETE` explícito: `RESTRICT` para lo histórico (ventas, lotes, asientos), `CASCADE` para lo dependiente (líneas de un borrador), `SET NULL` para lo opcional (usuario que borró).
3. **Nada de blobs PHP serializados**: `stores.preference`, `users.preference`, `user_group.permission`, `payments.details`, `product_to_store.preference` → columnas tipadas o `jsonb`.
4. **Nada de flags contables**: un movimiento contable tiene **un** `entry_type`; la clasificación se consulta, no se desparrama en booleanos.
5. **Nada de contadores derivados**: `quantity_in_stock`, saldos de cuenta y totales se derivan; si se cachean, hay un **test de invariante**.
6. **Toda tabla operativa lleva `store_id`** (o se alcanza por FK) y `created_by`/`created_at`.

---

## 4. La capa de país y fiscal

### 4.1 Objetivo: "cambiar de país = insertar filas"

El control es explícito: **un `if ($country === 'VE')` en el dominio es un defecto** (§9.1, §10.4, `openspec/config.yaml` → `context`). Para que ese control sea verificable, hace falta un **test de arquitectura**, no sólo una convención:

- Un test que recorra `app/Domain/` y falle si aparece un literal de código de país (`'VE'`, `'Venezuela'`, `Venezuela`, `USD`, `Bs`) fuera de `config/` y de los seeders. Es la versión ejecutable de "la Fase 2 se reduce a insertar filas" (§8.5.4).
- Un test que itere **todos** los perfiles fiscales sembrados y verifique que el mismo `TaxCalculator` produce el resultado esperado para cada uno, sin ramificaciones.

### 4.2 Tablas de la capa de país

Siguiendo §8.5.2, con las precisiones que hacen falta para Venezuela:

```
countries          → iso_code (VE, CL, MX, CO, PA), name, locale, default_currency_id,
                     timezone, phone_format, document_id_format
fiscal_profiles    → country_id, code (VE_ORDINARIO, VE_SIMPLIFICADO), name,
                     document_standard (none|seniat_ubl|...), rounding_rule (line|total),
                     tax_inclusive_default, effective_from/to
fiscal_series      → store_id, fiscal_profile_id, document_type, prefix,
                     next_number, from_number, to_number, valid_from, valid_to, status
fiscal_documents   → fiscal_series_id, number, store_id, customer_id, issue_date,
                     subtotal, tax_total, total, currency, status (draft|issued|cancelled|void),
                     hash, xml_path, pdf_path, provider, provider_response, created_by, created_at
fiscal_document_reservations → fiscal_series_id, number, reserved_by, reserved_at,
                     consumed_at, voided_at
tax_categories      → fiscal_profile_id, code (iva_gravable|iva_exento|iva_no_gravado|
                     iva_exonerado|iva_retenido), name, is_withholding, applies_to
tax_rates           → tax_category_id, percentage, method (inclusive|exclusive),
                     rounding_rule, valid_from, valid_to, priority
currencies          → code, decimals, symbol, symbol_position, thousand_separator,
                     decimal_separator
number_formats      → country_id, date_format, invoice_number_format, phone_format,
                     document_id_format
payment_methods     → code, name, requires_reference, country_rules (jsonb)
sms_providers       → code, name, supports_scheduling, credential_env_prefix
```

### 4.3 El IVA venezolano en el modelo

| Categoría | Semántica VE | Cómo se representa |
|---|---|---|
| `iva_gravable` | 16 % | `tax_rates` con `percentage = 16`, `method = exclusive` (el legacy usa `exclusive` por defecto en `purchase_item`, y `inclusive` en `product_to_store` — inconsistencia que hay que unificar; ver §4.4) |
| `iva_exento` | Artículos exentos | `tax_category` con `tax_rates` vacía o 0 % — el producto **apunta a la categoría**, no a "no taxing" |
| `iva_no_gravado` | No gravado | Igual, categoría propia |
| `iva_exonerado` | Exonerado (con resolución) | Categoría propia + referencia a la exoneración |
| `iva_retenido` | IVA retenido | `is_withholding = true` — **el legacy NO tiene retenciones** (sólo el `switch` de India `cgst`/`sgst`/`igst`). ⚠ HUEGO: si el negocio retiene IVA, es módulo nuevo |

**El producto apunta a `tax_category_id`, no a `taxrate_id`.** Es el cambio que hace posible el IVA venezolano: hoy `product_to_store.taxrate_id` apunta a una fila de `taxrates` que sólo tiene nombre y porcentaje, sin perfil, sin vigencia y sin la distinción gravable/exento/no gravado/exonerado (§2.3 `taxrates`, §7.2 "separar `tax_rate` de `tax_amount`").

**Redondeo.** El legacy **no tiene regla de redondeo explícita**: el FIFO compara con `number_format($stock, 3, '.', '')` (`_inc/model/invoice.php:254`), las plantillas usan `currency_format()` (2 decimales) y el IVA se calcula sin redondeo explícito (`_inc/model/invoice.php:227`). El destino lo hace **dato**: `fiscal_profiles.rounding_rule` ∈ {`line`, `total`} + `tax_rates.rounding_rule`, con una regla de **ajuste de redondeo** explícita en el total para que el documento cuadre al centavo. ⚠ HUEGO: la decisión línea-vs-total es del usuario (§11, P4).

### 4.4 Detalles que hay que fijar en el diseño

1. `tax_method` debe quedar en **un solo sitio**. Hoy conviven: `purchase_item.tax_method` enum(`exclusive`,`inclusive`) default `exclusive` y `product_to_store.tax_method` varchar default `inclusive` (`BASE DE DATOS/pos_moderno.sql`). El destino lo decide el `fiscal_profile.tax_inclusive_default`.
2. `selling_item.tax`/`gst` guardan el **porcentaje** y `item_tax` el **importe** (§6 trampa P0 #24) → el destino guarda `tax_rate_snapshot` (porcentaje, congelado en el documento) **y** `tax_amount` (importe), en columnas distintas y con nombres distintos.
3. El **impuesto se calcula una vez, en el servidor, y se persiste** (§8.5.3 pto. 4). El legacy calcula en Angular (`PosController.js` → `_calcTaxAmount`) **y** recalcula en PHP (`_inc/model/invoice.php:227`) → dos fuentes de verdad. Además el PHP usa el **precio de catálogo** (`$product_info['sell_price']`) en vez del cobrado (`$product['item_price']`) → §6 trampa P0 #13. El destino calcula sobre el **precio cobrado**.
4. `country`/`state` del cliente dejan de ser texto libre: `customers.country_id` → `countries`, y el "estado" se modela como `tax_regions` si el perfil fiscal lo necesita. ⚠ HUEGO: el legacy usa `customer_state` vs `preference[business_state]` para decidir `cgst/sgst` vs `igst` (`_inc/model/invoice.php:283-291`) — un concepto **indio** que en Venezuela no aplica; hay que decidir si el destino conserva "región fiscal" como concepto genérico.

---

## 5. Numeración fiscal atómica

### 5.1 El defecto que hay que eliminar

| Legacy | Evidencia | Por qué es un bloqueante |
|---|---|---|
| `generate_invoice_id()` | `_inc/helper/invoice.php:139-172` | Deriva del "último `invoice_id`" leído, le suma 1 y comprueba con `hasInvoice()`. **No reserva nada.** Y el reintento recursivo de la línea 160 **descarta el valor devuelto** → nunca reintenta de verdad |
| `unique_transaction_ref_no()` | `_inc/helper/invoice.php:95-109` | Usa `rowCount()` de un `SELECT` (en PDO no es el número de filas afectadas) y suma 1 |
| Compras | `_inc/purchase.php` (§3.10) | `invoice_id = get_invoice_prefix('purchase') . $request->post['reference_no']` → **prefijo + referencia escrita por el usuario**. No hay correlativo de compra |

### 5.2 Diseño de la reserva

Las tres tablas (`fiscal_series`, `fiscal_documents`, `fiscal_document_reservations`) están definidas en §10.1 del mapa. Lo que Fase 0 debe fijar:

```
-- Reserva atómica (PostgreSQL)
UPDATE fiscal_series
   SET next_number = next_number + 1
 WHERE id = ?
   AND next_number <= to_number
RETURNING next_number - 1;
```

- La reserva ocurre **dentro de la misma transacción** que la factura. Si la transacción falla, `next_number` **se devuelve** (rollback). Si la factura se anula, el número **no se reutiliza**: queda consumido con `fiscal_documents.status = 'void'` y su fila en `fiscal_document_reservations` con `voided_at`.
- En Venezuela el correlativo **no retrocede** (§8.5.3 pto. 5).
- `fiscal_document_reservations` es la **traza de auditoría**: quién tomó cada número y cuándo, incluso los cancelados.
- `to_number` + `valid_from`/`valid_to` materializan el **rango autorizado** (§8.5.1). Cuando `next_number > to_number`, la reserva **falla** y hay que abrir una serie nueva — no se improvisa un número.

**El numero no se deriva de `rowCount()` ni del "último registro".** Es una de las reglas del `openspec/config.yaml` → `rules.design`: *"El correlación fiscal se reserva con `UPDATE ... RETURNING` o `SELECT ... FOR UPDATE`; nunca con `rowCount()`"*.

### 5.3 Cómo se prueba la concurrencia sobre PostgreSQL

Este es el punto que más)\, y donde el stack elegido marca la diferencia. El repo ya está en PostgreSQL (`phpunit.xml` → `DB_CONNECTION=pgsql`) precisamente por esto.

**El problema de `RefreshDatabase`.** Pest+Laravel envuelve cada test en una transacción y hace rollback. Una **segunda conexión** no ve los datos no commiteados y el bloqueo no se reproduce. Por tanto los tests de concurrencia **no pueden** usar `RefreshDatabase`: necesitan `DatabaseMigrations` (migra y hace commit) o truncado explícito.

**Tres niveles de test, de menor a mayor exigencia:**

| # | Test | Mecánica | Qué prueba |
|---|---|---|---|
| T1 | **Secuencia** | N reservas secuenciales → N números consecutivos, sin huecos, sin repeticiones | Corrección del contador |
| T2 | **Bloqueo real** | Conexión A: `BEGIN; SELECT … FOR UPDATE` sobre la serie. Conexión B (`DB::connection('pgsql_b')`, con `SET LOCAL lock_timeout = '250ms'`) intenta la reserva → debe fallar con **SQLSTATE 55P03 `lock_not_available`** | Que el bloqueo de fila funciona de verdad en PG |
| T3 | **Rollback** | Reserva dentro de una transacción que luego hace `ROLLBACK` → `next_number` vuelve al valor anterior y el número queda libre | Que el fallo de la factura no quema número |

**T3 es el que más valor tiene** y el más fácil de hacer bien: es la garantía de que un checkout fallido no deja un hueco en la numeración fiscal.

**T4 (concurrencia real, dos procesos) — hueco a decidir.** El test definitivo ("dos conexiones compitiendo por el mismo `next_number`") requiere **dos procesos simultáneos**, y `pcntl_fork` no está disponible en Windows/XAMPP. Opciones, con su coste:

| Opción | Mecánica | Coste |
|---|---|---|
| 4a | Comando Artisan `fiscal:reserve` + `Symfony\Component\Process` lanzando 2 procesos a la vez en el test | Barato, determinista, requiere un comando de test |
| 4b | Test HTTP con `curl_multi` contra un endpoint de test | Caro, necesita servidor |
| 4c | `pgbench` / script SQL con `pg_sleep` fuera de la suite | Barato pero fuera del gate de CI |

⚠ **HUEGO (P5 en §11)**: la suite de Fase 0 no tiene un mechanism que certifique "dos cajas simultáneas obtienen números distintos" con una sola aserción. **T1+T2+T3 cubren laInvariant**; T4 certifica el escenario de negocio. Decisión: si el riesgo se acepta en Fase 0 (recomendable, porque el diseño con `UPDATE … RETURNING` es correcto por construcción) o si se exige T4a.

### 5.4 Series: el hueco de formato

Hay una **contradicción entre el mapa y el legacy** que hay que resolver antes de sembrar:

- §8.5.1 dice: Venezuela → "Correlativo consecutive por tienda, sin saltos (`000-00000000`), con rango autorizado".
- El legacy genera: `prefijo + store_id + secuencia de 8 dígitos` (`_inc/helper/invoice.php:159` y `:169`), con el formato de secuencia configurable por `preference[reference_format]` ∈ {`year_sequence`, `year_month_sequence`, `sequence`, `random`} (`_inc/helper/invoice.php:117-137`).

`000-00000000` es el formato **factual** venezolano (prefijo de 3 + correlativo de 8). El legacy **no lo produce**. → **Pregunta al usuario (P6 en §11)**: ¿el formato del correlativo fiscal es `000-00000000` (factura) y el de compra es otro, o se conserva el formato del legacy?

Además: ¿**las compras también consumen correlativo fiscal**? En Venezuela, sí (factura del proveedor). El legacy no. → **Pregunta al usuario (P7 en §11)**.

---

## 6. El motor fiscal (`TaxCalculator`)

### 6.1 Entradas

```php
final readonly class TaxContext {
    public function __construct(
        public FiscalProfile $profile,          // rounding_rule, tax_inclusive_default, currency
        public Currency $currency,              // decimals
        public RoundingMode $rounding,          // half-up / half-even / floor
    ) {}
}

final readonly class TaxLineInput {
    public function __construct(
        public int $taxCategoryId,              // FK tax_categories (NO un porcentaje)
        public Decimal $unitPrice,
        public Decimal $quantity,
        public ?Discount $lineDiscount,         // plain|percentage
        public TaxMethod $method,               // inclusive|exclusive (heredado del perfil)
        public ?Decimal $taxRateOverride,       // exento/exonerado con tasa propia
    ) {}
}

final readonly class TaxDocumentInput {
    public function __init__(/** … */) {
        // array $lines (TaxLineInput[])
        // ?Discount $documentDiscount
        // Decimal $shipping, $otherCharges
        // Decimal $previousDue        // deuda previa que se compensa
        // Decimal $interestAmount     // cuotas (interés simple)
        // bool $isPurchase             // compra vs venta: cambia el signo y el treatment
    }
}
```

### 6.2 Salidas (persistidas, nunca recalculadas en el cliente)

```php
final readonly class TaxLineResult {
    public Decimal $netAmount;        // base imponible
    public Decimal $taxAmount;        // importe del impuesto
    public Decimal $totalAmount;      // net + tax
    public Decimal $taxRateSnapshot;  // porcentaje congelado
    public int $taxCategoryId;
    public int $taxRateId;
}

final readonly class TaxDocumentResult {
    public array $lines;              // TaxLineResult[]
    public Decimal $subtotal;
    public Decimal $discountTotal;
    public Decimal $taxTotal;
    public array $taxBreakdown;       // por categoría: ['iva_gravable' => ['base'=>…, 'tax'=>…], 'iva_exento' => […]]
    public Decimal $shippingTotal;
    public Decimal $otherChargesTotal;
    public Decimal $roundingAdjustment;
    public Decimal $payableTotal;
    public Decimal $previousDueApplied;
}
```

### 6.3 Reglas que el motor debe respetar

1. **Cero país en código.** Todo sale de `fiscal_profiles` + `tax_categories` + `tax_rates`.
2. **El impuesto se persiste, no se recalcula.** El documento guarda `tax_rate_snapshot` (porcentaje) y `tax_amount` (importe) por línea y por documento. Si mañana cambia la tasa del 16 % al 19 %, los documentos emitidos **no cambian**.
3. **Base imponible explícita.** Por línea: `net = (unit_price × qty) − line_discount`; el impuesto es `net × rate` (exclusive) o está dentro del precio (inclusive). El `tax_method` viene del perfil, no de una columna duplicada en tres sitios (§4.4).
4. **Descuento de documento prorrateado** entre líneas, con el **método de prorrateo declarado** (proporcional al base imponible) — en el legacy el prorrateo es invisible.
5. **Redondeo declarado** y con **ajuste visible**: `roundingAdjustment` explica la diferencia entre la suma de líneas y el total redondeado. Sin ese campo, "los totales no cuadran" (§8.5.1).
6. **La base imponible del IVA de las compras es el IVA descontable**, y el retenido es un impuesto **retenido por el comprador**, no un impuesto del vendedor → modelado como categoría con `is_withholding = true`.
7. **El resultado es determinista y puro**: mismos inputs → mismo output, sin leer la BD dentro del cálculo (los perfiles y las tasas se resuelven **antes** de llamar al motor).

### 6.4 El hueco de la exención/exoneración

⚠ **HUEGO (P8 en §11)**: el legacy no tiene ninguna noción de exención por producto ni de exoneración con resolución. El destino sí tiene `tax_categories.iva_exento`/`iva_exonerado`, pero **el dato de qué productos están exentos** no existe en el legacy y hay que crearlo (seed + UI en Fase 1). En Fase 0 sólo se define el modelo.

---

## 7. Stock por lotes (FIFO)

### 7.1 Los tres defectos del FIFO del legacy

| # | Defecto | Evidencia |
|---|---|---|
| 1 | El lote consumido **no es determinista**: el `SELECT` de lotes disponibles **no tiene `ORDER BY`** | `_inc/model/invoice.php:237` y `:244` |
| 2 | El `UPDATE` de consumo toca **todos los lotes activos del producto**, no el seleccionado: `UPDATE purchase_item SET total_sell = total_sell + {$sell_quantity} WHERE store_id = ? AND item_id = ? AND status = ?` — **sin `id = ?`** | `_inc/model/invoice.php:262-263` |
| 3 | Interpolation de SQL en el propio consumo y en el stock: `total_sell = total_sell + {$sell_quantity}` y `quantity_in_stock = quantity_in_stock - {$quantity_substract}` | `_inc/model/invoice.php:262` y `:294` |

El defecto #2 es **peor** que el #1 y **no está en el catálogo de trampas del mapa**: el `UPDATE` sin `id` incremente `total_sell` en todos los lotes `active` del producto, no sólo en el que se seleccionó. Con más de un lote activo, el consumo se contabiliza varias veces.

> El mapa documenta el defecto #1 (§6 trampa P0 #12) pero no el #2. **Añadir el #2 al catálogo.**

### 7.2 Modelo destino

```
inventory_lots
  id, product_id, store_id, purchase_id, source_lot_id (nullable, para transferencias),
  unit_cost, quantity_received, quantity_consumed, quantity_returned,
  received_at, expires_at, notes, created_by, created_at
  -- available = quantity_received - quantity_consumed + quantity_returned
  -- NO hay `status` mutable: el estado se deriva de la aritmética
sale_item_lot_consumptions
  id, sale_item_id, lot_id, quantity, unit_cost, created_at
```

- **Se elimina el `status` mutable** (`stock`/`active`/`sold`). La tríada del legacy era una forma de codificar "disponible" con un enum y dos `UPDATE` — la aritmética de `qty_in/qty_out` es la fuente de verdad y no puede quedar desincronizada. Es además lo que el mapa ya pide: *"El FIFO deja de depender de `status` mutable"* (§8.3).
- **`sale_item_lot_consumptions` es la traza que el legacy no tiene**: permite (a) calcular el coste real de la venta (COGS) sin recalcular, (b) **revertir una devolución al lote exacto** — el legacy actualiza `purchase_item` con dos claves distintas (`invoice_id` en un `UPDATE` y `purchase_invoice_id` en otro, `_inc/sell_return.php:142-153`, §6 trampa P0 #10) y por eso deja lotes inconsistentes.

### 7.3 Consumo concurrente: `FOR UPDATE SKIP LOCKED`

```sql
SELECT id, quantity_received - quantity_consumed + quantity_returned AS available
  FROM inventory_lots
 WHERE product_id = ? AND store_id = ?
   AND quantity_received - quantity_consumed + quantity_returned > 0
 ORDER BY received_at ASC, id ASC
 FOR UPDATE SKIP LOCKED
```

- **`SKIP LOCKED`** es lo que permite que dos cajas vendan el mismo producto a la vez sin bloquearse entre sí y **sin sobreventa**: cada caja se salta los lotes que la otra tiene bloqueados y consume los siguientes. Es una ventaja directa de PostgreSQL (9.5+) que MySQL 5.7 no da igual — y la razón técnica de que la decisión de "tests sobre PostgreSQL" sea la correcta.
- El `ORDER BY received_at, id` **sí** es determinista (a diferencia del legacy) y con índice `(product_id, store_id, received_at, id)` donde `available > 0` no es indexable directamente → índice parcial o columna `quantity_available` materializada. **Decisión de diseño a tomar**: mantener `quantity_available` como columna denormalizada (mantenida en la misma transacción) o calcularlo. Con columna denormalizada + **test de invariante** (`product_stores.quantity_in_stock == SUM(available)`) es la opción más rápida de leer y de verificar.

### 7.4 Qué hay que probar en Fase 0

| Test | Regla que fija |
|---|---|
| FIFO ordena por antigüedad | Con 2 lotes, el consumo toma primero el más antiguo (el legacy **no** lo garantiza) |
| Consumo parcial | Un lote de 5 y otro de 3, se venden 4 → 4 del primero, 0 del segundo |
| Consumo multi-lote | Una venta de 7 con lotes 5+3 → consume 5 y 2, y deja `sale_item_lot_consumptions` con 2 filas |
| Devolución reponte al lote | La devolución del ítem X repone exactamente el lote que se consumió |
| Sin sobreventa concurrente | Dos procesos vendiendo el mismo stock limitado → el total vendido nunca excede el stock (usa `SKIP LOCKED`) |
| Invariante de stock | `product_stores.quantity_in_stock == SUM(inventory_lots.available)` tras cada operación |
| Servicio | Un producto `p_type = 'service'` no genera lote ni consume stock (`_inc/model/invoice.php:213-215`) |

---

## 8. Impresión

### 8.1 El estado actual (y por qué no se puede portar)

| Hecho | Evidencia |
|---|---|
| El legacy tiene los dos modos | `stores.remote_printing` (0 = Web Browser, 1 = PHP Server) — `BASE DE DATOS/pos_moderno.sql`, tabla `stores`; §3.20 |
| El botón "ver" de la pantalla de impresoras es un stub | §3.12 (`admin/printer.php`) |
| **El endpoint de impresión está roto** | `_inc/print.php:29` → `dd($data);` y `dd()` = `echo "<pre>".print_r($data,true)."</pre>"; exit;` (`_inc/helper/common.php:2-5`) → **vuelca el JSON y sale antes de generar ESC/POS** |
| El contrato de impresión | `_inc/print.php:28-31`: `json_decode($_GET['data'])` → `$escpos->load($data->printer)` → `$escpos->print_receipt($data)`. El **servidor genera el ESC/POS** y el navegador lo envía a la impresora local |
| El destino de red vive en el bridge | `printer_to_store.path` / `.ip_address` / `.port` (el catálogo `printers` sólo tiene `printer_id`, `title`, `type`, `profile`, `char_per_line`) |

> El `dd()` de `_inc/print.php:29` **no está en el catálogo de 94 trampas** del mapa. Es un hallazgo nuevo: **la impresión del legacy no funciona en el estado actual del repo**. Refuerza la decisión 2 (impresión LAN + servidor): en Fase 0 hay que diseñar el contrato desde cero, no portar.

### 8.2 Modelo destino

```
print_targets
  id, printer_id, store_id, transport (browser|lan_agent|server_socket),
  host, port, charset, paper_width, copies_default, status, sort_order
print_agents
  id, store_id, agent_id, name, version, token_hash, last_heartbeat_at,
  status (online|offline), capabilities (jsonb)
print_jobs
  id, store_id, target_id, template_id, document_type, document_id,
  payload (jsonb), payload_bytes (bytea), copies,
  status (queued|printing|done|error), error, attempts,
  printed_at, created_by, created_at
```

### 8.3 Contrato de API del agente LAN

El agente es un proceso en la LAN de la tienda (Windows/Linux). El servidor **no** puede alcanzar una impresora de la LAN privada: sólo el agente puede. De ahí el contrato:

| Método | Ruta | Cuerpo | Respuesta |
|---|---|---|---|
| Registro | `POST /api/print-agents/register` | `{agent_id, name, version, token}` | `{status, agent_id}` |
| Heartbeat | `POST /api/print-agents/heartbeat` | `{agent_id, version, capabilities[]}` | `{status, pending_jobs: N, poll_after_ms}` |
| Trabajo | `GET /api/print-agents/jobs?limit=N` (long-poll) | — | `[{job_id, printer, payload_base64, copies, charset}]` |
| Resultado | `POST /api/print-jobs/{id}/result` | `{status: done\|error, error?, printed_at}` | `{status}` |

- **Autenticación**: token por tienda, **hasheado** en `print_agents.token_hash` (nunca en claro).
- **Heartbeat**: `last_heartbeat_at` + `status` → el backoffice muestra "agente en línea / desconectado" (§10.2 del mapa). El umbral de "desconectado" es un parámetro, no un literal.
- **Long-poll**: el agente mantiene una petición abierta y recibe trabajo en cuanto existe. Alternativa simple: polling cada N segundos. Decisión de diseño menor.

### 8.4 Quién genera el ESC/POS: **decisión clave**

| Opción | Descripción | Ventaja | Coste |
|---|---|---|---|
| **A (recomendada)** | El **servidor** renderiza la plantilla y genera el ESC/POS; el agente sólo lo escribe en la impresora | Un único lugar genera el ticket → **el mismo formato** en navegador, LAN y servidor. Coherente con el legacy (`_inc/print.php:30-31` ya lo hacía el servidor) | Hay que resolver el ESC/POS en el servidor (una librería, que es lo que ya hace el legacy) |
| B | El agente recibe plantilla + datos y renderiza | El servidor no carga con el render | Tres implementaciones del render → tres formatos. **Rechazable** |

→ **Opción A**, anotada como ADR.

### 8.5 Separar `issued` de la impresión

- `fiscal_documents.status = 'issued'` es **independiente** de `print_jobs.status`. Un documento issued puede tener 0, 1 o N `print_jobs`.
- **Reimprimir no reemite**: una reimpresión crea un `print_job` nuevo contra el mismo `document_id`, **no** consume correlativo y **no** cambia el `fiscal_document`. Es la mitigación directa del riesgo "la impresión de tickets es un punto de fallo físico" (§8.4 del mapa).
- El POS **nunca espera a la impresora**: encola y sigue. El estado del trabajo se muestra en la UI.

### 8.6 Alcance por fase

§11.6 del mapa: en Fase 0 entra **la tabla `print_jobs` y el modo navegador**; el agente LAN es de Fase 1. Esto es coherente con la decisión 2 (ambas modalities) siempre que **el esquema y el contrato** se fijen en Fase 0 y el agente se implemente en Fase 1 sin migración de esquema.

---

## 9. Sincronización (adaptador del protocolo v2)

### 9.1 El contrato v2 exacto (verificado en `_inc/helper/sync.php` y `sync.php`)

**Petición** — `POST sync.php`, `Content-Type: application/json`:

```json
{ "username": "pos-01", "action": "sync", "data": "<json>", "timestamp": 1730000000, "nonce": "<hex>", "signature": "<hex>" }
```

**Canonización** (`sync_canonical()`, `_inc/helper/sync.php:149-158`):

```
canonical = username \n action \n timestamp \n nonce \n sha256(data)
signature = hash_hmac('sha256', canonical, secret)
```

**Verificación, en este orden** (`sync_verify()`, `:242-311`):

| Paso | Regla | Referencia |
|---|---|---|
| 1 | Cliente conocido: `SYNC_CLIENTS` (`id:secret`) | `:255-257` |
| 2 | `timestamp` debe casar `^\d{10}$` | `:259-261` |
| 3 | `abs(time() - ts) <= SYNC_MAX_AGE` (default **300 s**) | `:263-266` |
| 4 | `hash_equals(expected, signature)` | `:268-275` |
| 5 | Consumo del nonce (**después** de validar la firma) | `:279-281` |

**Nonce**: `storage/sync-nonces/<nonce>.nonce`, creación atómica con `fopen(..., 'x')` (`:219`), poda por antigüedad (`:206-215`), y `fail closed` si el directorio no es escribible (`:202-204`).

**Payload `data`**: JSON con `[{"sql": "<SQL>", "args":[…]}]`. El cliente lo arma en `ReadSyncData()` (`_inc/model/cron.php`) leyendo `storage/logs/sql.txt`, que escribe `_inc/lib/database.php:79` como `sql . '|' . serialize($args)` — **sólo** sentencias que contienen `INSERT`, `UPDATE` o `DELETE` (`:68-70`), excluyendo el `UPDATE users SET ip` (`:74`).

**Restricciones del servidor** (`sync.php`): sólo `action = 'sync'` (`:92`); `SYNC_MAX_PAYLOAD` (2 MiB, `:64`); `SYNC_MAX_STATEMENTS` (500, `:106`); `sync_sql_allowed()` acepta **sólo** `INSERT|UPDATE|DELETE` y **rechaza sentencias apiladas** (`_inc/helper/sync.php:326-350`); **todo el lote va en una transacción** (`sync.php:141-148`).

**Respuesta**: `{"status": …, "message": …, "for": …}` con `http_response_code` (`sync_respond()`, `_inc/helper/sync.php:421-433`).

**Transporte**: `SYNC_REQUIRE_HTTPS` (default `true`), `SYNC_ALLOWED_IPS`, `SYNC_ALLOW_LOCALHOST` (`sync_config()`, `:106-137`).

### 9.2 El problema: el payload es SQL de MySQL con nombres legacy

Esto **no está cubierto** por el documento de referencia (que sólo dice "el nuevo sistema lo habla como adaptador" — §8.4 punto 16, §10.5) y es el hallazgo más importante de esta exploración:

1. El terminal offline es una instalación **legacy** (PHP + MySQL/MariaDB). Su log de sync contiene **sentencias DML de MySQL**.
2. Esas sentencias llevan **nombres de tabla y columna del legacy**: `INSERT INTO selling_info (...)`, `UPDATE purchase_item SET total_sell = …`, `INSERT INTO customer_to_store …`.
3. El maestro nuevo es **PostgreSQL 17 con un esquema nuevo** (`sales`, `sale_items`, `inventory_lots`, …).
4. → El adaptador **no puede limitarse a verificar la firma**: tiene que **traducir identificadores**.

**Compatibilidad de dialecto (verificada):** el legacy **no usa** `ON DUPLICATE KEY UPDATE` ni `REPLACE INTO` (grep sobre `_inc/**` y `admin/*.php` → 0 coincidencias), y las funciones MySQL-específicas aparecen sólo en un `SELECT` de reporte (`CURDATE()` en `_inc/model/report.php:512`), que **no** entra al log (sólo `execute()` loguea, y los `SELECT` van por `fetch()`). PostgreSQL acepta los backticks como delimitadores de identificadores, y los tipos usados en DML (`decimal`, `varchar`, `int`, `datetime`) son compatibles con `numeric`/`varchar`/`integer`/`timestamp`. → **El dialecto no es el problema; los nombres de tabla sí.**

**Acotación del problema:** el terminal offline sólo produce ~18 tablas (venta, cobro, stock, caja, cuotas, tarjetas, quotations). El mapa de traducción es **acotado y testeable**.

### 9.3 Los dos fallos operacionales del protocolo que el adaptador debe tapar

| # | Fallo | Evidencia | Mitigación en el maestro nuevo (sin tocar el contrato) |
|---|---|---|---|
| S1 | **No hay idempotencia.** El maestro confirma (`commit`) y el cliente **no** trunca el log si la respuesta se pierde → el siguiente push reenvía las mismas sentencias → violación de PK → **el lote falla para siempre** y el terminal queda congelado | `sync.php:141-148` (commit) + `PushSqlToRemoteServer()` en `_inc/model/cron.php` (trunca el log **sólo** si la respuesta es exitosa) | Tabla `sync_batches` con `UNIQUE (client_id, payload_sha256)`: un reenvío idéntico se responde con éxito **sin volver a aplicar**. Es una **adición de servidor**, no un cambio de protocolo → compatible con el contrato fijo |
| S2 | **El almacén de nonces es de sistema de ficheros**: `storage/sync-nonces/` (`_inc/helper/sync.php:180`) → no escala a varios contenedores y no se puede compartir | `_inc/helper/sync.php:191-227` | Usar **Redis** (`SET NX EX`) — Redis ya está en el stack (`CACHE_STORE=redis`). Mismo contrato, mejor implementación |

**S1 es un P0 de operación** y no está en el catálogo de trampas del mapa. Con la decisión 9 ("el `sync.php` viejo se apaga el mismo día que se enciende el nuevo servicio"), un terminal que se congele por S1 se queda **sin poder sincronizar en la ventana de corte** — que es exactamente el riesgo operativo nº1 de §10.5.

**Detalle adicional del formato del log:** `ReadSyncData()` hace `explode('|', trim(fgets($handle)))` y toma `$line[1]`. Si el SQL contiene un `|` dentro de un literal, el `serialize()` queda truncado y `unserialize()` falla → **esa operación se pierde silenciosamente**. Riesgo bajo pero real; el adaptador debe validar que cada operación tiene `sql` y `args` válidos y **rechazar el lote entero** si no (§9.4).

### 9.4 Dónde vive el adaptador y qué NO cambia

```
app/Sync/Legacy/
  LegacySyncVerifier.php     ← puerto LITERAL de sync_verify() (mismo orden, mismos motivos de rechazo)
  LegacyStatementTranslator.php ← reescritura de identificadores (tabla/columna) según el mapa
  LegacyBatchApplier.php     ← una transacción, sólo DML permitido, idempotencia por sync_batches
  LegacyTableMap.php         ← el mapa como DATOS (seed), no como código
```

**El contrato de wire no cambia**: mismas claves JSON, mismo canonizado, mismo HMAC, mismo orden de verificación, mismos límites, misma forma de respuesta. Lo que cambia es **la implementación del lado maestro** (PostgreSQL + esquema nuevo) y **el almacén de nonces** (Redis). Ambas cosas son invisibles para el terminal legacy.

### 9.5 La pregunta de diseño que decide el tamaño del adaptador

**¿Los terminales offline siguen siendo legacy durante toda la transición?**

| Escenario | Consecuencia para el adaptador |
|---|---|
| **Los terminales siguen siendo legacy** hasta el corte | El maestro nuevo **debe** traducir SQL legacy → esquema nuevo. El mapa de traducción (~18 tablas) es **obligatorio** en Fase 0. |
| Los terminales se migran al sistema nuevo antes del corte | El adaptador sólo necesita hablar el protocolo durante una ventana corta, y el payload sería del sistema nuevo. El mapeo legacy → nuevo se reduce a un **importador** de una vez. |

§10.5 del mapa dice que el `sync.php` viejo se apaga el mismo día que se enciende el servicio nuevo → **los terminales son legacy durante la transición** → **el mapeo es obligatorio**. Esto es una **decisión de producto**, no técnica, y hay que confirmarla (P1 en §11).

---

## 10. Cola y trabajos

### 10.1 Qué sale del ciclo de la request

| Trabajo | Motivo | Transport |
|---|---|---|
| SMS / WhatsApp | El legacy envía **de forma síncrona dentro del POST** (§3.23: "El envío es síncrono dentro del POST") y `_inc/sms/index.php:162-248` — una venta con un gateway lento bloquea al cajero | `SendSmsMessage` |
| Impresión | §8.4: el POS nunca espera a la impresora | `DispatchPrintJob` |
| Backup | `DBBackup` escribe un dump completo en `storage/backups/` (§Operaciones) | `RunDatabaseBackup` (scheduled) |
| Sincronización saliente (si el sistema nuevo actúa como terminal) | Push por HTTP con timeout de 120 s (`_inc/helper/sync.php:394`) | `PushPendingOperations` |
| Sincronización entrante (aplicar lote legacy) | Lotes de hasta 500 sentencias | `ApplyLegacySyncBatch` |
| Reportes pesados | Los 21 reportes del legacy; §3.17 | `GenerateReport` + artefacto en storage |
| Cumpleaños / alertas | `SENDCUSTOMERBIRTHDAYSMS` | `SendBirthdayMessages` (scheduled) |

### 10.2 Modelo de cola

- **Laravel Queue sobre Redis**: `QUEUE_CONNECTION=redis` ya está en `.env.example`; la tabla `jobs` ya existe (migración `0001_01_01_000002_create_jobs_table.php`).
- **`print_jobs` no es la cola**: es la **tabla de dominio** del trabajo de impresión (con su estado, su destino, su payload y su historial). El job de Laravel es el **ejecutor** que toma trabajos `queued` y los despacha. Esta distinción importa: el estado de impresión es consultable por el backoffice y sobrevive a reinicios, y un job fallido se puede reintentar desde la UI sin depender del backend de cola.
- **Idempotencia de los jobs**: `SendSmsMessage` debe llevar un `idempotency_key` (id del `sms_message`) para no duplicar SMS si el worker reintenta. Mismo patrón para `DispatchPrintJob`.
- **Reintentos y DLQ**: `failed_jobs` de Laravel como cola de fallos; `print_jobs.status = 'error'` con `error` y `attempts` visibles en la UI.

---

## 11. Riesgos, huecos y preguntas al usuario

### 11.1 Riesgos de la Fase 0

| # | Riesgo | Evidencia | Mitigación |
|---|---|---|---|
| R1 | **El correlativo fiscal se duplica** si dos terminales emiten a la vez | §8.4 del mapa; `_inc/helper/invoice.php:139-172` | `fiscal_series` + `UPDATE … RETURNING` dentro de la transacción (§5.2) + tests T1-T3 |
| R2 | **El saldo deOpening es incorrecto al cortar** (proyecto verde: no hay histórico que lo respalde) | §8.4 ("Riesgo #1 de un arranque en verde"), §10.3 | Congelar saldos con **fecha y responsable acordados** antes de la Fase 0. Es una acción operativa, no de código |
| R3 | **El motor fiscal se filtra al código** y la fase Latinoamérica se convierte en un fork | §8.5.4, §10.4 | Test de arquitectura que falle ante un literal de país + `TaxCalculator` puro con entradas/salidas tipadas (§4.1, §6) |
| R4 | **El adaptador de sync no aplica el SQL legacy** → los terminales se congelan | §9.2 | Mapeo como **datos** + tests de traslación con casos reales del log |
| R5 | **Reenvío del log de sync** → violación de PK → terminal congelado para siempre | §9.3 S1 | `sync_batches` con `UNIQUE (client_id, payload_sha256)` |
| R6 | **El FIFO sigue sin ser determinista** si se copia el patrón | `_inc/model/invoice.php:237,244,262` | `ORDER BY received_at, id` + `FOR UPDATE SKIP LOCKED` + test de invariante |
| R7 | **Los totales no cuadran al centavo** | §8.5.1 ("crítico"); el legacy no tiene regla de redondeo | `rounding_rule` como dato + `roundingAdjustment` explícito (§4.3, §6.3) |
| R8 | **La suite corre en el motor equivocado** | Gap 3 de `sdd-init` id 157 — **ya cerrado** por el commit `ef6c160` | `phpunit.xml` ya fija `pgsql`; los tests de concurrencia **no** pueden usar `RefreshDatabase` (§5.3) |
| R9 | **El agente de impresión no se instala** en los terminales | §8.4 del mapa | Instalador sin intervención técnica + heartbeat visible. **Entregable de Fase 1**, pero el contrato queda fijado en Fase 0 |
| R10 | **El typecheck no está en el gate de CI** | Gap 2 de `sdd-init` id 157 | Añadir `npm run typecheck` a `.github/workflows/tests.yml` (coste bajo, valor alto para un equipo nuevo) |
| R11 | **Los datos del sync llegan escapados**: el legacy HTML-escapea todas las entradas (`Request`), por lo que el maestro recibiría `&amp;` en lugar de `&` | `AGENTS.md` landmine #8 (legacy) | El adaptador debe **des-escapar** al traducir (o el esquema nuevo acepta el valor ya escapado y lo normaliza una vez en la frontera). Decisión explícita, no implícita |
| R12 | **La impresión del legacy no funciona** → no hay baseline que portar | `_inc/print.php:29` | Diseñar el contrato desde cero (§8) |

### 11.2 Hallazgos nuevos NO cubiertos por el documento de referencia

Estos no están en el mapa funcional (1550 líneas) ni en el catálogo de 94 trampas. Son la aportación de esta exploración:

| # | Hallazgo | Evidencia | Impacto |
|---|---|---|---|
| H1 | El `UPDATE` de consumo del FIFO **no filtra por `id`**: `UPDATE purchase_item SET total_sell = total_sell + {$sell_quantity} WHERE store_id = ? AND item_id = ? AND status = ?` → con varios lotes `active`, el consumo se contabiliza **en todos** | `_inc/model/invoice.php:262-263` | **P0** en el legacy; en el destino, la traza por lote (`sale_item_lot_consumptions`) lo hace imposible |
| H2 | `_inc/print.php:29` tiene `dd($data)` → el endpoint de impresión **vuelca el JSON y sale** antes de generar ESC/POS | `_inc/print.php:29` + `_inc/helper/common.php:2-5` | La impresión del legacy **está rota**; no hay baseline |
| H3 | El protocolo de sync **no es idempotente**: commit del maestro + respuesta perdida → reenvío → violación de PK → el terminal queda congelado para siempre | `sync.php:141-148` + `PushSqlToRemoteServer()` | **P0 operativo**; se tapa con `sync_batches` sin tocar el contrato |
| H4 | El almacén de nonces del sync es de **sistema de ficheros** → no escala a varios contenedores | `_inc/helper/sync.php:180,191-227` | Sustituir por Redis (mismo contrato) |
| H5 | El **payload del sync es SQL crudo de MySQL con nombres legacy** → el adaptador **necesita traducción**, no sólo verificación | `_inc/lib/database.php:79` + `_inc/model/cron.php` `ReadSyncData()` | Define el tamaño del adaptador de sync (§9) |
| H6 | La fuga de país no está en 2 sitios sino en **16 plantillas** (`get_preference('invoice_view') == 'indian_gst'`), no en un `switch` de PHP | `_inc/template/*.php` (11) + `admin/*.php` (5) | Refuerza §8.5: el `switch` del mapa es una aproximación; la realidad es peor |
| H7 | `ReadSyncData()` parte el log con `explode('|', …)` → un `|` en un literal rompe la operación y **se pierde en silencio** | `_inc/model/cron.php` `ReadSyncData()` | El adaptador debe validar y rechazar el lote entero |
| H8 | El **interés de las cuotas lo calcula el cliente** (`PaymentFormModal.js:188`: `($scope.installmentInterestPercentage/100)*$scope.payable`) y el servidor lo persiste; además `_inc/model/invoice.php:378` reparte ese interés con un ajuste por cuota inicial (`$initInterestAmount`) | `PaymentFormModal.js:188`, `_inc/model/invoice.php:376-391` | La decisión 7 ("interés simple sobre el payable") es correcta pero **incompleta**: hay que replicar también el reparto por cuota y el ajuste de la cuota inicial, y **mover el cálculo al servidor** |
| H9 | El mapa dice "Banca y contabilidad (9)" pero lista 10 tablas, y `bank_account_to_store` está **contada dos veces** (en "Tiendas y bridges" y en "Banca") | §2.3 vs el grep de las 73 | Sólo aritmética del documento; el total 73 es correcto |
| H10 | El **correlativo de compra** del legacy es `prefijo + referencia escrita por el usuario` — no hay correlativo atómico de compra | `_inc/purchase.php` (§3.10) | Hay que decidir si las compras consumen serie fiscal (P7) |
| H11 | `product_to_store.purchase_price`/`sell_price` son **`float`**, no `decimal` | `BASE DE DATOS/pos_moderno.sql` | El destino debe ser `numeric(25,4)` |

### 11.3 Preguntas para el usuario (bloqueantes o de alcance)

**BLOQUEANTES (afectan el esquema de Fase 0):**

| # | Pregunta | Por qué bloquea | Mi recomendación |
|---|---|---|---|
| **P1** | ¿Los terminales offline **siguen siendo legacy** durante toda la transición, o se migran al sistema nuevo antes del corte? | Determina si el adaptador de sync necesita traducción de SQL legacy → esquema nuevo (§9.5) | **Siguen siendo legacy** (consecuencia de §10.5) ⇒ el mapeo es obligatorio y es un entregable de Fase 0 |
| **P2** | ¿**Qué nombres de tabla** tiene el esquema destino: los de §8.3 (`sales`, `sale_items`, `sale_totals`, `inventory_lots`) o los del legacy? | El mapa de traducción del sync depende de ello; y §8.3 es una propuesta, no una decisión | **Los de §8.3** + el mapa de traducción como datos |
| **P3** | ¿El **IVA retenido** forma parte del alcance? El legacy **no tiene retenciones** (sólo el `switch` de India) | Define si `tax_categories` incluye `iva_retenido` con `is_withholding` y si hay módulo de retenciones | Modelarlo en el esquema (barato) y dejar el módulo para Fase 1 |
| **P4** | ¿El **redondeo** es por línea o por total? | §8.5.1 lo deja "configurable" y sin decidir; afecta al IVA y a que el documento cuadre al centavo | **Por línea**, con `roundingAdjustment` visible en el total. Configurable por `fiscal_profiles` |

**DE ALCANCE (deciden si la Fase 0 crece o no):**

| # | Pregunta | Mi recomendación |
|---|---|---|
| **P5** | ¿Fase 0 exige un test de **concurrencia real** (dos procesos) para la reserva fiscal, o basta con secuencia + bloqueo + rollback (T1-T3)? | **T1-T3 en Fase 0**; T4a (comando Artisan + `Symfony\Process`) en Fase 1 |
| **P6** | ¿El **formato del correlativo fiscal** es `000-00000000` (como dice §8.5.1) o el del legacy (`prefijo + store_id + 8 dígitos`)? | **`000-00000000`** para factura; definir aparte el de compra |
| **P7** | ¿Las **compras** consumen correlativo fiscal (serie propia)? | **Sí**, serie propia por tienda (factura del proveedor) |
| **P8** | ¿La **exención/exoneración por producto** entra en Fase 0 (sólo modelo) o también en Fase 1 (UI + datos)? | Sólo el **modelo** en Fase 0 |
| **P9** | ¿**Qué saldos de apertura** se congelan, **con qué fecha** y **quién los certifica**? (§10.3 lo marca como el riesgo #1) | Decisión operativa **antes** de la Fase 0: stock por tienda/producto, saldo y deuda por cliente/proveedor, saldo de caja por cuenta y tienda |
| **P10** | ¿Caja = `bank_account` (cuenta) o `cash_register` (apertura/cierre)? ¿Se mantienen los **dos** conceptos (el legacy tiene ambos)? | **Los dos**: `bank_accounts` (saldo por cuenta y tienda) + `cash_registers` (apertura/cierre por fecha de negocio). Se elimina la duplicación de contadores |
| **P11** | ¿En Fase 0 entra la **interfaz `ElectronicInvoiceProvider`** (`issue()`, `cancel()`, `queryStatus()`, `downloadXml()`) con un adaptador SENIAT real, o sólo el contrato + un *stub*? | **Sólo el contrato + stub**: el adaptador|es de Fase 1/2, pero el contrato debe fijarse en Fase 0 (§8.5.4) |
| **P12** | ¿El **agente de impresión LAN** es un entregable de Fase 0 (esquema + contrato + instalador) o de Fase 1? §11.6 dice Fase 0 = tabla `print_jobs` + modo navegador | **Esquema + contrato en Fase 0**; instalador en Fase 1 |
| **P13** | ¿Se añade `typecheck` al gate de CI en esta fase? | **Sí** — es un gap abierto (`sdd-init` id 157) y el coste es una línea |

### 11.4 Lo que la Fase 0 **no** cubre (recordatorio de alcance)

§11.6 del mapa: no entran ventas/compras/POS completos (Fase 1), el agente LAN (Fase 1), la cola de SMS y sync (Fase 2), ni los 21 reportes (Fase 3). §8.6 añade que quedan fuera la facturación electrónica de otros países (arquitectura sí, adaptadores no), banners, analítica avanzada, kiosco y app móvil nativa.

---

## 12. Enfoques

### 12.1 El fork principal: esquema destino × adaptador de sync

| Enfoque | Descripción | Ventajas | Inconvenientes | Complejidad |
|---|---|---|---|---|
| **A — Nombres nuevos + mapa de traducción como datos** | Esquema §8.3. El adaptador traduce identificadores legacy → destino mediante una tabla `sync_legacy_map` **sembrada**, no código | Un solo modelo de verdad; el mapa es auditable y ajustable sin desplegar; coherente con la regla "datos, no código" | Hay que escribir y probar el traductor; el mapa hay que mantenerlo sincronizado con el esquema | **Alta** |
| **B — Nombres legacy en el núcleo transaccional + capa fiscal nueva** | El esquema destino conserva `selling_info`, `selling_item`, `purchase_item`, etc. y añade encima la capa de país/fiscal | El adaptador casi no traduce nombres; menor riesgo de fallo en sync; migración más rápida | Contradice §8.3; deja los nombres legacy (deuda cognitiva); el traductor **igual** tiene que mapear columnas, porque el esquema destino cambia las columnas de todos modos | **Media** |
| **C — Esquema espejo legacy + reconciliación por ETL** | Una base `legacy_sync` con las 73 tablas exactas; un job reconcilia hacia el modelo nuevo | Aísla completamente el sync legacy; cero riesgo de corromper el modelo nuevo | **Dos fuentes de verdad** (contradice §8.1 pto. 5); la reconciliación de FIFO, saldos y cubos es un proyecto aparte, más difícil que el problema original | **Muy alta** |

### 12.2 Fork secundario: cómo se prueba la concurrencia fiscal (§5.3)

| Enfoque | Descripción | Ventajas | Inconvenientes | Complejidad |
|---|---|---|---|---|
| **A — T1/T2/T3 (secuencia + bloqueo + rollback)** | `RefreshDatabase` fuera; dos conexiones PDO; `lock_timeout` para demostrar el bloqueo | Determinista, rápido, sin dependencias; cubre la invariante | No certifica el escenario "dos cajas a la vez" | **Baja** |
| **B — A + T4a (dos procesos reales)** | Un comando Artisan `fiscal:reserve` lanzado en paralelo con `Symfony\Process` | Certifica el escenario de negocio de extremo a extremo | Proceso más lento y propenso a *flaky*; hay que aislar la DB | **Media** |
| **C — A + `pgbench`/script SQL fuera de la suite** | El stress test vive fuera de CI | No alarga la suite | Fuera del gate de calidad → es un "extra", y la decisión 10 dice que los tests son del día 1 | **Media** |

### 12.3 Fork terciario: el "ajustador" de la traducción de sync (§9.4)

| Enfoque | Descripción | Ventajas | Inconvenientes | Complejidad |
|---|---|---|---|---|
| **A — Tabla de mapeo (datos) + reescritor ligero** | `sync_legacy_map(legacy_table, legacy_column, new_table, new_column, transform)` | Ajustable sin desplegar; auditable; coherente con "datos, no código" | Hay que escribir el reescritor (no un parser SQL completo: sólo identificadores) | **Media** |
| **B — Reimplementación por tabla en código** | Una clase por tabla legacy con su translation hardcodeada | Tipado y simple de probar | Se dispersa en el código; contradice la regla de oro; 18 tablas × código | **Alta** |
| **C — Tablas espejo en el esquema de negocio** | Crear vistas/tablas `selling_info` etc. sobre las nuevas | El SQL legacy funciona sin tocar | Vistas no admiten `INSERT`; tablas espejo duplican el modelo | **Alta** |

---

## 13. Recomendación

### 13.1 Enfoque recomendado

**Enfoque A (esquema §8.3 + mapa de traducción como datos) + Enfoque A de concurrencia (T1/T2/T3 en Fase 0, T4a en Fase 1) + Enfoque A de traducción (tabla de mapeo).**

**Por qué:**

1. **El enfoque B no ahorra trabajo real.** El adaptador tiene que mapear **columnas** igualmente (el esquema destino cambia `selling_item.tax` → `tax_rate_snapshot`, `purchase_item.total_sell` → `quantity_consumed`, `customer_transactions` → `customer_ledger_entries`, etc.). Traducir nombres de tabla pero no columnas no reduce el riesgo; sólo cambia dónde vive el mapa.
2. **El enfoque C crea un problema mayor que el que resuelve.** La reconciliación de stock FIFO y de saldos de caja entre dos modelos es más difícil y más riesgosa que la traducción directa, y contradice el principio "una sola fuente de verdad por dato" (§8.1 pto. 5).
3. **El enfoque A es el único que satisface la regla de oro** ("cambiar de país = insertar filas") **y** la regla de "datos, no código" **a la vez**: el mapa de traducción es un dato sembrado, y el motor fiscal es puro.
4. **T1/T2/T3 cubren la invariante que importa** (el número se reserva una vez, es consecutivo, y se devuelve si la transacción falla) con tests deterministas. El escenario "dos cajas a la vez" es real pero el diseño con `UPDATE … RETURNING` dentro de una transacción lo resuelve **por construcción**; certificarlo con dos procesos es Refuerzo, no corrección.

### 13.2 Contenido de Fase 0 (deliverables)

| # | Entregable | Criterio de aceptación |
|---|---|---|
| **E1** | **Esquema completo por migraciones** — las 73 Legacy traducidas según §3.2 + las tablas nuevas de §3.3 | `migrate:fresh` limpio; FKs reales; índices en toda columna de filtro y en `store_id` |
| **E2** | **Capa de país/fiscal** (§4) | `db:seed` deja una tienda Venezuela con `fiscal_profile`, `tax_categories` (16 % / exento / no gravado / exonerado), `tax_rates` con vigencia, `currencies` (VES, 2 decimales), `number_formats`, y una `fiscal_series` con rango autorizado |
| **E3** | **Numeración fiscal atómica** (§5) | T1/T2/T3 en verde; **el número no retrocede** tras una anulación; el rollback devuelve el número |
| **E4** | **`TaxCalculator`** (§6) + test de arquitectura anti-país | Mismo motor produce el resultado esperado para cada perfil sembrado; el test falla ante un literal de país en `app/Domain/` |
| **E5** | **Stock por lotes** (§7) | FIFO determinista, traza por lote, `SKIP LOCKED` sin sobreventa, test de invariante de stock |
| **E6** | **Adaptador de sync v2** (§9) — verificador, traductor con mapa como datos, aplicador idempotente | Test de **conformidad de protocolo** con vectores dorados generados por el legacy; test de idempotencia (reenvío → éxito sin doble aplicación) |
| **E7** | **Esquema de impresión y cola** (§8, §10) | `print_jobs`/`print_targets`/`print_agents`/`sms_messages`/`ledger_entries` + `ledger_entry_types` creados; el `print_job` **no** depende del estado `issued` del documento fiscal |
| **E8** | **Tests del dominio** (§3, §7) | FIFO, totales e IVA, crédito de cliente, cuota con interés, transferencia entre tiendas, saldo de caja, devolución que repone stock. **Cada test nombra la regla de negocio que fija** (§10.6) |
| **E9** | **Seed** del perfil fiscal venezolano + catálogos + tienda + ejemplos limpios | §10.3: nada de histórico; saldos de apertura congelados (P9) |
| **E10** | **ADRs** en `docs/adr/` | `0001` numeración fiscal atómica · `0002` capa de país/fiscal · `0003` stock por lotes · `0004` agente de impresión · `0005` protocolo de sync v2 · `0006` esquema destino 73→destino |
| **E11** | **CI** con `lint` + `typecheck` + `tests` + `build` | Badge en verde en el primer push (P13 cierra el gap de `sdd-init`) |

### 13.3 Orden de ejecución sugerido

```
1.  E1 migraciones del esquema destino (sin la capa de país todavía)
2.  E2 capa de país/fiscal + numeración
3.  E3 fiscal series + reservas  (+ E4 TaxCalculator)
4.  E5 inventario por lotes
5.  E6 adaptador de sync
6.  E7 cola + impresión + contabilidad
7.  E8 tests del dominio (transversales a 3-6)
8.  E9 seed · E10 ADRs · E11 CI
```

### 13.4 Verificación de Fase 0 (sin suite de tests automatizada en el legacy, el listón es alto)

- [ ] `php artisan migrate:fresh --seed` en verde sobre PostgreSQL
- [ ] `./vendor/bin/pest` en verde (con `npm ci && npm run build` primero — precondición de `openspec/config.yaml` → `testing.preconditions`)
- [ ] `npm run typecheck` y `npm run lint:check` en verde
- [ ] `vendor/bin/pint --test` en verde
- [ ] Dos reservas concurrentes obtienen números distintos (T4, si se acepta en Fase 0)
- [ ] El test de arquitectura anti-país pasa
- [ ] La suite de sync conforma con los vectores dorados del legacy
- [ ] `git log` sin cambios fuera de `openspec/changes/fase0-fundaciones/` hasta que se abra la fase de proposal

---

## 14. ¿Listo para propuesta?

**Sí, con reservas y con 4 preguntas bloqueantes abiertas (P1-P4).**

**Lo que está resuelto y no hay que re-preguntar:** las 11 decisiones del encargo, el stack, la traducción de las 73 tablas (§3.2), el diseño de la numeración fiscal (§5.2), el contrato del `TaxCalculator` (§6), el modelo de lotes (§7.2) y el contrato del agente de impresión (§8.3).

**Lo que hay que responder antes de escribir el `proposal.md`:**

1. **P1** — ¿los terminales offline siguen siendo legacy? (define el tamaño del adaptador de sync)
2. **P2** — ¿nombres de tabla del destino: §8.3 o legacy?
3. **P3** — ¿IVA retenido en el alcance?
4. **P4** — ¿redondeo por línea o por total?

**Lo que puede esperar al `proposal.md` y no bloquea:** P5-P13 (concurrencia real, formato del correlativo, compras con serie, exenciones, saldos de apertura, modelo de caja, interfaz de facturación electrónica, alcance del agente LAN, typecheck en CI).

**Riesgo residual principal:** que el adaptador de sync (E6) crezca más de lo previsto si P1 resulta que los terminales **no** son legacy — porque en ese caso el trabajo se convierte en un importador de una vez, no en un adaptador permanente, y la Fase 0 se encoge. El otro riesgo residual es **P9** (saldos de apertura), que no es técnico: sin un acuerdo con fecha y responsable, el sistema arranca con cifras que nadie puede defender.
