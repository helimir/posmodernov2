# Documentos fiscales — Especificación

## Purpose

Comprobante fiscal: contenido normado, documentos derivados, auditoría y contingencia.

## Requirements

### Requirement: Contenido y render normados del comprobante

El comprobante MUST llevar la denominación «Factura», la fecha `DDMMAAAA` y la hora `HH.MM.SS` con a.m/p.m, y MUST declarar su conformidad con la providencia.

| Elemento | Valor exigido |
|---|---|
| Partes | RIF y razón social de emisor y receptor; cédula o pasaporte para persona natural |
| Impuestos | base imponible e IVA discriminados por alícuota |
| Total | valor total; en moneda extranjera, ambas cantidades más el tipo de cambio |
| Tipografía | imprenta ≥ 6 pt; emisor y número de control ≥ 8 pt |
| Exentas | marcador `(E)`; «Contribuyentes Formales del IVA» en contribuyentes formales |
| Multipágina | mismo número de documento y de control |

**Trazabilidad:** S4 · Criterio C9 · SNAT/2026/00093 arts. 8.1–8.12, 9.5, 10, 11, 19 y 42.4

#### Scenario: Render de una factura con alícuotas

- GIVEN una factura con dos alícuotas de IVA y una línea exonerada
- WHEN se renderiza el comprobante
- THEN base e IVA aparecen por alícuota, con `(E)` y la frase de contribuyentes formales
- AND Regla fijada: el art. 8 es obligatorio

### Requirement: Documentos derivados que referencian el original

Una nota de crédito o débito MUST emitirse por el mismo medio que el original, MUST llevar el enunciado «Nota de Crédito» o «Nota de Débito» y MUST referenciar la fecha, el número correlativo, el número de control y el monto de ese original. El comprobante de retención MUST ser documento propio, con numeración `AAAAMMSSSSSSSS` de 14 caracteres y serie propia, y MUST declarar control y número del documento de origen, período de imposición, monto total, base imponible, impuesto causado y monto retenido. Fase 0: sólo esquema y numeración.

**Trazabilidad:** S4 · Criterio C9 · SNAT/2026/00093 arts. 12, 13, 18 y 21

#### Scenario: Nota y comprobante de retención

- GIVEN una factura emitida y una retención sobre ella
- WHEN se emiten la nota de crédito y el comprobante de retención
- THEN la nota referencia fecha, correlativo, control y monto; el comprobante numera con 14 caracteres
- AND Regla fijada: el IVA retenido es documento propio

### Requirement: Evidencia e integridad del comprobante

`fiscal_document_events` MUST ser append-only y registrar toda acción de emisión, modificación o anulación con actor, instante y carga útil; el sistema MUST NOT permitir su actualización ni su borrado. Los comprobantes MUST conservarse y ser accesibles durante al menos 10 años. El QR MUST ser opcional por canal y MUST NOT exigirse por el contrato de emisión. El hash y la firma MUST usar `hash_algorithm` y `signature_scheme` parametrizados por perfil o canal, sin valor por defecto asumido.

**Trazabilidad:** S4 · Criterio C9 · SNAT/2024/000102 art. 18.2 · SNAT/2026/00093 arts. 18.7 y 42.1

#### Scenario: Anulación auditada sin algoritmo supuesto

- GIVEN un documento emitido en un perfil sin QR ni algoritmo
- WHEN se anula
- THEN queda un evento de anulación, el de emisión permanece intacto y no se calcula hash por omisión
- AND Regla fijada: toda acción queda auditada

### Requirement: Contingencia exclusivamente en papel

Los formatos en papel MUST usarse únicamente por contingencia, ante las causales tasadas, y MUST mantenerse en el establecimiento del contribuyente. Al cesar la contingencia, los documentos físicos MUST registrarse y remitirse en PDF al día siguiente a la imprenta o a la API. El papel MUST NOT competir con el documento digital: MUST ser mecanismo conciliado.

**Trazabilidad:** S4 · Criterio C9 · SNAT/2026/00093 arts. 6 y 7 · SNAT/2024/000102 art. 16.3

#### Scenario: Conciliación al día siguiente

- GIVEN una contingencia cerrada con talonarios usados
- WHEN vence el día siguiente
- THEN los documentos físicos quedan registrados y remitidos en PDF
- AND Regla fijada: el papel sólo es contingencia y se concilia a D+1
