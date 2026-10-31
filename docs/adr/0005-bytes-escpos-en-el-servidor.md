# ADR-0005 · Bytes ESC/POS en el servidor + agente en modo pull; USB directo descartado

- **Estado**: Aceptado
- **Fecha**: 2026-10-31
- **Ámbito**: Fase 0 — Fundaciones (`fase0-fundaciones`)
- **Diseño**: `openspec/changes/fase0-fundaciones/design.md` §D.5 · §C.3 · §A.1 (M23)

## Contexto

El legacy genera el ESC/POS **en el servidor** (`_inc/print.php:30-31`) pero el endpoint está roto: `dd($data)` en `:29` y un segundo `dd($printer)` en `_inc/lib/escpos.php:32`; `dd()` = `echo "<pre>".print_r($data,true)."</pre>"; exit;`. Además, el modo "navegador" del legacy (`PosController.js:881-890`) imprimía **HTML**, no ESC/POS: el binario nunca fue un denominador común.

Al mover el servidor a la nube, el servidor deja de alcanzar la impresora de la LAN privada (NAT, firewall, IP privada): hace falta un proceso en la LAN.

Dos restricciones verificadas condicionan el diseño:

- **Windows + USB directo exige Zadig**: la ruta `libusb` de `node-escpos/driver` no puede separar el kernel driver en Windows (*"This functionality is not available on Windows"*) y exige instalar WinUSB/libusb mediante Zadig. Un despliegue desatendido no puede depender de que un técnico ejecute Zadig.
- **CP858 no es codificable** en `mike42/escpos-php` (`capabilities.json` declara la tabla 19 sólo con `name` y `python_encode`, sin `iconv`; `CodePage::isEncodable()` devuelve `false`). La tabla correcta para español es **CP850** (tabla 2), que sí es codificable.

## Decisión

1. El **servidor genera los bytes ESC/POS** con `mike42/escpos-php` y los congela en `print_jobs.payload_bytes` (bytea) junto con `payload_sha256`. El agente **sólo escribe**: recibe bytes y los entrega al destino.
2. El agente trabaja en **pull** con long-poll: siempre inicia la conexión (registro, heartbeat, `GET /api/print-agents/jobs`). El servidor nunca alcanza al terminal.
3. **Destinos admitidos**: `tcp://host:9100` (impresora de red) o **impresora compartida de Windows** (`smb://`, `LPT1` mapeado). **USB directo queda excluido** como destino admitido.
4. Autenticación: **token por agente** (no por tienda), sólo `token_hash` (argon2id/bcrypt), con `Authorization: Bearer` + `X-Agent-Id`; el servidor **fuerza** que el `agent_id` del token coincida con el del body/path y **resuelve la tienda desde el `agent_id`**, nunca desde el cuerpo.
5. El **perfil de impresora es dato** de `print_targets`: `printer_profile`, `charset` (CP850 por defecto), `char_per_line`, `max_qr_payload_bytes`. Se congela en el trabajo. Si el perfil no soporta QR, la generación **falla en el servidor** con error explícito; el límite de payload del QR lo impone el servidor, ninguna librería lo valida.
6. `fiscal_documents.status='issued'` es **independiente** de `print_jobs.status`. Una reimpresión crea un trabajo nuevo (`reason='reprint'`, `reprint_of_job_id`), **0 reservas nuevas** y **0** modificación del documento.

## Consecuencias

**Positivas**
- Un único formateador ⇒ el mismo ticket en LAN, en servidor y en el transporte `browser` (que renderiza HTML/PDF desde el **mismo modelo de plantilla**).
- El agente queda **agnóstico al lenguaje**: puede implementarse en Node, en Go o en un binario; no necesita la librería de impresión.
- El servidor puede **rechazar un ticket no representable antes de enviarlo**: el error aparece en el backoffice, no como un `?` en el papel.
- El token por agente acota una fuga a **una caja**, no a una tienda.
- `issued` no depende de la impresora: una impresora caída no invalida una factura.

**Costes**
- El servidor carga con el render ESC/POS y con `escpos-php` como dependencia.
- El perfil de impresora hay que configurarlo bien por destino: un `char_per_line` erróneo produce tickets mal alineados; el dato es auditable, pero hay que dar de alta las impresoras.
- La librería `escpos-php` no trae composición de ticket (`table`, `leftRight`): hay que construirla o usar una plantilla propia.
- El instalador del agente es **entregable de Fase 1** (riesgo R9 de `exploration.md`): en Fase 0 sólo se fijan esquema y contrato.
- El long-poll mantiene una petición abierta por agente: hay que dimensionar el servidor y el `poll_after_ms` es un parámetro, no un literal.

**Riesgo residual**
- El límite real de payload del QR es el buffer del modelo de impresora y no es verificable en este entorno (`escpos-php` no lo documenta): se declara un valor conservador en `print_targets.max_qr_payload_bytes` y se calibra en Fase 1 contra el manual del modelo.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| Renderizar el ticket en el agente | Tres implementaciones del render ⇒ tres formatos; contradice lo que ya hacía el legacy y la exigencia de un único formato. |
| Render en el agente desde una DSL | Traslada el problema: el agente vuelve a depender de una librería y de una versión de la plantilla. |
| Push desde el servidor al agente | Exige IP pública, reenvío de puertos o VPN/túnel en cada terminal; el pull atraviesa NAT sin reglas. Un "push" sobre la conexión abierta por el agente es pull con otra forma. |
| USB directo como destino admitido | Exige Zadig en Windows: inviable en despliegue desatendido. La alternativa para una impresora USB en terminal Windows es la **impresora compartida** (`LPT1` / `COM`), que no requiere driver adicional. |
| Token por tienda | Un terminal comprometido de la tienda A podría suplantar a la B si el endpoint no fuerza el scope. El token por agente acota el radio de impacto. |
| Que el navegador genere ESC/POS | El navegador no habla ESC/POS: el modo `browser` renderiza HTML/PDF desde el mismo modelo de plantilla. Lo unificable es el **origen del contenido**, no el binario. |
| CP858 como codepágina por defecto | No es codificable en `escpos-php`: los caracteres exclusivos se sustituyen por `?`. CP850 es codificable y cubre el español. |
