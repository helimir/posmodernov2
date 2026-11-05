# Tasks: Fase 0 — Fundaciones

> Contrato: `openspec/config.yaml → rules.tasks` (agrupar por fase · numeración jerárquica · cada tarea en una sesión · el test va junto a la implementación).
> Entrada: `openspec/changes/fase0-fundaciones/proposal.md` (S1–S10) · `specs/*/spec.md` (44 requisitos) · `design.md` (878 líneas) · `research/evidence/` · `docs/adr/0001…0006-*.md`.
> TDD estricto (`strict_tdd: true`): cada `-T` se escribe **primero en rojo**, luego la implementación lo pone en verde. Runner: `./vendor/bin/pest` sobre PostgreSQL 17 (`pos_moderno_v2_test`). Precondición: `npm ci && npm run build` ⇒ `public/build/manifest.json` debe existir.
> Convención de ids: `0.F.N` = implementación · `0.F.N-T` = test gemelo (id propio, con el nombre del test y la regla que fija).
> Rutas marcadas `(read-only)` son referencia: **no** se editan.

## Review Workload Forecast

| Field | Value |
|---|---|
| Estimated changed lines | **~8.735** (rango 7.300–9.200) — **recalculado**; el forecast inicial de ~6.740 estaba descalibrado (§"Por qué cambió el forecast") |
| 400-line budget risk | **High** |
| Chained PRs recommended | **Yes** |
| Suggested split | **16 PRs** encadenados (`stacked-to-main`); el corte fino de las 6 unidades que exceden 800 está en §Cadena de entrega |
| Delivery strategy | `ask-on-risk` → **resuelto: work units encadenadas** |
| Chain strategy | **`stacked-to-main`** (decidido 2026-10-31) |
500 líneas** (preflight del mantenedor; override del default de 400 de la skill `chained-pr`) |
| Unidades que exceden 800 | **6 de 9** — U1 960 · U2 1.061 · U3 845 · U4 1.205 · U8 1.013 · U9 1.330 |

Decision needed before apply: No
Chained PRs recommended: Yes
Chain strategy: stacked-to-main
400-line budget risk: High

> **El cambio es ~11× el presupuesto de revisión.** No es un PR: son work units encadenadas. El mantenedor decidió estrategia **`stacked-to-main`** y un 500 líneas por PR** (override explícito del default de 400 de la skill `chained-pr`). La medición real dean que **6 de las 9 unidades originales excedían 800**, así que la pasada honesta de slicing las parte en **16 PRs** (mínimo 12 si se aceptaran enteras las unidades frágiles U3/U5/U6/U7).

### Desglose de líneas estimadas

| Bloque | Contenido | Líneas |
|---|---|---|
| 27 migraciones | esquema destino: 109 tablas de negocio | ~1.380 |
| 6 seeders | perfil VE + CL/MX/CO/PA, taxonomías, tipos de asiento, mapa sync, saldos | ~405 |
| **66 modelos Eloquent** | casts exactos, relaciones, FKs (`tasks.md` nombra 65 + `User`) | **~2.220** |
| `app/Domain/**` | **~29 clases** · Tax (6) · Fiscal (8) · Inventory (3) · Accounting (1) · Checkout (2) + contratos/VO/enums | **~1.605** |
| Impresión + Sync | renderer ESC/POS, controller, jobs, adaptador v2 | ~700 |
| Config + CI | `config/database.php`, 3 configs, `.env.example`, workflow | ~120 |
| Suite Pest | **73 tareas `-T`** (una por tarea de implementación) + T1–T3 | **~2.875** |
| README + verificación ADR | arranque y trazabilidad | ~90 |
| **Total** | | **~8.735** |

### Por qué cambió el forecast

El forecast inicial de **~6.740** estaba descalibrado en tres bloques. Verificado con grep sobre el propio plan, no a ojo:

| Bloque | Estimado | Real | Causa |
|---|---|---|---|
| Modelos | ~980 | **~2.220** | El plan nombra **65 modelos + `User` = 66**, no "~28" |
| Tests | ~1.900 | **~2.875** | Hay **73 tareas `-T`** (una por tarea de implementación); los "51" de §E.5 son *nombres canónicos*, no ficheros |
| Dominio | ~1.100 | **~1.605** | Son **~29 clases**, no 20 (contratos, value objects y enums incluidos) |
| Modelos implícitos | — | **~390** | Series y documentos fiscales (U3), lotes y transferencias (U5), cuotas y cotizaciones (U6): exigidos por el diseño pero no enumerados en el plan |

