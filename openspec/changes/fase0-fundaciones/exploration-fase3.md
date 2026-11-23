# Exploracion de la Fase 3 — Cerrar el negocio

> Exploracion de SOLO LECTURA sobre `D:\htdocs\pos-moderno-v2` (el corte 1 cerrado: 57
> migraciones, 76 modelos, 19 controladores, 73 clases de dominio, 179 tareas, 719 tests /
> 10994 assertions) y sobre el legacy `D:\htdocs\pos_moderno`
> (`Documentacion/mapa-funcional.md`, 1550 lineas, seccion 6). **No se ejecuto nada**: ni
> `pest`, ni `npm`, ni `artisan migrate`, ni `codegraph sync`.
>
> **Fecha**: 2026-11-23. **Estado del dominio**: el nucleo esta bien y el BORDE esta medio
> hecho (permisos, impresion, caja del dia, Z, auditoria y backups). Lo que falta no es el
> nucleo: es **la puerta de entrada**. Hay dominio bueno, probado y en verde, que el negocio
> **no puede usar**.
>
> La exploracion de la Fase 2 esta en `exploration-fase2.md`. Esta es la siguiente de la
> serie: mismo formato, mismo tono, misma regla de no afirmar lo que no se ha leido.

## Donde estamos

El **corte 1 esta CERRADO**.

| Corte | Que es | Estado |
|---|---|---|
| **Fase 0** | Los cimientos: el dominio fiscal, el motor de impuestos, el stock por lotes, el libro contable y la caja | **completa** |
| **Fase 1** | La aplicacion del POS: 13 unidades, la caja, el catalogo, el credito y la caja del dia | **completa** |
| **Fase 2** | Cerrar el sistema: 14 unidades (F2-01 a F2-14): los permisos, el fiscal, la impresion, la caja del dia, el Z, la auditoria, los backups y el planificador | **completa** |

- **179 tareas cerradas**, cada una con su test gemelo.
- Suite: **719 tests / 10994 assertions**, en verde.
- **En produccion solo hay UN canal fiscal: el de papel/local.** El Portal del SENIAT esta
  bloqueado por documentacion externa (el Manual Tecnico del art. 41 y la API), y el
  adaptador **no se escribe ni una linea** antes de tenerlos en la mano.

Lo que el corte 1 deja bien: los permisos y los roles aplicados, el ticket encolado al
cobrar, el escritor directo a la impresora de red, la caja del dia con su Z inmutable y su
arqueo por denominaciones, la auditoria de negocio append-only, los backups verificados y el
planificador.

Lo que el corte 1 **no** deja: ni la anulacion, ni la devolucion, ni las compras, ni los
gastos, ni los reportes, ni la i18n, ni la instalacion de una tienda nueva.

## El hallazgo de fondo: un dominio huerfano

**Este es el hallazgo mas importante de la Fase 3, y va primero.**

Hay seis piezas de dominio **escritas, probadas y en verde** que **solo invocan tests**:
existen, funcionan, y **el negocio no puede usarlas**.

| Pieza | Que hace | Quien la llama hoy |
|---|---|---|
| `Domain/Inventory/TransferService` | la transferencia entre tiendas: el stock sale del lote origen y entra en el destino, con **una sola cabecera** | un test |
| `Domain/Inventory/LotReturner` | la devolucion que **repone el lote exacto** del que salio la linea | un test |
| `Domain/Credit/InstallmentService` | el plan de cuotas, con el **redondeo correcto** y el interes simple en el servidor | un test |
| `Domain/Fiscal/DerivedDocumentService` | las notas de credito y debito y el comprobante de retencion | tests |
| `Domain/Fiscal/ContingencyService` | la contingencia en papel y su conciliacion a D+1 | un test |
| `Domain/Fiscal/FiscalIssuanceService::void()` | la anulacion fiscal: el numero queda consumido y el evento auditado | tests |

