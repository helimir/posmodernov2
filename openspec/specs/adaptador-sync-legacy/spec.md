# Adaptador del sync legacy — Especificación

## Purpose

Adaptador del protocolo v2 de sincronización: el contrato de wire queda intacto, el SQL crudo MySQL del legacy se traduce con un mapa sembrado y la aplicación del lote es idempotente.

## Requirements

### Requirement: Contrato de wire v2 intacto

El maestro MUST aceptar el payload v2 con las mismas claves, el mismo canonizado (`username`, `action`, `timestamp`, `nonce`, `sha256(data)`), la misma firma `hash_hmac('sha256', …)`, el mismo límite de antigüedad `SYNC_MAX_AGE` y el mismo orden de verificación: cliente conocido, timestamp, firma y sólo después consumo del nonce. El nonce MUST consumirse después de validar la firma. El sistema MUST NOT modificar claves, límites ni forma de respuesta.

**Trazabilidad:** S7 · Criterio C8 · research/senat-venezuela.md §5.4 (orden de verificación) · exploration.md §9.1

#### Scenario: Verificación conforme al protocolo

- GIVEN un lote firmado con los vectores dorados del legacy
- WHEN el maestro lo verifica
- THEN lo acepta con la misma respuesta que el legacy
- AND Regla fijada: el contrato de wire no cambia

#### Scenario: Nonce no consumido por firma inválida

- GIVEN una petición con firma inválida y un nonce desconocido
- WHEN el maestro la verifica
- THEN la rechaza y el nonce queda disponible
- AND Regla fijada: el nonce se consume tras validar la firma

### Requirement: Traducción por mapa sembrado

El payload del terminal offline es SQL crudo MySQL con nombres del legacy, MUST NOT aplicarse literal sobre el esquema destino. La traducción de identificadores MUST residir en un mapa **sembrado** que cubra las ~18 tablas del terminal, nunca en código. La dirección de la traducción MUST ser del esquema legacy hacia el destino. El maestro MUST ser capaz de recibir y aplicar el payload sin tocar el contrato.

**Trazabilidad:** S7 · Criterio C8 · exploration.md §9.2 y §9.5 (P1)

#### Scenario: Traducción de una tabla legacy

- GIVEN una sentencia `INSERT INTO selling_info …` del log del terminal
- WHEN el adaptador la traduce
- THEN se resuelve la tabla y las columnas contra el mapa sembrado, sin código por tabla
- AND Regla fijada: el mapa de traducción es dato auditable, no código

#### Scenario: Lote con operación inválida

- GIVEN un lote con una operación sin `sql` y `args` válidos
- WHEN el adaptador lo procesa
- THEN rechaza el lote entero sin aplicar parcialmente
- AND Regla fijada: un lote es todo o nada

### Requirement: Aplicación idempotente del lote

El maestro MUST registrar cada lote aplicado en `sync_batches` con `UNIQUE (client_id, payload_sha256)`. Un reenvío con el mismo contenido MUST responderse con éxito MUST NOT volver a aplicarse. El lote MUST aplicarse dentro de una única transacción.

**Trazabilidad:** S7 · Criterio C8 · exploration.md §9.3 (S1)

#### Scenario: Reenvío por respuesta perdida

- GIVEN un lote ya aplicado cuyo cliente no recibió la respuesta
- WHEN el cliente reenvía el mismo contenido
- THEN el maestro responde con éxito sin una segunda aplicación
- AND Regla fijada: el reenvío no viola la clave primaria ni duplica efectos

### Requirement: Truncado del log sólo con respuesta exitosa

El cliente legacy trunca su log de sincronización sólo cuando la respuesta fue exitosa. Una respuesta perdida MUST, por tanto, provocar un reenvío idéntico. El maestro MUST tolerar ese reenvío sin fallar, de modo que el terminal no quede congelado en la ventana de corte.

**Trazabilidad:** S7 · Criterio C8 · exploration.md §9.3 (S1) y §10.5

#### Scenario: Terminal con respuesta perdida

- GIVEN un cliente que no trunca su log por respuesta perdida y reenvía el lote
- WHEN el maestro recibe el reenvío
- THEN lo acepta sin error y el terminal puede seguir sincronizando
- AND Regla fijada: la idempotencia del maestro desactiva la falla operativa del reenvío
