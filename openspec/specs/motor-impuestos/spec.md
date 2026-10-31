# Motor de impuestos — Especificación

## Purpose

Cálculo de impuestos determinista y puro: el mismo motor produce el resultado esperado de cualquier perfil fiscal sembrado, y el documento conserva la tasa y la categoría congeladas.

## Requirements

### Requirement: `TaxCalculator` puro

`TaxCalculator` MUST ser una función pura: MUST NOT leer la base de datos, MUST NOT acceder a la red ni al sistema de archivos, y MUST NOT depender del idioma. Los perfiles, categorías y tasas MUST resolverse antes de la llamada e inyectarse como datos. Las entradas y salidas MUST estar tipadas.

**Trazabilidad:** S5 · Criterio C7 · exploration.md §6.3 (regla 7)

#### Scenario: Mismo motor para dos perfiles

- GIVEN dos perfiles fiscales sembrados con tasas distintas
- WHEN se calcula un documento para cada uno con el mismo motor
- THEN cada resultado corresponde a su perfil, sin ramificaciones por país
- AND Regla fijada: el motor de impuestos es puro y agnóstico

### Requirement: Cinco categorías de IVA y snapshots congelados

El catálogo de categorías MUST distinguir `gravable`, `exento`, `no_gravado`, `exonerado` y `iva_retenido`. El producto MUST apuntar a una categoría fiscal, no a un porcentaje. El documento MUST congelar, por línea y por cabecera, el porcentaje de tasa, su estado de evidencia y la categoría aplicada; un cambio posterior de tasa MUST NOT alterar un documento ya emitido. La tasa de IVA venezolana MUST sembrarse como `pending_confirmation` hasta confirmarse contra el Decreto del IVA, y ese estado MUST viajar congelado al documento.

**Trazabilidad:** S5 · Criterio C7 · SNAT/2026/00093 art. 8.7 · research/senat-venezuela.md T7

#### Scenario: La tasa cambia después de emitir

- GIVEN un documento emitido con tasa del 16 % sembrada como `pending_confirmation`
- WHEN la tasa vigente se actualiza
- THEN el documento emitido conserva su tasa, su estado de evidencia y su categoría congeladas
- AND Regla fijada: el impuesto se persiste, no se recalcula

### Requirement: Redondeo por línea con ajuste visible

El cálculo MUST redondear por línea según la `rounding_rule` del perfil fiscal, que MUST ser dato y no constante. El total MUST exponer un `roundingAdjustment` visible que explique la diferencia entre la suma de las líneas y el total redondeado, de modo que los totales cuadren al centavo.

**Trazabilidad:** S5 · Criterio C7 · research/senat-venezuela.md T8 (redondeo ausente de la norma: decisión de producto)

#### Scenario: Totales que cuadran al centavo

- GIVEN un documento con tres líneas cuyas fracciones no cuadran
- WHEN se calcula el total
- THEN la suma de líneas más el ajuste visible iguala el total redondeado
- AND Regla fijada: el ajuste de redondeo es visible en el total

### Requirement: El IVA retenido es documento propio

El IVA retenido MUST NOT modelarse como una categoría de línea de un documento de venta. MUST existir como tipo de documento fiscal propio, con numeración, período de imposición y referencia al documento de origen; el motor de cálculo MUST marcarlo como retención y MUST NOT mezclarlo con el impuesto trasladado.

**Trazabilidad:** S5, S4 · Criterio C9 · SNAT/2026/00093 art. 18 · research/senat-venezuela.md §5.5

#### Scenario: Retención separada del IVA trasladado

- GIVEN una operación con IVA trasladado y retención practicada
- WHEN se calcula el documento
- THEN la retención se registra como documento propio y no como línea del documento de venta
- AND Regla fijada: el IVA retenido es crédito del comprador, con documento propio