Y **sus tablas ya estan en el esquema**: `fiscal_contingency_forms` (M22), `expenses` y
`expense_categories` (M19), `income_sources` (M32), `installment_schedules` (M15), y tambien
`gift_cards`, `loans`, `quotations` y `orders` (M15 a M17).

**Por que esto decide la Fase 3**: una unidad que **abre la puerta** a un dominio que ya
existe es mas barata, mas pequena y mas revisable que una unidad que escribe el dominio
desde cero. Y el riesgo es el inverso: como el codigo esta ahi y en verde, **da la impresion
de que la funcionalidad existe**. Un tester recorre la aplicacion, no el codigo: para el
negocio, la devolucion de venta **no existe**.

## El mapa de lo que hay

| Area | Estado | Que falta |
|---|---|---|
| Caja / POS | **COMPLETO** | — |
| Caja del dia (abrir, cerrar, reabrir, Z, Z impreso) | **COMPLETO** | — |
| Ventas | PARCIAL | **solo lectura**: sin anulacion ni devolucion |
| Comprobante fiscal | PARCIAL | sin anular, sin entregar |
| Clientes y credito | PARCIAL | sin cuotas |
| Catalogo | PARCIAL | sin categorias, marcas, unidades e impuestos |
| Contabilidad | PARCIAL | el plan de cuentas si; el libro mayor y los estados financieros no |
| Auditoria | **COMPLETO** | — |
| Usuarios y roles | PARCIAL | **asignar si; crear no** |
| Impresion | PARCIAL | la cola esta a medias (F3-02) |
| Agente LAN | servidor **COMPLETO**, cliente .NET ausente | el cliente es otro repositorio |
| Sync legacy | **COMPLETO** pero **apagado por defecto** | `SYNC_LEGACY_ADAPTER_ENABLED=false` |
| Reportes | PARCIAL | solo stock bajo |
| Config por tienda | PARCIAL | **2 de 17 columnas** con puerta |
| Administracion fiscal (series, perfiles, proveedores) | **NO EXISTE en UI** | solo seeders: **hoy no se puede crear una serie en produccion** |
| Gastos, ingresos y banca | **NO EXISTE** | las tablas estan vacias |
| Compras y proveedores | **NO EXISTE** | |
| Cuotas | **NO EXISTE** | |
| i18n | **NO EXISTE** | |
| Motor de reportes | **NO EXISTE** | (solo el reporte de stock bajo) |

## Lo que el legacy tenia y el v2 no

El legacy (mapa funcional, seccion 3) tiene pantallas que el v2 no tiene: **cotizaciones,
pedidos, prestamos, tarjetas de regalo, gastos y banca, SMS, i18n** y **unas 20 pantallas de
reporte**.

Y de la seccion 6 (defectos priorizados) los **P0 que siguen sin cubrir** son:

| # | Defecto del legacy | Donde lo resuelve la Fase 3 |
|---|---|---|
| **#2** | `UPDATE installment_orders SET last_installment_date = ?` **sin `WHERE invoice_id`**: actualiza **todas** las filas de la tabla | **F3-05** |
| **#3** | `UPDATE selling_price ... WHERE invoice_id = ?` **sin `store_id`**: el pago de cuota toca facturas de otras tiendas | **F3-05** |
| **#4** | cascada manual de cuotas sobre **8 tablas sin transaccion**: estados intermedios huerfanos | **F3-05** |
| **#9** | al borrar una compra el ajuste de stock usa **un unico `item_id`**: solo revierte el stock de un producto | **F3-04** |
| #15 | al vender una cotizacion no se borran `quotation_item`/`quotation_price` | no entra en este corte |
| #18 | `editLoan` no recalcula `amount`, `interest`, `payable` ni `due` | no entra en este corte |
| #19 | el pago de un prestamo valida `paid <= due` **sin transaccion**: sobrepago concurrente | no entra en este corte |
| #20 | al borrar una tarjeta de regalo se generan **dos retiros** y se borran las recargas | no entra en este corte |
| #21 | `editGiftcard` no actualiza ni `value` ni `balance` | no entra en este corte |

