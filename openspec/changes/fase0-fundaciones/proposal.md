# Proposal: Fase 0 — Fundaciones

## Intent

El legacy (PHP 8.2 + AngularJS 1.6 + MySQL) emite según **SNAT/2011/0071**, desalineado de la norma vigente (**SNAT/2024/000102**, G.O. 43.032; **SNAT/2026/00093**, G.O. 43.484, vigente 19/04/2027). El sistema nuevo (Laravel 12 + PostgreSQL 17) **reemplaza** al legacy, no lo porta: hoy el correlativo no se reserva, el FIFO no es determinista, el checkout no es transaccional y el sync no es idempotente.

Esta fase no migra 73 tablas: diseña el esquema destino **con la capa de país/fiscal dentro**, cierra la numeración atómica y deja **fijadas por test** las reglas que el legacy ejecuta sin garantías.

## Scope

### In Scope

| # | Entregable | Criterio de aceptación |
|---|---|---|
| S1 | Esquema destino por migraciones (55 conservadas, 17 fusionadas, 1 eliminada, 27 nuevas) | FKs reales, `numeric(25,4)`, sin blobs PHP, sin flags contables, sin contadores derivados |
| S2 | Capa de país/fiscal | `countries`, `fiscal_profiles`, `tax_categories`/`tax_rates`, `currencies`, `number_formats`, `fiscal_profile_document_types`, `fiscal_providers` |
| S3 | Numeración fiscal atómica | **Dos** series (documento del emisor + Nº de Control de la imprenta) con **dos** reservas independientes; tests T1–T3 en PostgreSQL |
| S4 | Documento fiscal y anexos | `fiscal_documents` + `_taxes` (multinivel) + `_parties` + `_links` + `_events` (append-only) + `_control_assignments` + contingencia + comprobante de retención `AAAAMMSSSSSSSS` |
| S5 | `TaxCalculator` puro | 5 categorías VE, snapshots congelados, redondeo por línea con `roundingAdjustment` |
| S6 | Stock por lotes | `inventory_lots` (sin `status` mutable) + `sale_item_lot_consumptions`; FIFO con `FOR UPDATE SKIP LOCKED`; invariante de stock |
| S7 | Adaptador del sync v2 | Contrato de wire **intacto**; traducción por mapa **sembrado**; idempotencia por `sync_batches` |
| S8 | Esquema + contrato de impresión LAN | `print_targets`/`print_agents`/`print_jobs` + máquina de estados; bytes ESC/POS en el servidor (CP850; TCP 9100 o impresora compartida; **USB directo descartado**) |
| S9 | Contabilidad y caja | `ledger_entries` con `entry_type`; `expenses` como fuente de verdad del gasto; `cash_registers` por fecha de negocio; saldos de apertura congelados |
| S10 | Calidad desde el día 1 | CI (lint + `typecheck` + tests + build) con PostgreSQL, suite Pest del dominio, 6 ADRs, README |

### Out of Scope

Ventas/compras/POS completos · agente LAN instalable (sólo esquema + contrato) · cola de SMS operativa · sync en producción · los 21 reportes · adaptadores por país de la Fase 2 · adaptadores reales de SENIAT/imprenta (bloqueados por el Manual Técnico del art. 41) · módulo de retenciones (sólo esquema).

## Capabilities

`openspec/specs/` está vacío → **todo es nuevo**.

### New Capabilities

- `esquema-dominio` — Esquema destino por migraciones e invariantes estructurales.
- `capa-pais-fiscal` — Perfiles, tipos de documento, esquemas de impuesto, vocabularios de estado, proveedores y catálogos por país.
- `numeracion-fiscal` — Series, rangos autorizados y reserva atómica de correlativo y de Nº de Control.
- `documentos-fiscales` — Cabecera fiscal, partes, impuestos por alícuota, auditoría append-only, notas y contingencia.
- `motor-impuestos` — Cálculo determinista, perfiles de redondeo y congelación de tasas.
- `inventario-lotes` — Lotes, consumos FIFO, devolución que repone el lote exacto, invariante de stock.
- `adaptador-sync-legacy` — Verificación, traducción y aplicación idempotente del protocolo v2.
- `impresion-lan` — Destinos, agentes, trabajos de impresión y su máquina de estados.
- `contabilidad-caja` — Asientos tipados, caja por fecha de negocio y saldos de apertura.
- `calidad-continua` — Puerta de calidad ejecutable: CI, suite del dominio, ADRs, documentación.

