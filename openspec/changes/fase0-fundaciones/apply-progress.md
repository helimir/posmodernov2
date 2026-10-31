# Apply progress — fase0-fundaciones

**Change**: `fase0-fundaciones` · **Work unit**: PR1-ciimientos-a · **Branch**: `feature/fase0-01-ciimientos-a`
**Mode**: Strict TDD (`strict_tdd: true`, runner `./vendor/bin/pest` sobre PostgreSQL 17 `pos_moderno_v2_test`)
**Chain strategy**: `stacked-to-main` · **Presupuesto**: 800 líneas (dentro: 797 authored)

## Estado

| Tarea | Estado | Nota |
|---|---|---|
| 0.1.1 Dependencias de dominio | ✅ | `brick/math` ^0.14.8, `mike42/escpos-php` ^5.0 |
| 0.1.1-T | ✅ | `tests/Unit/DomainDependenciesTest.php` |
| 0.1.2 Segunda conexión `pgsql_b` | ✅ | `config/database.php` |
| 0.1.2-T | ✅ | `tests/Feature/Database/SecondConnectionTest.php` |
| 0.1.3 Interruptores de módulo | ✅ | `config/fiscal.php`, `config/sync.php`, `config/print.php`, `.env.example` |
| 0.1.3-T | ✅ | `tests/Feature/Domain/ModuleSwitchesTest.php` |
| 0.1.4 Migrations M04 + M05 | ✅ | `stores`/`store_settings`/`store_fiscal_registrations` · `roles`/`permissions`/`role_permissions`/`role_store_scopes`/`login_logs`/`dashboard_shortcuts` |
| 0.1.4-T | ✅ | `tests/Feature/Domain/TypedStoreAndPermissionTablesTest.php` |
| 0.1.5 Migrations M06 + M07 | ✅ | edición de la migración base de `users` + `password_reset_tokens`/`user_settings` + `settings` |
| 0.1.5-T | ✅ | `tests/Feature/Domain/PasswordAndTypedSettingsTest.php` |
| 0.1.6 M25 + M26 | ⬜ | **PR2** — fuera de esta unidad |
| 0.1.6-T | ⬜ | **PR2** — fuera de esta unidad |
| 0.1.7 Modelos base | ◐ **parcial** | 8 modelos base + `User` entregados; **se completa en el PR2** con los 5 modelos de i18n/outbox (`Language`, `LanguageTranslation`, `SmsMessage`, `SmsProvider`, `MessageTemplate`), cuyas tablas son M25–M26 |
| 0.1.7-T | ✅ | `tests/Feature/Domain/ModelCastsTest.php` |

**11 de 152 tareas del change completadas.** 0.1.7 queda abierta a propósito: el corte es el que fija la tabla §Cadena de entrega (PR1 = 8 modelos; PR2 = i18n/outbox/auditoría).

## TDD Cycle Evidence

| Tarea | RED (test escrito primero) | GREEN (implementación) | REFACTOR |
|---|---|---|---|
| 0.1.1-T | ❌ `Failed asserting that false is true` (BigDecimal y Printer ausentes) | ✅ `composer require brick/math mike42/escpos-php` → 1 passed (3 assertions) | — |
| 0.1.2-T | ❌ `Failed asserting that null is identical to 'pos_moderno_v2_test'` | ✅ `pgsql_b` en `config/database.php` → 1 passed (2 assertions) | — |
| 0.1.3-T | ❌ `BindingResolutionException: Target class [config] does not exist` | ✅ 3 configs + `.env.example` → 1 passed (3 assertions) | el test se movió de `tests/Unit` a `tests/Feature`: `tests/Unit` no arranca la app (ver *Desviaciones*) |
| 0.1.4-T | ❌ `Failed asserting that true is true` (`store_settings` inexistente) | ✅ M04 + M05 → 1 passed (21 assertions) | — |
| 0.1.5-T | ❌ `Failed asserting that false is true` (`user_settings` inexistente) | ✅ M06 + M07 + edición de `users` → 1 passed (16 assertions) | — |
| 0.1.7-T | ❌ `Failed asserting that null is an instance of HasOne` | ✅ 8 modelos + `User` → 1 passed (33 assertions) | la aserción de relación pasó de `$model->rel` a `$model->rel()` (ver *Desviaciones*) |

