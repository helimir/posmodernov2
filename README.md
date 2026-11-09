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

| | |
|---|---|
| Correo | `test@example.com` |
| Contraseña | `password` |

La semilla deja además una tienda de demostración (`code_name = 'DEMO'`), los
perfiles fiscales de los países sembrados, el catálogo de tipos de asiento, el
mapa de traducción del terminal legacy y el saldo de apertura de caja **sin
certificar** (la certificación es un acto humano, no un dato).

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