### Modified Capabilities

None.

## Approach

**Orden**: S1 → S2 → S3/S5 → S6 → S7 → S8/S9 → S10.

**Correcciones que la evidencia oficial impone al diseño de la exploración** (`research/senat-venezuela.md`, `research/estandares-latam.md`):

1. **Dos numeraciones, no una** — documento del emisor (art. 8.2, 20) y Nº de Control de la **imprenta digital** (art. 8.3, 9.1, 27) → `fiscal_series.series_role` + `assigned_by`, dos reservas atómicas independientes. El número **no retrocede** ni por anulación (art. 12, 21) ni por reutilización (F2 art. 34).
2. **Actor intermedio obligatorio** — el emisor no emite solo (F2 art. 18.4/18.6; art. 42.1) → `fiscal_providers` (`printer`/`pac`/`portal`) y `ElectronicInvoiceProvider` con `submit()` → `assignControl()` → `issue()`.
3. **P3 ampliado** — el IVA retenido es **comprobante propio** con numeración de 14 caracteres y período de imposición (art. 18).
4. **Hallazgos negativos verificados** — el QR **no aparece** en la norma (opcional por canal); **no hay algoritmo de firma** (`hash_algorithm`/`signature_scheme` parametrizados, **sin default**); la **tasa de IVA no aparece** → el 16 % se siembra `pending_confirmation`.

**Por qué NO se introduce un `if` por país** (regla obligatoria de `config.yaml`): las 12 divergencias D1–D12 halladas en VE/CL/MX/CO/PA se absorben con **datos normalizados**, no con ramas — `fiscal_document_taxes` multinivel (`line_id` nullable) para el nodo de impuestos de México; `fiscal_document_parties` con `role` para los tres roles de Chile; `authority_reference` + `authority_reference_scheme` para CUFE/UUID/TED; `fiscal_status_vocabularies` (nativo → genérico); `fiscal_document_links` para notas/RADIAN/anulación; `fiscal_profiles.signer_model` (`authority`/`psp`/`printer`/`legal_signature`). Un `if ($country === 'VE')` bifurcaría tres núcleos (numeración por doble serie, emisión por actor intermedio, impuestos por 5 categorías) y la Fase 2 dejaría de ser "insertar filas". **Control ejecutable**: test de arquitectura que falla ante cualquier literal de país en `app/Domain/` + `TaxCalculator` puro (sin I/O; los perfiles se resuelven antes de la llamada).

**Atomicidad**: reserva con `UPDATE … RETURNING` (nunca `rowCount()`); toda escritura es unidad atómica con bloqueo de fila; FIFO con `ORDER BY received_at, id` + `FOR UPDATE SKIP LOCKED`; tests de concurrencia **sin** `RefreshDatabase` (dos conexiones, `SET LOCAL lock_timeout`).

## Affected Areas

| Área | Impacto | Qué cambia |
|---|---|---|
| `database/migrations/` · `database/seeders/` | New | Esquema destino, capa fiscal, seed VE, **mapa de traducción legacy** |
| `app/Domain/{Tax,Fiscal,Inventory,Accounting,Installments}/` | New | Núcleo testeable, independiente de HTTP |
| `app/Sync/Legacy/` · `app/Models/` · `app/Jobs/` | New/Mod | Adaptador v2, modelos con FKs reales, jobs de impresión y sync |
| `tests/Unit/` · `tests/Feature/` · `.github/workflows/tests.yml` | Mod | Suite del dominio + T1–T3 de concurrencia + `typecheck` y PostgreSQL en CI |
| `docs/adr/` · `README.md` | New | 6 ADRs y documentación de arranque |

## Risks

