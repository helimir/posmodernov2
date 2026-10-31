# Capa de país y fiscal — Especificación

## Purpose

Catálogos que hacen que el comportamiento fiscal sea dato: añadir un país MUST consistir en insertar filas en la capa, nunca en añadir ramas de código al dominio.

## Requirements

### Requirement: El motor fiscal es dato

El sistema MUST modelar `countries`, `fiscal_profiles`, `tax_categories`, `tax_rates`, `currencies`, `number_formats` y `fiscal_profile_document_types` como tablas. Todo parámetro fiscal MUST residir en esas filas; el dominio MUST NOT contener ninguna bifurcación por país.

**Trazabilidad:** S2 · Criterio C5 · research/estandares-latam.md §5.7 (semilla por perfil)

#### Scenario: Alta de un país por filas

- GIVEN un país nuevo con su perfil, tipos de documento, categorías y tasas
- WHEN se siembran esas filas y se ejecuta la suite del dominio
- THEN el mismo motor produce el resultado esperado del perfil, sin tocar código
- AND Regla fijada: cambiar de país es insertar filas

### Requirement: Actores y modelo de firma como dato

El sistema MUST modelar `fiscal_providers` con `kind` ∈ `printer`/`pac`/`portal` y `fiscal_profiles.signer_model` ∈ `authority`/`psp`/`printer`/`legal_signature`. La emisión MUST atravesar un actor intermedio por el contrato `ElectronicInvoiceProvider`, que MUST separar `submit()`, `assignControl()` e `issue()`.

**Trazabilidad:** S2, S4 · Criterio C9 · SNAT/2024/000102 art. 18.4 y 18.6; SNAT/2026/00093 art. 42.1

#### Scenario: Emisión con actor intermedio

- GIVEN un perfil cuyo canal es una imprenta digital
- WHEN se emite un documento
- THEN el flujo invoca `submit()`, recibe la asignación de control y recién entonces consolida `issue()`
- AND Regla fijada: el emisor no emite solo; hay un actor intermedio

### Requirement: Vocabularios de estado y referencias de autoridad por perfil

`fiscal_documents.status` MUST ser un enum genérico y el código nativo MUST residir en `native_status_code` con su mapeo en `fiscal_status_vocabularies`. La referencia de verificación MUST almacenarse como par `authority_reference` + `authority_reference_scheme`; el sistema MUST NOT usar una columna `uuid` única.

**Trazabilidad:** S2 · Criterio C5 · research/estandares-latam.md §4.3 (D3 y D4)

#### Scenario: Referencia de cada país

- GIVEN documentos de Chile, México y Colombia con referencias propias
- WHEN se persisten
- THEN cada una guarda valor y esquema de referencia, sin columna por país
- AND Regla fijada: la referencia de verificación es un par valor + esquema

### Requirement: Semilla del perfil venezolano con evidencia trazable

El perfil venezolano MUST sembrarse con sus tipos de documento, categorías de IVA y vocabulario. Cada fila sembrada MUST declarar `evidence_status` y `source_ref`; la tasa de IVA MUST quedar `pending_confirmation` hasta confirmarse contra el Decreto del IVA.

**Trazabilidad:** S2 · Criterio C1 · SNAT/2026/00093 arts. 54 y 57 (remisión al Decreto del IVA) · research/senat-venezuela.md §4.1 (O3)

#### Scenario: Tasa no confirmada

- GIVEN la semilla del perfil venezolano
- WHEN se inspecciona la tasa de IVA sembrada
- THEN figura como `pending_confirmation` con su `source_ref`, y no como valor confirmado
- AND Regla fijada: un dato no verificado se marca como pendiente, no se afirma
