# Apply progress — fase0-fundaciones

**Change**: `fase0-fundaciones` · **Unidades aplicadas**: PR1-ciimientos-a · PR2-ciimientos-b · PR3-pais-fiscal-a · PR4-pais-fiscal-b · PR5-pais-fiscal-c · PR6-pais-fiscal-d · **PR7-numeracion-a (esta)**
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
| 0.3.1 Migración M21 | ✅ | `fiscal_series` + `fiscal_document_reservations`; **renombrada** (no recrea `fiscal_providers`, que ya crea M02) — **3a-i / PR7** |
| 0.3.1-T | ✅ | `tests/Feature/Fiscal/FiscalSeriesSchemaTest.php` — **3a-i / PR7** |
| 0.3.3 `NumberSeries` | ✅ | `app/Domain/Fiscal/NumberSeries.php`: `UPDATE … RETURNING` + traza, con la **conexión inyectada** — **3a-ii / PR8** |
| 0.3.3-T | ✅ | `tests/Feature/Fiscal/NumberSeriesReservationTest.php` — **3a-ii / PR8** |
| 0.3.10 T1 (secuencia) | ✅ | `tests/Feature/Fiscal/NumberReservationSequenceTest.php` — **3a-ii / PR8** |
| 0.3.10-T | ✅ | 50 correlativos consecutivos, sin huecos ni repeticiones — **3a-ii / PR8** |
| 0.3.11 T2 (bloqueo real) | ✅ | `tests/Feature/Fiscal/NumberReservationLockTest.php` — **3a-ii / PR8** |
| 0.3.11-T | ✅ | SQLSTATE `55P03` real sobre dos sesiones — **3a-ii / PR8** |
| 0.3.12 T3 (rollback) | ✅ | `tests/Feature/Fiscal/NumberReservationRollbackTest.php` — **3a-ii / PR8** |
| 0.3.12-T | ✅ | el número vuelve; se verifica desde `pgsql_b` — **3a-ii / PR8** |
| 0.3.2 Migración M22 | ✅ | `fiscal_documents` + `_taxes` + `_parties` + `_links` + `_events` + `_control_assignments` + `fiscal_periodic_reports` + `fiscal_contingency_forms` — **3b / PR9** |
| 0.3.2-T | ✅ | `tests/Feature/Fiscal/FiscalDocumentEventsTest.php`: la traza es append-only y el bloqueo es del trigger — **3b / PR9** |
| 0.3.4 `FiscalIssuanceService` | ✅ | las TRES llamadas al proveedor fuera de la transaccion; la invariante del control se valida antes de consolidar — **3b / PR9** |
| 0.3.4-T | ✅ | `tests/Feature/Fiscal/FiscalIssuanceServiceTest.php`: tres escenarios de correspondencia — **3b / PR9** |
| 0.3.5 `ControlNumberGenerator` | ✅ | reserva atómica del correlativo de la serie de control + `identificador` y secuencial de ancho fijo; el rango total sale de la serie — **3b-ii / PR10** |
| 0.3.5-T | ✅ | `tests/Feature/Fiscal/ControlNumberGeneratorTest.php`: `00-00000001` y `00-00000002` — **3b-ii / PR10** |
| 0.3.8 Anulación | ✅ | `FiscalIssuanceService::void()`: el documento queda `void`, la reserva consumada y el evento `cancel` auditado; el número **no** vuelve — **3b-ii / PR10** |
| 0.3.8-T | ✅ | `tests/Feature/Fiscal/FiscalDocumentVoidTest.php`: se anula el 42 y la siguiente reserva devuelve el **43** — **3b-ii / PR10** |

**46 de 152 tareas del change marcadas** (14 de Fase 0.1 + 14 de Fase 0.2 + 18 de Fase 0.3). **La Fase 0.2 queda COMPLETA**. En la Fase 0.3 quedan **abiertos** el render del comprobante (0.3.6), los documentos derivados (0.3.7) y la contingencia en papel (0.3.9): el render exige el modelo de plantilla compartido con el ESC/POS (PR14), y los documentos derivados exigen su propia numeración de 14 caracteres (F1 art. 18). La unidad 3b se abre en **tres** slices encadenados.

