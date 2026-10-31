# Inventario por lotes — Especificación

## Purpose

Stock por lotes con FIFO determinista: el consumo se apoya en la aritmética del lote, la devolución repone el lote exacto y la concurrencia no produce sobreventa.

## Requirements

### Requirement: Aritmética de lotes sin estado mutable

`inventory_lots` MUST NOT tener columna `status` mutable. La cantidad disponible MUST derivarse como `quantity_received − quantity_consumed + quantity_returned`. Todo consumo MUST dejar traza en `sale_item_lot_consumptions` con la cantidad y el coste unitario del lote.

**Trazabilidad:** S6 · Criterio C6 · exploration.md §7.2

#### Scenario: Disponibilidad sin enum

- GIVEN un lote con 10 recibidos, 4 consumidos y 1 devuelto
- WHEN se consulta su disponibilidad
- THEN el sistema informa 7 y no existe ninguna columna de estado que mantener
- AND Regla fijada: el estado del lote se deriva, no se codifica

### Requirement: FIFO determinista con bloqueo por salto

El consumo MUST ordenar los lotes por `received_at` y luego por `id`, y MUST bloquearlos con `FOR UPDATE SKIP LOCKED`. El sistema MUST NOT decidir el lote consumido a partir de un criterio no determinista ni de un `status` mutable.

**Trazabilidad:** S6 · Criterio C6 · exploration.md §7.3

#### Scenario: Consumo del lote más antiguo

- GIVEN dos lotes del mismo producto, uno más antiguo
- WHEN se consume una cantidad que alcanza a ambos
- THEN se consume primero el más antiguo y la traza registra un consumo por lote
- AND Regla fijada: el FIFO ordena por antigüedad y por id

### Requirement: Devolución repone el lote exacto

La devolución de una línea MUST reponer la cantidad consumida en el mismo lote del que salió, usando la traza de consumos. El sistema MUST NOT reponer el lote más antiguo disponible ni crear un lote nuevo por la devolución.

**Trazabilidad:** S6 · Criterio C6 · exploration.md §7.2

#### Scenario: Reposición del lote consumido

- GIVEN una venta que consumió 4 unidades del lote B
- WHEN se devuelve esa línea
- THEN el lote B recupera 4 unidades disponibles
- AND Regla fijada: la devolución repone el lote exacto

### Requirement: Invariante de stock y no sobreventa

Tras cada operación de stock, `quantity_in_stock` MUST ser igual a `SUM(available)` de los lotes del producto en la tienda. Bajo concurrencia, el total consumido MUST NOT exceder el stock disponible.

**Trazabilidad:** S6 · Criterio C6 · exploration.md §7.3 y §7.4

#### Scenario: Dos ventas concurrentes sobre stock limitado

- GIVEN 5 unidades disponibles y dos ventas concurrentes de 4 unidades
- WHEN ambas reservas se ejecutan con `SKIP LOCKED`
- THEN una sola venta consume stock y la otra recibe un error de stock insuficiente
- AND Regla fijada: `quantity_in_stock` es igual a la suma de la disponibilidad de los lotes