**Los P0 #2, #3 y #4 son los mas graves que quedan**: son los que **corrompen datos**. Los
demas P0 del legacy estan cubiertos por el v2 o son de modulos que no existen.

## Las 13 unidades propuestas

### F3-01 Las series y los perfiles fiscales en la UI

- **Resuelve**: que una tienda se pueda **instalar desde la aplicacion**: crear la serie de
  documento y la de control, el perfil fiscal, el proveedor, su entorno y la inscripcion
  fiscal del emisor.
- **De donde sale**: las tablas ya estan (`fiscal_series` M21, `fiscal_profiles`,
  `fiscal_providers` y `fiscal_profile_environments` M02, `store_fiscal_registrations` M04) y
  hay seeders (`FiscalProfileSeeder`, `DemoStoreSeeder`). Hoy `PosContext` resuelve **la serie
  de las filas sembradas** y **no hay ninguna ruta de administracion fiscal**.
- **Por que es ahora**: sin esto, **instalar un cliente nuevo es una tarea de base de datos**:
  el corte 1 demuestra, no instala. Y el perfil declara los tokens que el comprobante
  necesita (F2-02), asi que **la fila es el unico sitio** donde se cambia un dato fiscal.

### F3-02 La cola de impresion completa

- **Resuelve**: cuatro huecos, y **ninguno es del canal**: (a) la **pantalla de agentes**:
  la revocacion y el restore existen **por API** (`print-agents/{agent}/revoke` y
  `/restore`) y **la UI no los llama nunca**: el agente aparece solo como **lista de lectura**
  dentro de la pantalla de destinos; (b) el **estado del ticket en el POS**: el campo `print`
  viaja en la respuesta del cobro y `pos/index.tsx` **no lo consume**, asi que el cajero ve
  "vendi" pero **no ve si el papel salio**; (c) el **Z** que ensena el estado de sus trabajos
  de impresion; y (d) el **heartbeat honesto**, que cuenta los trabajos en cola de **toda la
  tienda**, incluidos los de `browser` y `server_socket` y los que ese agente **no puede
  tomar**.
- **De donde sale**: el canal ya existe (F2-05 a F2-09): el dispatcher, la maquina de estados,
  los destinos, el agente y sus cinco endpoints. Lo que falta es **la puerta** (la UI) y la
  **honestidad** del conteo.
- **Por que es ahora**: la impresion es la **unica prueba** de que la caja entrego algo. Si el
  papel no salio, el cajero tiene que saberlo **antes** de que el cliente se vaya.

### F3-03 La anulacion y la devolucion de ventas

- **Resuelve**: la **anulacion de una venta** y la **devolucion**: la nota de credito que la
  deja sin efecto, la reposicion del stock en el lote exacto y el asiento que lo refleja.
- **De donde sale**: `FiscalIssuanceService::void()` (0.3.8), `DerivedDocumentService` (0.3.7),
  `LotReturner` (0.5.5) y las tablas `sale_returns`/`sale_return_items` (M11) **ya existen y
  solo los invocan tests**. `SalesController` esta **declarado de SOLO LECTURA**: no escribe,
  no cambia el estado de una venta y no toca la numeracion.
- **Por que es ahora**: sin esto **no hay devolucion de venta ni nota de credito**. Y el
  P0 #15 del legacy es exactamente el efecto de una anulacion mal hecha: dejar la venta
  historizada con importes viejos.

### F3-04 Las compras, los proveedores y las devoluciones de mercaderia

- **Resuelve**: el ciclo de **compras** (alta con sus lotes, que es como entra el stock), los
  **proveedores** con su deuda y las **devoluciones de mercaderia** (la compra que vuelve al
  proveedor y el stock que sale del lote exacto).