> **Estado de la cadena tras el 2026-11-02.** El slice **3a-i** está **fusionado** en `main`
> (PR #14, squash `88e912c`, issue #13 cerrado) y el slice **3a-ii** también (PR #17,
> squash `89fa520`, issue #16 cerrado). Ese PR mergeó además un `NumberSeries` propio que
> **queda sustituido** por el de **3a-ii** al fusionar `main` sobre la rama: la conexión se
> inyecta (`ConnectionInterface`) y sólo así T2 y T3 pueden ejecutar la reserva sobre la
> **segunda sesión** (`pgsql_b`). El `NumberSeries` estático del PR #14 no habría
> sostenido T2. El work unit siguiente es **3b** (M22 + motor de emisión), en la rama
> `feat/fase0-09-numeracion-b`.

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
| **0.3.1-T** | ❌ `Failed asserting that an array contains 'series_role'` (la tabla no existía) | ✅ M21 (`fiscal_series` + `fiscal_document_reservations`) → 1 passed (31 assertions) | el conjunto cerrado de rol y autoridad lo hace cumplir el **esquema** (`enum` → `CHECK` de PostgreSQL), no una constante del código |
| **0.3.3-T** | ❌ `Class "App\Domain\Fiscal\NumberSeries" not found` | ✅ `NumberSeries` (`UPDATE … RETURNING`) → 1 passed (+ 1 de triangulación) | la traza se escribe con `INSERT … SELECT`: copia el rol de la fila bloqueada y **no** mete un literal de rol en el dominio |
| **0.3.10-T (T1)** | ❌ `Class "App\Domain\Fiscal\NumberSeries" not found` | ✅ 50 reservas consecutivas → 1 passed | — |
| **0.3.11-T (T2)** | ❌ `Class "App\Domain\Fiscal\NumberSeries" not found` | ✅ dos sesiones + `SET LOCAL lock_timeout` → 1 passed | el mensaje de PostgreSQL nombra la condición en lenguaje natural ("Lock not available"); el test afirma el **SQLSTATE `55P03`**, que es la forma verificable |
| **0.3.12-T (T3)** | ❌ `Class "App\Domain\Fiscal\NumberSeries" not found` | ✅ rollback verificado desde `pgsql_b` → 1 passed | — |

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

> Work unit **3a**: las DOS series fiscales (la del documento y el Número de
> Control), la traza de reservas y la **reserva atómica** `UPDATE … RETURNING`.
> **Se parte en dos slices encadenados** (PR7 · 3a-i y PR8 · 3a-ii): medidos
> **874 líneas**, 74 por encima del techo de 800 ⇒ **no** se concede
> `size:exception`; el corte es por **dependencia real** (el esquema antes que
> la reserva que lo usa).

| Evidencia | Valor |
|---|---|
| Test focalizado | `php vendor/bin/pest tests/Feature/Fiscal` → **6 passed (71 assertions)** · `--filter="serie_se_distingue"` → 1 passed (31) · `--filter="55p03"` → 1 passed · `--filter="rollback_de_la_reserva"` → 1 passed · suite completa `./vendor/bin/pest` → **49 passed (451 assertions)** (baseline del PR6: 43 passed / 380 assertions) |
| Harness de runtime | script de integración sobre `pos_moderno_v2_test` con las dos sesiones (`pgsql` + `pgsql_b`): `[T1] primer=100 ultimo=149 consecutivos=1 next_number=150 reservas=50` · `[T2] sqlstate=55P03 elapsed_ms=337 next_number=100` — `SQLSTATE[55P03]: Lock not available: 7 ERROR: canceling statement due to lock timeout` · `[T3] reservado_en_la_transaccion=100 next_number_desde_B=100 reservas_visibles_desde_B=0 siguiente_reserva=100` |
| Mutación verificada | La mutación que reintroduce la lectura previa (`select "next_number" from "fiscal_series"`) **hace fallar** el test de 0.3.3-T en su assert de cero `SELECT`: el test **fija** la regla, no sólo la acompaña |
| Frontera de rollback | `git revert` del commit + `php artisan migrate:fresh`: se retiran M21, `NumberSeries` y los 5 ficheros de test. **NUNCA** borrar `fiscal_document_reservations`: el rollback de la emisión es **apagar** (`fiscal.emission_enabled = false`), nunca renumerar |
| Puertas de calidad | `vendor/bin/pint --test` → **8 ficheros preexistentes**, idéntico al baseline de `main` (ninguno de esta unidad) · `npm run lint:check` → limpio · `npm run typecheck` → limpio · `php artisan migrate:fresh --seed --force` → **13 migraciones en verde** · `public/build/manifest.json` presente |
| Tamaño | **874 líneas** (835 adiciones + 39 borrados): **725** de código y tests + **149** de artefactos SDD — **74 por encima** del techo de 800 ⇒ la work unit se **parte en dos slices encadenados**: **PR7 (3a-i: M21 + test de esquema + arnés, 362 líneas)** y **PR8 (3a-ii: `NumberSeries` + T1–T3 + artefactos SDD, 512 líneas)**. Sin `size:exception` |

## Ficheros de esta unidad (PR7-numeracion-a)

| Fichero | Acción | Qué hace |
|---|---|---|
| `database/migrations/2026_11_01_000021_create_fiscal_series_table.php` | Creado | M21: `fiscal_series` (rol canónico + autoridad + rango + credencial de la imprenta) + `fiscal_document_reservations` (traza, con `UNIQUE (series_id, number)`) |
| `app/Domain/Fiscal/NumberSeries.php` | Creado | Reserva atómica: `UPDATE … RETURNING next_number - 1` + traza; sin `rowCount()` ni lectura del último registro; sin `SKIP LOCKED` |
| `tests/Concerns/WithoutWrappingTransaction.php` | Creado | Trait de test: vacía la lista de conexiones que `RefreshDatabase` transacciona, para que `pgsql_b` vea las filas commiteadas |
| `tests/Fixtures/FiscalSeriesFixture.php` | Creado | Contexto fiscal de prueba (moneda, país, perfil, tienda, imprenta), creación de series y `TRUNCATE … RESTART IDENTITY CASCADE` |
| `tests/Feature/Fiscal/FiscalSeriesSchemaTest.php` | Creado | 0.3.1-T: el rol y la autoridad distinguen la serie; `series_kind` no existe; el rango de la imprenta vive en la serie de control |
| `tests/Feature/Fiscal/NumberSeriesReservationTest.php` | Creado | 0.3.3-T: el número sale de la misma sentencia que toma el bloqueo; la traza; el rango agotado falla |
| `tests/Feature/Fiscal/NumberReservationSequenceTest.php` | Creado | 0.3.10-T (T1): 50 reservas consecutivas sin huecos ni repeticiones |
| `tests/Feature/Fiscal/NumberReservationLockTest.php` | Creado | 0.3.11-T (T2): SQLSTATE `55P03` real, `next_number` no avanza, espera acotada |
| `tests/Feature/Fiscal/NumberReservationRollbackTest.php` | Creado | 0.3.12-T (T3): el rollback devuelve el número; se verifica desde `pgsql_b` |

## Decisiones de esta unidad (PR7-numeracion-a)

1. **M21 NO recrea `fiscal_providers`.** La tabla ya existe (la crea M02, PR4, fusionado en `main`): recrearla sería un error de migración. M21 se **renombra** a `2026_11_01_000021_create_fiscal_series_table.php` y sólo la **referencia** (`provider_id` → `fiscal_providers.id`). El test 0.3.1-T lo verifica: sólo M02 crea esa tabla.
2. **El conjunto cerrado de rol y autoridad vive en el ESQUEMA** (`enum` → `varchar CHECK (…)` de PostgreSQL), no en una constante de PHP: una tabla nueva no necesita un modelo para que su dominio no se pueda violar por SQL directo. `fiscal_providers.kind` y `fiscal_profiles.signer_model` siguen con validación en el dominio (PR5), porque sus tablas ya existían.
3. **El rol de la reserva se copia de la fila bloqueada, no de un literal.** La traza se escribe con `INSERT … SELECT`: `series_role` sale de `fiscal_series`, así que `app/Domain/` no contiene ningún literal de rol ni de país.
4. **La conexión se inyecta en `NumberSeries`.** Es lo que permite a T2 y T3 usar la segunda sesión (`pgsql_b`) con `SET LOCAL lock_timeout` DENTRO de su transacción; la reserva no abre transacción propia (la del llamante).
5. **`UNIQUE (series_id, number)`** en la traza: última línea de defensa contra la colisión del legacy. Una anulación deja la fila con `voided_at` ⇒ el número queda consumido y no se puede volver a repartir.
6. **El arnés de T1–T3 no usa `DatabaseMigrations`**: usa el trait `WithoutWrappingTransaction`, que vacía la lista de conexiones que `RefreshDatabase` transacciona. El efecto buscado por el diseño es el mismo (filas commiteadas, visibles para `pgsql_b`) y se evita un `migrate:fresh` por test. El aislamiento lo da el `TRUNCATE … RESTART IDENTITY CASCADE` del `afterEach`.
7. **`fiscal_series` referencia `stores` y `fiscal_profiles`** (FK reales): el correlativo es por tienda (P7) y la serie pertenece a un perfil fiscal. Cierra las dos FKs que PR3 y PR6 habían diferido a M21.

## Desviaciones del diseño y FKs diferidas (PR7-numeracion-a)

1. **Nombre de la migración**: el plan (`tasks.md` 0.3.1) pedía `2026_11_01_000021_create_fiscal_providers_and_series_table.php`. Se renombra porque `fiscal_providers` ya existe (M02). El contenido previsto (`fiscal_series` + la traza) se entrega íntegro.
2. **Sin modelos Eloquent** (`FiscalSeries`, `FiscalDocumentReservation`): ninguna tarea asignada los exige y la unidad debe caber en el techo de 800. La capa de modelos de la numeración llega con PR8, donde el servicio de emisión los consume; `NumberSeries` habla con las tablas por el query builder.
3. **Mensaje del bloqueo**: el diseño §E.1 pide que el mensaje contenga `lock_not_available`; PostgreSQL nombra la condición "Lock not available" (el identificador de la condición ES el SQLSTATE `55P03`, que el test sí afirma). La verificación es por SQLSTATE.
4. **FKs diferidas**: `fiscal_documents.authority_reference` → **M22** (PR8) · `products.tax_category_id` → **M08** (PR9) · `stores.country_id → countries.id` → **M27** (PR16). Esta unidad **no** declara ninguna FK a tablas que no existen todavía.
5. **La work unit "numeracion a" se parte en dos PRs** (874 líneas, 74 por encima del techo; sin `size:exception`): **PR7 (3a-i)** = M21 + `fiscal_series`/`fiscal_document_reservations` + el test de esquema + el arnés de concurrencia; **PR8 (3a-ii)** = `NumberSeries` + la reserva atómica + T1–T3 + los artefactos SDD. El corte es por dependencia real: **el esquema antes que la reserva que lo usa**, y cada slice deja la suite en verde. La cadena pasa de 18 a **19** PRs y **quedan 11** (PR9–PR19).

## Fuera de esta unidad (no implementado) — PR7-numeracion-a

- **M22** (`fiscal_documents` + `_events` + `_control_assignments` + `fiscal_periodic_reports` + `fiscal_contingency_forms`): 0.3.2, en el **PR8**.
- El **servicio de emisión** (`FiscalIssuanceService`: `submit()` → `assignControl()` → `issue()`), el **generador del Nº de Control** (`00` + `1`), el **render del comprobante** y las **notas de crédito/débito**: 0.3.4–0.3.9, en el **PR8**.
- La **anulación** que marca `voided_at` sin devolver el número (0.3.8): llega con el servicio de emisión; el esquema y el índice único ya lo soportan.

## Siguiente unidad

**PR9-numeracion-b** (`feat/fase0-09-numeracion-b`): M22 + `FiscalIssuanceService` + generador del control + render + notas derivadas (0.3.2 y 0.3.4–0.3.9). Predecesor: PR8 (3a-ii, esta rama).

---

## PR48-impuestos - 6-i: el motor de impuestos puro (2026-11-03)

> Este archivo estaba congelado en el PR9. A partir de aqui se registra
> el avance real de cada unidad de trabajo.

- **Estado**: entregado en el PR #49 (rama `feat/fase0-18-impuestos`).
- **Tareas cerradas**: 0.6.1, 0.6.2 y 0.6.3.
- **Ficheros**: `app/Domain/Tax/TaxCalculator.php`, `TaxLineInput.php`, `TaxDocumentInput.php`, `TaxDocumentResult.php` y `tests/Unit/Tax/TaxCalculatorTest.php`.
- **Evidencia**: `pest` 68 passed (930 assertions), con `main` en 67 passed (914 assertions); `pint` limpio; 324 lineas en 6 ficheros.

### Las tres decisiones que definen el motor

1. **El motor es PURO**: sin base de datos, sin red, sin locale y sin reloj. El perfil, las categorias y las tasas se resuelven ANTES y entran como datos, por `TaxContext` y `TaxLineInput`. La misma entrada da siempre el mismo resultado.
2. **La base es el precio COBRADO** (`net = unitPrice x quantity - lineDiscount`), no el de catalogo: el legacy multiplicaba el impuesto por el precio de catalogo, de modo que cuando el POS cobraba otro precio el impuesto era incorrecto (defecto P0 #13).
3. **El ajuste de redondeo es VISIBLE y EXACTO**: la suma de las lineas mas el ajuste da el total redondeado, hasta el ultimo decimal. El redondeo es un dato (`line` o `total`), half-up por defecto.

Los snapshots viajan congelados: el documento persiste la tasa con la que se emitio, su categoria, su tasa y el estado de su evidencia, de modo que un cambio posterior de la tasa en vigor no altera un documento ya emitido.

### La aritmetica

`BigDecimal` de brick/math: escala 6 en el calculo y escala 4 en la persistencia, que es la de las columnas `numeric(25,4)`. Un `float` no es dinero.

### Dos correcciones durante el trabajo

- `BigDecimal::roundToScale()` no existe en brick/math 0.14: el metodo es `toScale($escala, $modo)`.
- `TaxMethod` solo tiene `Inclusive` y `Exclusive`: el IVA retenido NO es un metodo de linea, sino un **documento fiscal PROPIO** con su numeracion y su serie (0.6.4).

### Siguiente unidad

**6-ii** - el `CheckoutService` (0.6.5): el checkout de dos transacciones, con la reserva del numero de documento y del numero de control, el consumo FIFO, los asientos contables y el pago. Si la segunda reserva falla, el numero de documento tambien vuelve.

---

## PR50-contabilidad-i - 7-i: el libro contable y el LedgerWriter (2026-11-04)

- **Estado**: entregado en el PR #51 (rama `feat/fase0-24-contabilidad-i`).
- **Tareas cerradas**: 0.7.1 y 0.7.4, con sus gemelas de test.
- **0.7.2 NO se cierra**: M19 trae 2 de sus 5 tablas (`expense_categories` y `expenses`). `cash_registers`, `income_sources` y el resto van en 7-ii. `opening_balances` ya existia desde M27.
- **Ficheros**: `app/Domain/Accounting/LedgerWriter.php`, `app/Models/{LedgerEntryType,LedgerEntry,LedgerLine}.php`, `database/migrations/2026_11_01_000018_create_ledger_tables.php`, `database/migrations/2026_11_01_000019_create_expense_tables.php`, `tests/Feature/Accounting/LedgerWriterTest.php`.
- **Evidencia**: `pest` 71 passed (979 assertions), con `main` en 68 passed (930 assertions); `pint` limpio.

### Por que esta unidad va ANTES que 6-ii

La tarea 0.6.5 (el `CheckoutService`) declara `Dep: ... 0.7.4`, y el propio grafo del plan lo dice en la linea 463: `0.7 -> 0.6 | LedgerWriter | el checkout escribe asientos dentro de T1`. La tabla de work units tenia 0.7 despues de 0.6, lo que contradecia su propio grafo. Con el checkout antes, la venta se habria escrito sin su asiento contable: un agujero silencioso.

### Lo que fija el LedgerWriter

1. El tipo del asiento se TOMA DEL CATALOGO `ledger_entry_types`, y solo si esta activo. El codigo nunca se escribe como literal en la cabecera.
2. Una linea es de UNA cuenta: no puede ser debito y credito a la vez, ni los dos a cero, ni negativa. El esquema lo garantiza con tres CHECK, no la confianza en el codigo.
3. El asiento CUADRA: la suma de debitos es igual a la suma de creditos, sin tolerancia.
4. Cabecera y lineas van en UNA transaccion. Si el llamante ya esta dentro (el checkout lo estara), Laravel anida con SAVEPOINT y el rollback del llamante se lleva el asiento entero.

### Dos decisiones del orquestador, sobre el trabajo del subagente

- **La cabecera guarda su descripcion**: la firma inicial no la admitia y la escribia siempre en `null`. El checkout necesita describir el asiento ("Venta #123"), asi que `write()` ahora acepta `?string $description`.
- **El importe se rechaza si es float y se redondea HALF-UP**: `bcadd` TRUNCA, y truncar `10.00505` deja medio centimo menos de lo que se cobro. Ahora el importe se normaliza con `BigDecimal::toScale(4, HALF_UP)`, igual que el motor de impuestos, y un `float` se RECHAZA con un `RuntimeException` explicito.

### Tres descubrimientos sobre el repositorio

- `Schema::getColumnType($tabla, $columna)` con dos argumentos devuelve el `typname` interno de PostgreSQL (`bool`), NO `boolean`. Un test de esquema que compare solo contra `boolean` pasa EN VERDE sobre una tabla llena de banderas. Con el tercer argumento `true` devuelve la definicion completa (`boolean`).
- `foreignId` es `bigint` en PostgreSQL, no `integer`.
- Laravel anida `transaction()` con SAVEPOINT, no con rollback total: un fallo del writer deja VIVA la transaccion del llamante con sus demas escrituras. Es justo lo que necesita el checkout, y lo garantiza el framework.

### Siguiente unidad

**6-ii** - el `CheckoutService` (0.6.5): el checkout de dos transacciones, con la reserva del numero de documento y del numero de control, el consumo FIFO, el asiento contable y el pago. Si la segunda reserva falla, el numero de documento tambien vuelve.

---

## PR51-impuestos-b - 6-ii: el CheckoutService (2026-11-04)

- **Estado**: entregado en el PR #52 (rama `feat/fase0-25-impuestos-b`).
- **Tareas cerradas**: 0.6.5 y 0.6.5-T.
- **Ficheros**: `app/Domain/Checkout/{CheckoutService,CheckoutInput,CheckoutLineInput,CheckoutPaymentInput,CheckoutResult}.php`, `app/Domain/Tax/TaxRateResolver.php`, `database/migrations/2026_11_01_000030_add_amounts_and_evidence_to_sales.php`, `tests/Feature/Checkout/CheckoutServiceTest.php`, `tests/Feature/Tax/TaxRateResolverTest.php`, y `app/Models/{SaleItem,SaleTotal}.php`.
- **Evidencia**: `pest` 76 passed (1081 assertions), con `main` en 71 passed (979 assertions); `pint` limpio; `php -l` sin errores en los 13 ficheros.

### La venta es UNA unidad atomica

TODO el cuerpo del `checkout()` vive dentro de UNA transaccion: la reserva del numero de documento, la del numero de control, el consumo FIFO, la cabecera, las lineas, los totales, el asiento contable y los pagos. Si algo falla a mitad, no queda nada escrito y **ningun numero se quema**.

La reserva del numero de CONTROL va dentro de la MISMA transaccion que la del documento. Si el rango de control esta agotado, la excepcion revierte T1 entera y **el numero del documento tambien vuelve**: el documento y su control son un solo hecho, no dos.

### Las tres decisiones que el subagenteCerillo

1. **La evidencia de la cabecera**: si todas las lineas comparten una evidencia, esa; si discrepan, `null`. La cabecera no afirma una evidencia que el documento no tiene.
2. **Las lineas del asiento a cero se omiten**: el libro no admite una linea a cero en los dos sentidos, y una venta con tasa 0 % (categoria exenta) es un caso normal, no un error.
3. **La cuarta linea del asiento puede ser un DEBITO**: si `grand_total - sub_total - tax_total` es negativa, la diferencia va como debito de `revenue`. Asi el asiento CUADRA en los dos signos.

### La invariante real del documento (correccion importante)

La relacion que se cumple NO es `grand_total = sub_total - discount + tax_total + rounding_adjustment`. El motor acumula `subtotal` como la suma de las bases YA NETAS del descuento de documento prorrateado, y `rounding_adjustment` es `grand_total - suma de los line_total`.

La invariante que SI se cumple, y que es la que hay que auditar, es:

```
suma de los line_total de los items + rounding_adjustment = grand_total
```

`sub_total` y `tax_total` son las sumas SIN redondear, y `sale_totals.discount` es INFORMATIVO: el descuento ya esta restado del `sub_total`. Quien lea esa tabla sin saberlo no la va a cuadrar, y por eso la invariante se audita sobre los items.

### Lo que se cerro por el camino

- **M30**: `sale_items` gana `base_amount`, `line_total` y `evidence_status`; `sale_totals` gana `evidence_status`. Sin el `line_total` redondeado, ni el total ni el ajuste son reconstruibles y el documento dependeria del perfil vigente. Y `design.md:315` exigia congelar la evidencia por linea y por cabecera: solo existia en `fiscal_document_taxes`.
- **El resolutor de tasa vigente** (`TaxRateResolver`): no existia. Ahora la tasa se CONSULTA (vigencia, `priority`, `valid_from`, `id`) y se congela en el documento.
- **Los modelos `SaleItem` y `SaleTotal`** se han puesto al dia con el esquema.

### El test canonico no detectaba una mutacion

`test_un_fallo_intermedio_no_deja_cabecera_ni_cambio_de_stock_persistidos` (0.6.5-T) prueba que **ningun numero se quema**, pero no detectaba que la reserva del control estuviera FUERA de T1: con la serie agotada la excepcion salta antes de abrir la transaccion, no se escribe nada y no se quema numero, y todas sus aserciones siguen cumpliendose.

Se ha añadido `test_sin_stock_suficiente_la_venta_falla_y_tambien_vuelve_el_numero_de_control`, que si la detecta. Las dos filas de la tabla de fallos de `design.md` §B.1 quedan cubiertas.

El test canonico verifica desde la **segunda sesion** (`pgsql_b`): es la unica forma de probar que el numero volvió de verdad y no solo en la vista de quien reservo.

### Siguiente unidad

**0.6.6** - T2 fiscal y el reintento: la llamada HTTP al proveedor ocurre FUERA de la transaccion; si falla, la venta queda `sales.status='fiscal_failed'` y el reintento **reutiliza la misma reserva**, sin quemar numeros nuevos.

> **Aviso para 0.6.6**: `fiscal_document_reservations` no tiene ninguna columna que apunte a la venta, asi que el T2 no puede encontrar la reserva de una venta por la venta. Habra que anadir un enlace (o resolverlo por la traza de `sales.fiscal_document_id`).

---

## PR52-t2-fiscal - 6-iii: el T2 fiscal y su reintento (2026-11-04)

- **Estado**: entregado en el PR #54 (rama `feat/fase0-26-t2-fiscal`).
- **Tareas cerradas**: 0.6.6 y 0.6.6-T.
- **Ficheros**: `app/Domain/Fiscal/FiscalIssuanceCoordinator.php`, `app/Domain/Sales/SalePaymentStatus.php`, `database/migrations/2026_11_01_000031_link_fiscal_documents_and_sales.php`, `tests/Fixtures/FakeInvoiceProvider.php`, `tests/Feature/Fiscal/{FiscalIssuanceCoordinatorTest,FiscalIssuanceControlAssignmentTest,FiscalDocumentLinkSchemaTest}.php`, y los cambios en `app/Domain/{Checkout/CheckoutService,Fiscal/FiscalIssuanceService,Fiscal/NumberSeries}.php`.
- **Evidencia**: `pest` 88 passed (1177 assertions), con `main` en 76 passed (1081 assertions); `pint` limpio; `php -l` limpio. Mutation testing en los tres caminos.

### El T2 y el reintento son el MISMO metodo

`FiscalIssuanceCoordinator::issue()` es a la vez la emision y el reintento: lo unico que cambia es si la venta ya tiene un `fiscal_documents` en `draft` de un intento anterior. No hay dos caminos, y por eso no pueden divergir.

El reintento **reutiliza la reserva**: se busca por el ENLACE (`reference_type='sale'` + `reference_id`), no por la serie, porque el reintento tiene que encontrar la reserva que YA tiene y no la ultima de la serie. Una venta sin reserva no se puede emitir, y **jamas** se reserva un numero nuevo para suplirla: el numero puede haberse publicado en la autoridad, y devolverlo entregaria DOS veces el mismo numero.

### Los tres huecos que M31 cierra

1. **La FK `sales.fiscal_document_id` -> `fiscal_documents.id` NO existia.** M11 la declaro sin `->constrained()` y su comentario decia que la cerraba M27, pero M27 resulto ser la de `opening_balances`. **Nunca se cerro**, y la spec de `esquema-dominio` exige que toda relacion tenga FK real.
2. **La FK `fiscal_documents.customer_id` -> `customers.id`**, por el mismo motivo.
3. **El enlace venta -> reserva**: `fiscal_document_reservations` gana `reference_type` y `reference_id` (el mismo patron que ya usa `ledger_entries`). Sin el, el T2 no puede encontrar la reserva de una venta por la venta: era el aviso que dejo la unidad 6-ii.

Y una **decision de NO hacer**: `fiscal_document_taxes.line_id` **no** lleva FK, porque es **polimorfico** (apunta a `sale_items` en una venta o a `purchase_items` en una compra, segun el `document_type`). Una FK ahi habria sido incorrecta para la mitad de los documentos. El porque queda como `COMMENT ON COLUMN` en PostgreSQL, que es donde se lee de verdad.

### La guarda que evita la doble emision

Si un intento anterior dejo el documento ya en `status='issued'` (porque el proveedor emitio y lo que fallo fue una escritura posterior), el reintento **NO vuelve a llamar al proveedor**: solo reconcilia el estado de la venta. Una doble emision ante la autoridad **no se puede deshacer**: devolver el numero no deshace un comprobante ya publicado.

Esa guarda la anadio el orquestador, y el subagente detecto su efecto colateral: dejaba **sin test** el fix del `FiscalIssuanceService` (leer la asignacion de control antes de insertar). Se cerro con un test que llama al servicio DOS VECES sobre el mismo documento y comprueba que sobrevive, que queda **UNA** asignacion, que el control publicado **no se reasigna** y que la diferencia queda auditada como `control_distinto_al_publicado`.

### `fiscal_failed` pisa el estado de pago

`sales.status` es un unico enum (draft|confirmed|paid|cancelled|fiscal_failed), asi que `fiscal_failed` **machaca** el estado de pago. Al reintentar con exito hay que **recalcular** el estado desde `sale_payments`, no restaurarlo: el estado anterior ya no existe. Para que T1 y T2 no diverjan, la regla vive ahora en un sitio compartido, `SalePaymentStatus`, y el `CheckoutService` la usa tambien.

### El secuencing que respeta el diseno

- La LECTURA y la PREPARACION del documento, fuera de toda transaccion: son lecturas y no deben abrir un bloqueo de fila.
- La llamada al proveedor, **fuera** de la transaccion: es I/O externo, y mantener un bloqueo de fila durante un HTTP puede agotar el `lock_timeout` de la caja.
- La escritura, en unidades atomicas por fase: el borrador y su enlace con la venta van juntos; el documento, el evento y la asignacion de control van juntos.

### Lo que el plan NO decia y esta unidad decidio

- **Que estado lleva la venta tras una emision exitosa**: el plan solo dice que el fallo deja `fiscal_failed`. Decision: vuelve al estado que le corresponde por su pago (`paid` o `confirmed`), recalculado.
- **Que pasa si el documento ya esta emitido**: decision del orquestador, la guarda de arriba.

### Riesgo residual, NO resuelto aqui

El reintento vuelve a llamar a `submit()` del proveedor. Si el proveedor publico el comprobante y la respuesta se perdio, la reemision puede **publicar dos veces** ante la autoridad. La idempotencia ante el proveedor real depende del **Manual Tecnico del art. 41** (bloqueado) y de la clave de idempotencia que definira el adaptador real: hoy no hay adaptador HTTP (`ElectronicInvoiceProvider` solo tiene la interfaz y los fakes). Queda anotado, no resuelto.

### Siguiente unidad

**7-ii** - M20 (`bank_accounts`), `cash_registers`, `income_sources`, el `CashRegisterService` (caja por fecha de negocio), el comando `ops:check-opening-balances` y los modelos contables que faltan. Y **0.6.4**: el IVA retenido como documento PROPIO.

---

## PR53-caja - 7-ii-a: el esquema contable que faltaba y sus modelos (2026-11-04)

- **Estado**: entregado en el PR #56 (rama `feat/fase0-27-caja`).
- **Tareas cerradas**: 0.7.2, 0.7.3, 0.7.3-T y 0.7.7.
- **Ficheros**: `database/migrations/2026_11_01_000020_add_opening_balance_to_bank_accounts_table.php`, `2026_11_01_000028_add_store_country_fk_and_opening_balance_constraints.php`, `2026_11_01_000032_create_cash_registers_and_income_sources_tables.php`, `app/Models/{BankAccount,BankAccountStore,CashRegister,Expense,ExpenseCategory,IncomeSource,OpeningBalance}.php`, `tests/Feature/Domain/{AccountingSchemaTest,AccountingModelCastsTest}.php`.
- **Evidencia**: `pest` 98 passed (1358 assertions), con `main` en 88 passed (1177 assertions); `pint` limpio; `php -l` limpio en los 12 ficheros. Las tres migraciones aplican, revierten y vuelven a aplicar.

### Tres migraciones, y por que no son las que decia el plan

1. **M20 es un ALTER, no un CREATE**: `bank_accounts` **ya existia** desde M10. Y `bank_account_stores` **no tenia** `deposit` ni `withdraw`: el propio M09 lo decia ("los contadores globales por cuenta duplicaban el saldo real"), asi que la prohibicion de la spec ya se cumplia. M20 solo anade `opening_balance`.
2. **M28** cierra la FK `stores.country_id` -> `countries.id` (M04 la promised para M27, y M27 resulto ser la de `opening_balances`: nunca se cerro) y anade los **cuatro CHECK de coherencia** de `opening_balances` (el `kind` exige SU entidad y prohibe las otras tres) y los **cuatro indices unicos parciales** por tipo.
3. **M32** crea `cash_registers` y `income_sources`.

### Se elimina `bank_accounts.account_type_id`

Era una FK a una tabla `account_types` que **no existe en el repo**: una relacion rota. La spec `esquema-dominio` exige que toda relacion tenga FK real, asi que la columna desaparece en vez de quedar colgando. No la sustituye nada: el tipo de cuenta no lo necesita el modelo actual.

### Los saldos: congelados al principio, DERIVADOS despues

- `bank_accounts.opening_balance` y `bank_account_stores.opening_balance`: el saldo de PARTIDA, congelado. La granularidad real es por cuenta **y** tienda.
- `cash_registers.opening_balance`: el saldo de partida de la caja de esa fecha de negocio. El saldo **en curso** se deriva de los asientos (`ledger_entries` con ese `business_date`), nunca de un contador. `closing_balance` es lo que se observo al cerrar.
- `business_date` es un **DATO del llamante**, nunca un `DAY()/MONTH()/YEAR()` calculado: es la trampa 62 del legacy.

### `income_sources` es un CATALOGO, y las once banderas desaparecen

El legacy tenia nueve booleanos (`for_sell`, `for_purchase_return`, `for_due_collection`, `for_loan`, `for_giftcard_sell`, `for_topup`, `for_stock_transfer`, `for_purchase_delete`, `for_expense_delete`) mas dos enums `yes`/`no` (`profitable`, `show_in_income`): **once banderas**. Una bandera no se puede consultar, y dos banderas activas a la vez no significan nada.

El `type` (`credit`/`debit`) **tambien** desaparece del catalogo: el tipo de movimiento se consulta en `ledger_entry_types`, que es el unico sitio donde se clasifica un movimiento. El ingreso se enlaza a su asiento por `ledger_entries.reference_type`/`reference_id`.

### Un nombre canonico que no existia

El nombre canonico de **0.7.3-T** (`test_el_saldo_de_cuenta_se_deriva_de_los_asientos_y_no_de_un_contador_almacenado`) **no estaba implementado en ningun test del repo** (71 tests, ninguno con ese nombre). Ahora si, y por eso 0.7.3-T queda cerrada.

Siguen **ABIERTAS** y anotadas en `tasks.md`, porque sus nombres canonicos no estan implementados: **0.7.2-T** (`test_el_gasto_nace_en_expenses_y_el_movimiento_bancario_no_lo_inventa`; la regla la cubre el test de 7-i, pero con el nombre de 0.7.4-T) y **0.7.7-T** (`test_el_tipo_de_asiento_se_toma_del_catalogo_y_no_de_un_literal`).

### SEIS relaciones rotas, no una

El test `test_ningun_modelo_declara_una_relacion_a_una_clase_que_no_existe` barre `app/Models/` y encontro **seis** relaciones que apuntan a clases inexistentes: `Sale::fiscalDocument()`, `Brand::stores()`, `Box::stores()`, `Unit::stores()`, `PaymentMethod::stores()` y `PosTemplate::stores()`. Los bridges existen como **tablas** (M09) pero no como **modelos**: `ProductStore`, `CustomerStore` y `SupplierStore` si existen, los otros seis no.

Cada una revienta con `Error: Class "..." not found` la primera vez que alguien **usa** la relacion, no al declararla. El test los lleva en una lista explicita de conocidos (un trinquete: si se arregla uno, hay que quitarlo de la lista). Quedan para **7-iii**.

### Un error mio corregido

Encarge los tests en ingles y el subagente los escribio en ingles: **mi peticion estaba mal**. Toda la suite del repo nombra los tests en espanol, y los nombres canonicos del plan estan en espanol. Se renombraron.

### Un hallazgo sobre el rollback

`migrate:rollback --step=3` **no** revierte las tres ultimas migraciones: Laravel revierte las tres del **ultimo lote**, y todas estan en el lote 1, asi que revirtio M32, M31 y M30. Para verificar exactamente las tres de esta unidad hay que usar `migrate:rollback --batch=1 --path=...`.

### Siguiente unidad

**7-ii-b** - el `CashRegisterService` (abre y cierra caja por tienda con `business_date`, y asocia cada movimiento al registro de caja de **su** fecha de negocio) y el comando `ops:check-opening-balances`, que **bloquea el arranque operativo** con codigo de salida distinto de cero mientras falte la certificacion de los saldos de apertura.

---

## PR54-caja-servicio - 7-ii-b: la caja por fecha de negocio y el arranque bloqueado (2026-11-04)

- **Estado**: entregado en el PR #58 (rama `feat/fase0-27-caja-b`).
- **Tareas cerradas**: 0.7.5, 0.7.5-T, 0.7.6 y 0.7.6-T.
- **Ficheros**: `app/Domain/Accounting/CashRegisterService.php`, `app/Console/Commands/CheckOpeningBalancesCommand.php`, `database/migrations/2026_11_01_000033_add_opened_by_to_cash_registers_table.php`, `tests/Feature/Accounting/{CashRegisterServiceTest,CheckOpeningBalancesCommandTest}.php`, y `app/Models/CashRegister.php`.
- **Evidencia**: `pest` 105 passed (1402 assertions), con `main` en 98 passed (1358 assertions); `pint` limpio; `php -l` limpio; la migracion aplica, revierte y vuelve a aplicar.

### La caja se resuelve por la FECHA DE NEGOCIO, no por la de hoy

`CashRegisterService::registerFor($storeId, $businessDate)` devuelve el registro de ESA fecha de negocio. Un movimiento cae en el registro de **su** fecha, no en el de "hoy": el legacy filtraba por `DAY()/MONTH()/YEAR()` calculados (la trampa 62), que parten el dia a la medianoche del servidor y no del negocio.

**La asociacion del movimiento con la caja es por la CLAVE NATURAL** (`store_id`, `business_date`): `ledger_entries` ya tiene las dos columnas y `cash_registers` tiene UNIQUE sobre ellas. **No se anadio `cash_register_id`**: seria una columna redundante que puede desincronizarse de la clave natural.

Los saldos: `opening_balance` es el saldo de partida que **se cuenta a mano** (un dato), `closing_balance` el saldo **observado** al cerrar (el conteo fisico es un hecho, no una cuenta que se derivar), y el saldo **en curso** se deriva de los asientos.

### El arranque operativo queda bloqueado

`php artisan ops:check-opening-balances` es de **solo lectura** y no certifica nada: la certificacion es un **acto humano** (fecha y responsable nominal, `certified_at` + `certified_by`). Comprueba los cuatro tipos de saldo del plan (stock por tienda y producto; saldo y deuda por cliente y proveedor; saldo de caja por cuenta y tienda) y sale con codigo **distinto de cero** mientras falte alguna certificacion, nombrando que falta.

Este es el mecanismo que hace operativa la dependencia **B1**: el codigo puede **frenar** una decision humana, no sustituirla. Y el `opening_balance` de una cuenta se exige por **FILA** de `bank_account_stores`, aunque la cuenta valga cero: una cuenta bancaria nueva en una tienda exige su saldo certificado antes de poder arrancar.

### El hueco que cerro el subagente: `opened_by`

`cash_registers` tenia `closed_by` pero **no** `opened_by`, asi que el responsable de la apertura no se podia registrar en ninguna parte: `closed_by` sin `opened_by` es media confianza. **M33** anade `opened_by` (FK real a `users`, nullable, `nullOnDelete`) y `open()` lo persiste. Los dos pueden faltar, y cuando faltan **el hueco se ve**, porque no se inventa un valor.

### Mutacion testing en los dos caminos

- El comando devolviendo `SUCCESS` siempre: **falla** el test canonico y el del codigo de error.
- `registerFor()` devolviendo el registro mas reciente en vez del de SU fecha: **falla** el test canonico (`2026-11-04` frente a `2026-11-05`).

### Lo que esta unidad NO toca (deuda anotada)

- **El checkout todavia no exige la caja abierta**: el `CashRegisterService` existe y esta verificado, pero el `CheckoutService` no lo consulta todavia. Es el siguiente enganche.
- Los seis bridges que faltan como modelo (`BrandStore`, `BoxStore`, `UnitStore`, `PaymentMethodStore`, `PosTemplateStore`, `FiscalDocument`): **7-iii**.
- El typo preexistente del modelo `CashRegister` ("saldo EN CURNO" por "EN CURSO"): revertido a proposito para que el diff del modelo sea estrictamente el de `opened_by`.

### Siguiente unidad

**7-iii** - los bridges que faltan como modelo y el enganche del checkout con la caja. Y **0.6.4**: el IVA retenido como documento PROPIO.

---

## PR55-bridges - 7-iii: los bridges como modelo y los nombres canonicos (2026-11-04)

- **Estado**: entregado en el PR #60 (rama `feat/fase0-28-bridges`).
- **Tareas cerradas**: 0.4.4-T, 0.4.5-T, 0.5.1-T, 0.5.3-T, 0.5.4-T, 0.5.5-T, 0.5.6, 0.5.6-T, 0.5.8-T, 0.6.1-T, 0.6.2-T, 0.6.3-T, 0.7.2-T y 0.7.7-T. Catorce, de un golpe.
- **Ficheros**: `app/Models/{BrandStore,BoxStore,UnitStore,PaymentMethodStore,PosTemplateStore,FiscalDocument}.php`, `app/Domain/Fiscal/TaxRateResolver.php` (movido), `tests/Feature/Domain/QuotationOrderSchemaTest.php`, y los tests renombrados o ampliados.
- **Evidencia**: `pest` 112 passed (1617 assertions), con `main` en 105 passed (1402 assertions); `pint` limpio; `php -l` limpio. Los trece nombres canonicos aparecen **exactamente una vez** cada uno.

### Las seis relaciones rotas: cerradas

`Sale::fiscalDocument()`, `Brand::stores()`, `Box::stores()`, `Unit::stores()`, `PaymentMethod::stores()` y `PosTemplate::stores()` apuntaban a clases que **no existian**: los bridges estaban como tablas (M09) pero no como modelos. Cada una reventaba con `Error: Class not found` la primera vez que alguien **usaba** la relacion. Los seis modelos existen ahora, y el test `test_ningun_modelo_declara_una_relacion_a_una_clase_que_no_existe` **ya no lista ninguna**: no queda ninguna relacion rota en `app/Models/`.

Los modelos que **siguen sin existir** (y por eso esas relaciones NO se declaran): `FiscalSeries`, `FiscalDocumentControlAssignment` y los de `fiscal_document_taxes`/`fiscal_document_events`. Declarar una relacion hacia una clase inexistente es el defecto que estamos corrigiendo: no se repite.

### El motor de impuestos es PURO DE ESTRUCTURA

El test canonico de 0.6.1-T ya no se conforma con el determinismo: **escanea la carpeta `app/Domain/Tax/`** y falla si algun fichero referencia `DB::`, `Http::`, `file_get_contents`, `curl_`, `now()` o `date(`. Y exige ademas que **ningun** fichero de la carpeta inyecte una `Connection`: sin conexion no hay consulta que hacer.

Para que eso sea verdad hubo que **mover el `TaxRateResolver` a `app/Domain/Fiscal/`**: resolvia la tasa vigente (LEE la base de datos) y convivia en la carpeta del motor. La carpeta `app/Domain/Tax/` es ahora el motor y **solo** el motor.

### Los nombres canonicos: cinco renombrados, ocho escritos

Cinco tests cubrian la regla pero con **otro** nombre, asi que la tarea quedaba abierta: 0.4.4-T, 0.5.1-T, 0.5.4-T, 0.5.6-T y 0.6.1-T se renombraron al canonico exacto. Los otros ocho se escribieron nuevos, entre ellos:

- **0.6.3-T**: tras la venta se cambia la tasa vigente (16 % -> 20 %) y la evidencia de la categoria; el documento emitido **no se mueve**.
- **0.6.2-T**: dos lineas de 10.005 suman 23.2200 y el total redondeado es 23.2100: la diferencia es el **ajuste visible** persistido, y `lineas + ajuste = total` exacto.
- **0.7.7-T**: un tipo de asiento **nuevo** en el catalogo es un asiento valido **sin tocar codigo**: la clasificacion se consulta, no se desparrama.

### Dos decisiones que el subagente dejo a mi criterio

1. **0.5.6-T prometia mas de lo que probaba**: su nombre dice "y no se sobrevende bajo concurrencia", y su cuerpo solo comprobaba la primera mitad (cache = suma de lotes). Se **amplio** con la mitad que faltaba (dos sesiones, `SKIP LOCKED`, sin sobreventa y la invariante cumpliendose) y el nombre ahora es verdad.
2. **0.5.8-T seguia divergente**: `test_dos_ventas_concurrentes_no_sobrevenden_el_stock` paso a llamarse `test_dos_ventas_concurrentes_sobre_stock_limitado_no_se_sobrevenden`, su canonico exacto.

### Siguiente unidad

**Fase 0.8** - impresion LAN (M23, el `EscposRenderer`, los cinco endpoints del agente, la maquina de estados de `print_jobs`) y el adaptador del sync legacy (M24, el verificador v2, el traductor y el aplicador de lotes). Y **0.6.4**: el IVA retenido como documento PROPIO.

---

## PR56-impresion - 8-i: el esquema de impresion y los bytes ESC/POS (2026-11-05)

- **Estado**: entregado en el PR #62 (rama `feat/fase0-29-impresion`).
- **Tareas cerradas**: 0.8.1, 0.8.1-T, 0.8.3 y 0.8.3-T.
- **Ficheros**: `database/migrations/2026_11_01_000023_create_print_tables.php`, `app/Models/{Printer,PrintTarget,PrintAgent,PrintJob}.php`, `app/Print/{PrintPayload,RenderedPayload,BufferPrintConnector,EscposRenderer,PrintJobDispatcher}.php`, `app/Casts/Bytea.php`, `config/print.php`, `tests/Feature/Print/{PrintTargetSchemaTest,EscposRendererTest}.php`.
- **Evidencia**: `pest` 114 passed (1644 assertions), con `main` en 112 passed (1617 assertions); `pint` limpio en los paths tocados; `php -l` limpio; la migracion aplica, revierte y vuelve a aplicar.

### La premisa del diseno venia de la v3 de la libreria

El diseno decia "`mike42/escpos-php`" y anadia "CP850 por defecto, CP858 no codificable". En la **v5** (la instalada) **no existen** las clases `EscposText` ni `EscposQRCode` (eran v3), **ni** las constantes `CHARSET_*` de `Printer`: la API esta en `Printer` (`text()`, `qrCode()`, `selectCharacterTable()`) y las codepaginas son los **66 ids de codificacion** del JSON de capacidades del perfil.

El diseno de fondo se confirma con evidencia de la v5: `CP850` es codificable (`isEncodable() === true`) y **`CP858` NO lo es** (no tiene `iconv` ni `data`). Como el perfil `default` declara `CP858` sin lanzar excepcion, **la guarda la pone el SERVIDOR**, no la libreria: es el servidor quien rechaza un ticket no representable antes de enviarlo.

Y el **numero de tabla de la codepagina es DATO del perfil**, no una constante del codigo: una tabla fija en el codigo seria un fork por modelo de impresora.

### El "perfil de impresora" del legacy NO EXISTIA

El legacy `_inc/print.php` imprimia por USB o por navegador, y su endpoint esta **roto**: un `dd($data)` corta la ejecucion antes de generar ESC/POS. No habia perfiles de impresora modelados.

Resolucion: `print_targets.printer_profile` es el **id de perfil de `mike42/escpos-php`** (`default`, `simple`, `TM-T88II`, `TM-P80`, ...). Es dato, no codigo: el destino lo declara y el servidor consulta el perfil. Un perfil inexistente falla con un error explicito.

Lo que si tiene el legacy es `store_settings.char_per_line` (42 por defecto): **no se ha migrado** a `print_targets.char_per_line`, porque el mapeo del dato de tienda es de otra unidad. `char_per_line` nace con 32.

### Tres trampas de PostgreSQL que corrompen en silencio

1. **PDO devuelve `bytea` como RECURSO**: un cast `'string'` de Eloquent daria `"Resource id #525"`, y los bytes del ticket se vuelven texto basura. De ahi `app/Casts/Bytea.php`.
2. **El constructor de consultas trunca los bytes en el NUL**: `1b401b42` se guardaba como `1b401b42`. Se escribe con `decode(?, 'hex')`.
3. **El flujo hay que rebobinarlo**: la segunda lectura del atributo salia vacia. Fijado con una asercion en el test canonico.

### El USB directo se rechaza por partida doble

`transport` es un enum de Laravel, asi que en PostgreSQL es `varchar(255) check (col in (...))` y **`usb` no esta**: la primera barrera. Y un `server_socket` o un `lan_agent` con un endpoint que no sea `tcp://host:9100` ni una impresora compartida de Windows lo rechaza el **CHECK**: la segunda. Y el `browser` exige `endpoint IS NULL`, porque renderiza HTML/PDF desde el mismo modelo de plantilla.

El recurso UNC se detecta con `chr(92)`, no con barras invertidas literales: `\\` en SQL tiene tres niveles de escape (PHP -> SQL -> ERE) y el CHECK no puede romperse por como PostgreSQL interprete la cadena.

### La firma de impresion, congelada

`payload` (jsonb) es el **modelo** del ticket; `payload_bytes` (bytea) son los **bytes ya generados** y `payload_sha256` su hash. El agente no interpreta el ticket: lo recibe hecho (design D11). Y el dispatcher **genera los bytes antes de escribir el trabajo**: si la generacion falla, no queda ningun `print_jobs` a medias.

### Lo que queda para otras unidades

- Los **cinco endpoints** del agente LAN (0.8.4) y la **maquina de estados** de `print_jobs` con la reimpresion (0.8.5): `reason` y `reprint_of_job_id` quedan siempre a null desde este dispatcher, porque la firma `dispatch()` no los admite.
- El `EscposRenderer` **no corta el papel**: el corte no estaba en el encargo y es un byte que el agente escribiria en la impresora equivocada si el destino no soporta corte parcial.
- `print_agents` no tiene `instance_id`, que el research menciona para detectar un segundo agente con el mismo token.

### Siguiente unidad

**8-ii** - los cinco endpoints del `PrintAgentController` (auth por token + `X-Agent-Id`, la tienda se resuelve desde el `agent_id`, la respuesta a un `result` repetido es idempotente) y la maquina de estados de `print_jobs` (`queued` -> `claimed` -> `printing` -> `printed`; `failed` reintentable; `cancelled` terminal; `lease_expires_at` devuelve a `queued` con `attempts++`) con la reimpresion (0 reservas nuevas, 0 cambios en `fiscal_documents`).

---

## PR57-impresion-endpoints - 8-ii: el agente LAN y la maquina de estados (2026-11-05)

- **Estado**: entregado en el PR #64 (rama `feat/fase0-30-impresion-endpoints`).
- **Tareas cerradas**: 0.8.4, 0.8.4-T, 0.8.5 y 0.8.5-T. Ademas, el corte de papel del `EscposRenderer`.
- **Ficheros**: `app/Http/Controllers/PrintAgentController.php`, `app/Http/Middleware/PrintAgentAuthenticate.php`, `app/Jobs/DispatchPrintJob.php`, `app/Print/{PrintJobStateMachine,PrintJobReprinter}.php`, `routes/api.php`, `tests/Fixtures/PrintFixture.php`, `tests/Feature/Print/{PrintAgentControllerTest,PrintJobReprintTest,PrintJobStateMachineTest}.php`, y `bootstrap/app.php`.
- **Evidencia**: `pest` 129 passed (1727 assertions), con `main` en 114 passed (1644 assertions); `pint` limpio en los ficheros de la unidad; `php -l` limpio en los 13; las cinco rutas listadas por `route:list --path=api`.

### La tienda se resuelve desde el `agent_id`, NUNCA desde el cuerpo

El middleware `PrintAgentAuthenticate` hace tres cosas, en este orden: busca el agente cuyo `token_hash` **coincida** con el hash del token recibido (con `hash_equals`), **fuerza la coincidencia del `agent_id`** de la cabecera con el del agente autenticado (si no, 403), y resuelve la tienda **desde el `agent_id`**. Un `store_id` en el cuerpo se **ignora**. Un token de la tienda A contra el `X-Agent-Id` de la B no entrega el trabajo de la B.

La mutacion que pone la tienda en el cuerpo hace **fallar** el test canonico: le llega el trabajo de la tienda B.

### Reimprimir NO es reemitir

La reimpresion crea un trabajo **NUEVO** con `reason='reprint'`, `reprint_of_job_id` y los **MISMOS bytes** y el **mismo sha256**. **No consume ningun correlativo**: no toca `fiscal_documents` ni `fiscal_document_reservations`. La mutacion que reserva un numero hace **fallar** el test canonico.

### El corte de papel: el generador, y una correccion mia

El renderer ahora **corta el papel al final**, con el corte **COMPLETO** (`GS V 65`), que es el estandar y **no depende del modelo de impresora**. Va en los bytes porque el agente no interpreta el ticket: lo recibe hecho.

> **Correccion**: yo dije que `GS V 66` era el corte completo. **Es el PARCIAL** (depende del modelo). En `mike42/escpos-php` v5, `CUT_FULL = 65` (`GS V 65` -> `1d 56 41`) y `CUT_PARTIAL = 66`. Ademas `setCutDistance()` **no existe** en la v5 instalada. El test comprueba que los bytes terminan en `1d 56 41 03` y que **no** aparece `1d 56 42`.

### Un bug real de Carbon 3

`(now(), false)` devuelve **negativo** (-29.99): con esa formula el long-poll salia **al instante**. La forma correcta es `now()->diffInSeconds()`, y hay que conservarla como **float**: truncarla a `int` la anula otra vez (0.997 -> 0). Fijado con un test propio.

### Tres decisiones que van mas alla de lo pedido

1. **El interruptor `PRINT_LAN_AGENT_ENABLED` gatea los cinco endpoints**. `register` es el unico endpoint sin token (no hay token antes de registrarse) y su unica defensa era el interruptor: sin el, cualquiera con acceso de red a la API podria registrar un agente para **cualquier** tienda y tirar de sus tickets, que llevan bytes fiscales. El interruptor ya existia en `config/print.php`.
2. **El hash del token es SHA-256 determinista, no argon2id/bcrypt**: §C.3 pide bcrypt, pero el contrato exige **buscar por el hash** (indice unico), y con sal eso no se puede indexar. Es el patron de **token de API**, no de contrasena: 256 bits, digest rapido.
3. **El `result` encadena dos transiciones** (`claimed -> printing -> printed`): `markPrinted` es estrictamente `printing -> printed` y no hay endpoint para marcar `printing`, asi que sin el encadenamiento el flujo real nunca llegaria a `printed`. La maquina de estados sigue siendo la unica via.

### La maquina de estados

`queued` -> `claimed` -> `printing` -> `printed`; `failed` reintentable; `cancelled` **terminal**; y el **lease caducado** devuelve a `queued` con `attempts++`. Toda transicion valida el estado de ORIGEN y falla con un mensaje claro si no encaja.

### Lo que queda para otras unidades

- `releaseExpiredLeases()` **no tiene consumidor**: falta el comando o la programacion que barra los leases caducados.
- El test de **concurrencia** del long-poll (dos agentes tomando el mismo trabajo) necesita dos sesiones PostgreSQL: es el arnes de 9-ii.
- `DispatchPrintJob` **choca de nombre** con el diagrama del design, donde parece ser lo que entrega el trabajo al agente; aqui es el job de reintento.

> **Duda de seguridad para decidir**: `register` es publico y su unica defensa es el interruptor. Si en Fase 1 se enciende, cualquiera con acceso de red puede registrar un agente para cualquier `store_id` y leer los tickets de esa tienda. Recomiendo un **secreto de registro por tienda** (columna nueva) o limitar `register` a la red local.

### Siguiente unidad

**8-iii** - M24 (`sync_batches` con UNIQUE `(client_id, payload_sha256)`, `legacy_translation_map`), el verificador v2 (mismo canonizado y mismo orden de verificacion), el traductor (un lote es todo o nada) y el aplicador de lotes. Y luego las tres unidades de 0.9 y **0.6.4**.
