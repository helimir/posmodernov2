# ADR-0002 · Actor intermedio obligatorio: emisor e imprenta digital

- **Estado**: Aceptado
- **Fecha**: 2026-10-31
- **Ámbito**: Fase 0 — Fundaciones (`fase0-fundaciones`)
- **Diseño**: `openspec/changes/fase0-fundaciones/design.md` §D.2 · §C.1 · §B.1 (pasos 11–15)

## Contexto

El emisor **no emite solo**. SNAT/2024/000102 art. 18.4 obliga al emisor a contratar una **imprenta digital autorizada**; el art. 18.6 exige remitirle la estructura del documento para su validación; el art. 29 y SNAT/2026/00093 art. 42.1 establecen que es la **imprenta** quien asigna el Nº de Control y valida estructura y autenticidad. El Nº de Control es, por tanto, **entrada** del emisor, no salida.

El art. 4 de SNAT/2026/00093 enumera los medios de emisión (Portal/App del SENIAT, imprenta digital o proveedor tecnológico autorizado, máquinas fiscales) y el art. 39 permite que el propio sistema sea **imprenta digital** si es persona jurídica domiciliada en el país, con obligaciones propias de registro automatizado (art. 44).

## Decisión

1. Toda emisión atraviesa un **actor intermedio** por el contrato `ElectronicInvoiceProvider`, que separa las fases:
   `submit(FiscalDocumentDraft): SubmissionReceipt` → `assignControl(SubmissionReceipt): ?ControlAssignment` → `issue(FiscalDocumentDraft, ?ControlAssignment): FiscalDocumentData`,
   más `cancel()`, `queryStatus()` y `deliver()`.
2. El actor es **dato**: `fiscal_providers.kind` ∈ `printer` | `pac` | `portal`; el canal concreto es `fiscal_profile_environments` con `provider_id`.
3. La responsabilidad de la firma es **dato**: `fiscal_profiles.signer_model` ∈ `authority` | `psp` | `printer` | `legal_signature`. No hay valor por defecto asumido para `hash_algorithm` ni `signature_scheme` (no hay algoritmo en la norma).
4. El papel del despliegue es **dato**: `fiscal_profile_environments.role` ∈ `issuer` | `printer`, de modo que el sistema pueda ser emisor, imprenta o ambos sin bifurcación de código.
5. El control tiene **una única sede**: `fiscal_document_control_assignments` (1:1 con el documento), con `control_number`, `control_range_from/to`, `control_assigned_at`, `printer_id`, `printer_rif`.
6. El dominio ramifica **sólo** sobre `ProviderKind` (3 valores) y `SignerModel` (4 valores), leídos de filas.

## Consecuencias

**Positivas**
- Un solo flujo de emisión cubre emisor, imprenta y portal: añadir un canal es una fila.
- El rol dual (emisor + imprenta) queda disponible sin reescribir el núcleo.
- La fase de emisión es auditable paso a paso: `submit` deja rastro antes de que exista documento.
- El QR, el hash y la firma dejan de ser supuestos: son opcionales y parametrizados.

**Costes**
- El emisor necesita un canal configurado; sin él, no emite.
- `assignControl()` puede no devolver control (canal portal: el SENIAT lo asigna) ⇒ el tipo de retorno es nullable y la invariante del control se valida por perfil, no por el contrato.
- El control llega **después** del documento: la numeración de control no puede depender de la respuesta de la imprenta sin romper la consecutividad sin huecos (ver ADR-0001).
- El adaptador real de SENIAT/imprenta está bloqueado por el Manual Técnico del art. 41: en Fase 0 sólo se fija el contrato y un stub.

**Riesgo residual**
- Un perfil mal configurado (actor y rol coherentes pero credenciales inválidas) produce fallos de emisión, no de validación previa: los errores de canal se detectan en T2, no en el alta.

## Alternativas descartadas

| Alternativa | Por qué se descartó |
|---|---|
| `issue()` único, sin actor intermedio | Contradice F2 arts. 18.4 y 18.6 y F1 art. 42.1: el emisor no valida ni obtiene el control por sí mismo. |
| Emisión directa al SENIAT como camino único | Excluye la imprenta digital, que la norma contempla y que el mercado venezolano exige. |
| Actor y rol como columnas en `fiscal_profiles` | Un despliegue puede tener varios canales y roles; hace falta una tabla de entornos, no un par de columnas. |
| `if ($kind === 'printer')` por canal | Bifurca el núcleo fiscal y rompe la regla "cambiar de país = insertar filas". |
| Duplicar el control en `fiscal_documents` y en `fiscal_document_control_assignments` | Dos fuentes de verdad para el mismo dato; contradice el criterio transversal 4. |