- **De donde sale**: `TransferService` (0.5.7, 0.6.9), `LotReturner` (0.5.5), las tablas
  `purchases`, `purchase_items`, `purchase_totals`, `purchase_payments` y `purchase_returns`
  (M13) y `suppliers` (M10). Es **dominio huerfano con test verde**.
- **Por que es ahora**: el P0 #9 del legacy (borrar una compra revierte el stock de **un solo
  producto**) **se evita aqui**: la devolucion repone el **lote exacto** y la compra se
  revierte **completa**. Y sin compras **no hay costo real**: el inventario valorado no tiene
  contra que valorar.

### F3-05 El cobro de cuotas

- **Resuelve**: el **cobro de cuotas**: el plan que ya se calcula, el cobro de una cuota con
  su imputacion, el estado del plan y el efecto en la deuda del cliente.
- **De donde sale**: `InstallmentService` (0.6.7, 0.6.8) **ya calcula el plan con el
  redondeo correcto** y **solo lo llama un test**; las tablas `installment_plans` e
  `installment_schedules` (M15) ya estan; y el libro del cliente (1.4) ya sabe como se
  imputa un cobro.
- **Por que es ahora**: **los P0 #2, #3 y #4 del legacy estan aqui y son los mas graves que
  quedan**. El legacy actualizaba `installment_orders` y `selling_price` **sin `WHERE`**, y la
  cascada de cuotas corria sobre **8 tablas sin transaccion**. Aqui el plan **es una fila**,
  la cascada va **en una transaccion** y cada cobro imputa **en su tienda**.

### F3-06 La contingencia en papel y el QR como imagen

- **Resuelve**: (a) la **contingencia en papel**: abrir un talonario, registrar los
  documentos fisicos usados y remitir los PDF **al dia siguiente**; y (b) el **QR como
  imagen**: hoy el QR es una cadena (`qr_content`) y **no se dibuja como imagen** en ningun
  sitio (no hay libreria de QR en `composer.json`).
- **De donde sale**: `ContingencyService` (0.3.9) y la tabla `fiscal_contingency_forms`
  (M22) **existen y no tienen puerta**. El render ESC/POS (0.8.3) ya falla cuando el QR no
  cabe.
- **Por que es ahora**: la contingencia es el **unico camino que deja el sistema sin
  conexion** y hoy **no se puede abrir**: un corte de red no tiene salida. Y el QR es la
  forma de **consultar el comprobante**, que hoy no existe (F3-12).

### F3-07 Los gastos, los ingresos y la banca

- **Resuelve**: los **cuatro tipos de asiento que hoy NO se escriben**: `expense`,
  `bank_movement`, `refund` y `adjustment`. Hoy solo se escriben **tres de los siete** del
  catalogo: `sale` (el checkout), `sale_payment` (el cobro) y `opening_balance` (la apertura
  de caja).
- **De donde sale**: las tablas **ya estan y estan vacias**: `expenses` y
  `expense_categories` (M19), `income_sources` (M32), `bank_accounts` (M20), mas el catalogo
  de los siete tipos (`LedgerEntryTypeSeeder`). `expenses` es la **fuente de verdad del
  gasto** (0.7.2) y `LedgerWriter` (0.7.4) ya es el unico pathway del asiento.
- **Por que es ahora**: **es la precondicion de los reportes**. Sin gastos, "ingresos y
  gastos" daria ceros, el PyG seria una mentira y el libro de caja no cuadraria con el banco.
  Y el P0 #8 del legacy (el gasto que suma a `total_deposit` en vez de `total_withdraw`)
  **esta resuelto por el diseno**: los saldos se derivan de los asientos.

### F3-08 El motor de reportes

- **Resuelve**: el **motor de reportes**: ventas por periodo, producto y cajero; IVA por
  alicuota; PyG; libro de caja; aging de clientes; inventario valorado; arqueo; Z por
  periodo; y la **exportacion**, que hoy **no existe**.
