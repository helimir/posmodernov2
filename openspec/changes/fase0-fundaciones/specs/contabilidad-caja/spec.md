# Contabilidad y caja — Especificación

## Purpose

Asientos contables tipados, gasto con fuente de verdad única, caja por fecha de negocio y saldos de apertura congelados.

## Requirements

### Requirement: Asientos con tipo explícito

`ledger_entries` MUST tener un `entry_type` explícito que clasifique cada movimiento contable. El esquema destino MUST NOT conservar las 21 banderas booleanas de `income_sources` y `expense_categorys`; la clasificación MUST consultarse, no desparramarse en indicadores dispersos.

**Trazabilidad:** S9 · exploration.md §3.2 (tabla `ledger_entries`) y §3.4 (criterio transversal 4)

#### Scenario: Clasificación de un movimiento

- GIVEN un movimiento de cobro con pago de deuda
- WHEN se registra su asiento
- THEN el asiento lleva un único `entry_type` y ninguna bandera booleana dispersa
- AND Regla fijada: un movimiento contable tiene un tipo, no once banderas

### Requirement: `expenses` es la fuente de verdad del gasto

El gasto MUST nacer en `expenses` y generar su asiento contable. El movimiento bancario MUST NOT ser la fuente de verdad del gasto: un gasto registrado sin movimiento bancario sigue siendo gasto, y un movimiento bancario sin gasto no MUST inventar un gasto.

**Trazabilidad:** S9 · exploration.md §3.2 (tabla `expenses`) y §7.2

#### Scenario: Gasto sin movimiento bancario

- GIVEN un gasto registrado en caja sin asiento bancario
- WHEN se consulta el gasto del período
- THEN el gasto figura por su propio registro, con su asiento generado
- AND Regla fijada: el gasto nace en `expenses`, no en el banco

### Requirement: Caja por fecha de negocio

`cash_registers` MUST registrar apertura y cierre por tienda con su fecha de negocio, y MUST NOT filtrar por día, mes y año calculados sobre la fecha de la operación. Los movimientos de caja MUST quedar asociados al registro de caja de su fecha de negocio.

**Trazabilidad:** S9 · exploration.md §3.2 (tabla `cash_registers`) y §6 (trampa 62)

#### Scenario: Cierre de caja del día correcto

- GIVEN una operación registrada cerca del cambio de día
- WHEN se cierra caja
- THEN el movimiento queda en el registro de caja de su fecha de negocio
- AND Regla fijada: la caja se filtra por fecha de negocio, no por día/mes/año

### Requirement: Sin contadores derivados

El esquema destino MUST NOT almacenar `bank_accounts.total_deposit`, `total_withdraw`, `total_transfer_from_other` ni `total_transfer_to_other`, ni los contadores `deposit` y `withdraw` del vínculo con tienda. Los saldos MUST derivarse de los asientos; si se cachean, MUST existir un test de invariante.

**Trazabilidad:** S9 · exploration.md §3.2 (tablas `bank_accounts` y `bank_account_to_store`)

#### Scenario: Saldo derivado sin contador

- GIVEN dos movimientos de depósito y uno de retiro sobre una cuenta
- WHEN se consulta el saldo
- THEN el saldo se deriva de los asientos y no de un contador almacenado
- AND Regla fijada: los saldos de cuenta se derivan, no se cuentan dos veces

### Requirement: Saldos de apertura congelados

Los saldos de apertura de stock por tienda y producto, de saldo y deuda por cliente y proveedor, y de caja por cuenta y tienda MUST congelarse antes del arranque, con fecha y responsable acordados. La congelación MUST preceder al uso del sistema y MUST ser una dependencia operativa explícita, no una derivación del sistema.

**Trazabilidad:** S9 · Criterio C1 · exploration.md §11.1 (R2) y §11.3 (P9) · research/senat-venezuela.md §5.3

#### Scenario: Arranque sin saldos certificados

- GIVEN un saldo de apertura sin fecha ni responsable acordados
- WHEN se intenta el arranque operativo
- THEN el arranque queda bloqueado hasta que exista el saldo certificado
- AND Regla fijada: sin saldos de apertura firmados no hay cifras defendibles