| Riesgo | Sev. | Mitigación |
|---|---|---|
| La doble numeración VE no se implementa → un solo correlativo | Alta | `series_role`/`assigned_by` + test de invariante ("exactamente un `control_number` si el perfil lo exige") |
| Se omite el actor intermedio en la emisión | Alta | `fiscal_providers` + `signer_model` + `submit`/`assignControl`/`issue` |
| El motor fiscal se filtra al código → LATAM se vuelve un fork | Alta | Test anti-país + `TaxCalculator` puro |
| Sync no idempotente → terminal congelado en la ventana de corte | Media-Alta | `sync_batches UNIQUE (client_id, payload_sha256)`; el contrato de wire no cambia |
| Mapa de traducción desalineado del esquema | Media | Mapa sembrado + tests de traslación con casos reales de `storage/logs/sql.txt` |
| Tasa de IVA 16 % sin confirmar (O3) | Media | `evidence_status='pending_confirmation'` + `source_ref` al Decreto del IVA |
| Totales que no cuadran al centavo | Media | `rounding_rule` como dato + `roundingAdjustment` visible |
| Texto venezolano de transcripción de terceros, no del PDF oficial (O2) | Media | Marcar procedencia por cita; validar contra la G.O. cuando se alcance |
| `typecheck` fuera del gate de CI | Baja | Añadir `npm run typecheck` (una línea) |

## Rollback Plan

Fase 0 es **proyecto verde**: no hay datos de producción que migrar ⇒ el rollback es de **código y esquema**, no de datos.

| Cambio riesgoso | Rollback |
|---|---|
| **Esquema** | `git revert` de los commits de migración + `php artisan migrate:fresh --seed`; cada migración declara `down()`. El riesgo real es heredar un esquema erróneo → el gate (`migrate:fresh` + suite en verde) es la condición de merge. |
| **Numeración fiscal** | Al fallar una emisión el documento queda `void` y la reserva con `voided_at`. **El número NO se recicla jamás** (art. 8.2, 27; F2 art. 34): un rollback **no** borra `fiscal_document_reservations`, desactiva la emisión (feature flag) y conserva la traza. |
| **Stock / FIFO** | El consumo es aritmética (`qty_received − qty_consumed + qty_returned`), sin `status` mutable ⇒ no hay estado desincronizable que revertir; sin datos de producción, el reset es `migrate:fresh`. |
| **Mapa de traducción sync** | Es **dato sembrado**: truncar sus filas y re-sembrar. Nunca afecta al esquema. |
| **CI / ADRs / docs** | Sin impacto en runtime. |

**Punto de no retorno**: desde el primer documento fiscal emitido la numeración es irrecuperable por diseño ⇒ el rollback de la numeración es **"apagar", nunca "renumerar"**.

## Dependencies

- **Manual Técnico del art. 41** (SNAT/2026/00093) — bloquea el adaptador real de Fase 1, **no** Fase 0.
- **G.O. 43.484 (PDF)** y **Decreto del IVA** — validan transcripción y tasa (O2, O3).
- **Listado de imprentas digitales autorizadas** (O6) — decide el actor del canal.
- **Saldos de apertura congelados** con fecha y responsable (P9) — dependencia **operativa**: bloquea el arranque, no el código.
- **Terminales offline siguen siendo legacy** (P1) → el mapa de traducción (~18 tablas) es **obligatorio** en Fase 0.
- `mike42/escpos-php` (ya vendorizada en el legacy) para el contrato de impresión.

## Success Criteria

- [ ] `php artisan migrate:fresh --seed` en verde sobre PostgreSQL 17.
- [ ] `./vendor/bin/pest` en verde (precondición: `npm ci && npm run build` → `public/build/manifest.json`).
- [ ] `npm run typecheck`, `npm run lint` y `vendor/bin/pint --test` en verde, con `typecheck` **en el gate de CI**.
- [ ] Reserva fiscal: T1 (secuencia sin huecos), T2 (bloqueo real → SQLSTATE `55P03`), T3 (rollback devuelve el número).
- [ ] Test anti-país en verde (0 literales de país en `app/Domain/`).
- [ ] FIFO: orden por antigüedad, consumo multi-lote, devolución al lote exacto, `SKIP LOCKED` sin sobreventa, invariante `quantity_in_stock == SUM(available)`.
- [ ] `TaxCalculator`: mismo motor y resultado esperado por perfil sembrado; `roundingAdjustment` cuadra al centavo.
- [ ] Sync: conformidad con vectores dorados del legacy + reenvío idempotente sin doble aplicación.
- [ ] Documento fiscal: 2 series, 2 reservas, `control_number` presente si el perfil lo exige, `_events` append-only, una reimpresión **no** consume correlativo.
- [ ] 6 ADRs en `docs/adr/` + README de arranque.
- [ ] CI en verde con PostgreSQL en el primer push.