- **De donde sale**: el corte 1 ya tiene el dato de todos ellos: `sales`, `sale_items`,
  `sale_totals` y `sale_payments`, `ledger_entries` y `ledger_lines`,
  `sale_items.tax_rate_snapshot`, `cash_registers` y el Z sellado (F2-10).
- **Por que es ahora**: **depende de F3-07**. Sin gastos, "ingresos y gastos" daria ceros. Y
  el reporte de stock bajo (1.5) es hoy el **unico** reporte: un POS que no ensenia como va el
  mes no lo usa un dueno.

### F3-09 El correlativo fiscal atomico y el outbox de la emision

- **Resuelve**: (a) el **correlativo fiscal atomico de verdad**: `NumberSeries::reserve()`
  hace el `UPDATE ... RETURNING` y el `INSERT` de la traza en **dos sentencias**, y el
  llamante decide la transaccion. En el camino fiscal (`DerivedDocumentService::issueNote()`)
  la reserva se hace **fuera** de la transaccion que crea la nota, asi que un fallo
  posterior **quema el numero sin dejar traza**. Y (b) el **outbox de la emision**: hoy la
  emision fiscal (T2) ocurre **FUERA** de la transaccion de la venta y **sin outbox**: si el
  proceso muere entre el commit de la venta y la emision, la venta queda **sin comprobante y
  sin trabajo pendiente**, y el reintento es **manual**.
- **De donde sale**: `NumberSeries` (0.3.3) ya es atomico **por sentencia**; lo que falta es
  que **la reserva y su traza sean la misma unidad**. Y el patron de un outbox **ya existe**
  en este repositorio: `sms_messages` (M25).
- **Por que es ahora**: es la diferencia entre "una venta sin comprobante que alguien
  reintenta a mano" y "una venta que **sabe** que debe un comprobante". Y el correlativo es
  justo lo que **no se puede recuperar** (SNAT/2026/00093 arts. 8.2, 21, 27): un hueco en la
  traza es un hueco **definitivo** en el libro.

### F3-10 El registro del agente que no pisa el token de otro, y la idempotencia del resultado

- **Resuelve**: (a) el **registro del agente que no pisa el token de otro**:
  `PrintAgentController::register()` busca primero por `instance_id` y, si no hay fila,
  **busca por NOMBRE**. Cuando la fila encontrada tiene otro `instance_id`, **la reemplaza**:
  dos agentes con el mismo nombre en la misma tienda **se pisan**, y el segundo **invalida el
  token del primero sin auditoria**. Y (b) la **idempotencia del resultado**: un `result`
  reenviado tras un lease vencido **cae en `claimed`/`printing`** (el lease lo devuelve a
  `queued` con `agent_id = null`) y **devuelve 422** en vez de ser un no-op: la idempotencia
  depende del estado, y el agente que **si** imprimio el papel se lleva un error.
- **De donde sale**: el canal endurecido de la Fase 2 (F2-09) ya tiene argon2id, revocacion
  definitiva y rotacion con ventana de gracia. Faltan estas dos cosas, que **son las que el
  cliente .NET va a sufrir**.
- **Por que es ahora**: **el cliente .NET viene despues**. Un contrato con estas dos falhas
  obliga al agente a **reimplementar** el rodeo: es mas barato arreglarlo **antes** de que el
  cliente exista.

### F3-11 El selector de tienda y la configuracion por tienda completa

- **Resuelve**: (a) el **selector de tienda**: `PosContext::resolveStore()` elige **SIEMPRE
  la primera tienda** del usuario, asi que un usuario con dos tiendas **cobra siempre en la
  primera**. Y (b) la **configuracion por tienda completa**: de las **17 columnas** de
  `store_settings` solo **2 tienen puerta** en la UI (los dos umbrales de la Fase 2);
  `auto_print` y `remote_printing` las **lee** el sistema, y las demas **no las edita
  nadie**.
- **De donde sale**: `store_settings` (M04 y M47), `user_stores` y `role_store_scopes` (M05)
  ya estan, y `PosContext` ya resuelve la tienda por rol y ambito.