Migraciones y seeders cuadraban. El desvío global es de **+30%**, y es la causa directa de que 6 de las 9 unidades originales superen las 800 líneas.

### Work Units medidas y corte final en 16 PRs

**Medición por unidad original** (una sola pasada honesta de slicing, como exige la skill `chained-pr`):

| Unit | Fase | Migr | Modelos | Dominio | Seeds | Tests | Cfg/Docs | TOTAL | Veredicto |
|---|---|---|---|---|---|---|---|---|---|
| 1 | 0.1 | 265 | 400 | 0 | 0 | 215 | 80 | **960** | excede 800 (+160) |
| 2 | 0.2 | 150 | 336 | 355 | 0 | 220 | 0 | **1.061** | excede 800 (+261) |
| 3 | 0.3 | 120 | 140 | 245 | 0 | 340 | 0 | **845** | excede 800 (+45, frágil) |
| 4 | 0.4 | 325 | 700 | 0 | 0 | 180 | 0 | **1.205** | excede 800 (+405) |
| 5 | 0.5 | 220 | 112 | 155 | 0 | 285 | 0 | **772** | dentro (margen 28, frágil) |
| 6 | 0.6 | 0 | 112 | 285 | 0 | 360 | 0 | **757** | dentro (margen 43, frágil) |
| 7 | 0.7 | 145 | 252 | 170 | 0 | 225 | 0 | **792** | dentro (margen 8, frágil) |
| 8 | 0.8 | 100 | 168 | 395 | 0 | 310 | 40 | **1.013** | excede 800 (+213) |
| 9 | 0.9 | 55 | 0 | 0 | 405 | 740 | 130 | **1.330** | excede 800 (+530) |
| | | | | | | | | **Total** | **~8.735** | 6 de 9 exceden 800 |

### Corte final: 18 PRs (`stacked-to-main`)

> **Estado real de la cadena** (actualizado 2026-10-31). La work unit "pais/fiscal a" resultó medir **1.004 líneas**, 204 por encima del techo, así que se **re-sliced** en dos: la work unit "pais/fiscal a" original pasó a ser **PR3 (M01)** y **PR4 (M02 + 7 modelos)**. De ahí 16 PRs pasan a **17**. El re-slice quedó registrado en el ledger con `sdd-attempt reset` y su motivo. La work unit "pais/fiscal c" resultó medir **1.052 líneas** de código y tests (**1.203** con los artefactos SDD), 252 por encima del techo: **no** se concede `size:exception` y se **parte** en dos — **PR5 (0.2.4 + 0.2.5: contrato del actor intermedio + value objects + enums genéricos)** y **PR6 (0.2.3 + 0.2.6 + 0.2.7: M03 + modelos `TaxCategory`/`TaxRate` + `TaxContext`)**. De ahí 17 PRs pasan a **18**. La work unit "numeracion a" resultó medir **874 líneas** (835 adiciones + 39 borrados), **74 por encima** del techo de 800: **tampoco** se concede `size:exception` y se **parte** en dos slices encadenados — **PR7 (3a-i: M21 + `fiscal_series`/`fiscal_document_reservations` + test de esquema + arnés de concurrencia, 362 líneas)** y **PR8 (3a-ii: `NumberSeries` + reserva atómica + T1–T3 + artefactos SDD, 512 líneas)**. **T1–T3 salen del PR9 (numeracion b) y entran en el PR8.** De ahí 18 PRs pasan a **19** y **quedan 11** (PR9–PR19).

