# Apply progress — fase0-fundaciones

**Change**: `fase0-fundaciones` · **Unidades aplicadas**: PR1-ciimientos-a · PR2-ciimientos-b · PR3-pais-fiscal-a · PR4-pais-fiscal-b · PR5-pais-fiscal-c · **PR6-pais-fiscal-d (esta)**
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
| 0.1.7 Modelos base | ✅ **cerrada** | los **13** modelos existen: 8 base + `User` (PR1) y 5 de i18n/outbox + `AuditLog` (PR2) |
| 0.1.7-T | ✅ | `tests/Feature/Domain/ModelCastsTest.php` (extendido de 8 a 13 modelos) |
| 0.2.1 Migrations M01 | ✅ | `countries`/`currencies`/`number_formats`: el formato del correlativo es una fila (PR3) |
| 0.2.1-T | ✅ | `tests/Feature/Domain/NumberFormatAsDataTest.php` |
| 0.2.2 Migration M02 | ✅ | perfil fiscal + 5 satélites + `fiscal_providers` (decisión de PR3, aplicada en PR4) |
| 0.2.2-T | ✅ | `tests/Feature/Domain/FiscalProfileLayerTest.php` (PR4) |
| 0.2.3 Migration M03 | ✅ | `tax_categories` (5) + `tax_rates` (vigencia, evidencia, fuente, redondeo) — **PR6** |
| 0.2.3-T | ✅ | `tests/Feature/Domain/TaxCategoryCatalogueTest.php` — **PR6** |
| 0.2.4 Contrato del actor intermedio | ✅ | `ElectronicInvoiceProvider` + **9** value objects — **PR5** |
| 0.2.4-T | ✅ | `tests/Feature/Domain/ElectronicInvoiceProviderContractTest.php` — **PR5** |
| 0.2.5 Enums genéricos del dominio fiscal | ✅ | `ProviderKind`/`SignerModel`/`EnvironmentRole`/`TaxMethod`/`RoundingRule`; **sustituyen** a las constantes de PR4 — **PR5** |
| 0.2.5-T | ✅ | `tests/Feature/Domain/FiscalDomainEnumsTest.php` — **PR5** |
| 0.2.6 Modelos de la capa (12) | ✅ **cerrada** | los **12** modelos existen: 10 de PR3/PR4 + `TaxCategory` y `TaxRate` (M03) — **PR6** |
| 0.2.6-T | ✅ **definitivo** | `tests/Feature/Domain/ModelCastsTest.php` cubre los **12** modelos — **PR6** |
| 0.2.7 `TaxContext` | ✅ | `app/Domain/Tax/TaxContext.php` + `FiscalProfile::taxContext()` — **PR6** |
| 0.2.7-T | ✅ | `tests/Feature/Domain/TaxContextTest.php` — **PR6** |
| 0.3.1 Migrations M21 | ✅ | `fiscal_series` + `fiscal_document_reservations`; `fiscal_providers` la crea M02 (PR4) y M21 sólo la referencia — **PR7** |
| 0.3.1-T | ✅ | `tests/Feature/Fiscal/FiscalSeriesSchemaTest.php` — **PR7** |
| 0.3.3 `NumberSeries` | ✅ **cerrada** | `app/Domain/Fiscal/NumberSeries.php`: reserva con `UPDATE … RETURNING` + traza — **PR7** |
| 0.3.3-T | ✅ | `tests/Feature/Fiscal/NumberSeriesReservationTest.php` — **PR7** |

