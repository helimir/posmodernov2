# Apply progress — fase0-fundaciones

**Change**: `fase0-fundaciones` · **Unidades aplicadas**: PR1-ciimientos-a · PR2-ciimientos-b · PR3-pais-fiscal-a · PR4-pais-fiscal-b · **PR5-pais-fiscal-c (esta)**
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
| 0.2.3 Migration M03 | ⏳ | llega con el **PR6** (`tax_categories` (5) + `tax_rates` (vigencia, evidencia, fuente, redondeo)) |
| 0.2.3-T | ⏳ | llega con el **PR6** (`tests/Feature/Domain/TaxCategoryCatalogueTest.php`) |
| 0.2.4 Contrato del actor intermedio | ✅ | `ElectronicInvoiceProvider` + **9** value objects — **PR5** |
| 0.2.4-T | ✅ | `tests/Feature/Domain/ElectronicInvoiceProviderContractTest.php` — **PR5** |
| 0.2.5 Enums genéricos del dominio fiscal | ✅ | `ProviderKind`/`SignerModel`/`EnvironmentRole`/`TaxMethod`/`RoundingRule`; **sustituyen** a las constantes de PR4 — **PR5** |
| 0.2.5-T | ✅ | `tests/Feature/Domain/FiscalDomainEnumsTest.php` — **PR5** |
| 0.2.6 Modelos de la capa (12) | ⏳ | `TaxCategory` y `TaxRate` llegan con M03 en el **PR6**: la tarea sigue abierta |
| 0.2.6-T | ✅ **parcial** | `tests/Feature/Domain/ModelCastsTest.php` cubre los **10** modelos de PR3/PR4; los **12** llegan con el **PR6** |
| 0.2.7 `TaxContext` | ⏳ | `app/Domain/Tax/TaxContext.php` + `FiscalProfile::taxContext()` llegan con el **PR6** |
| 0.2.7-T | ⏳ | llega con el **PR6** (`tests/Feature/Domain/TaxContextTest.php`) |

**23 de 152 tareas del change marcadas** (14 de Fase 0.1 + 9 de Fase 0.2). **La Fase 0.2 sigue abierta**: 0.2.3 (M03), el cierre de 0.2.6 (los dos modelos que faltan) y 0.2.7 (`TaxContext`) llegan con el **PR6**.

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
| **0.2.4-T** | ❌ `Class "App\Domain\Fiscal\Contracts\ElectronicInvoiceProvider` not found` | ✅ contrato + 9 value objects → 1 passed (16 assertions) | el test cubre los **tres** pasos en orden y el canal sin control (`null`) con el MISMO proveedor |
| **0.2.5-T** | ❌ `Class "App\Domain\Fiscal\Enums\ProviderKind" not found` | ✅ 5 enums + sustitución de las constantes → 1 passed (12 assertions) | el escáner de literales **no es vacuo**: un fixture con un código de país prueba que DETECTA |

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

## Fuera de esta unidad (no implementado)

- El **catálogo fiscal** (M03: `tax_categories` + `tax_rates`) y los dos modelos que faltan: 0.2.3 y el cierre de 0.2.6, en el **PR6**.
- El **contexto fiscal** (`TaxContext` + `FiscalProfile::taxContext()`): 0.2.7, en el **PR6**.
- El **motor de impuestos** (`TaxCalculator`, `TaxLineInput`, `TaxDocumentInput`, `TaxDocumentResult`): 0.6.1 (PR12).
- El **motor de emisión** (`FiscalIssuanceService`, que orquesta `submit()` → `assignControl()` → `issue()`): 0.3.4 (PR8). Aquí sólo existe el **contrato**.
- El **adaptador real** de un canal: bloqueado por el Manual Técnico (research O1/O4/O6).
- El **seed** del perfil y del catálogo de impuestos (5 categorías + tasas con `evidence_status`): PR16 (`FiscalProfileSeeder`).

## Siguiente unidad

**PR6-pais-fiscal-d** (`feat/fase0-06-pais-fiscal-d`): M03 (`tax_categories` + `tax_rates`) + `TaxCategory`/`TaxRate` + `TaxContext` + `FiscalProfile::taxContext()`/`categories()`, con el cierre de 0.2.6 y 0.2.7. Predecesor: PR5-pais-fiscal-c.
