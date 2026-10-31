# Apply progress — fase0-fundaciones

**Change**: `fase0-fundaciones` · **Work unit**: PR2-ciimientos-b · **Branch**: `feat/fase0-02-ciimientos-b`
**Mode**: Strict TDD (`strict_tdd: true`, runner `./vendor/bin/pest` sobre PostgreSQL 17 `pos_moderno_v2_test`)
**Chain strategy**: `stacked-to-main` · **Presupuesto**: 800 líneas por PR

## Estado

| Tarea | Estado | Nota |
|---|---|---|
| 0.1.1 Dependencias de dominio | ✅ | `brick/math` ^0.14.8, `mike42/escpos-php` ^5.0 (PR1) |
| 0.1.1-T | ✅ | `tests/Unit/DomainDependenciesTest.php` |
| 0.1.2 Segunda conexión `pgsql_b` | ✅ | `config/database.php` (PR1) |
| 0.1.2-T | ✅ | `tests/Feature/Database/SecondConnectionTest.php` |
| 0.1.3 Interruptores de módulo | ✅ | `config/fiscal.php`, `config/sync.php`, `config/print.php`, `.env.example` (PR1) |
| 0.1.3-T | ✅ | `tests/Feature/Domain/ModuleSwitchesTest.php` |
| 0.1.4 Migrations M04 + M05 | ✅ | `stores`/`store_settings`/`store_fiscal_registrations` · `roles`/`permissions`/`role_permissions`/`role_store_scopes`/`login_logs`/`dashboard_shortcuts` (PR1) |
| 0.1.4-T | ✅ | `tests/Feature/Domain/TypedStoreAndPermissionTablesTest.php` |
| 0.1.5 Migrations M06 + M07 | ✅ | edición de la migración base de `users` + `password_reset_tokens`/`user_settings` + `settings` (PR1) |
| 0.1.5-T | ✅ | `tests/Feature/Domain/PasswordAndTypedSettingsTest.php` |
| 0.1.6 M25 + M26 | ✅ | M25: `languages`/`language_translations`/`sms_providers`/`message_templates`/`sms_messages` (outbox) · M26: `audit_logs` (auditoría de negocio) |
| 0.1.6-T | ✅ | `tests/Feature/Domain/SmsProviderCredentialsTest.php` |
| 0.1.7 Modelos base | ✅ **cerrada** | los **13** modelos existen: 8 base + `User` (PR1) y 5 de i18n/outbox + `AuditLog` (esta unidad) |
| 0.1.7-T | ✅ | `tests/Feature/Domain/ModelCastsTest.php` (extendido de 8 a 13 modelos) |

**14 de 152 tareas del change completadas.** 0.1.7 queda **cerrada**: los 13 modelos de la tarea existen y su test gemelo los cubre.

## TDD Cycle Evidence

| Tarea | RED (test escrito primero) | GREEN (implementación) | REFACTOR |
|---|---|---|---|
| 0.1.1-T | ❌ `Failed asserting that false is true` (BigDecimal y Printer ausentes) | ✅ `composer require brick/math mike42/escpos-php` → 1 passed (3 assertions) | — |
| 0.1.2-T | ❌ `Failed asserting that null is identical to 'pos_moderno_v2_test'` | ✅ `pgsql_b` en `config/database.php` → 1 passed (2 assertions) | — |
| 0.1.3-T | ❌ `BindingResolutionException: Target class [config] does not exist` | ✅ 3 configs + `.env.example` → 1 passed (3 assertions) | el test se movió de `tests/Unit` a `tests/Feature` |
| 0.1.4-T | ❌ `Failed asserting that true is true` (`store_settings` inexistente) | ✅ M04 + M05 → 1 passed (21 assertions) | — |
| 0.1.5-T | ❌ `Failed asserting that false is true` (`user_settings` inexistente) | ✅ M06 + M07 + edición de `users` → 1 passed (16 assertions) | — |
| 0.1.6-T | ❌ `Failed asserting that an array contains 'credential_env_prefix'` | ✅ M25 + M26 → 1 passed (18 assertions) | — |
| 0.1.7-T | ❌ `Class "App\Models\Language" not found` | ✅ 5 modelos de i18n/outbox + `AuditLog` (M25–M26) → 1 passed (66 assertions) | `AuditLog` declara además `action`/`entity_type`/`ip_address` como `string` (los casts de PR1 eran incompletos) |

## Work Unit Evidence (PR2-ciimientos-b)

| Evidencia | Valor |
|---|---|
| Test focalizado | `php vendor/bin/pest --filter="credenciales_de_proveedor"` → **1 passed (18 assertions)**; `php vendor/bin/pest --filter="modelos_declaran_sus_casts"` → **1 passed (66 assertions)**; suite completa `./vendor/bin/pest` → **34 passed (193 assertions)** (baseline del PR1: 33 passed / 142 assertions) |
| Harness de runtime | `php artisan migrate:fresh --seed --force` → **9 migraciones en verde** (0001×3 + M04–M07 + M25–M26); `tinker` → outbox `status=pending`/`sent_at=NULL`, traducción sin traducir con `lang_value=NULL`, fila de proveedor con sólo `credential_env_prefix` (sin secreto), `audit_logs.context` con payload JSON |
| Frontera de rollback | `git revert` del commit + `php artisan migrate:fresh`: se retiran M25, M26, los 5 modelos y la extensión del test. No toca M04–M07 (base de la cadena) ni ninguna tabla de PR3–PR16 |
| Puertas de calidad | `vendor/bin/pint --test` → **8 ficheros preexistentes**, idéntico al baseline de `main` (ninguno de esta unidad) · `npm run lint:check` → limpio · `npm run typecheck` → limpio · `public/build/manifest.json` presente |

