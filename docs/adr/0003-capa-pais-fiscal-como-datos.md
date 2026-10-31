# ADR-0003 · Capa de país/fiscal como datos, sin `if` por país

- **Estado**: Aceptado
- **Fecha**: 2026-10-31
- **Ámbito**: Fase 0 — Fundaciones (`fase0-fundaciones`)
- **Diseño**: `openspec/changes/fase0-fundaciones/design.md` §D.3 · §C.1 (regla de despach) · §E.3 (test anti-país)

## Contexto

`openspec/config.yaml → context` fija la regla de oro: el motor fiscal, de impuestos, de numeración y de formatos es **dato, no código**; y la regla de control: *cambiar de país = insertar filas*; un `if ($country === 'VE')` en el dominio es un defecto.

La comparación de CL/MX/CO/PA/VE (`research/estandares-latam.md` §4.3) registra **12 divergencias** que obligan a diseño de modelo, no a ramificación: impuestos multinivel (D1, MX), doble ubicación del tratamiento fiscal (D2), vocabularios de estado incomparables (D3), cuatro formas de referencia de verificación (D4), tres roles de parte (D5, CL), cuatro dueños del sello (D6), tres formas de autorizar el rango (D7), cuatro naturalezas de anulación (D8), QR obligatorio sólo en Colombia (D9), doble numeración (D10), actor intermedio (D11) y RIF opcional para persona natural (D12).

En el legacy el prefijo de país vive en **16 sitios** (`get_preference('invoice_view') == 'indian_gst'` en 11 plantillas y 5 vistas), no en un `switch`: la fuga ya es difusa, y una arquitectura que la admita la reproduce.

## Decisión

1. La capa de país/fiscal es un conjunto de **catálogos**: `countries`, `currencies`, `number_formats`, `fiscal_profiles`, `fiscal_profile_document_types`, `fiscal_profile_environments`, `fiscal_profile_fields`, `fiscal_tax_schemes`, `fiscal_status_vocabularies`, `tax_categories`, `tax_rates`, `fiscal_providers`.
2. El dominio **sólo** ramifica sobre enums genéricos cuyos valores vienen de filas: `ProviderKind`, `SignerModel`, `EnvironmentRole`, `TaxMethod`, `RoundingRule`. **Nunca** sobre un código de país.
3. Todo parámetro normativo vive como fila con `evidence_status` y `source_ref`: una tasa de IVA no confirmada se siembra `pending_confirmation`, no se afirma.
4. Las frases y tokens obligatorios del documento (por ejemplo el prefijo "serie" del art. 20, o "Contribuyentes Formales del IVA") se siembran en `fiscal_profile_fields`; el dominio **compara contra el valor leído**, nunca contra un literal.
5. El control es **ejecutable**: `tests/Architecture/NoCountryLiteralsTest.php` escanea `app/Domain/` y falla ante cualquier literal de código o nombre de país, de código de moneda o de autoridad, identificando fichero, línea, literal y categoría.

## Consecuencias

**Positivas**
- La Fase 2 (Latinoamérica) se reduce a sembrar filas: sin bifurcación del núcleo, sin fork.
- Un solo motor de impuestos sirve todos los perfiles: el mismo `TaxCalculator` produce el resultado esperado de cada perfil.
- Las divergencias D1–D12 tienen **sede propia** en el esquema (`fiscal_document_taxes` multinivel, `fiscal_document_parties` con `role`, `authority_reference` + `authority_reference_scheme`, `fiscal_status_vocabularies`), no se resuelven con casos especiales.
- La evidencia de cada dato sembrado es rastreable hasta la norma.

**Costes**
- Un país nuevo exige sembrar muchas filas (perfil, tipos de documento, ambientes, esquemas de impuesto, vocabulario, tasas, formatos). Es un coste de **datos**, no de código, pero es real.
- El test anti-país es un **proxy**: detecta literales, no todos los forks. Un fork sin literal (por ejemplo, un comportamiento que dependa de `signer_model` de forma indebida) no lo detecta. Riesgo aceptado y compensado por el code review.
- El vocabulario genérico (`draft`/`issued`/`void`/…) es más poor que el nativo: el código nativo vive en `native_status_code` y se traduce con `fiscal_status_vocabularies`, lo que añade una indirección.
- Semilla eagerly incorrecta: si `evidence_status` no se mantiene, el sistema afirmará datos no verificados. Por eso `pending_confirmation` viaja congelado al documento.

**Riesgo residual**
- Un adaptador de país colocado **dentro** de `app/Domain/` (por error de ubicación) puede reintroducir el fork sin que el test lo detecte. Mitigación: los adaptadores viven fuera del dominio (`app/Providers/Fiscal/`) y el test verifica la ruta.

## Alternativas descartadas

| Alternativa | Por qué se descartó |
|---|---|
| `if ($country === 'VE')` / `switch` de país | Contradice la regla de oro y la regla de control; convierte la Fase 2 en un fork. |
| Una clase de dominio por país (`VenezuelaFiscalProfile`) | La clase acaba ramificando el motor; el perfil es un conjunto de filas. |
| Configuración por `config/` (`config/fiscal/ve.php`) | No es auditable, no se versiona como dato, no se siembra ni se valida, y no admite vigencia. |
| Interfaces por país con implementaciones por país (`TaxCalculatorVE`, `TaxCalculatorCL`) | Multiplica el número de motores que hay que probar y mantener; contradice "un solo motor". |
| Detectar el país por heurística (moneda, nº de decimales, formato de fecha) | Las heurísticas colisionan entre países y no son auditables. |
| Sin test anti-país, sólo por convención | La regla de oro se erosiona sin control ejecutable; el propio legacy demuestra que la convención no basta. |
