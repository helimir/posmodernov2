# POS Moderno v2

El sistema de punto de venta, rehecho desde cero sobre el dominio del legacy
(`mapa-funcional.md`) en lugar de parcheado. Laravel 12 + Inertia 2 + React 19
+ Tailwind 4 sobre **PostgreSQL 17**, con Redis 7 para el sync del terminal
legacy.

La diferencia de fondo con el legacy no es el framework: es que **el motor
fiscal, el de impuestos, el de numeración y el de formatos son DATO**. Cambiar
de país es insertar filas, no bifurcar código.

## Requisitos

| Herramienta | Versión |
|---|---|
| PHP | 8.2 o superior (con `bcmath` y `redis`) |
| Composer | 2 |
| Node.js | 20 o superior |
| PostgreSQL | 17 |
| Redis | 7 |

## Puesta en marcha

```bash
composer install
npm ci
npm run build                     # genera public/build/manifest.json

cp .env.example .env
php artisan key:generate

php artisan migrate:fresh --seed
```

`migrate:fresh --seed` es el camino de instalación: el esquema lo dan las
migraciones y los datos los seeders. Los seeders son idempotentes: sembrar dos
veces no duplica ninguna fila.

### Credenciales de la semilla

| Correo | Contraseña | Rol |
|---|---|---|
| `test@example.com` | `password` | Superuser (todo) |
| `cashier@example.com` | `password` | Cajero (el mostrador, con ámbito en la tienda `DEMO`) |

La semilla deja además una tienda de demostración (`code_name = 'DEMO'`), los
perfiles fiscales de los países sembrados, el catálogo de tipos de asiento, el
mapa de traducción del terminal legacy y el saldo de apertura de caja **sin
certificar** (la certificación es un acto humano, no un dato).

### Los permisos y los roles

El **catálogo de permisos** vive en un solo sitio, en código:
`app/Domain/Auth/Enums/Permission.php`. Un permiso que no está en el catálogo
no existe, y uno que está existe para todos. El catálogo son 28 permisos en 11
módulos, con la forma `{modulo}.{accion}`.

El permiso de una ruta se declara **junto a la ruta**
(`Route::middleware('permission:sales.read')`), y lo comprueba el servidor: el
botón que se oculta en la interfaz es una cortesía, y el permiso es la ley.

Los **tres roles por defecto** son un contrato, y los siembra
`PermissionSeeder`:

| Rol | Puede |
|---|---|
| `superuser` | todo, sin excepción (lo concede el `Gate::before`, por el slug de su rol) |
| `cashier` | la caja, la caja del día (abrir, ver y cerrar), las ventas, los clientes y el catálogo en lectura |
| `accountant` | las ventas y los clientes en lectura, la contabilidad en lectura y los reportes |

Un usuario **sin rol** no puede hacer nada. La pantalla de administración
(`/usuarios` y `/roles`) asigna el rol de una persona, los permisos de un rol
y su ámbito de tiendas, y **todo cambio queda auditado** en `audit_logs` con
su usuario y su IP.

## La puerta de calidad

```bash
npm run build                     # precondición de la suite
vendor/bin/pint --test            # lint de PHP
npm run lint:check                # lint de JS (no muta: `npm run lint` sí)
npm run typecheck                 # typecheck
./vendor/bin/pest                 # la suite del dominio
```

La suite corre contra PostgreSQL (`pos_moderno_v2_test` por defecto, lo fija
`phpunit.xml`). El build precede a la suite: las pruebas de página necesitan el
manifiesto de Vite.

## Interruptores de módulo

Todos **apagados** por defecto: cada módulo se enciende cuando su canal está
validado, y apagar un módulo nunca renumera lo ya emitido.

| Variable | Qué apaga o enciende |
|---|---|
| `FISCAL_EMISSION_ENABLED` | La emisión fiscal. Apagada hasta validar el canal del país. |
| `SYNC_ENABLED` | El protocolo de sync v2 (el maestro acepta lotes). |
| `SYNC_LEGACY_ADAPTER_ENABLED` | El adaptador del terminal legacy (traduce y aplica lotes). |
| `PRINT_LAN_AGENT_ENABLED` | El agente de impresión LAN. |