- **Por que es ahora**: **una columna que nadie puede cambiar son datos que nadie posee**, y
  **una tienda que no se puede elegir no es una tienda**. Y `auto_print` ya decide si el
  ticket se encola: se lee de una fila que el operador **no puede corregir**.

### F3-12 La entrega del comprobante al cliente

- **Resuelve**: la **entrega del comprobante al cliente**: por correo o por **consulta
  publica** (el QR), con el **acuse** de la entrega.
- **De donde sale**: el contrato ya tiene `deliver()` y los modelos de destino
  (`DeliveryRecipient`) y de acuse (`DeliveryReceipt`); `fiscal_documents.qr_content` y
  `qr_url` ya guardan el contenido y la URL. El proveedor local **lanza**: "el canal fiscal
  local no entrega el comprobante".
- **Por que es ahora**: **ojo: la entrega NO depende del adaptador del Portal**. Depende de
  **donde se hospeda el PDF**, y eso es **una decision nuestra**. El adaptador del Portal
  sigue **bloqueado**; **la entrega no**. Se dijo que dependia del adaptador cuando **no** es
  cierto, y esa confusion es hoy **la mas cara del plan**: bloquea una unidad que se puede
  escribir ya.

### F3-13 La i18n

- **Resuelve**: la **i18n**: el copy de la UI esta en **ingles hardcodeado** y los `errorMsg`
  en **castellano**, en la misma aplicacion.
- **De donde sale**: las tablas `languages` y `language_translations` (M25) y `number_formats`
  (M01) **existen y no se siembran ni se consultan**. Y `number_formats` es el sitio donde
  deberia vivir el formato de fecha y de numero que el renderer hoy escribe a pelo
  (`d/m/Y`, `h.i.s`).
- **Por que es ahora**: **no bloquea el uso: es calidad**. Entra **al final** de la Fase 3,
  y entra porque es **mecanica** (el 80 % es extraccion) y porque las tablas **ya estan
  pagadas**: el coste es el trabajo, no el esquema.

## Las tres decisiones del mantenedor

| # | Decision | Fecha | Estado |
|---|---|---|---|
| 1 | **El corte con el legacy**: el historico **NO se migra** | 2026-11-22 | **decidida**, falta el dia |
| 2 | **El agente LAN en .NET**: servicio nativo, fuera del corte | 2026-11-12 | **decidida**, falta una decision pequena |
| 3 | **El adaptador del Portal del SENIAT** | — | **BLOQUEADA** por documentacion externa |

### 1. El corte con el legacy

**Decidido el 2026-11-22**: el historico **NO se migra**.

- El v2 **arranca en cero**, con sus saldos de apertura **certificados**: `opening_balances`
  con `certified_at` y `certified_by`, y el comando `ops:check-opening-balances` **ya
  existen** (0.7.6) y **bloquean el arranque operativo** mientras falte la certificacion.
- El historico **se consulta en el legacy**.

**Lo que falta es el dia**: el corte es una fecha, y la fecha **no esta decidida**. Faltan
cuatro cosas, y ninguna es de codigo:

- **Quien certifica los saldos**: la direccion financiera del cliente.
- **La ventana de solo lectura del legacy**: el mapa funcional propone **30 dias**.
- **El aviso al cliente y al contador**: que a partir de la fecha el historico esta en el
  sistema viejo, y que **no se mezclan bases**.
- **El apagado del `sync.php` del legacy**: **el mismo dia** que se enciende el nuevo
  servicio, **nunca antes**.

**Lo que hay que certificar** son **cuatro familias** de saldos: **stock** por tienda y
producto; **saldo y deuda** por cliente y proveedor; **saldo de caja** por cuenta y tienda.

**Por que tiene fecha**: cada semana el historico se queda **mas solo en la base vieja**, y el
mapa funcional avisa que **sin ese acuerdo** el legacy y el nuevo muestran **stocks
distintos el primer dia** y nadie sabe cual es el bueno.