## Work Unit Evidence

| Evidencia | Valor |
|---|---|
| Test focalizado | `php vendor/bin/pest --filter='dependencias_de_decimal_exacto\|segunda_conexion_pgsql_b\|interruptores_de_modulo\|tablas_de_tienda_y_de_permisos\|contrasenas_se_guardan_con_hash\|modelos_declaran_sus_casts'` → **6 passed (78 assertions)**; suite completa `./vendor/bin/pest` → **33 passed (142 assertions)** |
| Harness de runtime | `php artisan migrate:fresh --force` sobre PostgreSQL 17 → **7 migraciones en verde** (0001×3 + M04–M07) |
| Frontera de rollback | `git revert` del commit + `php artisan migrate:fresh`. Sin datos de producción: todo el esquema se reconstruye. Reversible sin afectar a PR2–PR16 (M04–M07 son la base de la cadena). |
| Puertas de calidad | `vendor/bin/pint --test` → **8 ficheros preexistentes** fuera de esta unidad (idéntico a `main`); `npm run lint:check` → limpio; `npm run typecheck` → limpio; `public/build/manifest.json` presente |

## Ficheros

| Fichero | Acción | Qué hace |
|---|---|---|
| `composer.json` / `composer.lock` | Modificado | `brick/math` (decimal exacto en PHP puro) + `mike42/escpos-php` (ADR-0005) |
| `config/database.php` | Modificado | Segunda sesión PostgreSQL `pgsql_b` sobre la misma base (design §E.1) |
| `config/fiscal.php`, `config/sync.php`, `config/print.php` | Creado | Interruptores de módulo, los tres en `false` por defecto |
| `.env.example` | Modificado | Declara los tres interruptores |
| `database/migrations/0001_01_01_000000_create_users_table.php` | Modificado | D18: se edita la migración base (`group_id`, columnas del legacy, `raw_password`/`preference`/`pass_reset_code`/`reset_code_time` **no** existen) |
| `database/migrations/2026_11_01_000004_create_stores_table.php` | Creado | M04: `stores` sin blob + `store_settings` tipada + `store_fiscal_registrations` |
| `database/migrations/2026_11_01_000005_create_roles_permissions_tables.php` | Creado | M05: `roles`, `permissions`, `role_permissions`, `role_store_scopes`, `login_logs`, `dashboard_shortcuts` |
| `database/migrations/2026_11_01_000006_edit_users_table_add_user_settings.php` | Creado | M06: `password_reset_tokens`, `user_settings`, FK `users.group_id → roles.id` |
| `database/migrations/2026_11_01_000007_create_settings_table.php` | Creado | M07: ajustes globales tipados `key`/`value`/`type` |
| `app/Models/{Store,StoreSetting,StoreFiscalRegistration,Role,Permission,Setting,UserSetting,AuditLog}.php` | Creado | Modelos base con casts exactos y relaciones con FK |
| `app/Models/User.php` | Modificado | `hashed`, sin `raw_password`/`preference`, `group_id → roles` |
| `tests/Unit/DomainDependenciesTest.php` | Creado | 0.1.1-T |
| `tests/Feature/Database/SecondConnectionTest.php` | Creado | 0.1.2-T |
| `tests/Feature/Domain/ModuleSwitchesTest.php` | Creado | 0.1.3-T |
| `tests/Feature/Domain/TypedStoreAndPermissionTablesTest.php` | Creado | 0.1.4-T |
| `tests/Feature/Domain/PasswordAndTypedSettingsTest.php` | Creado | 0.1.5-T |
| `tests/Feature/Domain/ModelCastsTest.php` | Creado | 0.1.7-T |