Los interruptores se leen de `config/fiscal.php`, `config/sync.php` y
`config/print.php`.

## Las decisiones

Cada decisión estructural de la fase tiene su ADR en `docs/adr/`, con la
decisión, su motivo y sus consecuencias:

1. `0001` Numeración fiscal de dos series con reserva atómica.
2. `0002` Actor intermedio: la imprenta digital asigna el número de control.
3. `0003` La capa de país y fiscal como datos.
4. `0004` Stock por lotes, sin estado mutable.
5. `0005` Los bytes ESC/POS se generan en el servidor.
6. `0006` El adaptador del sync legacy conserva el contrato de wire v2.

## La suite

Los tests son la documentación de las reglas de negocio: cada test se llama
como la regla que fija, y hay un test por regla. `tests/Architecture/` sostiene
las reglas que miran el propio repositorio (el escáner anti-país, el barrido de
los nombres canónicos y la cobertura de la suite).

## Ver el avance: la costura

El dominio esta completo pero **sin interfaz no se ve**. La costura lo hace visible.

### La venta de prueba, por dentro

```bash
php artisan migrate:fresh --seed   # sobre la base de datos de desarrollo
php artisan ops:smoke-sale
```

El comando monta el escenario (tienda, perfil fiscal, producto, compra y lote), ejecuta un **checkout real** e imprime la anatomia de la venta: la cabecera con sus dos numeros (documento y control), las lineas con el snapshot de la tasa, los totales con el **ajuste de redondeo**, el pago, el **asiento contable** (con la comprobacion de que cuadra), las reservas y como bajo el stock. Las comprobaciones finales **son el codigo de salida**: si una cifra no cuadra, el comando sale con codigo distinto de cero.

### La emision fiscal: el interruptor y el reintento

La emision fiscal (T2) esta **conectada al cobro** y desligada por un
interruptor:

```bash
FISCAL_EMISSION_ENABLED=true    # el interruptor de modulo
FISCAL_ENVIRONMENT=testing      # el despliegue: testing | production
```

Con el interruptor **apagado**, el cobro no entra en la emision: la venta sale
sin documento fiscal, y la respuesta lo dice (`fiscal.status` =
`not_emitted`). Con el interruptor **encendido**, el cobro emite el
comprobante **fuera** de la transaccion de la venta (design D4, decision C5),
y la respuesta lleva el estado fiscal: `issued` (con su numero de control),
`failed` (con el motivo) o `not_emitted`.

Un fallo de emision **no pierde la venta**: la venta queda en
`fiscal_failed`, con su reserva intacta, y el reintento la vuelve a emitir
sin quemar numeros:

```bash
php artisan ops:emit-fiscal               # todas las pendientes
php artisan ops:emit-fiscal --sale=42     # una concreta
```

El canal fiscal se **resuelve**, no se fija: perfil fiscal -> entorno ->
proveedor -> implementacion, segun el `kind` del proveedor. El unico canal
sembrado hoy es el **local** (`printer`), que es el de rol dual: el sistema
es emisor E imprenta, y el numero de control lo genera el generador local.
Un canal externo (`pac`, `portal`) da un error claro: no hay actor al que
conectarse.

### Las ventas en el navegador
| Ruta | Que se ve |
|---|---|
| `/ventas` | la lista de ventas: numero, fecha de negocio, cliente, estado y total |
| `/ventas/{id}` | el detalle: cabecera, lineas, totales, pagos y el asiento contable |

Ambas exigen sesion (`auth`). Con la lista vacia, la pagina sugiere el comando.

### Lo que falta para verlo en produccion

- Un **VirtualHost** que apunte a `public/` (en desarrollo, `php artisan serve` sirve).
- `npm ci && npm run build` en el despliegue (el manifiesto se regenera).
- La base de datos **migrada y sembrada**: `php artisan migrate --seed`.
- Usuarios reales y el `APP_KEY` del entorno.