### 2. El agente LAN en .NET

**Decidido el 2026-11-12**: servicio nativo .NET, **fuera del corte 1** porque es **otro
lenguaje y otro repositorio**.

El motivo de fondo (del research del repo):

- (a) la impresion debe funcionar **aunque el servidor web este caido**;
- (b) con el servidor remoto, el agente es un **proceso de la LAN**, no del servidor web;
- (c) **desacopla** la impresion de la migracion de XAMPP;
- (d) puede vivir en **otra maquina de la LAN** (un PC en el mostrador), que es el caso real.

**Lo que el corte 1 puede y lo que no**: el corte 1 imprime **desde el servidor**
(`server_socket`): vale para impresoras **con IP alcanzable** y **no** para una impresora
**USB o privada de la caja** (el servidor en la nube no alcanza la IP privada de la tienda).
El modo `browser` (HTML) es el **unico respaldo**, y el mapa funcional lo exige como
**entregable de primera clase**, no un extra.

**La frontera ya esta cerrada**: el servidor **genera y congela** los bytes ESC/POS y el
agente **solo escribe**; el token es argon2id con rotacion y revocacion definitiva; el agente
lleva **journal local** para el "efectivamente-una-vez"; y **nunca hay USB directo**.

**Queda una decision pequena**: si el `instance_id` viaja en el **cuerpo** o en la **cabecera**.

### 3. El adaptador del Portal del SENIAT

**BLOQUEADO** por el **Manual Tecnico del art. 41** (la estructura tecnica que el emisor
remite) y por la **API del Portal**.

**Por que "no se escribe ni una linea"**: el Manual puede cambiar el payload de raiz, y
escribirlo antes es **escribirlo dos veces**.

**El dominio ya esta preparado**: el canal es **DATO** (`fiscal_providers.kind` = `printer`,
`pac` o `portal`) y **"quien asigna el control"** es una propiedad del canal
(`ProviderKind::issuerReservesControl()`, F2-04), asi que el adaptador sera **pequeno** si el
payload es el esperado.

De las piezas que la exploracion de la Fase 2 listaba como **avanzables sin el Manual**,
**casi todas estan hechas**: los permisos, el T2 con reintento, la impresion, el Z, la
auditoria y los backups.

Lo que **no** depende del Manual y se puede hacer: **la entrega del comprobante (F3-12)**,
que hoy es **la confusion mas cara del plan**, porque se dijo que dependia del adaptador
cuando **no** depende.

## Lo que NO entra en la Fase 3, y por que

| Fuera | Por que |
|---|---|
| El **adaptador del Portal del SENIAT** (~2 000 l) | **BLOQUEADO** por el **Manual Tecnico del art. 41** y la **API del SENIAT**. El Manual puede cambiar el payload de raiz, asi que **no se escribe ni una linea** antes de tenerlo en la mano |
| La **remision mensual al SENIAT** (~900 l) | depende del adaptador; las tablas (`fiscal_periodic_reports`, `fiscal_contingency_forms`) ya estan en el esquema |
| El **cliente .NET del agente LAN** (1 500 a 2 500 l, fuera de PHP) | decision tomada (.NET, servicio nativo), pero es otro lenguaje y otro repositorio: aqui **solo se cierra el contrato** |
| El **motor de reportes sin gastos** (~3 300 l) | **dependencia interna**: sin F3-07, "ingresos y gastos" daria ceros. Los reportes van **despues** de los gastos, no en paralelo |

## La regla de la cadena

- **Una unidad = un PR.** El diff muestra **solo** esa unidad. Un diff contaminado es un bug
  de base: se retargetea o se rebasa.
- **`stacked-to-main`**: cada PR se abre contra `main` **despues** de que el anterior este
  fusionado, se implementa sobre la rama del anterior y se rebasa sobre `main` tras la
  fusion.