## Ficheros de esta unidad

| Fichero | Acción | Qué hace |
|---|---|---|
| `database/migrations/2026_11_01_000025_create_languages_and_messages_table.php` | Creado | M25: i18n (`languages`, `language_translations`) + mensajería (`sms_providers` con `credential_env_prefix`, `message_templates`, `sms_messages` como outbox con `status`/`sent_at`) |
| `database/migrations/2026_11_01_000026_create_audit_logs_table.php` | Creado | M26: auditoría de **negocio** (actor, acción, entidad, payload), distinta de la fiscal append-only `fiscal_document_events` |
| `app/Models/{Language,LanguageTranslation,MessageTemplate,SmsMessage,SmsProvider}.php` | Creado | Modelos con casts exactos y relaciones con FK; `SmsProvider::credential()` resuelve el secreto desde el entorno |
| `app/Models/AuditLog.php` | Modificado | La tabla de M26 ya existe: docblock actualizado y casts `action`/`entity_type`/`ip_address` declarados |
| `tests/Feature/Domain/SmsProviderCredentialsTest.php` | Creado | 0.1.6-T: ninguna credencial en claro; el secreto sale del entorno por prefijo |
| `tests/Feature/Domain/ModelCastsTest.php` | Modificado | 0.1.7-T extendido a los 13 modelos (casts + relaciones con FK) |

## Desviaciones del diseño

1. **FK `stores.country_id → countries.id` diferida a M27** (PR1). `countries` la crea M01, en el PR3; M04 crea la columna indexada y M27 añade la restricción.
2. **FK `users.group_id → roles.id` añadida en M06** (PR1): la migración base de `users` no puede declarar la FK porque `roles` no existe todavía.
3. **`password_reset_tokens` se crea en M06** (PR1) y se retira de la migración base de `users`, como fija el diseño.
4. **La aserción de relación usa `$model->rel()` y no `$model->rel`** (PR1): en Laravel 12 `getRelationshipFromMethod()` devuelve el resultado de la consulta.
5. **`message_templates` conserva los TAGS del legacy, no el cuerpo del mensaje.** `mail_sms_tag` guardaba `tagname` + `type`; el legacy no guardaba cuerpos, así que la tabla destino es el catálogo de marcadores `{{…}}` (`tag_name` + `type`).
6. **La resolución del secreto vive en el modelo, no en un servicio.** `SmsProvider::credential($variable)` lee `PREFIX_VARIABLE` del entorno (`Illuminate\Support\Env`): es el mínimo que hace verificable la regla de 0.1.6-T sin adelantar el job de envío de Fase 2.
7. **`language_translations` no conserva `key_type`** (`specific`/`default` del legacy). El contrato que fija la tarea es `lang_key`/`lang_value`/`lang_id`, y ninguna regla del change usa `key_type`.
8. **`timestamptz` en el outbox y en la auditoría.** `sms_messages.sent_at`/`scheduled_at` y `audit_logs.created_at`/`updated_at` son `timestamptz` (design §A.2: "timestamptz para auditoría"); el resto de M25 usa `timestamp`, como las tablas de M04–M07.

## Fuera de esta unidad (no implementado)

- **0.2.\***–**0.9.\***, **T1–T3** (`0.3.10–0.3.12` → PR6), y todo `0.9.5` (→ PR15).
- El **job de envío SMS** (Fase 2): aquí sólo el esquema y el modelo del outbox.
- `fiscal_document_events` (auditoría fiscal append-only): es de M22, en el PR6.

## Hallazgos

- `vendor/bin/pint --test` **ya fallaba en `main`** con 8 ficheros del esqueleto de Laravel (`NewPasswordController`, `PasswordResetLinkController`, `RegisteredUserController`, `VerifyEmailController`, `LoginRequest`, `bootstrap/providers.php`, `config/auth.php`, `UserFactory`). **No se han tocado**: ninguno pertenece a esta unidad.
- `Schema::getColumnListing()` sobre una tabla inexistente devuelve `[]` en vez de lanzar: el RED de 0.1.6-T falla por aserción, no por excepción.
- `Illuminate\Support\Env` lee `putenv()` (adaptador activo por defecto), lo que permite probar la resolución del secreto sin tocar `.env`.
- `tests/Unit` **no arranca la aplicación** en este proyecto (`tests/Pest.php` sólo extiende `TestCase` + `RefreshDatabase` en `Feature`).

## Siguiente unidad

**PR3-ciimientos-c** (`feat/fase0-03-pais-fiscal-a`): M01–M02 + los 10 modelos de país/perfil fiscal, cierra 0.2.1–0.2.3 y 0.2.6.
