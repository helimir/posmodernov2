# Exploración de la Fase 2 — qué falta para cerrar el sistema

> Exploracion de SOLO LECTURA sobre `D:\htdocs\pos-moderno-v2` (45 migraciones, 76 modelos,
> 22 controladores, 4 comandos artisan) y sobre el legacy `D:\htdocs\pos_moderno`
> (`Documentacion/mapa-funcional.md`, 1550 lineas). **No se ejecuto nada**: ni `pest`, ni `npm`,
> ni `artisan migrate`, ni `codegraph sync`.
>
> **Fecha**: 2026-11-12. **Estado del dominio**: solido y bien pensado (numeracion atomica de
> doble serie, motor de impuestos puro, FIFO con `SKIP LOCKED`, libro contable con asiento
> balanceado, caja por fecha de negocio, T2 fiscal con reintento que no quema numeros).
> **Lo que falta es TODO EL BORDE**: el cableado del cobro a la impresora, los reportes, el Z de
> fin de dia, los permisos aplicados, la auditoria, la i18n y los backups.

## Las decisiones del mantenedor (2026-11-12)

| # | Decision | Respuesta |
|---|---|---|
| 1 | Alcance de la Fase 2 | **El minimo operable** (corte 1): permisos, impresion, Z y arqueo, auditoria, backups |
| 2 | Canal fiscal | **El Portal del SENIAT** (sin intermediario: el control lo asigna el SENIAT) |
| 3 | El Z de fin de dia | **Snapshot inmutable**: el dia se sella y una venta de ayer no lo cambia |
| 4 | El agente LAN | **.NET, servicio nativo** (fuera del corte 1, pero el canal se endurece antes) |

---

## 1. El emisor fiscal contra una autoridad real

### Que hay

| Pieza | Ruta |
|---|---|
| Contrato del actor intermedio | `app/Domain/Fiscal/Contracts/ElectronicInvoiceProvider.php` (6 metodos: `submit`, `assignControl`, `issue`, `cancel`, `queryStatus`, `deliver`) |
| Proveedor local (rol dual) | `app/Domain/Fiscal/LocalPrinterProvider.php` (421 l.) |
| Servicio de emision | `app/Domain/Fiscal/FiscalIssuanceService.php` (197 l.): las 3 llamadas al proveedor **fuera** de la transaccion; la persistencia **dentro** |
| Coordinador | `app/Domain/Fiscal/FiscalIssuanceCoordinator.php` (513 l.): idempotente, reutiliza la reserva |
| Resolucion del canal | `app/Domain/Fiscal/FiscalProviderResolver.php` (207 l.): serie -> perfil -> entorno -> proveedor -> implementacion por `kind` |
| Enums | `ProviderKind` (`printer`\|`pac`\|`portal`), `EnvironmentRole` (`issuer`\|`printer`) |
| Tablas | `fiscal_providers` (`base_url`, `auth_scheme`, `credentials_ref`), `fiscal_profile_environments` (`role`, `provider_id`, `is_testing`, `is_production`) |
| Traza append-only | `fiscal_document_events` bloqueada con trigger de PostgreSQL |
| Research | `research/evidence/senat-venezuela.md` (245 l.): transcripcion de F1 (SNAT/2026/00093) y F2 (SNAT/2024/000102) |

### Que falta

1. **BLOQUEANTE: el Manual Tecnico del art. 41 (O1)** define la estructura que el emisor remite.
   Sin el no existe el payload. Y **la API del SENIAT (O4)**. Con el canal elegido (Portal), **O6
   desaparece**: no hay que contratar a nadie.
2. **El emisor no tiene identificativo fiscal**: `store_fiscal_registrations` **no se siembra** en
   ninguna parte, asi que el comprobante sale con el RIF del emisor en `null` (el art. 8.4 lo exige).
