# Brief de diseño — Fase 0 (pasada 1 de 2)

> **Por qué existe este fichero**: la fase `sdd-design` falló dos veces en la sesión anterior con un error de
> transporte del runtime (`sdd_task_result_empty` y luego `sdd_task_dispatch_latched`). El runtime lacha una fase
> fallida dentro de la sesión, así que **hay que relanzar el diseño desde una sesión nueva**.
> Este fichero es el input completo para esa sesión: no hace falta reconstruir el contexto.

## Cómo reanudar

1. **Abrir la sesión en `D:\htdocs\pos-moderno-v2`** (NO en `D:\htdocs\pos_moderno`).
   El runtime vincula la sesión SDD al cwd de la sesión: en la sesión anterior quedó registrada contra el
   legacy, por eso la `continuation` del envelope apuntaba a `D:\htdocs\pos_moderno` y ahí no había change activo.
2. Verificar el estado:
   ```bash
   gentle-ai sdd-status fase0-fundaciones --cwd "D:\htdocs\pos-moderno-v2" --json
   ```
   Debe reportar `nextRecommended: design`, `artifacts.proposal: done`, `artifacts.specs: done`, `artifacts.design: missing`.
3. Lanzar `sdd-design` con el prompt de la §1 de este documento.

## §1 — Prompt para la fase `sdd-design` (pasada 1)

```
Ejecuta la fase `sdd-design` para el change SDD `fase0-fundaciones` del proyecto **pos-moderno-v2**.
Esta es la PASADA 1 de 2 del diseño (la pasada 2 la hará una sesión posterior).

## Rutas
- Raíz: `D:\htdocs\pos-moderno-v2`
- Artefacto a escribir: `openspec\changes\fase0-fundaciones\design.md` (créalo)
- Store `openspec`, repo-local. No escribas código de aplicación. No hagas commits. No toques el legacy.

## Lee antes de redactar
1. `openspec\changes\fase0-fundaciones\proposal.md` — alcance S1–S10.
2. `openspec\changes\fase0-fundaciones\specs\*\spec.md` — 10 capacidades, 44 requisitos. Cada requisito tiene
   línea "Trazabilidad" y escenarios "Regla fijada". El diseño debe satisfacerlos; si alguno no tiene diseño,
   señálalo en "Requisitos sin diseño en esta pasada".
3. `openspec\changes\fase0-fundaciones\exploration.md` — §3 esquema, §5 numeración, §6 motor fiscal, §7 FIFO,
   §8 impresión, §9 sync.
4. `openspec\changes\fase0-fundaciones\research\` + `research\evidence\` — los tres carriles y la evidencia oficial.

## Reglas obligatorias de `openspec\config.yaml` (rules.design)
- Diagramas de secuencia para los flujos transaccionales: checkout, FIFO, reserva fiscal.
- Documentar las decisiones de arquitectura con su motivo (deja una lista seccionada "Decisiones que serán ADR
  en la pasada 2", con una línea de motivo y la alternativa descartada).
- Toda operación de escritura es unidad atómica con bloqueo de fila (SELECT ... FOR UPDATE).
- El correlativo se reserva con `UPDATE ... RETURNING` o `SELECT ... FOR UPDATE`; nunca con `rowCount()`.

## A) Esquema destino, migración por migración
Lista ordenada de migraciones Laravel: nombre, tablas que crea o altera, propósito. Respeta:
- 55 conservadas · 17 fusionadas · 1 eliminada · 27 nuevas (disposición completa en exploration.md §3).
- Maestro/bridge multi-tienda con FKs reales y numeric(25,4).
- Sin blobs PHP serializados: users.preference, stores.preference, user_group.permission, payments.details
  → columnas tipadas o tablas de relación.
- Se eliminan los 21 flags contables de income_sources/expense_categorys y los contadores derivados
  (bank_accounts.total_*, bank_account_to_store.deposit/withdraw).
- Renombres: selling_info/_item/_price → sales/sale_items/sale_totals; purchase_item → inventory_lots SIN
  status mutable; bank_transaction_* → ledger_entries con entry_type; quotation_* se divide en quotations + orders;
  holding_* (pedido en espera del POS) ≠ order_*; expenses = fuente de verdad del gasto.
- Capa de país/fiscal: countries, fiscal_profiles, fiscal_profile_document_types, fiscal_profile_environments,
  fiscal_tax_schemes, fiscal_status_vocabularies, tax_categories, tax_rates, currencies, number_formats,
  fiscal_providers, fiscal_series, fiscal_documents, fiscal_document_taxes, fiscal_document_parties,
  fiscal_document_links, fiscal_document_events (append-only), fiscal_document_control_assignments,
  fiscal_periodic_reports.
- Operativa: inventory_lots, sale_item_lot_consumptions, ledger_entries, ledger_lines, cash_registers,
  print_targets, print_agents, print_jobs, sync_batches, legacy_translation_map (dato sembrado, no código).

## B) Los tres diagramas de secuencia
1. Checkout — reserva del número de documento, reserva del número de control, consumo FIFO con SKIP LOCKED,
   TaxCalculator, snapshots, ledger_entry, pago; con el rollback explícito.
2. Consumo FIFO — multi-lote, ORDER BY received_at, id, SKIP LOCKED, devolución que repone el lote exacto,
   invariante quantity_in_stock == SUM(available), comportamiento bajo concurrencia sin sobreventa.
3. Reserva fiscal — las DOS reservas (series_role 'document' y 'control'), UPDATE ... RETURNING, qué ocurre si
   la segunda reserva falla, y por qué el número no retrocede (anulación → void + voided_at).

## C) Contratos de núcleo
- ElectronicInvoiceProvider: submit() → assignControl() → issue(); signer_model
  (authority/psp/printer/legal_signature) y actor (fiscal_providers: printer/pac/portal) como DATOS; el caso
  "issued no depende de la impresión".
- TaxCalculator: puro, sin I/O; perfiles resueltos antes de la llamada; snapshots congelados de tasa y categoría;
  redondeo por línea + roundingAdjustment.
- Contrato de API del agente de impresión: endpoints, autenticación, long-poll, máquina de estados de print_jobs,
  y por qué los bytes ESC/POS se generan en el servidor (el agente sólo escribe).

## Reglas de estilo
- Español; identificadores, rutas y comandos literalmente.
- Cada decisión lleva motivo Y alternativa descartada.
- Fuera de alcance, no lo incluyas: adaptadores reales de SENIAT/imprenta, módulo operativo de retenciones,
  adaptadores por país de la Fase 2, ventas/compras/POS completos, instalador del agente LAN, cola de SMS
  operativa, los 21 reportes.
- Si un requisito te parece inviable o contradictorio, dilo en "Conflictos detectados".

## Persistencia
Engram con `project: "pos-moderno-v2"` (obligatorio; mem_update falla con project_transition_conflict; usa
mem_save con project explícito). capture_prompt: false. Tope de 50 000 B por observación.

## Contrato de resultado
status · executive_summary (5-8 líneas) · artifacts · next_recommended · risks · skill_resolution.
Devuélveme un mensaje COMPACTO de máx 15 líneas.

## Skills a cargar antes de trabajar
- `C:\Users\helim\.claude\skills\cognitive-doc-design\SKILL.md`
```