**30 de 152 tareas del change marcadas** (14 de Fase 0.1 + 14 de Fase 0.2 + 2 de Fase 0.3). **La Fase 0.2 queda COMPLETA**: 0.2.1–0.2.7 y sus siete tests gemelos están cerrados. La Fase 0.3 queda con su primera unidad (0.3.1 + 0.3.3) cerrada; M22 y el motor de emisión (0.3.4–0.3.12) son de **PR8**.

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
| 0.2.1-T | ❌ `Failed asserting that false is true` (`number_formats` no existe) | ✅ M01 + `Country`/`Currency`/`NumberFormat` → 1 passed (12 assertions) | triangulación: dos países con formatos distintos conviven a la vez |
| 0.2.2-T | ❌ `Class "App\Models\FiscalProfile" not found` | ✅ M02 + los 7 modelos fiscales → 1 passed (7 assertions) | la aserción de `uuid` se acota a las tablas de negocio: `failed_jobs.uuid` es de la cola |
| 0.2.6-T (PR3/PR4) | ❌ `Class "App\Models\FiscalProfile" not found` | ✅ los 10 modelos de país/perfil → 1 passed (81 assertions) | casts: se declaran los tipos con significado (FK, booleanos, fechas, conjuntos cerrados) |
| Conjuntos cerrados | ❌ `InvalidArgumentException` no se lanzaba | ✅ validación en `saving` de `FiscalProfile`/`FiscalProfileEnvironment`/`FiscalProvider` **contra los enums genéricos** → 1 passed (6 assertions) | — |
| C4 (token de serie) | ❌ `Class "App\Models\FiscalProfileField" not found` | ✅ catálogo `fiscal_profile_fields` con `key`/`value` → 1 passed (5 assertions) | — |
| **0.2.3-T** | ❌ `Class "App\Models\TaxCategory" not found` | ✅ M03 + `TaxCategory`/`TaxRate` → 1 passed (8 assertions) | la aserción del esquema (`tax_categories` sin `percentage`, `tax_rates` con `percentage`) hace verificable la aceptación sin esperar a M08 |
| **0.2.4-T** | ❌ `Class "App\Domain\Fiscal\Contracts\ElectronicInvoiceProvider` not found` | ✅ contrato + 9 value objects → 1 passed (16 assertions) | el test cubre los **tres** pasos en orden y el canal sin control (`null`) con el MISMO proveedor |
| **0.2.5-T** | ❌ `Class "App\Domain\Fiscal\Enums\ProviderKind" not found` | ✅ 5 enums + sustitución de las constantes → 1 passed (12 assertions) | el escáner de literales **no es vacuo**: un fixture con un código de país prueba que DETECTA |
| **0.2.6-T (cierre)** | ❌ `Exception "InvalidArgumentException" not thrown` (regla de redondeo inválida) | ✅ validación en cascada de `TaxRate.rounding_rule` → 1 passed (108 assertions) | `ModelCastsTest` pasa a ser **definitivo** con los 12 modelos |
| **0.2.7-T** | ❌ `BadMethodCallException: Call to undefined method App\Models\FiscalProfile::taxContext()` | ✅ `TaxContext` + `FiscalProfile::taxContext()` → 1 passed (11 assertions) | el test comprueba además que el fichero del dominio **no** contiene el literal del token |

## Work Unit Evidence (PR2-ciimientos-b)

| Evidencia | Valor |
|---|---|
| Test focalizado | `php vendor/bin/pest --filter="credenciales_de_proveedor"` → **1 passed (18 assertions)**; `php vendor/bin/pest --filter="modelos_declaran_sus_casts"` → **1 passed (66 assertions)**; suite completa `./vendor/bin/pest` → **34 passed (193 assertions)** (baseline del PR1: 33 passed / 142 assertions) |
| Harness de runtime | `php artisan migrate:fresh --seed --force` → **9 migraciones en verde** (0001×3 + M04–M07 + M25–M26); `tinker` → outbox `status=pending`/`sent_at=NULL`, traducción sin traducir con `lang_value=NULL`, fila de proveedor con sólo `credential_env_prefix` (sin secreto), `audit_logs.context` con payload JSON |
| Frontera de rollback | `git revert` del commit + `php artisan migrate:fresh`: se retiran M25, M26, los 5 modelos y la extensión del test. No toca M04–M07 (base de la cadena) ni ninguna tabla de PR3–PR16 |
| Puertas de calidad | `vendor/bin/pint --test` → **8 ficheros preexistentes**, idéntico al baseline de `main` (ninguno de esta unidad) · `npm run lint:check` → limpio · `npm run typecheck` → limpio · `public/build/manifest.json` presente |

## Ficheros de esta unidad (PR2-ciimientos-b)

| Fichero | Acción | Qué hace |
|---|---|---|
| `database/migrations/2026_11_01_000025_create_languages_and_messages_table.php` | Creado | M25: i18n (`languages`, `language_translations`) + mensajería (`sms_providers` con `credential_env_prefix`, `message_templates`, `sms_messages` como outbox con `status`/`sent_at`) |
| `database/migrations/2026_11_01_000026_create_audit_logs_table.php` | Creado | M26: auditoría de **negocio** (actor, acción, entidad, payload), distinta de la fiscal append-only `fiscal_document_events` |
| `app/Models/{Language,LanguageTranslation,MessageTemplate,SmsMessage,SmsProvider}.php` | Creado | Modelos con casts exactos y relaciones con FK; `SmsProvider::credential()` resuelve el secreto desde el entorno |
| `app/Models/AuditLog.php` | Modificado | La tabla de M26 ya existe: docblock actualizado y casts `action`/`entity_type`/`ip_address` declarados |
| `tests/Feature/Domain/SmsProviderCredentialsTest.php` | Creado | 0.1.6-T: ninguna credencial en claro; el secreto sale del entorno por prefijo |
| `tests/Feature/Domain/ModelCastsTest.php` | Modificado | 0.1.7-T extendido a los 13 modelos (casts + relaciones con FK) |

## Desviaciones del diseño (PR2-ciimientos-b)

1. **FK `stores.country_id → countries.id` diferida a M27** (PR1). `countries` la crea M01, en el PR3; M04 crea la columna indexada y M27 añade la restricción.
2. **FK `users.group_id → roles.id` añadida en M06** (PR1): la migración base de `users` no puede declarar la FK porque `roles` no existe todavía.
3. **`password_reset_tokens` se crea en M06** (PR1) y se retira de la migración base de `users`, como fija el diseño.
4. **La aserción de relación usa `$model->rel()` y no `$model->rel`** (PR1): en Laravel 12 `getRelationshipFromMethod()` devuelve el resultado de la consulta.
5. **`message_templates` conserva los TAGS del legacy, no el cuerpo del mensaje.** `mail_sms_tag` guardaba `tagname` + `type`; el legacy no guardaba cuerpos, así que la tabla destino es el catálogo de marcadores `{{…}}` (`tag_name` + `type`).
6. **La resolución del secreto vive en el modelo, no en un servicio.** `SmsProvider::credential($variable)` lee `PREFIX_VARIABLE` del entorno (`Illuminate\Support\Env`): es el mínimo que hace verificable la regla de 0.1.6-T sin adelantar el job de envío de Fase 2.
7. **`language_translations` no conserva `key_type`** (`specific`/`default` del legacy). El contrato que fija la tarea es `lang_key`/`lang_value`/`lang_id`, y ninguna regla del change usa `key_type`.
8. **`timestamptz` en el outbox y en la auditoría.** `sms_messages.sent_at`/`scheduled_at` y `audit_logs.created_at`/`updated_at` son `timestamptz` (design §A.2: "timestamptz para auditoría"); el resto de M25 usa `timestamp`, como las tablas de M04–M07.

## Work Unit Evidence (PR3-pais-fiscal-a)

| Evidencia | Valor |
|---|---|
| Test focalizado | `php vendor/bin/pest --filter="formato_del_correlativo"` → **1 passed (12 assertions)** · `--filter="referencia_de_verificacion"` → **1 passed (7)** · `--filter="conjuntos_cerrados"` → **1 passed (4)** · `--filter="prefijo_de_serie"` → **1 passed (5)** · `--filter="capa_de_pais_y_fiscal"` → **1 passed (81)**; suite completa `./vendor/bin/pest` → **39 passed (301 assertions)** (baseline del PR2: 34 passed / 193 assertions) |
| Harness de runtime | `php artisan migrate:fresh --seed --force` → **11 migraciones en verde** (base×3 + M01–M02 + M04–M07 + M25–M26) · `tinker` → 8 tablas pobladas y relaciones resueltas (`correlativo=000-00000000`, `environment_provider=Imprenta de prueba`, `field=serie`, `vocab=issued`), `hash_algorithm=NULL`, `signature_scheme=NULL`, y un `signer_model` fuera del conjunto cerrado **rechazado** por el dominio |
| Frontera de rollback | `git revert` del commit + `php artisan migrate:fresh`: se retiran M01, M02, los 10 modelos y los 3 ficheros de test. No toca M04–M07 ni M25–M26 (base de la cadena) ni ninguna tabla de PR4–PR16 |
| Puertas de calidad | `vendor/bin/pint --test` → **8 ficheros preexistentes**, idéntico al baseline de `main` (ninguno de esta unidad) · `npm run lint:check` → limpio · `npm run typecheck` → limpio · `public/build/manifest.json` presente |

## Decisiones de esta unidad (PR3-pais-fiscal-a)

1. **`fiscal_providers` se crea en M02.** El plan no asignaba ninguna migración a esa tabla y `fiscal_profile_environments.provider_id` la referencia. Es una tabla de la capa fiscal (el actor intermedio) y M02 es su migración natural: se crea **antes** que `fiscal_profile_environments` y con FK real a `fiscal_profiles`. El modelo `FiscalProvider` entra en 0.2.6.
2. **`signer_model` y `role` NO son enums todavía.** Son columnas string con **validación en el dominio**: `FiscalProfile::SIGNER_MODELS`, `FiscalProfileEnvironment::ROLES` y `FiscalProvider::KINDS` son conjuntos cerrados comprobados en el evento `saving` (`InvalidArgumentException`). Los enums genéricos (`SignerModel`, `EnvironmentRole`, `ProviderKind`) son de 0.2.5 (PR5): sustituirlos es cambiar la fuente de la constante, sin migración ni ruptura.
3. **La referencia de verificación se guarda como valor + esquema.** `fiscal_profiles.authority_reference_scheme` guarda el esquema (dato del país) y el valor viaja con el documento en `fiscal_documents.authority_reference` (M22, PR8). **No** hay columna `uuid`: el test barre el esquema y falla si aparece una en una tabla de negocio.
4. **M01 aterriza antes que M04.** Por timestamp (`2026_11_01_000001` < `2026_11_01_000004`), la FK `stores.country_id → countries.id` podría declararse ya en M04; se mantiene **diferida a M27** (decisión de PR1) para no ensuciar el diff de este PR.

## Ficheros de esta unidad (PR3-pais-fiscal-a)

| Fichero | Acción | Qué hace |
|---|---|---|
| `database/migrations/2026_11_01_000001_create_countries_currencies_number_formats_table.php` | Creado | M01: `countries`/`currencies` (decimales + separadores)/`number_formats` (número, fecha y **formato del correlativo**) |
| `database/migrations/2026_11_01_000002_create_fiscal_profiles_table.php` | Creado | M02: `fiscal_profiles` + `fiscal_providers` + 5 satélites (`document_types`, `environments`, `fields`, `tax_schemes`, `status_vocabularies`) |
| `app/Models/{Country,Currency,NumberFormat}.php` | Creado | Capa de país: catálogo, moneda y formato como datos |
| `app/Models/{FiscalProfile,FiscalProfileDocumentType,FiscalProfileEnvironment,FiscalProfileField,FiscalTaxScheme,FiscalStatusVocabulary,FiscalProvider}.php` | Creado | Capa fiscal: casts, relaciones FK y validación de los conjuntos cerrados |
| `tests/Feature/Domain/NumberFormatAsDataTest.php` | Creado | 0.2.1-T: el formato del correlativo se cambia insertando una fila |
| `tests/Feature/Domain/FiscalProfileLayerTest.php` | Creado | 0.2.2-T: referencia valor + esquema (sin `uuid`), conjuntos cerrados y token de serie como dato |
| `tests/Feature/Domain/ModelCastsTest.php` | Modificado | 0.2.6-T (parte): casts y relaciones de los 10 modelos de esta unidad |

## Desviaciones y FKs diferidas (PR3-pais-fiscal-a)

1. **`fiscal_profiles.authority_reference_scheme` no figuraba en el diseño** (decisión 3): sin una columna que guarde el esquema, la mitad "valor + esquema" de 0.2.2-T no era verificable hasta M22.
2. **Los booleanos de la capa fiscal llevan valor por defecto `false`** salvo `holes_allowed` (`true`, como fija la investigación) e `is_active` (`true`); `is_testing`/`is_production` son nullable (un ambiente puede no ser ni de prueba ni de producción). La investigación no fijó la nulabilidad: `false` = "no afirmado".
3. **Los casts de sólo presentación no se declaran** (`name`, `label`, `base_url`, separadores): un `varchar` ya vuelve como string y el patrón dominante del proyecto (`Store`, `StoreSetting`) no los declara. Se castean FK, booleanos, fechas y los campos de conjunto cerrado.
4. **`fiscal_profiles.status_vocabulary_id` no se crea.** La investigación lo listaba, pero el perfil y el vocabulario se referenciaban mutuamente (FK circular, resoluble sólo con un parche `Schema::table`). El puntero es redundante: el vocabulario de un perfil se obtiene por `fiscal_status_vocabularies.fiscal_profile_id` y el estado genérico se busca por `native_code`. Así se evita la FK circular y el vocabulario admite más de un juego nativo por perfil.
5. **FKs diferidas por no existir todavía**: `stores.country_id → countries.id` → **M27** (decisión de PR1) · `fiscal_documents.authority_reference` (el valor de la referencia) → **M22** (PR8) · `products.tax_category_id → tax_categories.id` → **M03** (PR5) · `fiscal_series.fiscal_profile_id` / `fiscal_providers` → **M21** (PR6). **Ninguna** FK a una tabla de M03/M21/M22 se declara en esta unidad.

## Work Unit Evidence (PR4-pais-fiscal-b) — *registro añadido en PR5*

> PR4 (`ba28322`, fusionado) **no dejó su propia sección** en este artefacto: el
> fichero iba una unidad por detrás (su commit registró la evidencia de PR3).
> Se completa aquí para que el artefacto cubra **todas** las unidades aplicadas.

| Evidencia | Valor |
|---|---|
| Work unit | M02 + `fiscal_providers` + los **7** modelos de perfil fiscal (parte de 0.2.6) |
| Tamaño | **767 líneas** (761 adiciones + 6 borrados) — dentro del techo de 800 |
| Test focalizado | `--filter="referencia_de_verificacion"` → 1 passed (7 assertions) · `--filter="conjuntos_cerrados"` → 1 passed (4) · `--filter="prefijo_de_serie"` → 1 passed (5) · suite completa → **39 passed (301 assertions)** |
| Harness de runtime | `php artisan migrate:fresh --seed --force` → **11 migraciones en verde** (base×3 + M01–M02 + M04–M07 + M25–M26) |
| Frontera de rollback | `git revert` del commit + `php artisan migrate:fresh`: se retiran M02, los 7 modelos y el test de la capa fiscal. No toca M01 (base de la cadena) ni M04–M07, M25–M26 |
| Puertas de calidad | `vendor/bin/pint --test` → **8 ficheros preexistentes** (ninguno de la unidad) · `npm run lint:check` → limpio · `npm run typecheck` → limpio |

**Decisiones de PR4 que esta unidad sustituye.** `signer_model`, `role` y `kind` se dejaron como **cadenas validadas en el dominio** (`FiscalProfile::SIGNER_MODELS`, `FiscalProfileEnvironment::ROLES`, `FiscalProvider::KINDS`) a propósito, porque los enums genéricos son de 0.2.5. **PR5 los sustituye** por `SignerModel`/`EnvironmentRole`/`ProviderKind` sin cambiar el esquema ni los valores aceptados.

## Work Unit Evidence (PR5-pais-fiscal-c)

> Work unit **2c-a**: el **contrato del actor intermedio** (0.2.4) y el **vocabulario genérico** del dominio fiscal (0.2.5). La work unit original "pais/fiscal c" medía **1.052 líneas** de código y tests (**1.203** con los artefactos SDD) y **no** cabe en el techo de 800: **no** se concede `size:exception` y se **parte** en dos (esta unidad y `PR6-pais-fiscal-d`, §Desviaciones, punto 1).

| Evidencia | Valor |
|---|---|
| Test focalizado | `php vendor/bin/pest --filter="contrato_del_proveedor"` → **1 passed (16 assertions)** · `--filter="enums_del_dominio_fiscal"` → **1 passed (12)** · `--filter="conjuntos_cerrados"` → **1 passed (6)**; suite completa `./vendor/bin/pest` → **41 passed (331 assertions)** (baseline del PR4: 39 passed / 301 assertions) |
| Harness de runtime | `php artisan migrate:fresh --seed --force` → **11 migraciones en verde** (base×3 + M01–M02 + M04–M07 + M25–M26) · `tinker` → un `signer_model`, un `role` y un `kind` fuera del conjunto cerrado **rechazados** por el dominio, y las filas ya sembradas siguen siendo válidas (la sustitución es sólo código: no hay migración) |
| Frontera de rollback | `git revert` del commit + `php artisan migrate:fresh`: se retiran el contrato, los 9 value objects, los 5 enums y los 2 ficheros de test nuevos. **No toca** M01/M02 (base de la cadena) ni M04–M07, M25–M26, y **no toca ninguna tabla**: la unidad no lleva migración |
| Puertas de calidad | `vendor/bin/pint --test` → **8 ficheros preexistentes**, idéntico al baseline de `main` (ninguno de esta unidad) · `npm run lint:check` → limpio · `npm run typecheck` → limpio · `public/build/manifest.json` presente |
| Tamaño | **773 líneas** (686 adiciones + 87 borrados): **597** de código y tests + **176** de artefactos SDD (`tasks.md` + `apply-progress.md`) — **dentro** del techo de 800 |

## Ficheros de esta unidad (PR5-pais-fiscal-c)

| Fichero | Acción | Qué hace |
|---|---|---|
| `app/Domain/Fiscal/Contracts/ElectronicInvoiceProvider.php` | Creado | Contrato del actor intermedio: `submit()` · `assignControl()` · `issue()` · `cancel()` · `queryStatus()` · `deliver()` |
| `app/Domain/Fiscal/ValueObjects/*.php` | Creado | 9 value objects: `FiscalDocumentDraft`, `SubmissionReceipt`, `ControlAssignment`, `FiscalDocumentData`, `CancellationReason`, `CancellationReceipt`, `ProviderStatus`, `DeliveryRecipient`, `DeliveryReceipt` |
| `app/Domain/Fiscal/Enums/*.php` | Creado | 5 enums genéricos: `ProviderKind` (3), `SignerModel` (4), `EnvironmentRole` (2), `TaxMethod`, `RoundingRule` |
| `app/Models/FiscalProfile.php` · `FiscalProfileEnvironment.php` · `FiscalProvider.php` | Modificado | Las constantes de conjuntos cerrados se **sustituyen** por los enums, sin cambio de esquema |
| `tests/Feature/Domain/ElectronicInvoiceProviderContractTest.php` | Creado | 0.2.4-T: el emisor no emite solo; el actor separa ENVIAR, ASIGNAR CONTROL y CONSOLIDAR |
| `tests/Feature/Domain/FiscalDomainEnumsTest.php` | Creado | 0.2.5-T: ningún literal de país en `app/Domain/` + el escáner **no es vacuo** |
| `tests/Fixtures/CountryLiteralFixture.php` | Creado | Fixture que prueba que el escáner de literales de país **detecta** (evita un verde vacío) |
| `tests/Feature/Domain/FiscalProfileLayerTest.php` | Modificado | El test de conjuntos cerrados cubre ahora `kind` contra `ProviderKind` |

## Decisiones de esta unidad (PR5-pais-fiscal-c)

1. **Las constantes se sustituyen, no se duplican.** `FiscalProfile::SIGNER_MODELS`, `FiscalProfileEnvironment::ROLES` y `FiscalProvider::KINDS` **desaparecen**: la validación pasa a usar `SignerModel::tryFrom()`, `EnvironmentRole::tryFrom()` y `ProviderKind::tryFrom()`. Los enums aceptan **exactamente** los mismos valores (`authority`/`psp`/`printer`/`legal_signature`, `issuer`/`printer`, `printer`/`pac`/`portal`), de modo que **las filas ya sembradas siguen siendo válidas sin tocar el esquema**. El mensaje de error conserva el formato ("must be one of: …").
2. **`CancellationReceipt` se añade como noveno value object.** El plan enumeraba ocho; el diseño §C.1 firma `cancel(): CancellationReceipt`, y un contrato sin tipo de retorno no es un contrato. El enum `ProviderStatus` se reserva para el estado genérico que devuelve el actor.
3. **El contrato no depende de los enums, pero los enums sí se apoyan en el plan de 0.2.4.** `ElectronicInvoiceProvider` y sus 9 value objects no referencian ningún enum: son datos puros (`string`, `int`, `DateTimeImmutable`). Por eso esta unidad es la **primera** del corte: 0.2.5 declara `Dep: 0.2.4` en el plan y el enums `RoundingRule` lo usa ya `TaxRate` en el PR6.

## Desviaciones del diseño y FKs diferidas (PR5-pais-fiscal-c)

1. **1.052 líneas (1.203 con los artefactos SDD): la work unit se parte en dos PRs, sin `size:exception`.** El presupuesto por PR es de 800 y la work unit lo excede en 252 (código y tests) o 403 (con `tasks.md` y `apply-progress.md`). **No se ha recortado código para encajar**: el contenido es el mínimo que exige el alcance más lo que el forecast no contabilizó (la sustitución en cascada de las constantes de PR4, la extensión del test de conjuntos cerrados, el escáner de literales con su fixture y los docblocks del dominio). El corte es por **dependencias reales**, no por presupuesto: **PR5 = 0.2.4 + 0.2.5** (contrato + value objects + enums, sin tabla ni modelo nuevo) y **PR6 = 0.2.3 + 0.2.6 + 0.2.7** (M03 + los dos modelos que faltan + `TaxContext`), que es donde vive `TaxRate`, que ya usa `RoundingRule`.
2. **Riesgo para PR17 (`NoCountryLiteralsTest`)**: el escáner de este PR **extrae literales de cadena** y descarta comentarios antes de comparar. Un escáner que haga `str_contains($código, 'serie')` produciría un **falso positivo** con `series_prefix_required_token` (clave de `fiscal_profile_fields` y, en el PR6, propiedad de `TaxContext`). El escáner de PR17 debe comparar **literales**, no subcadenas, y necesita un **fixture con un literal prohibido** que demuestre que **detecta** (aquí: `tests/Fixtures/CountryLiteralFixture.php`).

## Fuera de esta unidad (no implementado) — PR5-pais-fiscal-c

- El **catálogo fiscal** (M03: `tax_categories` + `tax_rates`) y los dos modelos que faltan: 0.2.3 y el cierre de 0.2.6, en el **PR6**.
- El **contexto fiscal** (`TaxContext` + `FiscalProfile::taxContext()`): 0.2.7, en el **PR6**.
- El **motor de impuestos** (`TaxCalculator`, `TaxLineInput`, `TaxDocumentInput`, `TaxDocumentResult`): 0.6.1 (PR12).
- El **motor de emisión** (`FiscalIssuanceService`, que orquesta `submit()` → `assignControl()` → `issue()`): 0.3.4 (PR8). Aquí sólo existe el **contrato**.
- El **adaptador real** de un canal: bloqueado por el Manual Técnico (research O1/O4/O6).
- El **seed** del perfil y del catálogo de impuestos (5 categorías + tasas con `evidence_status`): PR16 (`FiscalProfileSeeder`).

## Siguiente unidad tras PR5-pais-fiscal-c

**PR6-pais-fiscal-d** (`feat/fase0-06-pais-fiscal-d`): M03 (`tax_categories` + `tax_rates`) + `TaxCategory`/`TaxRate` + `TaxContext` + `FiscalProfile::taxContext()`/`categories()`, con el cierre de 0.2.6 y 0.2.7. Predecesor: PR5-pais-fiscal-c.

## Work Unit Evidence (PR6-pais-fiscal-d)

> Work unit **2c-b**: el **catálogo fiscal** (0.2.3 + el cierre de 0.2.6) y el **contexto fiscal** (0.2.7). Segunda mitad del corte de "pais/fiscal c"; la primera es `PR5-pais-fiscal-c` (0.2.4 + 0.2.5).

| Evidencia | Valor |
|---|---|
| Test focalizado | `php vendor/bin/pest --filter="catalogo_de_categorias"` → **1 passed (8 assertions)** · `--filter="conjuntos_cerrados"` → **1 passed (8)** · `--filter="modelos_de_la_capa"` → **1 passed (108)** · `--filter="prefijo_de_serie"` → **2 passed (16)**; suite completa `./vendor/bin/pest` → **43 passed (380 assertions)** (baseline del PR5: 41 passed / 331 assertions) |
| Harness de runtime | `php artisan migrate:fresh --seed --force` → **12 migraciones en verde** (base×3 + M01–M07 + M25–M26) · `tinker` → 5 categorías con `iva_retenido` como única de retención, 1 tasa `pending_confirmation` leída como `16.000000`, token leído `serie` con `requiresSeriesPrefix('serie-001')=true` y `('001')=false`, método `Exclusive` y redondeo `Line` resueltos desde las filas, y una `rounding_rule` inválida **rechazada** por el dominio |
| Frontera de rollback | `git revert` del commit + `php artisan migrate:fresh`: se retiran M03, `TaxContext`, los 2 modelos y los 2 ficheros de test nuevos. **No toca** M01/M02 (base de la cadena) ni M04–M07, M25–M26, y **no toca** el contrato ni los enums del PR5 |
| Puertas de calidad | `vendor/bin/pint --test` → **8 ficheros preexistentes**, idéntico al baseline de `main` (ninguno de esta unidad) · `npm run lint:check` → limpio · `npm run typecheck` → limpio · `public/build/manifest.json` presente |
| Tamaño | **559 líneas** (533 adiciones + 26 borrados): **461** de código y tests + **98** de artefactos SDD (`tasks.md` + `apply-progress.md`) — **dentro** del techo de 800 |

## Ficheros de esta unidad (PR6-pais-fiscal-d)

| Fichero | Acción | Qué hace |
|---|---|---|
| `database/migrations/2026_11_01_000003_create_tax_categories_and_rates_table.php` | Creado | M03: `tax_categories` (5 categorías con `is_withholding`) + `tax_rates` (vigencia, `evidence_status`, `source_ref`, `rounding_rule`) |
| `app/Domain/Tax/TaxContext.php` | Creado | Contenedor de datos resueltos antes de la llamada + `taxMethod()` derivado y `requiresSeriesPrefix()` (C4) |
| `app/Models/TaxCategory.php` · `app/Models/TaxRate.php` | Creado | Los 2 modelos que faltaban; `TaxRate` expone su `evidence_status` y valida su `rounding_rule` contra `RoundingRule` |
| `app/Models/FiscalProfile.php` | Modificado | Gana `categories()` y `taxContext()`: el modelo lee el catálogo de claves y construye el contexto |
| `tests/Feature/Domain/TaxCategoryCatalogueTest.php` | Creado | 0.2.3-T: el producto apunta a una categoría fiscal, no a un porcentaje |
| `tests/Feature/Domain/TaxContextTest.php` | Creado | 0.2.7-T: el token normativo obligatorio es un dato, no un literal del dominio |
| `tests/Feature/Domain/ModelCastsTest.php` | Modificado | 0.2.6-T pasa a ser **definitivo**: los 12 modelos de la capa |
| `tests/Feature/Domain/FiscalProfileLayerTest.php` | Modificado | El test de conjuntos cerrados cubre ahora `rounding_rule` contra `RoundingRule` |

## Decisiones de esta unidad (PR6-pais-fiscal-d)

1. **`tax_categories` es un catálogo POR PERFIL** (`fiscal_profile_id`), como en exploration §4.2 y como pide la spec («el perfil venezolano MUST sembrarse con sus tipos de documento, **categorías de IVA** y vocabulario»). Las 5 categorías son por tanto `gravable`, `exento`, `no_gravado`, `exonerado` e `iva_retenido` —**sólo** `iva_retenido` lleva `is_withholding = true`.
2. **`tax_rates` NO tiene columna `method`.** El método del impuesto lo decide `fiscal_profiles.tax_inclusive_default` (design §C.2, aceptación de 0.4.5): dos fuentes de verdad violarían el requisito «una sola fuente de verdad por dato». El enum `TaxMethod` vive en el dominio y `TaxContext::taxMethod()` lo deriva del booleano del perfil.
3. **`TaxContext` no depende de ningún modelo.** Es un contenedor puro de 6 datos; la lectura de las filas vive en `FiscalProfile::taxContext()` (el modelo lee `fiscal_profile_fields` por clave). Así el motor de impuestos (0.6.1) sigue siendo puro (D9) y el dominio no conoce ni el esquema ni las claves del catálogo.
4. **`requiresSeriesPrefix()` compara contra el valor leído** (C4): `str_contains($prefix, $this->seriesPrefixRequiredToken)`. El test comprueba además que `app/Domain/Tax/TaxContext.php` **no contiene** el literal del token.
5. **La regla de redondeo por defecto es `line`** cuando el perfil no la declara. Es una **decisión de producto** (P4: la providencia no menciona el redondeo), no un requisito normativo, y es revisable por fila.
6. **`TaxRate` valida su `rounding_rule` contra `RoundingRule`** (enum del PR5): el valor nulo significa "hereda la regla del perfil fiscal" y cualquier otro valor se rechaza.

## Desviaciones del diseño y FKs diferidas (PR6-pais-fiscal-d)

1. **La work unit "pais/fiscal c" se parte en dos PRs, sin `size:exception`** (ver §Desviaciones de PR5, punto 1). El corte lo dictan las **dependencias reales**: `TaxRate` usa el enum `RoundingRule` (PR5) y sus propias tablas (M03, aquí), y `FiscalProfile::taxContext()` usa `TaxContext` (aquí) — de ahí que el contrato + enums sean la primera mitad y el catálogo + contexto la segunda. Ninguna tabla ni clase queda pendiente en ninguna de las dos mitades.
2. **Códigos de las categorías**: `tasks.md` fija `gravable`/`exento`/`no_gravado`/`exonerado`/`iva_retenido`; exploration §4.2 usaba el prefijo `iva_` (`iva_gravable`…). Se sigue el plan (es el artefacto que fija la tarea); **el seeder de PR16 debe sembrar estos mismos códigos**.
3. **La clave del catálogo para la frase del contribuyente formal** es `formal_contributor_phrase` (en `fiscal_profile_fields`). El diseño la situaba en una columna de `fiscal_profiles` que M02 no creó; al no haber migración disponible, vive en el catálogo de claves. El seeder de PR16 debe sembrar esa clave.
4. **El modo de redondeo (`half-up`) no tiene columna.** `RoundingRule` cubre el ALCANCE (`line`/`total`); el modo ("half-up" por defecto) no tiene sede en M02/M03 y lo necesita 0.6.2. Queda como fila de `fiscal_profile_fields` (o columna nueva) — decisión de la unidad de motor de impuestos.
5. **FKs diferidas por no existir todavía**: `products.tax_category_id → tax_categories.id` → **M08** (work unit 4a, PR9: `products` no existe todavía) · `stores.country_id → countries.id` → **M27** (decisión de PR1) · `fiscal_documents.authority_reference` (el valor de la referencia) → **M22** (PR8) · `fiscal_series.fiscal_profile_id` / `fiscal_providers` → **M21** (PR7). **Ninguna** FK a una tabla de M08/M21/M22 se declara en esta unidad.
6. **`applies_to` no es un conjunto cerrado validado**: `'sale'|'purchase'|'both'` queda como dato libre de la fila; no hay enum para él en el alcance de 0.2.5 y `direction` ya cubre el eje compra/venta en `fiscal_profile_document_types`.

## Fuera de esta unidad (no implementado) — PR6-pais-fiscal-d

- El **documento propio de retención** (numeración `AAAAMMSSSSSSSS`, período de imposición, referencia al original): la **categoría** `iva_retenido` existe; el documento llega con M21/M22 (PR7–PR8).
- El **motor de impuestos** (`TaxCalculator`, `TaxLineInput`, `TaxDocumentInput`, `TaxDocumentResult`): 0.6.1 (PR12).
- El **motor de emisión** (`FiscalIssuanceService`, que orquesta `submit()` → `assignControl()` → `issue()`): 0.3.4 (PR8). El contrato ya existe desde el PR5.
- El **adaptador real** de un canal: bloqueado por el Manual Técnico (research O1/O4/O6).
- El **seed** del perfil y del catálogo de impuestos (5 categorías + tasas con `evidence_status`): PR16 (`FiscalProfileSeeder`).

## Work Unit Evidence (PR7-numeracion-a)

> Work unit **3a**: la primera mitad de la numeración fiscal — el esquema de las DOS series (M21) y la **reserva atómica** (`NumberSeries`). La segunda mitad (M22 + motor de emisión + T1–T3) es **PR8-numeracion-b**.

| Evidencia | Valor |
|---|---|
| Test focalizado | `./vendor/bin/pest --filter="NumberSeries"` → **1 passed (13 assertions)** · suite completa `./vendor/bin/pest` → **45 passed (424 assertions)** (baseline del PR7: 44 passed / 411 assertions) |
| Harness de runtime | `php artisan migrate:fresh --force` (BD de test) → **13 migraciones en verde** · `tinker` → 2 reservas seguidas devuelven **100** y **101**, `next_number` queda en **102** y quedan **2** trazas con `series_role='document'` |
| Mutación verificada | La mutación que reintroduce la lectura previa (`select "next_number" from "fiscal_series" where "id" = ? limit 1`) **hace fallar** el test en el assert de cero `SELECT`: el test **fija** la regla, no sólo la acompaña |
| Frontera de rollback | `git revert` de los **2** commits del PR (`6742e17` + el de `NumberSeries`) + `migrate:fresh`: se retiran M21, `NumberSeries` y su test. **No toca** M01/M02/M03 (base de la cadena) ni el contrato ni los enums del PR5 |
| Puertas de calidad | `vendor/bin/pint --test` → **8 ficheros preexistentes**, idéntico al baseline de `main` (ninguno de esta unidad) · `npm run lint:check` → limpio · `npm run typecheck` → limpio · `public/build/manifest.json` presente |
| Tamaño | **232 líneas** en este commit (178 de código y tests + 50 de artefactos SDD); el PR7 completo, con el commit previo de M21 (`6742e17`), suma **590 líneas** en 8 ficheros — dentro del techo de 800 |

## Ficheros de esta unidad (PR7-numeracion-a)

| Fichero | Acción | Qué hace |
|---|---|---|
| `database/migrations/2026_11_01_000021_create_fiscal_series_table.php` | Creado | M21: `fiscal_series` (`series_role` canónico, `assigned_by`, rango, `control_identifier`, `sequence_length`) + `fiscal_document_reservations` (traza, `unique(series_id, number)`) |
| `app/Domain/Fiscal/NumberSeries.php` | Creado | `reserve()`: toma el número con `UPDATE … RETURNING` — la misma sentencia que bloquea la fila — y escribe la traza |
| `tests/Feature/Fiscal/FiscalSeriesSchemaTest.php` | Creado | 0.3.1-T: la serie se distingue por rol y por autoridad; `series_kind` no es una columna |
| `tests/Feature/Fiscal/NumberSeriesReservationTest.php` | Creado | 0.3.3-T: el número se entrega en la sentencia que toma el bloqueo, sin ninguna lectura |

## Decisiones de esta unidad (PR7-numeracion-a)

1. **`reserve()` NO abre transacción propia si el llamador ya tiene una.** La reserva es parte de la unidad atómica del checkout (T1) y de la emisión (T2), y el rollback de esa unidad debe **devolver** el número (T3). Abrir una transacción interna rompería el `SET LOCAL lock_timeout` de T2 (que sólo tiene efecto dentro de una transacción) y el rollback de T3. Si el llamador no tiene ninguna, `reserve()` abre una para que el `UPDATE` y su traza no queden separados.
2. **El número y el rol salen del MISMO `RETURNING`**: `RETURNING next_number - 1 AS reserved_number, series_role`. El rol de la traza se copia de la fila ya bloqueada, sin una segunda lectura — la traza no depende de que la serie cambie después.
3. **Rango agotado → `RuntimeException`.** El `UPDATE` con `next_number <= to_number` no toca la fila cuando el rango se acaba: no hay número que repartir, el contador no avanza y no queda traza. Se usa `RuntimeException` (SPL) siguiendo la convención ya observada en el dominio (`InvalidArgumentException` en las validaciones de `FiscalProfile`/`TaxRate`), sin añadir una clase de excepción fuera de la lista de ficheros del design.
4. **El número repartido es `next_number - 1`.** `next_number` guarda el último número repartido; el rango se acota con `next_number <= to_number`, de modo que el primer número de una serie con `next_number = from_number = 100` es el 100.
5. **`reserved_by` es un parámetro explícito del llamador** (`?int`, la columna admite null). Quien emite decide quién tomó el número; la capa de dominio no lee la sesión.

## Desviaciones del diseño y FKs diferidas (PR7-numeracion-a)

1. **El nombre del fichero de la migración** no es el del plan: `2026_11_01_000021_create_fiscal_providers_and_series_table.php` → `2026_11_01_000021_create_fiscal_series_table.php`. `fiscal_providers` la crea **M02** (PR4, ya fusionado en `main`) y M21 sólo la referencia; recrearla sería un error de migración. Queda justificado en la cabecera de la propia migración.
2. **`reserved_by` no se ejercita con un usuario real.** Crear un usuario exige un rol (FK `roles.id`) y no aporta a la regla que fija el test; la columna es nullable por diseño y el parámetro del llamador es explícito. La cobertura con usuario real llega con el motor de emisión (PR8).
3. **La serie de control guarda el espejo local del rango de la imprenta** (`from_number`, `to_number`, `credential_ref`), no una referencia a la fila del proveedor: el rango autorizado es un dato de la serie (C3). La resolución contra la imprenta es del motor de emisión (PR8).

## Fuera de esta unidad (no implementado) — PR7-numeracion-a

- **M22** (`fiscal_documents` + `_taxes` + `_parties` + `_links` + `_events` + `_control_assignments` + `fiscal_periodic_reports` + `fiscal_contingency_forms`): **PR8**.- **`FiscalIssuanceService`** (0.3.4: orquesta `submit()` → `assignControl()` → `issue()`), el generador del Nº de Control (0.3.5), el render del comprobante (0.3.6), los documentos derivados (0.3.7), la anulación (0.3.8) y la contingencia en papel (0.3.9): **PR8**.
- **T1–T3** (0.3.10 secuencia sin huecos, 0.3.11 bloqueo real `55P03`, 0.3.12 el rollback devuelve el número): **PR8**, junto al código que prueban.
- **El arnés de concurrencia** (0.9.5: `DatabaseMigrations` con `TRUNCATE … RESTART IDENTITY CASCADE`): **PR17**. Esta unidad se apoya en `WithoutWrappingTransaction` + `TRUNCATE`, que ya es suficiente sin dos sesiones.

## Siguiente unidad

**PR8-numeracion-b** (`feat/fase0-08-numeracion-b`): M22 (`fiscal_documents` y sus tablas satélite) + `FiscalIssuanceService` (submit → assignControl → issue) + el generador del Nº de Control + el render del comprobante + **T1–T3**. Predecesor: PR7-numeracion-a.