3. **Faltan tres tokens del perfil** que el propio codigo ya lee:
   - `provision_reference` — el comprobante **no dice "conforme a la providencia"** (art. 19).
   - `final_consumer_name` — una venta **sin cliente no tiene receptor** (art. 8.6).
   - `qr_required_tokens` — el proveedor local no emite QR (correcto: el QR no es requisito legal).
4. **`fiscal_providers.credentials_ref` es texto libre y no se resuelve**: no hay `Env::get()`
   equivalente. Compárese con `SmsProvider::credential()` (`app/Models/SmsProvider.php:33`), que sí
   resuelve. **`credential_env_prefix` no existe en la tabla fiscal** (solo en `sms_providers`).
5. **El vocabulario nativo de estados esta vacio de verdad**: `FiscalProfileSeeder:977-1002` mapea
   `draft|issued|void` a si mismo.
6. **`ContingencyService` (116 l.) y `DerivedDocumentService` existen y NO tienen llamador**:
   la contingencia en papel y las notas de credito/debito estan **modeladas pero no accesibles**.
7. **La entrega al cliente (correo o consulta publica, art. 18.9/18.10) no existe**: `deliver()`
   del proveedor local **lanza**.

### Consecuencia de la decision del Portal (2)

Con el Portal, **el control lo asigna el SENIAT, no el emisor**. Hoy `CheckoutService` **reserva
control localmente** (paso 3) y el proveedor reutiliza esa reserva. Con un canal externo esa
reserva sobra, y el modelo "la venta reserva el control" **debe cambiar antes** de que llegue el
adaptador: si no, habra que rehacer el checkout.

---

## 2. La impresion de punta a punta

### Que hay

| Pieza | Ruta |
|---|---|
| Renderer ESC/POS | `app/Print/EscposRenderer.php` (257 l.): bytes en el **servidor**, perfil y codepagina son **dato del destino**, corte completo `GS V 65` |
| Modelo del ticket | `app/Print/PrintPayload.php`, `app/Print/ReceiptPrintPayload.php` (el puente fiscal <-> ticket: mismo control, mismo total, mismo QR) |
| Congelamiento | `app/Print/PrintJobDispatcher.php`: los bytes se generan **antes** de escribir el trabajo |
| Maquina de estados | `app/Print/PrintJobStateMachine.php` (206 l.): transiciones atomicas |
| Reimpresion | `app/Print/PrintJobReprinter.php` (94 l.) |
| Contrato del agente | `app/Http/Controllers/PrintAgentController.php` (500 l.): registro con secreto, long-poll <=30 s, resultado idempotente, rotacion de token |
| Esquema | `print_jobs`, `print_targets` (CHECK que **prohibe USB directo** y admite `tcp://host:9100`, `smb://` o UNC), `printers`, `print_agents` |

### Que falta

1. **El tramo "el POS cobra" -> "hay un trabajo en la cola" NO EXISTE**: `CheckoutService` no toca
   `print_jobs`. El unico productor es `ReceiptController::imprimir()`, que exige que un humano
   abra el comprobante y pulse "imprimir".
2. **El transporte `server_socket` NO EXISTE**: el esquema lo admite y **no hay ni un
   `fsockopen` ni un `stream_socket_client` en `app/`**. Un destino `server_socket` es un trabajo que
   se encola y **nunca sale**.
3. **El cliente del agente LAN no existe** (es la unidad .NET, decision 4).

### Los diez defectos del canal (lado servidor, que si existe)

