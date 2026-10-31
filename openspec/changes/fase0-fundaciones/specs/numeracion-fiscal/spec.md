# Numeración fiscal — Especificación

## Purpose

Reserva atómica del correlativo fiscal y del Número de Control. El número se reserva una sola vez, no retrocede y no se reutiliza.

## Requirements

### Requirement: Dos series con dos reservas atómicas independientes

`fiscal_series` MUST discriminating por `series_role` (`document` del emisor, `control` de la imprenta digital) y por `assigned_by`. Un documento fiscal MUST tener sus propias reservas: una del número de documento y otra del número de control, independientes entre sí. La reserva MUST ejecutarse con `UPDATE … RETURNING` dentro de la transacción de emisión; el sistema MUST NOT derivar el número con `rowCount()` ni del último registro leído.

**Trazabilidad:** S3 · Criterio C4 · SNAT/2026/00093 arts. 8.2, 8.3 y 27 · SNAT/2024/000102 art. 30

#### Scenario: Reserva de ambas series

- GIVEN un perfil que exige número de control
- WHEN se emite un documento
- THEN se reservan un número de serie y un número de control, en dos reservas distintas
- AND Regla fijada: hay dos numeraciones con autoridades distintas

### Requirement: El número no retrocede

El número reservado MUST NOT liberarse ni por anulación ni por reutilización. Un documento anulado MUST conservarse con estado `void` y su reserva MUST quedar marcada con `voided_at`; una transacción fallida MUST devolver el número por rollback.

**Trazabilidad:** S3 · Criterio C4 · SNAT/2026/00093 arts. 8.2, 21 y 27 · SNAT/2024/000102 art. 34

#### Scenario: Anulación no libera el correlativo

- GIVEN un documento emitido con correlativo 42
- WHEN el documento se anula
- THEN la siguiente reserva devuelve 43 y el 42 queda consumado
- AND Regla fijada: el número consumido no se reutiliza jamás

### Requirement: Primera asignación de control

La primera asignación de control de una serie MUST usar identificador `00` y secuencial `1`, con identificador de 2 dígitos y secuencial de hasta 8. El documento MUST registrar el rango total de control, desde el número inicial hasta el final.

**Trazabilidad:** S3 · Criterio C9 · SNAT/2026/00093 arts. 9.1 y 27 · SNAT/2024/000102 art. 30

#### Scenario: Primer control de la serie

- GIVEN una serie de control recién abierta
- WHEN se asigna el primer número de control
- THEN se obtiene el identificador `00` con secuencial `1` y queda registrado el rango asignado
- AND Regla fijada: la primera asignación de control es `00` + `1`

### Requirement: Invariante del número de control

Cuando el perfil fiscal exija número de control, un documento fiscal MUST tener exactamente un `control_number`; cuando no lo exija, MUST no tenerlo. El sistema MUST validar esta invariante antes de consolidar la emisión.

**Trazabilidad:** S3, S4 · Criterio C9 · research/estandares-latam.md §7.2 (R3)

#### Scenario: Documento sin control en perfil que no lo exige

- GIVEN un perfil sin número de control obligatorio
- WHEN se consolida un documento
- THEN la emisión se rechaza porque el documento tiene un control inesperado
- AND Regla fijada: exactamente un número de control si el perfil lo exige

### Requirement: T1–T3 verifican la reserva sin `RefreshDatabase`

Los tests T1 (secuencia sin huecos), T2 (bloqueo real → SQLSTATE `55P03`) y T3 (rollback devuelve el número) MUST ejecutarse sobre PostgreSQL con dos conexiones y `SET LOCAL lock_timeout`, y MUST NOT usar `RefreshDatabase`, que impediría reproducir el bloqueo.

**Trazabilidad:** S3, S10 · Criterio C4 · exploration.md §5.3

#### Scenario: Bloqueo real reproducido

- GIVEN una conexión A con la serie bloqueada con `FOR UPDATE`
- WHEN la conexión B intenta reservar con `lock_timeout` de 250 ms
- THEN la reserva falla con SQLSTATE `55P03`
- AND Regla fijada: el bloqueo de fila es real, no simulado