| # | Rama | Work unit | Contenido | Líneas est. | GitHub | Estado |
|---|---|---|---|---|---|---|
| 1 | `feat/fase0-01-ciimientos-a` | 1a | M04-M07 + `composer.json` + `pgsql_b` + 3 interruptores + 8 modelos base | **1022** | PR #2 - issue #1 | **fusionado** `65b529d` |
| 2 | `feat/fase0-02-ciimientos-b` | 1b | M25-M26 + 6 modelos (i18n, outbox SMS, auditoria) | **491** | PR #4 - issue #3 | **fusionado** `a5e5100` |
| 3 | `feat/fase0-03-pais-fiscal-a` | 2a | M01 + `Country`/`Currency`/`NumberFormat` | **241** | PR #6 - issue #5 | **fusionado** `8b9c553` |
| 4 | `feat/fase0-04-pais-fiscal-b` | 2b | M02 + `fiscal_providers` + 7 modelos de perfil fiscal | **767** | PR #8 - issue #7 | **fusionado** `ba28322` |
| 5 | `feat/fase0-05-pais-fiscal-c` | 2c-a | contrato `ElectronicInvoiceProvider` + 9 value objects + 5 enums genericos | **773** | PR #10 - issue #9 | **fusionado** `adac1b6` |
| 6 | `feat/fase0-06-pais-fiscal-d` | 2c-b | M03 + `TaxCategory`/`TaxRate` + `TaxContext` + `FiscalProfile::taxContext()` | **559** | PR #12 - issue #11 | **fusionado** `efbf00d` |
| 7 | `feat/fase0-07-numeracion-a` | 3a-i | M21 + `fiscal_series`/`fiscal_document_reservations` + test de esquema + arnes | **362** | PR #14 - issue #13 | **fusionado** `88e912c` |
| 8 | `feat/fase0-08-numeracion-b` | 3a-ii | `NumberSeries` + reserva atomica + **T1-T3** + artefactos SDD | **512** | PR #17 - issue #16 | **fusionado** `89fa520` |
| 9 | `feat/fase0-09-numeracion-b` | 3b-i | M22 + `FiscalIssuanceService` + tests | **619** | PR #19 - issue #18 | **fusionado** `b7f0c56` |
| 10 | `feat/fase0-10-numeracion-c` | 3b-ii | `ControlNumberGenerator` + anulacion que consume el numero | **310** | PR #21 - issue #20 | **fusionado** `abb0b3b` |
| 11 | `feat/fase0-11-numeracion-d` | 3b-iii | notas de credito/debito + comprobante de retencion | **417** | PR #23 - issue #22 | **fusionado** `6baaa3c3` |
| 12 | `feat/fase0-12-numeracion-e` | 3b-iv | contingencia en papel + conciliacion D+1 + fix del escaner | **238** | PR #25 - issue #24 | **fusionado** `97cd355` |
| 13 | `feat/fase0-13-numeracion-f` | 3b-v | modelo de plantilla + render del comprobante | **513** | PR #27 - issue #26 | **fusionado** `9c5a467` |
| 14 | `feat/fase0-09-catalogo-a` | 4a-i | M08 + M09 (los doce bridges) + tests | **299** | PR #29 - issue #28 | **fusionado** `54a3d64` |
| 15 | `feat/fase0-10-catalogo-b` | 4a-ii | M10 (clientes y proveedores) + FKs diferidas | **158** | PR #31 - issue #30 | **fusionado** `69c1b70` |
| 16 | `feat/fase0-10-catalogo-b` | 4a-iii | los 13 modelos del catalogo, bridges y clientes | **737** | PR #33 - issue #32 | **fusionado** `01f115a` |
| 17 | `feat/fase0-11-catalogo-c` | 4a-iv | M11 ventas + M12 extractos + M13 compras | **336** | PR #35 - issue #34 | **fusionado** `8c1fafe` |
| 18 | `feat/fase0-12-catalogo-d` | 4a-v | los 13 modelos de venta, compra y extractos | **669** | PR #37 - issue #36 | **fusionado** `4c2fbd0` |
| 19 | `feat/fase0-13-stock-lotes` | 5-i | M14 + `StockInvariant` + tests | **268** | PR #39 - issue #38 | **fusionado** `908a3b6` |
| 20 | `feat/fase0-14-stock-lotes-b` | 5-ii | `LotConsumer` (FIFO + `SKIP LOCKED`) + `LotReturner` | **409** | PR #41 - issue #40 | **fusionado** `21ac229` |
| 21 | `feat/fase0-15-stock-lotes-c` | 5-iii | test de concurrencia del stock con dos sesiones | **151** | PR #43 - issue #42 | **fusionado** `b43ca60` |
| 22 | `feat/fase0-16-stock-lotes-d` | 5-iv | M15 + M17 + el servicio sin lote (0.5.7) | **331** | PR #45 - issue #44 | **fusionado** `1fd23d0` |
| 23 | `feat/fase0-17-stock-lotes-e` | 5-v | el servicio sin lote + la transferencia con una sola cabecera | **224** | PR #47 - issue #46 | **fusionado** `5e53603` |
| 24 | `feat/fase0-18-impuestos` | 6-i | `TaxCalculator` puro + sus value objects + el test del motor | **360** | PR #49 - issue #48 | **fusionado** `4e773c9` |
| 25 | `feat/fase0-24-contabilidad-i` | 7-i | M18 (el libro contable) + M19 (gastos) + `LedgerWriter` + los 3 modelos del asiento | **896** | PR #51 - issue #50 | **fusionado** `d23afef` |
| 26 | `feat/fase0-25-impuestos-b` | 6-ii | el `CheckoutService`: el checkout de dos transacciones + M30 + el resolutor de tasa | **1470** | PR #53 - issue #52 | **fusionado** `44e4740` |
| 27 | `feat/fase0-26-t2-fiscal` | 6-iii | el T2 fiscal (M31 + `FiscalIssuanceCoordinator`) y el reintento que reutiliza la reserva | **1871** | PR #55 - issue #54 | **fusionado** `23092ff` |
| 28 | `feat/fase0-27-caja` | 7-ii-a | M20 + M28 + M32 (caja e ingresos) + los 7 modelos contables | **1136** | PR #57 - issue #56 | **fusionado** `e25d6a1` |
| 29 | `feat/fase0-27-caja-b` | 7-ii-b | el `CashRegisterService` (caja por fecha de negocio) + el comando `ops:check-opening-balances` | **914** | PR #59 - issue #58 | **fusionado** `7e74686` |
| 30 | `feat/fase0-28-bridges` | 7-iii | los seis bridges y `FiscalDocument` como modelo + los trece nombres canonicos | **1155** | PR #61 - issue #60 | **fusionado** `7e64428` |
| 31 | `feat/fase0-29-impresion` | 8-i | M23 (el esquema de impresion) + los 4 modelos + el `EscposRenderer` + el congelado del payload | **1364** | PR #63 - issue #62 | **fusionado** `70c0ea5` |
| 32 | `feat/fase0-30-impresion-endpoints` | 8-ii | los cinco endpoints del agente LAN + la maquina de estados de `print_jobs` + la reimpresion + el corte de papel | **1652** | PR #65 - issue #64 | **fusionado** `26ee98d` |
| 33 | `feat/fase0-31-secreto-registro` | 8-iii | el secreto de registro por tienda: el registro del agente deja de ser publico | **467** | PR #67 - issue #66 | **fusionado** `530db45` |
| 34 | `feat/fase0-33-sync-verificador` | 8-iv-a | M24 + el verificador del contrato v2 + los tres rechazos de transporte | **1859** | PR #68 - issue #67 | **en curso** |
| 35 | `feat/fase0-34-sync-traductor` | 8-iv-b | el `LegacyStatementTranslator` + el mapa sembrado (las ~24 tablas del terminal) | ~1200 | - - - | pendiente |
| 36 | `feat/fase0-35-sync-aplicador` | 8-iv-c | el `LegacyBatchApplier` + el job idempotente por `sync_batches` | ~900 | - - - | pendiente |
| 37 | `feat/fase0-21-seed` | 9a | M27 + 6 seeders + `DatabaseSeeder` | **533** | - - - | pendiente |
| 38 | `feat/fase0-22-suite` | 9b | `NoCountryLiteralsTest` + tests canonicos | **550** | - - - | pendiente |
| 39 | `feat/fase0-23-ci-docs` | 9c | workflow CI + `README.md` + meta-tests | **340** | - - - | pendiente |
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
| **B6** | ~~Estrategia de cadena de PRs~~ **RESUELTO 2026-10-31**: `stacked-to-main`, 16 PRs, 500 líneas por PR (§Cadena de entrega) | Decisión del equipo | Maintainer | Resuelto: **no bloquea `sdd-apply`** |
| **B7** | **Formato del correlativo** (`000-00000000`, P6) y **serie propia de compras** (P7) | Decisión de producto, ya recomendada | Producto | Nada: son **datos sembrados** (`number_formats`, `fiscal_series`) |
| **B8** | Rol dual **emisor + imprenta** (P32/D1) | Decisión de producto, ya recomendada | Producto | Nada en Fase 0: se siembran ambos roles y se activa sólo `issuer` |

**No bloquea `sdd-apply`**: B1–B5 y B7–B8 son dependencias de datos y decisiones de producto; **B6 quedó resuelto** (estrategia `stacked-to-main`, 16 PRs, 500 líneas por PR), de modo que la implementación puede arrancar.

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