## §2 — Qué le toca a la pasada 2

Cuando `design.md` exista con las secciones A, B y C, la **pasada 2** amplía ese mismo fichero con:

| Sección | Contenido |
|---|---|
| **D. Los 6 ADRs** | Numeración fiscal de dos series con reserva atómica ("apagar, nunca renumerar") · actor intermedio emisor/imprenta digital · capa de país/fiscal como datos sin `if` por país (con el test anti-país como control ejecutable) · stock por lotes sin `status` mutable + `FOR UPDATE SKIP LOCKED` · bytes ESC/POS en el servidor + agente en pull + USB descartado (Zadig) · adaptador sync v2 con wire intacto, traducción por datos sembrados e idempotencia por `sync_batches`. Cada uno: contexto, decisión, consecuencias y alternativas descartadas. |
| **E. Plan de testing ejecutable** | T1 (secuencia sin huecos), T2 (bloqueo real → SQLSTATE `55P03`), T3 (rollback devuelve el número) **sin `RefreshDatabase`**: dos conexiones, `SET LOCAL lock_timeout`; suite del dominio (FIFO, IVA, crédito, cuota, transferencia, invariantes); test de arquitectura anti-país (qué escanea y qué falla); qué se resuelve con `RefreshDatabase` y qué no. |
| **F. Trazabilidad requisito → diseño** | Tabla que mapea los **44 requisitos** de las 10 specs a la parte del diseño que los satisface. Requisito sin diseño = hallazgo, se señala explícitamente. |

## §3 — Contexto que no hay que reconstruir

### Decisiones de producto (confirmadas por el usuario)