| # | Defecto |
|---|---|
| S1 | El token se guarda con **SHA-256 plano**, no argon2id/bcrypt (`PrintAgentController.php:137,146`) |
| S2 | **No hay `instance_id`**: no se detecta un segundo agente con el mismo token |
| S3 | **No hay revocacion** (`status='revoked'`) |
| S4 | **No se exige TLS** en ningun punto, y el payload lleva datos fiscales |
| S5 | El **heartbeat ignora el cuerpo**: el agente no puede inventariar sus impresoras ni el servidor autorizar el destino |
| S6 | La **rotacion no tiene ventana de gracia** (`token_valid_until`) |
| S7 | **`releaseExpiredLeases()` no tiene consumidor**: un trabajo con lease caducado queda bloqueado para siempre |
| S8 | **`PrintJobReprinter` no tiene ruta HTTP** |
| S9 | `print_agents` sin `instance_id`; `char_per_line` legacy no migrado |
| S10 | `PrintJobDispatcher::dispatch()` **no admite `reason` ni `reprint_of_job_id`** |

### Se puede probar sin impresora fisica

**Si, en el 80%.** `EscposRenderer` escribe a `BufferPrintConnector` (buffer en memoria): los bytes
se pueden asertar exactamente. El lado agente se prueba con un cliente HTTP falso. Lo que no se
puede probar sin hardware es la escritura real en TCP 9100: se verifica contra un socket sink
(`nc -l 9100`).

---

## 3. Los reportes

### Que hay

`CashDay` (240 l., el derivado de la caja del dia), `StockReportController` (stock **real**, no el
cache), `SalesController` (**un "recentro" de 50**, no un reporte por periodo). El legacy tenia 21
pantallas de reporte, todas con filtro `from`/`to` y botones DataTables Print/Copy/Excel/CSV/PDF.

### Que falta (el resumen)

| # | Reporte | De donde sale el dato |
|---|---|---|
| R1 | **Z de fin de dia** (cierre) | `sales` + `sale_totals` + `sale_payments` + `ledger_lines` + `fiscal_documents` |
| R2 | **Arqueo por medio de pago Y por denominacion** | falta el catalogo de denominaciones (billetes y monedas) |
| R3 | **Ventas por periodo** | `sales` + `sale_totals` (hoy la lista es de 50 sin filtro de fecha) |
| R4 | Ventas por producto / categoria / proveedor | `sale_items` + el costo FIFO de `inventory_lots` |
| R5 | Ventas por cajero | `sales.created_by` |
| R6 | Impuestos por alicuota | `sale_items.tax_rate_snapshot` + `tax_amount` |
| R7 | **Ingresos y gastos por mes** | **el dato NO EXISTE**: solo se escriben 2 de los 7 tipos de asiento (`sale`, `sale_payment`); `expense`, `bank_movement`, `refund`, `adjustment` y `opening_balance` **nunca** se escriben |
| R8 | Perdidas y ganancias | depende de R7 |
| R9 | Libro de caja | `ledger_lines` (cuenta de efectivo) + `cash_registers` |
| R10 | Cartera de clientes (aging) | parcial: hay saldo derivado, no hay aging |
| R11 | Deuda a proveedores | **no hay modulo de compras** |
| R12 | Inventario valorado | parcial: hay stock bajo, no hay valoracion ni rotaciones |
| R13 | Margen por producto | costo FIFO consumido vs precio cobrado |
| R15 | Auditoria fiscal | hay consulta puntual por venta, no listado |

**El hallazgo de fondo**: **R7/R8/R9 no se pueden construir sin el modulo de gastos y de banca.**
`expenses`, `expense_categories` e `income_sources` estan en el esquema (M19, M32) y **vacios**.

---

## 4. El cierre de caja y el arqueo

### Que hay

`CashRegisterService` (abrir/cerrar, atomico), `CashDay` (las cuatro cifras: partida **dato**,
en curso **derivado**, esperado, contado **dato**, descuadre con signo), `CashDayController` +
`cash/day.tsx` (tres estados), y el gatillo **"no se cobra sin caja ABIERTA"** comprobado dentro
de la transaccion de la venta.

### Que falta

1. **No hay Z de fin de dia (informe Z).** El cierre guarda `closing_balance` y el descuadre se
   **calcula en vivo**: si manana se corrige una venta de ayer, el "cierre" de ayer **cambia**.