- **Techo de 10000 lineas por PR** (decision del mantenedor del 2026-11-11). Es un
  **presupuesto de atencion, no un objetivo**: una unidad que se parte limpio se parte, y una
  que no, no. Y **ninguna unidad crece porque el techo lo permita**.
- **El test va con su unidad**: TDD estricto, el test **primero en rojo**, y cada test **en
  espanol y con el nombre que declara la regla que fija**.
- Los permisos van **declarados junto a la ruta**, el fallo es **422 con `{"errorMsg": ...}`**,
  y los **importes viajan como texto decimal**.

## Las tres decisiones del mantenedor, RESUELTAS (2026-11-23)

Lo de arriba es el contexto. Esto es lo que se decidio, y **cada decision tiene su consecuencia
escrita en el plan**:

### 1. EL CORTE CON EL LEGACY: **se decide al desplegar**

El historico **no se migra** y **la fecha no se fija ahora**: se fija el dia que el cliente
**certifique** los saldos de apertura. Por que: es lo que evita el "el legacy y el nuevo
muestran stocks distintos el primer dia y nadie sabe cual es el bueno".

**Lo que hay que tener listo ANTES de ese dia** (es la unidad **F3-14**):

- el **checklist** de los cuatro saldos que hay que certificar: stock por tienda y producto,
  saldo y deuda por cliente y proveedor, saldo de caja por cuenta y tienda;
- la **guia** de la ventana de solo lectura del legacy;
- el **material de aviso** al cliente y al contador: que a partir del corte el historico esta
  en el sistema viejo, y que **no se mezclan bases** (o se pregunta al v2, que solo tiene lo
  nuevo, o al legacy, que solo tiene lo viejo);
- el **apagado** del `sync.php` del legacy **el mismo dia** que se enciende el nuevo servicio
  de sincronizacion, **nunca antes**.

### 2. EL AGENTE LAN EN .NET: **se abre el repositorio del agente**

El cliente del agente es **otro repositorio y otro lenguaje**, asi que **no es una unidad de
este repositorio**: no esta en la Fase 3 y no puede estarlo.

Lo que este repositorio **ya tiene cerrado** y no hay que rehacer: el contrato HTTP de los
cinco endpoints, la autenticacion (argon2id, rotacion con ventana de gracia, revocacion
definitiva), el TLS obligatorio, la tienda resuelta desde el `agent_id` y nunca del cuerpo, la
frontera de los bytes (el servidor congela el ESC/POS y el agente **solo escribe**), el journal
local para el "efectivamente-una-vez", y la **prohibicion de USB directo**.

**Queda una decision pequena**, que es del cliente .NET y no de aqui: si el `instance_id`
viaja en el **cuerpo** o en la **cabecera**. El middleware del servidor cambia **antes** de
escribirlo, si hace falta.

**Y MIENTRAS TANTO**, este repositorio sigue con lo que tiene: la impresion desde el servidor
(`server_socket`, ahora programado cada minuto) y el modo `browser` como respaldo.

### 3. EL ADAPTADOR DEL PORTAL DEL SENIAT: **se pide el Manual Tecnico y la API ya**

El bloqueo es **documental**: sin el Manual Tecnico del art. 41 y la especificacion de la API
del Portal no se escribe **ni una linea** del adaptador, porque el Manual puede cambiar el
payload de raiz y escribirlo antes es escribirlo dos veces.

**La accion es pedir la documentacion**, al cliente o al PSCE, y no una unidad de codigo.

**Y MIENTRAS TANTO**, el canal en papel es un estado valido (la emision fiscal esta en la base
y el comprobante se emite), y se puede avanzar en todo lo que **no depende** del adaptador:

- la **entrega del comprobante al cliente** (**F3-12**): el `deliver()` del contrato ya existe
  y lo que falta es **donde se hospeda el PDF**, que es decision nuestra;
- la **contingencia en papel** y el **QR como imagen** (**F3-06**);
- la **remision mensual** sigue depending del adaptador, asi que sigue fuera.