Pedidos sí se usan (se rehacen) · impresión en red local **y** servidor · sincronización offline→online sí, el
protocolo v2 es contrato fijo · facturación electrónica sí · **proyecto verde** (sin histórico, saldos de
apertura congelados con fecha y responsable) · multi-tienda sí · modelo de cuotas se conserva tal cual ·
SMS/WhatsApp sí · el sistema **reemplaza** al legacy (`sync.php` viejo se apaga el mismo día) · equipo nuevo
(CI, tests y docs son requisitos del día 1) · **multipaís: Venezuela por defecto, extensible a Latinoamérica en
2ª fase**.

P1 terminales offline **siguen siendo legacy** → el traductor de SQL legacy es entregable de Fase 0.
P2 **nombres de tabla nuevos en inglés** + mapa de traducción como datos sembrados.
P3 **IVA retenido se modela ahora** (esquema + tipo de documento + serie propios); el módulo operativo va a Fase 1.
P4 **redondeo por línea con `roundingAdjustment` visible**, configurable por perfil — **decisión de producto, no
requisito normativo**.

### Hechos fijados por la norma venezolana

Providencia **SNAT/2026/00093** (G.O. 43.484, 21/10/2026, vigente **19/04/2027**) + **SNAT/2024/000102**
(G.O. 43.032, 19/12/2024). El legacy emite bajo SNAT/2011/0071 → desalineado.

- **Dos numeraciones**: documento (del emisor, art. 8.2 y 20, con prefijo "serie" si no hay sistema centralizado)
  y **Nº de Control** (de la **imprenta digital**, art. 8.3, 9.1 y 27, `NN` + 8 dígitos, primera asignación
  `00`+`1`).
- **Actor intermedio obligatorio**: el emisor no emite solo (F2 art. 18.4 y 18.6; art. 42.1).
- **P3** es un comprobante de retención **propio** (art. 18): `AAAAMMSSSSSSSS`, período de imposición.
- **Hallazgos negativos verificados**: el **QR no aparece** en la norma (opcional por canal); **no hay algoritmo
  de firma** (`hash_algorithm`/`signature_scheme` parametrizados, sin default); **la tasa de IVA no aparece**
  → el 16 % se siembra `pending_confirmation`.
- **Obligaciones de plataforma**: auditoría append-only de toda acción; acceso al SENIAT 10 años; remisión del
  registro automatizado en los 10 días del fin de mes; conciliación del papel de contingencia D+1; formatos de
  contingencia en el local; multipágina conserva el mismo nº de documento y de control; la nota de crédito
  referencia fecha, nº correlativo, nº de control y monto del original.

### Impresión

Bytes ESC/POS **en el servidor** (el agente sólo escribe → agnóstico a lenguaje) · agente en **pull** con long-poll
(atravesa NAT) · el modo `browser` del legacy imprimía **HTML, no ESC/POS** → lo unificable es el **origen del
contenido** · **USB directo en Windows exige Zadig → descartado** (destinos: TCP 9100 o impresora compartida de
Windows) · **CP850** es la codepágina correcta (CP858 no es codificable en `escpos-php`) · el ancho es dato por
perfil · `getSupportsQrCode()` lanza excepción si el perfil no lo soporta · el límite de payload del QR lo impone
el servidor · el estado `issued` **no** depende de la impresión (se reimprime sin reemitir).

### Sincronización

El payload v2 es **SQL crudo de MySQL con nombres legacy** → el adaptador **traduce**, con mapa **sembrado**
(~18 tablas), nunca en código. El protocolo **no es idempotente** (el cliente trunca su log sólo si la respuesta
fue exitosa) → se tapa en el maestro con `sync_batches UNIQUE (client_id, payload_sha256)` **sin tocar el
contrato de wire**.

### Restricciones del entorno

- `pdo_pgsql`, `intl`, `sodium` habilitadas en `C:\xampp\php\php.ini` (ya hecho).
- PostgreSQL 17 + Redis 7 en Docker (`docker compose up -d`); base de test `pos_moderno_v2_test`.
- Tests: **PostgreSQL también** (decisión del usuario); `phpunit.xml` apunta a `pos_moderno_v2_test`.
- Precondición de la suite: `npm ci && npm run build` (falta `public/build/manifest.json`).
- CI en `.github/workflows/tests.yml` con servicio PostgreSQL + `lint:check` + `typecheck`.
- `strict_tdd: true` (ver `openspec\config.yaml`).

### Pendientes conocidos

- La providencia usada es **transcripción de terceros**; el PDF de la G.O. 43.484 no se alcanzó (SENIAT da 403).
- El **Manual Técnico del art. 41** bloquea el adaptador real de SENIAT (Fase 1/2), no la Fase 0.
- El PDF de referencia ESC/POS de Epson da 403 → usar `escpos-php` como especificación práctica.
- `documentos-fiscales` está en **647/650 palabras**: un requisito nuevo la desborda.