2. **No hay arqueo por denominacion**: el desglose por medio existe, pero el cajero cuenta
   **billetes y monedas**, y no hay catalogo de denominaciones.
3. **No hay motivo ni firma del descuadre**: `cash_registers.note` existe y **nadie lo lee**.
4. **La apertura no deja asiento contable**: el tipo `opening_balance` existe en el catalogo y
   **nunca se escribe**. El saldo de partida es un dato suelto: el libro **no cuadra con la caja**.
5. **No hay Z imprimible**, ni cierre por turno, ni **reversion de un cierre** (`close()` es
   irreversible y no hay `reopen()`).

### El orden importa

El **asiento de apertura (C3) cambia el saldo en curso**: si la partida entra al libro,
`esperado = partida + en curso` **cuadra dos veces**. Va **antes** que el snapshot, y
`esperado` pasa a ser **solo el saldo en curso**.

---

## 5. Lo que falta para produccion

### Permisos y roles — el hallazgo mas grave

**El esquema esta normalizado y es bueno** (`roles`, `permissions` con `slug` UNIQUE, `role_permissions`,
`role_store_scopes`, `login_logs`, `dashboard_shortcuts`). Y **ningun codigo lo lee**:

- grep de `app/` por `permissions|role_permissions|can(|hasPermission|Gate::` → **10 coincidencias,
  todas de definiciones de modelo**. **No hay `Gate`, ni `Policy`, ni middleware de permiso, ni
  `$this->authorize()`.**
- **No hay permisos sembradas**, ni roles, ni `role_permissions`.
- **No hay usuarios sembrados**: el `User::factory(10)` esta **comentado**; solo existe
  `test@example.com`.
- **El modelo `User` no tiene ni un metodo de autorizacion** (62 lineas en total).

**Consecuencia**: **hoy cualquier usuario autenticado hace todo**. Un cajero puede abrir la caja
del dia, **cerrar la caja de otro usuario**, editar el plan de cuentas y ver ventas ajenas. Y sin
matriz de permisos **no hay ni el catalogo de lo que hay que proteger**.

**Como lo hacia el legacy**: `user_group_id() == 1` → superusuario; si no, `has_permission('access',
'<slug>')` sobre un blob serializado con ~190 slugs en 24 grupos (`mapa-funcional.md:895-957`). **El
defecto del legacy a NO heredar**: las columnas de accion se ocultan por permiso en el HTML, pero
los formatters PHP **no comprueban el permiso** → boton visible que devuelve 422.

### Auditoria

`audit_logs` (M26) existe con `user_id`, `store_id`, `action`, `entity_type`, `entity_id`,
`context` jsonb, `ip_address`. **Un solo escritor**: `FiscalIssuanceRunner::auditaElFallo()`, que
escribe `action='fiscal_issuance_failed'` con el `ip_address` en `null`. **De 40+ acciones auditables
se audita una.** Y `audit_logs` **no es append-only** (si lo es `fiscal_document_events`, con trigger).

### i18n

`languages` y `language_translations` existen; **no hay seleccion de idioma** (cero llamadas a
`setLocale()` o `trans()` fuera del scaffolding), **no hay middleware de locale**, **las tablas no se
siembran**, y **todas las cadenas estan en espanol hardcodeado** en PHP y en TSX. `number_formats`
existe y **el renderer nunca lo consulta**: escribe `d/m/Y` y `h.i.s` a pelo.

### Backups, configuracion por tienda y multi-pais

- **No hay backups**: cero coincidencias de `backup` en `app/`, `config/` y `database/`. El legacy
  tenia `ModelCron::DBBackup()` y `admin/backup_restore.php`.
- **No hay ninguna pantalla de tienda**: `resources/js/pages/settings/` tiene `profile`, `password` y
  `appearance`, que son del **usuario**. `store_settings.auto_print` y `remote_printing` **no los lee
  nadie**.
