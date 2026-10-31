# Esquema de dominio — Especificación

## Purpose

Esquema destino del POS moderno sobre PostgreSQL 17: traducción de las 73 tablas del legacy, con la capa de país/fiscal dentro, sin estado derivable duplicado y sin escrituras no atómicas.

## Requirements

### Requirement: Esquema destino por migraciones

El esquema MUST construirse sólo por migraciones: 55 tablas conservadas, 17 fusionadas, 1 eliminada y 27 nuevas. Cada relación MUST usar clave foránea real con `ON DELETE` explícito (`RESTRICT` histórico, `CASCADE` dependiente, `SET NULL` opcional). Todo importe MUST ser `numeric(25,4)`; el sistema MUST NOT usar `float` ni columnas con blobs PHP serializado.

**Trazabilidad:** S1 · Criterio C1 (`migrate:fresh --seed` en verde sobre PostgreSQL 17) · research/estandares-latam.md §5

#### Scenario: Esquema íntegro y reproducible

- GIVEN un checkout limpio con PostgreSQL 17
- WHEN se ejecuta `php artisan migrate:fresh --seed`
- THEN el esquema queda completo, sin `float` monetario ni blobs serializados
- AND Regla fijada: el esquema se entrega por migraciones, no por scripts

#### Scenario: Integridad referencial

- GIVEN una tabla hija con clave foránea a su padre
- WHEN se inserta una fila cuyo padre no existe
- THEN la base rechaza la inserción
- AND Regla fijada: toda relación tiene FK real

### Requirement: Una sola fuente de verdad por dato

El esquema MUST NOT almacenar contadores derivados ni banderas contables dispersas. Los saldos de cuenta, el stock disponible y los totales MUST derivarse de sus hechos origen; cuando se cacheen, el sistema MUST incluir un test de invariante que detecte la desincronización.

**Trazabilidad:** S1 · Criterio C1 · exploration.md §3.4 (criterios transversales 4 y 5)

#### Scenario: Banderas booleanas eliminadas

- GIVEN los catálogos de ingresos y gastos del legacy, con 21 banderas contables
- WHEN se genera el esquema destino
- THEN ningún catálogo expone esas banderas y cada movimiento tiene un tipo único
- AND Regla fijada: el tipo de movimiento sustituye a las banderas booleanas

### Requirement: Atomicidad de toda escritura

Toda operación de escritura MUST ejecutarse como una unidad atómica con bloqueo de fila (`SELECT … FOR UPDATE`) sobre los registros de inventario que modifica. El sistema MUST NOT dejar escrituras parciales tras un fallo intermedio.

**Trazabilidad:** S1, S3, S6 · Criterios C1, C4 · openspec/config.yaml → rules.design

#### Scenario: Escritura parcial revertida

- GIVEN una operación que escribe cabecera, líneas e inventario
- WHEN la escritura de la línea falla
- THEN ninguna cabecera ni cambio de stock queda persistido
- AND Regla fijada: toda escritura es una unidad atómica con bloqueo de fila

### Requirement: El dominio no contiene literales de país

El motor fiscal, de impuestos, de numeración y de formatos MUST ser dato, no código. El sistema MUST incluir un test de arquitectura que falle ante cualquier literal de código o nombre de país en `app/Domain/`; cambiar de país MUST consistir únicamente en insertar filas.

**Trazabilidad:** S2, S5 · Criterio C5 (test anti-país en verde) · openspec/config.yaml → context (regla de oro)

#### Scenario: Literal de país detectado

- GIVEN un archivo de `app/Domain/` con una comparación por código de país
- WHEN se ejecuta la suite
- THEN el test de arquitectura falla identificando el archivo y el literal
- AND Regla fijada: cambiar de país es insertar filas, no bifurcar código