## Desviaciones del diseño

1. **FK `stores.country_id → countries.id` diferida a M27.** `countries` la crea M01, que aterriza en el PR3; en el orden final de migraciones M01 corre **antes** que M04, pero M01 no puede añadir una FK a `stores` (la tabla aún no existe). M04 crea la columna indexada y la restricción se añade en M27 (`add_domain_indexes_and_constraints`), que el propio diseño designa como la migración de FKs tardías. Sin esta diferencia, `migrate:fresh` del PR1 fallaría.
2. **FK `users.group_id → roles.id` añadida en M06, no en la migración base.** La columna se crea en la migración base de `users` (D18) y la restricción en M06, que es la primera migración que corre después de M05 (`roles`). La migración base no puede declarar la FK: `roles` no existe todavía.
3. **`password_reset_tokens` se crea en M06** y se retira de la migración base de `users`, como fija el diseño (M06 «crea `password_reset_tokens`, `user_settings`»). Se conserva la forma de Laravel (`email` PK, `token`, `created_at`) para no romper `PasswordResetTest`.
4. **La aserción de relación usa `$model->rel()` y no `$model->rel`.** En Laravel 12, `getRelationshipFromMethod()` devuelve el **resultado** de la consulta, no la instancia de `Relation`; el objeto de relación se obtiene del método. Corregido tras el primer RED.
5. **0.1.7 queda parcial.** El corte PR1/PR2 lo fija la tabla §Cadena de entrega del propio plan (PR1 = 8 modelos; PR2 = M25–M26 + 6 modelos). No se ha estirado el alcance ni inventado las tablas de M25–M26.

## Fuera de esta unidad (no implementado)

- **0.1.6 / 0.1.6-T** (M25–M26) → **PR2**.
- **0.9.5 / 0.9.5-T** (`test_el_arnes_de_concurrencia_deja_la_base_limpia_y_la_segunda_conexion_apunta_a_la_misma_base`) → **PR15**. La fase 0.9 está explícitamente fuera del alcance de esta work unit; 0.1.2 deja lista la conexión `pgsql_b` que el arnés necesita.
- **0.1.7 (resto)**: los 5 modelos de i18n/outbox → **PR2**.
- Todo `0.2.*`–`0.9.*` y `T1–T3`.

## Hallazgos

- `vendor/bin/pint --test` **ya fallaba en `main`** con 9 ficheros del esqueleto de Laravel (`NewPasswordController`, `PasswordResetLinkController`, `RegisteredUserController`, `VerifyEmailController`, `LoginRequest`, `bootstrap/providers.php`, `config/auth.php`, `UserFactory`, `app/Models/User.php`). Los 8 restantes son preexistentes y ajenos a esta unidad: **no se han tocado**. `app/Models/User.php` sí estaba en la lista y se ha corregido (fichero de esta unidad): la lista baja de 9 a 8.
- `tests/Unit` **no arranca la aplicación** en este proyecto (`tests/Pest.php` sólo extiende `TestCase` + `RefreshDatabase` en `Feature`). Cualquier test que necesite `config()` o la BD va en `tests/Feature`.
- El nombre canónico de **0.1.7-T** en `tasks.md` L156 es `test_los_modelos_declaran_sus_casts_de_importes_y_de_json`, no `test_las_tablas_de_tienda_y_de_permisos_no_guardan_blobs_php_serializados` (ese es el de 0.1.4-T). Se implementó el canónico de `tasks.md`.

## Siguiente unidad

**PR2-ciimientos-b** (`feature/fase0-02-ciimientos-b`): M25–M26 + los 6 modelos de i18n/outbox/auditoría, cierra 0.1.6, 0.1.6-T y 0.1.7.