- **No hay selector de tienda**: un usuario con dos tiendas cobra siempre en la primera.
- Multi-pais: la arquitectura esta muy bien (todo como datos, test anti-pais), pero **CL/MX/CO/PA no
  tienen proveedor** y sus series son de demostracion. **Hoy solo VE es semanticamente completo.**

---

## Los riesgos

| # | Riesgo | Se rompe | Mitigacion |
|---|---|---|---|
| R1 | El Manual Tecnico (O1) cambia el payload | todo el adaptador (~2 000 l) | escribirlo **al final**, no antes |
| R2 | Con canal externo, el control lo asigna la autoridad y hay que **revertir** "la venta reserva el control" | `ControlNumberGenerator`, `CheckoutService`, `LocalPrinterProvider` | decidir **antes** del adaptador |
| R3 | El asiento de apertura rompe el saldo en curso | `CashDay::runningBalance`, `expected` | el asiento **antes** que el snapshot; `esperado = solo en curso`; test de arqueo |
| R4 | Permisos aplicados rompen pantallas que hoy funcionan | ~30 rutas | aplicar por ruta **nuevas primero**; definir los roles **antes** de los middleware; probar cada ruta con cada rol |
| R5 | Tocar `sales.status` choca con el enum fiscal | `fiscal_failed` ya pisa el estado de pago | **no tocar el enum**: derivar el estado de pago de `sale_payments` en la lectura |
| R6 | El "ticket mudo": bytes que la impresora no soporta | el cajero recibe un ticket ilegible | `EscposRenderer` ya falla si el QR no cabe; **falta validar el ancho** contra el contenido |
| R7 | El agente duplica tickets si el resultado no llega | doble ticket = doble evidencia | journal local en el agente |
| R8 | El Z se sella con una aritmetica que luego cambia | el Z de ayer deja de cuadrar | snapshot **y** politica de correccion: una venta de un dia cerrado genera **una nota**, no una edicion |
| R9 | **El corte con el legacy no esta planificado en ninguna unidad** | el historico queda en otra base | la decision 9 del mapa ("sustitucion, no convivencia") implica corte con exportacion e importacion |
| R10 | `opening_balances` sin certificar | el saldo de partida es un numero sin dueno | **acto humano** (`certified_at` + `certified_by`): "no code can close that" |
| R11 | Multi-pais "sembrado" en apariencia | CL/MX/CO/PA no funcionan | no anunciarlo hasta que cada perfil tenga proveedor, tokens y vocabulario |
| R12 | El `NoCountryLiteralsTest` es un proxy debil | un fork por pais metido en `app/Providers/` no lo detecta | **crear `app/Providers/Fiscal/`** y extender el test a verificar la ruta |
| R13 | El rango de control es de demostracion | un rango real agotado = ventas sin comprobante | `NumberSeries` ya falla con mensaje explicito; falta la **alerta** de rango agotado |
| R14 | El long-poll con muchos agentes | N procesos PHP bloqueados 30 s | con PHP-FPM y pocos terminales aguanta; con 50 cajas se queda sin workers |
| R15 | `legacy` y `v2` con datos distintos y el mismo cliente | el contador mezcla dos bases | decidir el corte **antes** de la Fase 2 |

## Lo que NO se sabe y no se afirma

- Cuantas filas tiene `countries`/`currencies`/`number_formats` sembradas (el `TaxonomySeeder` no se
  leyo linea a linea).
- El estado real de la base de datos de esta maquina: **no se ejecuto nada** contra PostgreSQL.
- Si el Manual Tecnico del art. 41 se ha conseguido desde la revision del research del 2026-10-30.
  Lo ultimo que dice el repo es que **no se ha alcanzado**.
- El conteo de "21 pantallas de reporte" del legacy: la tabla lista **20** filas (la 21a,
  `report_pos_overview.php`, esta marcada como huerfana).
