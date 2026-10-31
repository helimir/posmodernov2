# Impresión LAN — Especificación

## Purpose

Destinos, agentes y trabajos de impresión con su máquina de estados. El servidor genera el contenido; el agente sólo lo escribe. La emisión fiscal nunca depende de la impresora.

## Requirements

### Requirement: Un origen de contenido, binario por transporte

El servidor MUST generar los bytes ESC/POS para los transportes `lan_agent` y `server_socket`, y MUST renderizar HTML o PDF desde el mismo modelo de plantilla para el transporte `browser`. El agente MUST sólo escribir los bytes recibidos y MUST NOT generarlos, de modo que sea agnóstico al lenguaje y a la librería.

**Trazabilidad:** S8 · Criterio C9 · research/escpos-agente-lan.md §7.2 (opción A)

#### Scenario: El agente no genera el ticket

- GIVEN un trabajo encolado hacia un agente LAN
- WHEN el agente lo recibe
- THEN recibe bytes ya generados y los escribe en el destino, sin interpretarlos
- AND Regla fijada: lo unificable es el origen del contenido, no el binario

### Requirement: Agente en pull con long-poll

El agente MUST iniciar siempre la conexión: registro, heartbeat y long-poll de trabajos. El token MUST ser por agente, almacenado sólo como hash, y el servidor MUST resolver la tienda desde el `agent_id`, nunca desde el cuerpo de la petición. La respuesta de un resultado repetido MUST ser idempotente.

**Trazabilidad:** S8 · Criterio C9 · research/escpos-agente-lan.md §5.2, §5.3 y §7.1

#### Scenario: Agente detrás de NAT

- GIVEN un terminal en una LAN privada sin IP pública
- WHEN el agente consulta trabajos por long-poll
- THEN recibe el trabajo sin que el servidor deba alcanzar al terminal
- AND Regla fijada: el agente siempre inicia la conexión

### Requirement: Destinos de impresión admitidos

Los destinos de un agente MUST ser una impresora de red por TCP 9100 o una impresora compartida de Windows. El acceso USB directo en Windows MUST NOT ser un destino admitido, porque exige instalar un driver con Zadig y no es viable en despliegue desatendido.

**Trazabilidad:** S8 · Criterio C9 · research/escpos-agente-lan.md E13, E14 y §6.3

#### Scenario: Destino USB rechazado

- GIVEN un destino configurado con acceso USB directo
- WHEN se valida la configuración
- THEN el sistema la rechaza y exige TCP 9100 o impresora compartida
- AND Regla fijada: el USB directo se descarta por requisito de Zadig

### Requirement: El perfil de impresora es dato

El `charset`, el `char_per_line`, el perfil ESC/POS y el límite de payload del QR MUST residir en el destino y congelarse en el trabajo. La codepágina por defecto MUST ser CP850, porque CP858 no es codificable en la librería de impresión usada. Si el perfil no soporta QR, la generación MUST fallar en el servidor con un error explícito, y el servidor MUST imponer el límite de payload del QR, que ninguna librería valida.

**Trazabilidad:** S8 · Criterio C9 · research/escpos-agente-lan.md §3.3, §3.4 y §7.1

#### Scenario: QR con perfil incompatible

- GIVEN un destino cuyo perfil no soporta QR
- WHEN se encola un trabajo con QR
- THEN el servidor lo rechaza antes de enviarlo, sin dejar un ticket mudo
- AND Regla fijada: el límite de payload del QR lo impone el servidor

### Requirement: La emisión fiscal no depende de la impresión

El estado `issued` del documento fiscal MUST ser independiente del estado del trabajo de impresión. Una reimpresión MUST crear un trabajo nuevo contra el mismo documento, MUST NOT consumir correlativo y MUST NOT modificar el documento fiscal.

**Trazabilidad:** S8 · Criterio C9 · research/escpos-agente-lan.md §5.4 y §7.3

#### Scenario: Impresora caída tras emitir

- GIVEN un documento ya emitido
- WHEN falla la impresión y luego se reimprime
- THEN el documento sigue emitido y no se crea ninguna reserva de correlativo adicional
- AND Regla fijada: reimprimir no es reemitir
