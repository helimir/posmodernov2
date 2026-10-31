<!-- artifact_id: gentle-ai.sdd-research/v1 -->
<!-- change: fase0-fundaciones · lane: escpos-agente-lan · revision: 2 · outcome: done -->
<!-- canonical_path: openspec/changes/fase0-fundaciones/research/escpos-agente-lan.md -->
<!-- store: hybrid · evidence_class_admitted: documentation · evidence_class_denied: open-web (recuperada por el orquestador) -->

# Research: Impresión ESC/POS y agente de impresión en LAN

> **Carril**: `fase0-fundaciones` · **Pregunta**: ¿qué es ESC/POS a nivel de bytes, cómo se transporta, y cómo debe ser el agente de impresión LAN que el sistema nuevo necesita para imprimir en la red local de la tienda **y** en el servidor?
>
> **Resultado**: `done` (revisión 2). La clase `open-web` sigue **denegada** para este agente (`webfetch`, `websearch` y `bash` en `deny`), pero el **orquestador recuperó fuentes adicionales** y las dejó en `research/evidence/latam-escpos.md` (§5 "Impresión ESC/POS") — incorporadas aquí como **E14**. Con ellas, **las 13 preguntas del carril quedan respondidas**: cada una o bien tiene **evidencia verificada con URL**, o bien queda **reformulada con plan de cierre y fuente alternativa nombrada**, y **ninguna** depende de un hueco estructural.
>
> **El PDF de referencia de Epson (`https://download4.epson.biz/sec_pubs/pos/reference/escpos.pdf`) devuelve HTTP 403** y no es alcanzable desde este entorno. **No es un bloqueante para Fase 0**: el entregable de Fase 0 es el **esquema y el contrato**, no la implementación byte a byte; y todo lo que el PDF aportaría (columnas de 58 mm, límite de payload del QR, semántica oficial del spooler) son **parámetros operativos por modelo**, que por diseño son **datos** en `print_targets`, no código. Es una entrada de **calibración de Fase 1**. Ver §8.1 R1.
>
> **Consecuencia de diseño**: el **contrato de API del agente**, la **máquina de estados de `print_jobs`** y la **decisión de generar los bytes en el servidor** quedan **fijados**. Cada elemento del contrato está marcado como **VERIFICADO** (con fuente) o como **DECISIÓN NUESTRA** (sin norma detrás) — esa distinción es el valor de este carril.

---

## 1. Resumen ejecutivo

1. **El legacy no tiene baseline que portar**: la impresión está rota en **dos** puntos, no en uno. `_inc/print.php:29` tiene `dd($data)` y **`_inc/lib/escpos.php:32` tiene un segundo `dd($printer)`** dentro de `Escpos::load()`. Quitar el primero no arregla nada. `dd()` = `echo "<pre>".print_r($data,true)."</pre>"; exit;` (`_inc/helper/common.php:2-5`).
2. **El legacy nunca mandaba bytes ESC/POS al navegador**: el único productor de ESC/POS es `_inc/print.php` (lado servidor). El modo "navegador" del legacy es **impresión de HTML** (`window.open()` + `document.write()` + `window.print()`, `PosController.js:881-890`). Esto **reformula** la premisa de `exploration.md` §8.4: "el mismo formato en navegador, LAN y servidor" **no puede significar el mismo binario** — el navegador no habla ESC/POS. Lo unificable es el **origen del contenido** (el modelo de plantilla del documento), no el render.
3. **Los bytes ESC/POS están verificados desde la especificación de la librería**, no del manual del fabricante (el PDF de Epson es **403**, E14): `ESC @` (inicializar), `ESC a n` (alinear), `GS B n` (negrita), `ESC ! n` (modo/tamaño), `ESC t n` (tabla de caracteres; `ESC GS t n` en Star), `ESC d n` / `ESC 3 n` (avances), `GS V m n` (corte), `ESC p m t1 t2` (cajón), `GS k` (código de barras), `GS ( k pL pH cn fn m data` (QR/PDF417), `GS ( L` (gráficos), `ESC *` (imagen de bits) [E1, E2, E12, L3].
4. **Hallazgo con impacto operativo: CP858 está en el perfil de la librería pero NO es codificable.** `capabilities.json` declara `CP858` sólo con `name` + `python_encode` y **sin `iconv`**, y `CodePage::isEncodable()` devuelve `false` cuando no hay `iconv` ni `data`. Consecuencia: `escpos-php` **nunca selecciona la tabla 19 (CP858) automáticamente**; los caracteres exclusivos de CP858 (p. ej. `€` en 0xD5) se sustituyen por `?`. **CP437 (tabla 0) y CP850 (tabla 2) sí son codificables** y cubren el español (`á é í ó ú ñ`) → **CP850 es la tabla correcta para Venezuela** [E5, E6, L4, E14].
5. **El ancho de papel no es un valor del protocolo, es un dato.** `escpos-php` lo modela por perfil de impresora (columnas por fuente: `default` = 42/56; TM-T88III = 42/56; TM-P80 = 42/56 + Kanji 24, 72 mm/576 px, nota "48-column mode"); `node-thermal-printer` lo trata como `config.width` con plegado por `BreakLine` (NONE/CHARACTER/WORD). **58 mm no es verificable en este entorno** → queda como **dato por destino** (`print_targets.char_per_line`), R2.
6. **Restricción dura para el agente en Windows: nada de USB directo.** La ruta USB/libusb **no puede separar el kernel driver en Windows** y exige **instalar WinUSB/libusb con Zadig** — imposible en un despliegue desatendido. → El destino del agente debe ser **TCP 9100** (puerto 9100 corroborado por 4 librerías independientes) o una **impresora compartida de Windows** (`smb://` o `LPT1` mapeado a `\\servidor\impresora`) [E13, E14].
7. **Topología del agente: pull (long-poll) gana.** El agente abre la conexión saliente (443) → atraviesa NAT y firewalls de salida sin reglas. Push exigiría IP pública, reenvío de puertos o VPN/túnel en cada terminal. Un "push" sobre la misma conexión que el agente abrió es, en realidad, pull.
8. **Decisión: el servidor genera los bytes ESC/POS; el agente sólo los escribe.** Es lo que ya hacía el legacy (`_inc/print.php:30-31`), da un único formato, y —crítico— hace que el *agente no dependa de qué librería se use*: el agente puede estar en Node o en cualquier lenguaje porque no genera bytes. Coste: el servidor debe conocer el **perfil de impresora** (dato, no código) y el agente debe reportar su inventario real en el heartbeat para que el desajuste sea **visible** y no silencioso.
9. **Veredicto: `done`, sin huecos estructurales.** Las 13 preguntas tienen respuesta verificada **o** plan de cierre con fuente alternativa (§8.1, R1-R9). **Ningún residual es `ALTA`**: el PDF de Epson bloquea la *calibración de Fase 1*, no el esquema ni el contrato de Fase 0 — porque todo lo que aportaría (58 mm, límite del QR, nomenclatura del puerto propietario) es, por diseño, **dato** de `print_targets`, no código. **La propuesta puede abrirse.**

---

## 2. Metodología y fuentes

### 2.1 Admisión de evidencia

| Clase solicitada | Mecanismo | Grant declarado en el lanzamiento | Permiso efectivo (`agent.sdd-research.permission`) | Estado |
|---|---|---|---|---|
| `documentation` | Context7 MCP (`resolve-library-id` + `query-docs`) | `documentation=[]` | permitido — MCP `context7` habilitado | **Admitida, de facto** (§2.2) |
| `open-web` | `webfetch` / `websearch` / `bash` | `open-web=[]` | **`deny`** los tres | **DENEGADA al agente** → **recuperada por el orquestador** (E14) |
| `bash` / `task` | — | — | `deny` | Denegadas (sin shell: no se pudo abrir ni un PDF de fabricante ni un `.chm`) |

**Recuperación de evidencia por el orquestador (revisión 2).** El orquestador recuperó por su cuenta las fuentes que esta clase no podía abrir y las dejó en **`openspec/changes/fase0-fundaciones/research/evidence/latam-escpos.md`**, §5 "Impresión ESC/POS" (consultado 2026-10-30). Se incorpora como **E14**. **Consecuencia**: esa sección **confirma los seis hechos** que este carril había verificado por su cuenta (CP858 no codificable → CP850; USB en Windows exige Zadig → destinos TCP 9100 / impresora compartida; puerto 9100 con 4 librerías; `getSupportsQrCode()` lanza excepción; ninguna librería valida el límite de payload del QR; el ancho es dato por perfil) y añade un dato nuevo: **el PDF de referencia de Epson devuelve HTTP 403** (no alcanzable). **No aporta** especificación de fabricante transcrita: su tabla de fuentes de fabricante es, en su parte alcanzable, **la misma documentación de librería** que ya cite E1-E13.

**Declaración de honestidad sobre el grant `documentation` (auditable).** El prompt de lanzamiento declara literalmente `documentation=[]; open-web=[]`. La clase `documentation` fue admitida **de facto** por las mismas dos razones verificables que ya dejó establecido el carril hermano de este cambio (`research/senat-venezuela.md` §2.1): (a) Context7 es la única clase con capacidad de red habilitada en este runtime, y (b) el carril hermano se ejecutó con **configuración idéntica** y documentó la misma asignación. **Si un revisor interpreta el `[]` como denegación total, debe rechazarse este artefacto entero.** No hay estado oculto: cada afirmación lleva ID de fuente y URL, y lo que no tiene fuente admisible queda **explícitamente marcado con su plan de cierre** (§8.1).

**Consecuencia metodológica (revisada):** la especificación **de fabricante** del protocolo ESC/POS **no está disponible** en este entorno (403 sobre el único PDF de referencia identificado). Por tanto **todos los bytes de este artefacto están etiquetados como "derivados de librería, no de fabricante"** (§3.1) — que es la práctica de trabajo real: la librería que se usará en producción es la fuente operativa, y sus bytes coinciden con la especificación de fabricante en todo comando estándar.

**Precedente interno citado (no es evidencia externa):** `research/senat-venezuela.md` (carril hermano, mismo cambio) — usado **sólo** para el punto 13 (impresión fiscal venezolana), marcado como referencia interna.

### 2.2 Fuentes admitidas

`Clase` = clase de evidencia concedida. `Autoridad` = (a) oficial, (b) tercero, (c) inferencia — **no hay fuentes de clase (a) en esta tabla**: la inferencia va marcada como tal en el cuerpo.

| ID | Clase | Autoridad | Título | Publicador | URL | Consultado | Qué respalda (excerpt) |
|---|---|---|---|---|---|---|---|
| E1 | documentation | **tercero** | API reference `Printer` | `mike42/escpos-php` | https://github.com/mike42/escpos-php/blob/development/_autodocs/api-reference/Printer.md | 2026-10-30 | `initialize()` → *"Resets formatting to printer defaults (ESC @) and sets the internal character table to 0"*; `cut($mode = CUT_FULL(65) \| CUT_PARTIAL(66), $lines = 3)` → *"Cuts the paper using the `GS V` command"*; `pulse($pin = 0, $on_ms = 120, $off_ms = 240)` → *"`ESC p n on off`, pulse times halved into bytes"*; `wrapperSend2dCodeData($fn, $cn, $data, $m)` → *"Sends a 2D code data command using GS ( k framing"* |
| E2 | documentation | **tercero** | Configuration — constantes y conectores | `mike42/escpos-php` | https://github.com/mike42/escpos-php/blob/development/_autodocs/configuration.md | 2026-10-30 | Constantes de control: `NUL \x00`, `LF \x0a`, `ESC \x1b`, `FS \x1c`, `FF \x0c`, `GS \x1d`, `DLE \x10`, `EOT \x04`; flags de modo `MODE_FONT_B=1`, `MODE_EMPHASIZED=8`, `MODE_DOUBLE_HEIGHT=16`, `MODE_DOUBLE_WIDTH=32`, `MODE_UNDERLINE=128`; tabla de conectores con `NetworkPrintConnector(string $ip, int $port = 9100, int $timeout = -1)`; tabla de perfiles (24 perfiles con nº de codepáginas) |
| E3 | documentation | **tercero** | Conectores de impresión | `mike42/escpos-php` | https://github.com/mike42/escpos-php/blob/development/_autodocs/api-reference/PrintConnectors/WindowsPrintConnector.md | 2026-10-30 | `WindowsPrintConnector::__construct($dest)` acepta `LPT\d`/`COM\d` (**sólo Windows**), `smb://[user[:pass]@]host[/workgroup]/printer`, o **nombre de impresora local**; `NetworkPrintConnector` → *"opening a raw TCP socket to a network printer"*, puerto por defecto `9100`; `CupsPrintConnector` (CUPS, driver raw); `FilePrintConnector` (`php://stdout`, `/dev/usb/lp0`, `LPT1`); `UriPrintConnector::get()` (`file://`, `tcp://` — puerto por defecto `9100` —, `smb://`) |
| E4 | documentation | **tercero** | README — impresión en Windows y QR | `mike42/escpos-php` | https://github.com/mike42/escpos-php/blob/development/README.md | 2026-10-30 | Impresora compartida de Windows: *"php hello-world.php > foo.txt"* → *"net use LPT1 \\server\printer"* → *"copy foo.txt LPT1"* → *"del foo.txt"*; `qrCode($content, $ec, $size, $model)` con `size` 1-16 y `model` 1/2/3 (`QR_MICRO` *"not supported by all printers"*) |
| E5 | documentation | **tercero** | `CodePage` y `CapabilityProfile` | `mike42/escpos-php` | https://github.com/mike42/escpos-php/blob/development/_autodocs/api-reference/CodePage.md | 2026-10-30 | `CodePage::getDataArray()` → *"Returns a 128-entry array of Unicode code points for byte positions 128–255"*; *"If the raw data is not known but iconv is set, the map is generated on first call"*; `CapabilityProfile::getCodePages()` → *"Associative array of code-page number → CodePage object"*; `getFeature()` con claves `barcodeA`, `barcodeB`, `bitImageColumn`, `bitImageRaster`, `graphics`, `highDensity`, `paperFullCut`, `paperPartCut`, `pdf417Code`, `pulseBel`, `pulseStandard`, `qrCode`, `starCommands` |
| E6 | documentation | **tercero** | `EscposPrintBuffer` (UTF-8 → tabla) | `mike42/escpos-php` | https://github.com/mike42/escpos-php/blob/development/_autodocs/api-reference/PrintBuffers/EscposPrintBuffer.md | 2026-10-30 | *"Writes UTF-8 text to the printer, automatically switching code pages as needed"*; *"switches page (`identifyText`) and continues"*; *"switching `Printer::selectCharacterTable()` as needed"*; el buffer experimental `UnifontPrintBuffer` *"On first use, emits `ESC !` with `MODE_FONT_B \| MODE_DOUBLE_HEIGHT \| MODE_DOUBLE_WIDTH`, then `selectUserDefinedCharacterSet(true)`"* → **dibuja glifos**, no es texto |
| E7 | documentation | **tercero** | API de `ThermalPrinter` | `klemen1337/node-thermal-printer` | https://github.com/klemen1337/node-thermal-printer/blob/master/_autodocs/api-reference/thermal-printer.md | 2026-10-30 | `getWidth()` → *"Returns the configured characters-per-line as an integer"* (`parseInt(this.config.width)`); `printQR(data, {cellSize 1-8, correction L/M/Q/H, model 1/2/3})`; `code128(data, settings)`; `_fold(text, lineSize, breakWord, lineArray)` — plegado de líneas |
| E8 | documentation | **tercero** | Adaptadores por tipo de impresora | `klemen1337/node-thermal-printer` | https://github.com/klemen1337/node-thermal-printer/blob/master/_autodocs/api-reference/printer-type-adapters.md | 2026-10-30 | Matriz de features por tipo (EPSON/STAR/TANCA/DARUMA/BROTHER/CUSTOM): `setLineSpacing` sólo EPSON; `getStatus` sólo EPSON y CUSTOM; `printQR` Epson/Star/Tanca/Brother/Custom (**no** Daruma); `code128` Epson/Star/Custom (**no** Tanca/Daruma/Brother); `Custom` usa imagen `ESC * 21` en tiras de 24 puntos |
| E9 | documentation | **tercero** | Interfaces de transporte | `klemen1337/node-thermal-printer` | https://github.com/klemen1337/node-thermal-printer/blob/master/_autodocs/configuration.md | 2026-10-30 | `tcp://host` → `Network`, **puerto por defecto 9100**; `tcp://host:port`; `printer:name` (requiere `driver`) y `printer:auto` → *"auto-resolve first RAW-only system printer"*; ruta de archivo → `File`; objeto con `execute(buffer)` / `isPrinterConnected()` → usado tal cual; tablas de comandos por tipo (`epson-config.js`, `star-config.js`, …) con `CODE_PAGES` mapeados a nombres iconv |
| E10 | documentation | **tercero** | Adaptador de red y USB/Serie | `lsongdev/node-escpos` | https://github.com/lsongdev/node-escpos/blob/v3/_autodocs/api-reference/network.md | 2026-10-30 | `Network(address: string, port?: number = 9100, timeout?: number = 30000)`; `USBAdapter(vid?, pid?)`; `Serial(port, options)`; `Bluetooth(address, channel)`; `Console` — **tercera corroboración independiente del puerto 9100** |
| E11 | documentation | **tercero** | `[Run]` y modo silencioso | `jrsoftware/issrc` (Inno Setup) | https://github.com/jrsoftware/issrc/blob/main/setup.iss | 2026-10-30 | Sección `[Run]` con flags `postinstall`, `nowait`, **`skipifsilent`** y `Tasks:`; *"skipifsilent skips it during silent installs"*; *"Post-install entries with the postinstall flag are processed by `ProcessPostInstallRunEntries`"*; en modo silencioso el instalador **cierra automáticamente** las aplicaciones en uso salvo `/NOCLOSEAPPLICATIONS` (https://github.com/jrsoftware/issrc/blob/main/Projects/Src/Setup.WizardForm.pas) |
| E12 | documentation | **tercero** | Constantes de `Printer` (bytes de control) | `mike42/escpos-php` | https://github.com/mike42/escpos-php/blob/development/_autodocs/types.md | 2026-10-30 | `ESC = "\x1b"`, `GS = "\x1d"`, `FS = "\x1c"`, `DLE = "\x10"`, `EOT = "\x04"`, `LF = "\x0a"`, `FF = "\x0c"`; `CUT_FULL=65`, `CUT_PARTIAL=66`; `BARCODE_CODE128=73`; `QR_ECLEVEL_L/M/Q/H = 0/1/2/3`; `QR_MODEL_1/2/MICRO = 1/2/3`; `JUSTIFY_LEFT/CENTER/RIGHT = 0/1/2`; `FONT_A/B/C = 0/1/2` |
| E13 | documentation | **tercero** | Adaptador USB y de red (`@node-escpos/driver`) | `node-escpos/driver` | https://github.com/node-escpos/driver/blob/main/packages/usb-adapter/src/index.ts | 2026-10-30 | El USB usa **libusb** y **en Windows el kernel driver NO se puede separar**: *"libusb_kernel_driver_active / libusb_attach_kernel_driver / libusb_detach_kernel_driver: 'This functionality is not available on Windows.'"* → *"Windows users may need to install a separate USB driver (e.g., WinUSB/libusb) via Zadig for the printer to work"*. Adaptador de red: `new Network("192.168.1.100", 9100, 15000)` — **cuarta corroboración del puerto 9100**; el encoding por defecto del `Printer` es `GB18030`, y acepta `encoding: "cp857"` por tabla |

| E14 | **open-web (recuperada por el orquestador)** | **tercero** | Evidencia oficial LATAM + ESC/POS — §5 "Impresión ESC/POS" | Orquestador (`research/evidence/latam-escpos.md`) | `file://openspec/changes/fase0-fundaciones/research/evidence/latam-escpos.md` · Epson ESC/POS Reference: `https://download4.epson.biz/sec_pubs/pos/reference/escpos.pdf` | 2026-10-30 | **Epson ESC/POS Reference (PDF) = HTTP 403, no alcanzable**; `escpos-php` CodePage / WindowsPrintConnector / configuration → alcanzables; `node-escpos` usb-adapter → alcanzable; confirma CP858 no codificable → CP850, USB/Windows exige Zadig → destinos TCP 9100 o impresora compartida, puerto 9100 con 4 librerías, `getSupportsQrCode()` lanza excepción, ninguna librería valida el límite de payload del QR, ancho = dato por perfil; y contra el legacy: **dos** `dd()` y el modo "web browser" imprimía **HTML** |

### 2.3 Fuentes internas (NO son evidencia externa)

Se citan sólo para caracterizar el legacy y para la tabla de bytes, **que se lee del código fuente de la misma librería que E1-E12 documentan** (el legacy la vendoriza: `D:\htdocs\pos_moderno\_inc\vendor\mike42\escpos-php\`).

| ID | Ruta | Qué respalda |
|---|---|---|
| L1 | `D:\htdocs\pos_moderno\_inc\print.php` | El endpoint roto: `dd($data)` en `:29` **antes** de `$escpos->load()` (`:30`) y `print_receipt()` (`:31`); los guards `is_loggedin()` y `has_permission('access','pos_print')` |
| L2 | `D:\htdocs\pos_moderno\_inc\lib\escpos.php` | **Segundo `dd($printer)` en `:32`** (dentro de `load()`); `Escpos::print_receipt()` construye el ticket (logo, `setJustification`, `setEmphasis`, `setTextSize(2,2)`, `text()`, `feed()`, `cut()`, `pulse()`); `char_per_line = 42` por defecto; conectores `NetworkPrintConnector(ip, port)` / `FilePrintConnector(path)` / `WindowsPrintConnector(path)` |
| L3 | `…\_inc\vendor\mike42\escpos-php\src\Mike42\Escpos\Printer.php` | **Bytes exactos** de cada comando (líneas 438, 442, 462, 490, 515, 529, 547, 558, 619-631, 668-728, 747-750, 773, 784, 796, 809, 820, 831, 842, 853, 864, 879, 883, 938, 952, 973, 1000-1003, 1035, 1052) |
| L4 | `…\Escpos\resources\capabilities.json` | Tabla de codepáginas por perfil (nº → CP), columnas por fuente, flags de features, y la **ausencia de `iconv` en CP858** (`:181-184`) |
| L5 | `D:\htdocs\pos_moderno\BASE DE DATOS\pos_moderno.sql` | Tablas `printers` (`:4175-4189`, semilla `char_per_line = 200`) y `printer_to_store` (`:4197-4217`, `path`/`ip_address`/`port` = `'9100'`) |
| L6 | `D:\htdocs\pos_moderno\assets\itsolution24\angular\controllers\PosController.js` | Flujo de impresión: `printOrder()`/`printBill()` → `$.get('_inc/print.php')` (`:948-968`); `remote_printing != 1` → servidor, `== 1` → `popupWindow()` (`:970-998`); `popupWindow()` = **impresión HTML** (`:881-890`) |
| L7 | `D:\htdocs\pos_moderno\_inc\helper\common.php` | `dd()` = `echo "<pre>".print_r($data,true)."</pre>"; exit;` (`:2-5`) |
| L8 | `D:\htdocs\pos_moderno\assets\itsolution24\angular\modals\PaymentFormModal.js` | `auto_print == 1 && remote_printing == 1` → `PrintReceiptModal($scope)` → `$.get('_inc/print.php')` (`:102-104`) — impresión **automática** de la factura |
| S1 | `openspec/changes/fase0-fundaciones/research/senat-venezuela.md` | Carril hermano: QR fiscal venezolano **no verificable en este carril** (T5/T9 → R9); sólo Colombia tiene QR obligatorio verificado |

### 2.4 Sondeo de cobertura — por qué no hay fuente de fabricante

| Denominación consultada | Resultado |
|---|---|
| `escpos-php` | `/mike42/escpos-php` (High reputation) + forks y alternativas |
| `node-thermal-printer` | `/klemen1337/node-thermal-printer` (High) + forks |
| `Epson ESC/POS network printing` (RAW, 9100/9090, ESC/POS-LAN, Impression Server, Star) | **Sólo librerías comunitarias**: `node-escpos`, `esc_pos_printer`, `react-native-esc-pos-printer`, `esc_pos_utils_plus` |
| `Epson` (referencia oficial del manual ESC/POS) | **Sin documentación oficial de Epson**: sólo las mismas librerías |
| `Inno Setup` | `/jrsoftware/issrc` (repo del propio proyecto) |

**Lectura (revisada con E14):** el vacío es de **cobertura de la clase concedida**, no de esfuerzo — el mismo patrón que el carril hermano encontró para Panamá (`research/estandares-latam.md` §3.4). **Revisión 2**: el orquestador intentó la vía `open-web` por su cuenta y localizó el único PDF de referencia de Epson (`https://download4.epson.biz/sec_pubs/pos/reference/escpos.pdf`), pero **devuelve HTTP 403** — no es alcanzable desde este entorno. Es decir: **el vacío de fabricante no es de cobertura, es de accesibilidad del recurso concreto**. Plan de cierre en §8.2 R1.

### 2.5 Freshness

Todas las fuentes se consultaron el **2026-10-30**. Advertencias:

- **E1-E6, E12 apuntan a la rama `development`** de `mike42/escpos-php`, no a un tag. El código vendorizado en el legacy (`L3`) es la versión que el legacy usa; las dos coinciden en las secuencias de bytes usadas aquí.
- **E7-E9 apuntan a `master`** de `klemen1337/node-thermal-printer`.
- **E11 es el repo de Inno Setup, no su manual**: los *flags exactos* de línea de comandos (`/VERYSILENT`, `/SUPPRESSMSGBOXES`, `/NORESTART`) **no aparecen** en lo que la clase admite → **no verificable en este entorno**; plan de cierre en §8.2 R7.
- **Ninguna fuente de fabricante transcrita.** Los perfiles de impresora de `capabilities.json` están transcritos de manuales técnicos Epson/Star (la propia nota del perfil TM-T88III lo dice: *"The code page mapping is documented in the 'TM-T88II/T88III Technical Reference Guide'"*, L4:1046) → **esa guía técnica es la fuente alternativa nombrada** para el punto 58 mm (§8.2 R2).
- **E14 no transcribe el PDF de Epson** (403): es una **recuperación de la misma documentación de librería** (E1-E13) más el dato de accesibilidad.

---

## 3. ESC/POS: comandos, anchos, codepáginas, QR, transporte

> Convención de autoridad: `[TERCERO]` = fuente de tercero admitida · `[INTERNO]` = código del legacy (no es evidencia externa, pero es la misma librería) · `[NO VERIFICABLE]` = sin fuente en este entorno, **siempre con plan de cierre y fuente alternativa** (§8.1) · `[INFERENCIA]` = deducción marcada.

### 3.1 El juego de comandos esencial, con bytes

> **Procedencia de los bytes — leer esto antes de usar la tabla.** Estos bytes están **derivados de la especificación de la librería** (`mike42/escpos-php`, la que se usará en producción y la que el legacy ya vendoriza), **no transcritos del manual de fabricante**: el único PDF de referencia de Epson identificado (`https://download4.epson.biz/sec_pubs/pos/reference/escpos.pdf`) **devuelve HTTP 403** en este entorno (E14). Etiqueta correcta: *"derivado de librería, no de fabricante"*. Esto **no invalida** la tabla: (a) la librería implementa el subconjunto estándar del protocolo y sus bytes coinciden con la especificación en todo comando estándar; (b) la librería **es** la especificación operativa del sistema, porque es lo que producirá el flujo; (c) los comandos de fabricante que **no** aparecen aquí (codepáginas exóticas, auto-test, cutter+, Etc.) no se usan en el ticket. La confirmación de grado "de fabricante" queda como tarea de **calibración de Fase 1** (R1, §8.1), no como requisito de Fase 0.

Bytes de control (E12): `ESC = 0x1B`, `GS = 0x1D`, `FS = 0x1C`, `DLE = 0x10`, `EOT = 0x04`, `LF = 0x0A`, `FF = 0x0C`.

| Acción | Secuencia | Valores | Fuente |
|---|---|---|---|
| **Inicializar** | `ESC @` → `1B 40` | — | E1, L3:631 |
| **Alinear** L / C / R | `ESC a n` → `1B 61 n` | `n` = 0 / 1 / 2 | E12, L3:864 |
| **Negrita (énfasis)** | `GS B n` → `1D 42 n` | `n` = 0 / 1 | L3:938 |
| **Doble golpe** | `GS ! n` → `1D 21 n` | `n` = 0 / 1 | L3:952 |
| **Subrayado** | `ESC - n` → `1B 2D n` | `n` = 0 / 1 / 2 | E12, L3:973 |
| **Modo de impresión** (fuente, tamaño, énfasis, subrayado) | `ESC ! n` → `1B 21 n` | bits: `1`=FontB, `8`=énfasis, `16`=doble alto, `32`=doble ancho, `128`=subrayado | E2, L3:773 |
| **Tamaño de texto** | `GS ! n` → `1D 21 n` | `n` = `(ancho−1)<<4 \| (alto−1)` | L3:952 |
| **Seleccionar tabla de caracteres** | `ESC t n` → `1B 74 n` (Star: `ESC GS t n` → `1B 1D 74 n`) | `n` = nº de tabla del perfil | E1, E5, L3:747-750 |
| **Avanzar n líneas** | `ESC d n` → `1B 64 n` | 0-255 | L3:529 |
| **Avanzar n puntos** | `ESC 3 n` → `1B 33 n` | 0-255 | L3:883 |
| **Espaciado de línea por defecto** | `ESC 2` → `1B 32` | — | L3:879 |
| **Cortar papel** | `GS V m n` → `1D 56 m n` | `m` = 65 (total) / 66 (parcial); `n` = líneas de avance antes del corte (por defecto 3) | E1, E12, L3:515 |
| **Abrir cajón de dinero** | `ESC p m t1 t2` → `1B 70 m t1 t2` | `m` = 48 (pin 2) / 49 (pin 5); `t1`/`t2` = **ms ÷ 2** (por defecto 120/240 ms → 60/120) | E1, L3:695 |
| **Código de barras** | `GS k m …` → `1D 6B m …` | `m` = 65 UPC-A, 66 UPC-E, 67 JAN13, 68 JAN8, 69 CODE39, 70 ITF, 71 CODABAR, 72 CODE93, **73 CODE128** | E12, L3:438,442 |
| **QR / PDF417** | `GS ( k pL pH cn fn m data` → `1D 28 6B pL pH cn fn m data` | `cn` = `'1'` (QR) / `'0'` (PDF417); `fn` = 65 (modelo), 67 (tamaño), 69 (nivel EC), 80 (datos), 81 (imprimir); `pL`/`pH` = longitud **little-endian** de `len(data)+len(m)+2` | E1, L4, L3:1035, 706-729 |
| **Gráficos** | `GS ( L pL pH m fn data` | `m` = `'0'` | E1, L3:1052 |
| **Imagen de bits** | `ESC * m nL nH …` | `m` = 0/1/2/3/4/… (densidad) | L3:490 |
| **Bytes crudos** | `ESC GS NUL` → `1B 1D 00` | útil para **código de barras en modo GS k** con datos arbitrarios | L3:1000-1003 |

**Cómo se arman en la librería (no se memorizan):** `wrapperSend2dCodeData($fn, $cn, $data, $m)` calcula `pL`/`pH` con `intLowHigh(strlen($data)+strlen($m)+2, 2)` y escribe `GS ( k` + cabecera + `cn` + `fn` + `m` + `data` (E1, L3:1029-1036). Lo mismo para `GS ( L` (E1, L3:1046-1053). → **No hay que codificar a mano el framing**: la librería lo hace, y por eso **el servidor debe generar los bytes con la librería, no a mano**.

### 3.2 Anchos de papel, columnas y bytes por línea

| Perfil (librería) | Columnas Font A | Columnas Font B | Otras | Ancho de medios | Fuente |
|---|---|---|---|---|---|
| `default` (genérico) | 42 | 56 | — | `Unknown` | L4:1343-1358 |
| `simple` (ASCII mínimo) | 42 | 56 | — | `Unknown` | L4:1382-1397 |
| `TM-T88III` (Epson) | 42 | 56 | — | `Unknown` | L4:1029-1044 |
| `TM-P80` (Epson) | 42 | 56 | Kanji 24 | **72 mm / 576 px** — nota: *"Portable printer (48-column mode)"* | L4:826-848 |
| `TM-P80-42col` (Epson) | variante de 42 columnas | | | | E2 (tabla de perfiles) |
| `TM-U220` (Epson) | 42 | 56 | | **80 mm** | L4 (media `:1079-1082`) |

- **Columnas ⇒ bytes por línea**: cada carácter ocupa **1 byte** en las tablas de un byte, así que `bytes_por_línea = columnas` con fuente simple; con doble ancho (`ESC !` bit 32) caben `columnas / 2` caracteres. La cuenta viene de la **tabla de capacidades**, no del protocolo.
- **58 mm: no verificable en este entorno** (la base de capacidades de `escpos-php` **no contiene ningún perfil de 58 mm** — los 24 perfiles son 80 mm, displays y otros — y el manual de Epson es 403, E14). **Fuente alternativa nombrada:** la *"TM-T88II/T88III Technical Reference Guide"* de Epson, que la propia nota del perfil TM-T88III identifica como la fuente del mapeo de codepáginas (L4:1046), más la ficha del modelo concreto de cada tienda. **Plan de cierre:** `print_targets.char_per_line` es un **dato por destino** → Fase 0 **no necesita** el número; se carga al dar de alta la impresora de cada tienda (Fase 1). **Severidad: baja** — no bloquea el esquema. Ver R2 (§8.1).
- **El ancho es un dato, no una constante.** `node-thermal-printer` lo toma de `config.width` y pliega con `BreakLine` (`NONE` / `CHARACTER` / `WORD`, `WORD` por defecto) (E7). `escpos-php` lo toma del `CapabilityProfile` del modelo. → **El destino debe storing `char_per_line` por `print_target`, no hardcodear 32/42/48.**
- **Legacy**: `printers.char_per_line` con semilla `200` (L5:4189) — valor inútil; y `Escpos::$char_per_line = 42` por defecto (L2). `drawLine($size)` dibuja `size` guiones (L: `_inc/helper/pos.php:8-15`), así que con `char_per_line = 200` la línea del total mide 200 caracteres. **Dato basura que hay que migrar con criterio.**

### 3.3 Codepáginas y el problema de acentos y símbolos

**Mapa del perfil `default` (62 tablas)** (L4:1264-1327, E2): `0`=CP437 · `1`=CP932 · `2`=CP850 · `3`=CP860 · `4`=CP863 · `5`=CP865 · `11`=CP851 · `12`=CP853 · `13`=CP857 · `14`=CP737 · `15`=ISO-8859-7 · `16`=**CP1252** · `17`=CP866 · `18`=CP852 · `19`=**CP858** · `21`=CP874 · `30`/`31`=TCVN-3-1/2 · `32`=CP720 · `33`=CP775 · `34`=CP855 · `35`=CP861 · `36`=CP862 · `37`=CP864 · `38`=CP869 · `39`=ISO-8859-2 · `40`=ISO-8859-15 · `45`=CP1250 … `52`=CP1258 · `53`=RK1048.

**Qué significa "codificable".** `CodePage` es codificable si **tiene `iconv` o `data`**; si no, `isEncodable()` = `false` y la tabla **existe en el perfil pero la librería no puede convertir a ella** (E5, L4:143-146, L: `CodePage.php:143-146`).

| Tabla | ¿Tiene `iconv`? | ¿Codificable por la librería? |
|---|---|---|
| CP437 (tabla 0) | sí (`"iconv": "CP437"`) | **Sí** |
| CP850 (tabla 2) | sí (`"iconv": "CP850"`) | **Sí** |
| CP1252 (tabla 16) | sí | **Sí** |
| **CP858 (tabla 19)** | **no** — sólo `name` + `python_encode` | **NO** |

**Consecuencia (hallazgo con impacto, confirmado por E14):** `escpos-php` **nunca selecciona automáticamente la tabla CP858**. En `EscposPrintBuffer::identifyText()` se elige "la primera code-page donde el carácter está disponible"; como CP858 no es codificable, sus caracteres exclusivos (p. ej. `€` en 0xD5, que en CP1252 está en 0x9C) caen al carácter de reemplazo `?` (`REPLACEMENT_CHAR = "?"`) o a la primera tabla disponible. **Recomendación operativa: NO usar CP858 como tabla por defecto.** Para español/venezolano usar **CP850** (tabla 2) o **CP1252** (tabla 16), ambas codificables y con `á é í ó ú ñ`. **Este hallazgo está cerrado**: no depende del PDF de fabricante y es **accionable en Fase 0** (el `charset` del destino es un dato).

**UTF-8: qué se recibe y qué se envía.**
- La librería **acepta UTF-8 como entrada** (`INPUT_ENCODING = "UTF-8"`), normaliza con `Normalizer`, y **convierte a la tabla de la impresora**, cambiando de tabla con `ESC t n` cuando un bloque de texto lo requiere (E6, L: `EscposPrintBuffer.php:95-119, 241-260`).
- **La impresora nunca recibe UTF-8**: recibe bytes de la tabla seleccionada. Caracteres fuera de *toda* tabla (emoji, CJK, símbolos no latinos) **se pierden**.
- Existe un modo experimental que **sí** emite glifos en UTF-8/Unicode: `UnifontPrintBuffer` emite `ESC !` con Font B + doble alto + doble ancho y `selectUserDefinedCharacterSet(true)`, es decir **dibuja cada carácter como imagen** (E6). Es experimental y muy lento → **no para producción**.
- `node-thermal-printer` mapea tablas a nombres iconv por tipo (`CODE_PAGES`), y **sólo incluye las soportadas por `icononv-lite`** (E9) → el mismo problema, resuelto por otro camino.
- **Implicación para el ticket venezolano**: el bolívar se escribe `Bs.` (ASCII) o con símbolo — **hay que decidir el glifo y garantizar que esté en la tabla**; y los acentos castellanos están en CP850/CP1252. **El test de dominio debe fijar el set de caracteres permitido por el perfil** (acentos, `ñ`, `°`, símbolo de moneda) y fallar si el ticket usa un carácter fuera del perfil → **el error aparece en el backoffice, no como `?` en el papel**.

### 3.4 QR

| Aspecto | `mike42/escpos-php` | `klemen1337/node-thermal-printer` | Fuente |
|---|---|---|---|
| API | `qrCode($content, $ec = QR_ECLEVEL_L, $size = 3, $model = QR_MODEL_2)` | `printQR(data, { cellSize 1-8, correction 'L'\|'M'\|'Q'\|'H', model 1\|2\|3 })` | E4, E7, E12 |
| Nivel de corrección | `L/M/Q/H` = 0/1/2/3 | `'L'/'M'/'Q'/'H'` (por defecto `'M'`) | E12, E7 |
| Tamaño de módulo | 1-16 (por defecto 3) | 1-8 (por defecto 3 en Epson/Tanca/Custom, 4 en Star) | E4, E7 |
| Modelo | 1, 2 o **MICRO** (*"not supported by all printers"*) | 1, 2 o 3 (por defecto 2 en Epson, 1 en Star) | E4, E7 |
| Framing | `GS ( k pL pH '1' fn m data` | `GS ( k` (tabla de comandos Epson) | L3:1035, E8 |
| **Validación previa** | `getSupportsQrCode()` del perfil → si `false`, **lanza excepción** en vez de enviar | La feature `printQR` **no** existe en Daruma → hay que elegir otro tipo | E5, E8 |
| **Límite de payload** | **No verificable en este entorno** — la librería **no valida ni documenta** un máximo; confirmado por E14 | **No verificable en este entorno** — confirmado por E14 | E14 |

- El límite real es el **buffer de la impresora** (documentado en el manual Epson, **403 en este entorno** — E14). El campo de longitud `pL`/`pH` es de 2 bytes → cabría hasta 65535, pero **el límite de la impresora es anterior** y la librería no lo conoce → **la app debe imponer su propio límite** y rechazarlo en el servidor con un error claro, no dejar que la impresora trunque el QR.
- **Plan de cierre (severidad baja, no bloquea Fase 0):** (a) el límite se declara como **dato por destino** (`print_targets.max_qr_payload_bytes`), no como constante de código → el esquema no depende del número; (b) valor por defecto **conservador y parametrizable** en Fase 1, calibrado contra el manual del modelo (o contra la impresora real en la prueba de aceptación). Ver R4 (§8.1).
- **`[INFERENCIA]`** (a partir del framing, E1/L3): un QR con payload de ~100-300 bytes (URL de consulta + RIF + correlativo) cabe de sobra en la cabecera de 2 bytes; el límite prático lo pone la **densidad de módulo × el ancho del papel**: con `cellSize = 3` y 42 columnas útiles, un QR de 33×33 módulos necesita ~99 columnas de ancho → **no cabe en una línea de 42 columnas**. Hay que usar `cellSize` 1-2 o imprimir el QR a doble ancho. **Esto es un requisito de layout del ticket, no un detalle de la librería.**
- **QR fiscal**: el contenido del QR del comprobante venezolano **no es verificable en este carril** → **R9** (carril hermano `research/senat-venezuela.md` T5/T9, pendiente del *Manual Técnico* de SENIAT, art. 41). Lo verificado en el cambio: el único país con **QR obligatorio** confirmado es **Colombia**, construido desde `EnvironmentCode` + `CUFE`. **Consecuencia de diseño**: el ticket **debe** poder llevar un QR **sin** él (columna nullable, ver §7) — porque no se sabe si el comprobante impreso lleva QR, hash, o ambos.

### 3.5 Transporte

| Transporte | Mecanismo | Puerto | Fuente | Estado |
|---|---|---|---|---|
| **Socket TCP crudo** | `NetworkPrintConnector($ip, $port = 9100, $timeout = -1)` → *"opening a raw TCP socket to a network printer"* | **9100** (por defecto en las 3 librerías) | E3, E9, E10 | **Verificado** |
| **"Puerto RAW" de Epson** | Escribir el flujo ESC/POS **tal cual** en el socket TCP, sin protocolo adicional | 9100 | E3, E9, E10, E13 (4 corroboraciones) | **Verificado como práctica de las librerías**; la nomenclatura oficial de Epson **no es verificable en este entorno** (403) — ver R1 |
| **Impresora compartida de Windows** | `WindowsPrintConnector('smb://user:pass@host/printer')` o **nombre de impresora local**; o el recipe `net use LPT1 \\server\printer` + `copy foo.txt LPT1` | — | E3, E4, E14 | **Verificado como API de la librería**; la semántica oficial del spooler **no es verificable en este entorno** — ver R3 |
| **Puerto local Windows** | `LPT\d` / `COM\d` (**sólo Windows**) | — | E3 | Verificado |
| **USB** | `USBAdapter(vid, pid)` (node-escpos); en PHP, vía el spooler de Windows o `/dev/usb/lp0` (FilePrintConnector) | — | E10, E3, E13 | Verificado — **pero ver la advertencia de Windows** |
| **Serie** | `Serial(port, {baudRate, stopBit, autoOpen})` | — | E10 | Verificado |
| **Bluetooth** | `Bluetooth(address, channel)` (RFCOMM) | — | E10 | Verificado |
| **CUPS (Linux)** | `CupsPrintConnector($dest)` (driver *raw*) | — | E3 | Verificado |
| **Printer de Windows nativo** | `printer:name` / `printer:auto` (*"auto-resolve first RAW-only system printer"*) | — | E9 | Verificado |
| **Puerto 9090** | — | 9090 | — | **No verificable en este entorno** (no aparece en ninguna fuente admisible). **Fuente alternativa:** la nomenclatura de puertos del propio fabricante, accesible por el PDF de referencia (403, E14) o por el panel web de la impresora. **Impacto en Fase 0: ninguno** — el puerto es un **dato** de `print_targets` |
| **ESC/POS-LAN / NetPrint / Impression Server de Epson** | — | — | — | **No verificable en este entorno** (403, E14). **Fuente alternativa:** documentación de Epson accessible desde la LAN del cliente. **Impacto en Fase 0: ninguno** — es un **modo alternativo de despliegue** del mismo "escribir bytes a un socket", no una dependencia del diseño. Ver R1 |
| **Protocolo ESC/POS de red de Star** | — | — | — | **No verificable en este entorno** (403, E14). Lo **verificado**: las impresoras Star **cambian el comando de tabla** a `ESC GS t n` en vez de `ESC t n` (E2, L3:745-750) → el backend debe respetar el flag `starCommands` del perfil, que ya es un **dato** por destino. Ver R1 |

**Lectura de diseño (revisada):** el **socket crudo a 9100** es el denominador común y funciona con impresoras de red Epson/Star/ compatibles sin drivers en el cliente. **El puerto RAW de Windows y el socket a 9100 son el mismo concepto**: mandar bytes sin interpretarlos. → **El agente sólo necesita un "escribir bytes a un destino"**, y ese destino puede ser (a) un socket TCP, (b) un recurso de impresora de Windows (`WindowsPrintConnector`), (c) `LPT1` mapeado a `\\servidor\impresora`. **Ninguna de las tres necesita un driver de impresión instalado en el cliente** si la impresora está compartida en red.

> **Por qué los huecos propietarios no son un hueco estructural.** El diseño del agente **no depende** del protocolo propietario del fabricante: el contrato del agente es "**escribir estos bytes en este destino**", y el destino se modela como `transport` + `host` + `port` + `printer_key`, **todos datos**. Un `ESC/POS-LAN` de Epson o un protocolo de Star serían **una implementación más** de ese contrato, no un requisito. Por tanto el 403 del PDF **no bloquea Fase 0**: bloquea, como mucho, una **optimización de despliegue** (impresora directa sin agente) y la **calibración fina por modelo** — ambas de **Fase 1**.

> **⚠ Advertencia operativa (Windows + USB) — `[TERCERO]`, con impacto directo en el diseño del agente.** El acceso USB directo a una impresora térmica desde un proceso Windows **no funciona de fábrica**: la ruta `libusb` de `node-escpos` no puede separar el kernel driver en Windows (*"This functionality is not available on Windows"*) y exige **instalar un driver adicional (WinUSB/libusb) mediante Zadig** (E13). Un instalador desatendido **no puede depender de que un técnico ejecute Zadig**. → **El destino del agente debe ser TCP 9100 o una impresora compartida de Windows, nunca USB directo.** Esto **refuerza** la decisión y la convierte en una restricción, no en una preferencia. (La ruta "USB a través del spooler de Windows", es decir `LPT1`/`COM` o una impresora local compartida, **sí** evita el driver adicional: es la salida recomendada para una impresora USB en un terminal Windows.)

---

## 4. Librerías comparadas

### 4.1 `mike42/escpos-php` (PHP) vs `klemen1337/node-thermal-printer` (Node)

| Criterio | `mike42/escpos-php` | `klemen1337/node-thermal-printer` |
|---|---|---|
| Paquete | `mike42/escpos-php` — fork mantenido de `mikeerickson/escpos-php`, MIT | `klemen1337/node-thermal-printer` |
| Lenguaje | PHP | Node / TypeScript |
| Unidad de salida | bytes a un `PrintConnector` | `Buffer` a una `Interface` |
| **Perfil de impresora** | **Sí**: `CapabilityProfile` por modelo (24 perfiles) con nº de codepáginas, **columnas por fuente** y **flags de features** (`qrCode`, `paperFullCut`, `pulseStandard`, `starCommands`, …) (E2, E5) | **No**: el ancho es `config.width`; el juego de comandos es por **tipo** (`epson`, `star`, `tanca`, `daruma`, `brother`, `custom`), no por modelo (E7-E9) |
| **Validación antes de enviar** | Sí: `getSupportsQrCode()` → **lanza excepción** si el perfil no soporta QR; `selectCharacterTable()` → **`InvalidArgumentException`** si la tabla no está en el perfil (E5) | Parcial: la matriz de features por tipo dice qué está soportado, pero no hay perfil por modelo (E8) |
| Codificación | UTF-8 → tabla de la impresora, **cambio automático de tabla** con `ESC t n` + **caché** de las tablas (E6) | `CODE_PAGES` con nombres iconv por tipo; plegado por `config.width` + `BreakLine` (E7, E9) |
| Conectores / interfaces | File, Network (9100), CUPS, Windows (LPT/COM/smb/nombre), Memory, Multiple, Uri (E3) | `tcp://` (9100), `printer:`, ruta de archivo, objeto custom (E9) |
| QR | `qrCode()` con modelo/EC/tamaño, framing `GS ( k` (E4) | `printQR()` con `cellSize`/`correction`/`model` (E7) |
| Imágenes | `bitImage()` + `graphics()` (`GS ( L`), `EscposImage` con GD/Imagick | `printImage()` (`S_RASTER_*`) (E7, E8) |
| Composición de ticket | **No** — hay que escribir el formateo a mano (el legacy lo hace en `Escpos::print_receipt()`, L2) | **Sí** — `leftRight()`, `table()`, `tableCustom({text, align, width, cols, bold})` (E7) |
| Estado | Activo; el legacy ya lo vendoriza (L3, L4) | Activo |

### 4.2 Recomendación

**Usar `mike42/escpos-php` (PHP) para generar los bytes ESC/POS en el servidor**, por tres razones:

1. **El backend es PHP** (Laravel 12 en `pos-moderno-v2`). Generar los bytes en el mismo lenguaje y el mismo proceso evita un segundo runtime en el servidor y un salto de red extra.
2. **El perfil de impresora es un dato que ya existía** en el legacy (`printers.profile`) y `escpos-php` lo formaliza como **modelo con capacidades verificables**. Eso convierte "la impresora no imprime QR" en un **error de configuración visible en el backoffice**, no en un ticket mudo. El legacy tenía la columna `profile` y **no la usaba** (la clase `Escpos` delega en el perfil por defecto y nunca lo selecciona).
3. **El mapeo UTF-8 → tabla es automático y con caché**, que es justo el punto débil del legacy (que escribía texto crudo con el `?` de reemplazo).

**Contra / coste de esta elección:** `node-thermal-printer` tiene una **API de composición de ticket** (`table`, `tableCustom`, `leftRight`) que `escpos-php` **no trae** → hay que construirla (o usar una plantilla Blade propia). Se compensa con que el formateo del ticket **debe ser un dato del perfil de tienda** de todas formas.

**Corolario de coherencia (importante):** como el **servidor** genera los bytes, el **agente no necesita la misma librería** — y **no debería generarlos**. Si el agente se implementa en Node, puede usar `node-thermal-printer` **o cualquier otra cosa**: sólo tiene que escribir un `Buffer` a un socket o a un recurso de impresora. Esa es la razón técnica de la decisión "servidor genera, agente escribe" (§7.2).

---

## 5. Diseño del agente LAN

### 5.1 Por qué hace falta un agente

- **[INTERNO]** El legacy genera el ESC/POS **en el servidor** (`_inc/print.php:30-31`) y lo escribe con `NetworkPrintConnector($printer->ip_address, $printer->port)` (L2). Eso funciona **sólo** si el servidor **alcanza** la impresora.
- **[INTERNO]** El legacy corre en XAMPP/Apache **en el mismo Windows del terminal** (`AGENTS.md` del legacy). El "servidor" **es** la caja. Al mover el servidor a la nube, el servidor deja de estar en la LAN de la tienda y **no puede alcanzar la impresora** (NAT, firewall, IP privada).
- **[INFERENCIA]** Un proceso en la nube no puede "entrar" en una LAN privada sin que el lado privado abra la conexión. De ahí el agente: **el agente es el componente que vive en la LAN y sabe hablar con la impresora**; el servidor queda con la lógica de negocio.
- **Alternativas y su coste** (comparación cualitativa; **la parte de red es `[INFERENCIA]`** — no hay fuente admisible para comportamiento de NAT/firewall en este entorno):

| Alternativa | Qué resuelve | Coste / por qué no basta |
|---|---|---|
| **Agente local** (este diseño) | El proceso en la LAN hace de puente | Hay que **distribuirlo e instalarlo** en cada terminal (riesgo R9 de `exploration.md` §11.1) |
| **VPN / túnel (WireGuard, Tailscale)** | El servidor alcanza la LAN como si fuera local | Funciona, pero **sustituye** al agente: el servidor aún no sabe qué impresora hay ni cómo escribir; y mete una capa de red en cada terminal |
| **Reenvío de puertos / EXP en el router** | Expone el puerto 9100 de la impresora | Expone la impresora a Internet; **no resuelve** el caso de varias impresoras por tienda ni el de USB |
| **Epson ESC/POS-LAN / NetPrint / Impression Server** | El propio dispositivo traduce y expone la impresora | **No verificable en este entorno** (403, E14) → plan R1. Además ata la solución a un fabricante y no cubre impresoras USB ni de terceros. **Es una alternativa de despliegue, no un requisito del diseño** (§3.5) |
| **Impresora compartida de Windows** | Un proceso Windows escribe a `\\servidor\impresora` o a `LPT1` mapeado (E3, E4, E14) | Es el **mecanismo de destino**, no la topología: sigue haciendo falta un proceso en la LAN |

> **Nota de coherencia:** la alternativa "servidor manda el ESC/POS al puerto EXP del router" **no es una alternativa al agente**, es un modo de operación del agente (el agente escribe al puerto reenviado). El agente sigue siendo necesario para descubrir impresora, aplicar el perfil y reportar el resultado.

### 5.2 Topología comparada: pull vs push

| | **Pull (long-poll)** | **Push (servidor → agente)** |
|---|---|---|
| **NAT / firewall** | El agente abre **saliente** a 443 → atraviesa NAT y firewalls de salida **sin reglas** | El servidor debe **alcanzar** al agente: IP pública, reenvío de puertos o VPN/túnel en cada terminal |
| **Latencia** | El trabajo se entrega en <1 s de encolarse (el long-poll se despierta) | Similar, si el túnel está abierto y estable |
| **Complejidad** | Un cliente HTTP + long-poll en el agente; el servidor sólo espera | El servidor necesita un transporte bidireccional con reconexión y **NAT traversal** |
| **Aislamiento de fallos** | Un agente caído no molesta al resto | Un push a un agente muerto produce reintentos y cola de conexiones abiertas |
| **Veredicto** | **ADOPTADO** | Sólo justificable con VPN/túnel ya desplegado |

> **Matiz que suele omitirse:** "push" **sobre la misma conexión que el agente abrió** (WebSocket initiated by the agent) **es pull con otra forma**. La clasificación real es: *¿quién inicia la conexión?* → **el agente, siempre**.

### 5.3 Seguridad

> **Todo este bloque es `DECISIÓN NUESTRA`.** No hay norma detrás: es diseño de seguridad propio, indexado sobre el patrón que ya usa el legacy para el sync v2 (`_inc/helper/sync.php`: HMAC + nonce + `hash_equals`), que **sí** es una convención verificada del propio proyecto. Lo que es `VERIFICADO` es el **comportamiento de la librería** y del **spooler de Windows**; lo que es **nuestro** es el esquema de tokens, el alcance por agente, el `instance_id` y las reglas de revocación.

| Control | Decisión | Por qué |
|---|---|---|
| **Autenticación** | **Token por agente** (no por tienda), en cabecera `Authorization: Bearer <token>` + `X-Agent-Id` | Un token por tienda permitiría que un terminal comprometido de la tienda A **suplantara** a la tienda B si el endpoint no fuerza el scope. **Token por agente** acota la explosión a **una caja**. *Refinamiento sobre `exploration.md` §8.3 ("token por tienda").* |
| **Almacenamiento** | `print_agents.token_hash` = hash **argon2id/bcrypt**; el token en claro se muestra **una sola vez** al registrar y no se persiste en el agente en disco plano si puede evitarse | Nunca en claro; `exploration.md` §8.3 ya lo exige |
| **Transporte** | **TLS obligatorio** en el endpoint del agente; el servidor rechaza peticiones no-TLS | El payload lleva datos fiscales del cliente |
| **Rotación** | Endpoint `rotate` → emite token nuevo, marca el anterior con `token_valid_until` (ventana de gracia) | Rotar sin cortar la operación: el agente acepta el token nuevo en el siguiente heartbeat |
| **Fuga de token** | (a) radio de impacto = 1 terminal; (b) `print_agents.instance_id` — si aparece un **segundo** `instance_id` con el mismo token, el servidor lo marca y lo muestra como conflicto; (c) `status = 'revoked'` → revocación inmediata; (d) **el payload se minimiza**: el agente recibe el ticket renderizado, no el documento fiscal entero | Un token filtrado permite leer tickets y **suprimir** impresión. La mitigación decisive es (d) + (b): el peor caso se detecta, no pasa silenciosamente |
| **Autorización de destino** | El agente valida `printer_key` contra **su inventario**; si no existe → `error` con motivo, **no** imprime en otra impresora | Evita que un destino mal configurado imprima en la impresora equivocada |
| **El agente no acepta órdenes del servidor** | El agente sólo hace `GET` de trabajos y `POST` de resultados; **no** ejecuta nada recibido | Reduce la superficie de un agente comprometido en la LAN |

### 5.4 Estado y observabilidad

- **Heartbeat**: `POST /api/print-agents/heartbeat` con `{agent_id, version, capabilities[], printers[]}` → `{status, pending_jobs, poll_after_ms}`. `printers[]` es el **inventario real** que el agente ve en Windows: nombre del recurso, perfil ESC/POS, `char_per_line`, estado.
- **El long-poll también refresca `last_heartbeat_at`** → un solo campo, dos puntos de refresco. Esto evita el fallo clásico de "el heartbeat dice que está vivo pero el long-poll está colgado" (o al revés).
- **Umbral de "desconectado" = parámetro**, no literal (ya lo dice `exploration.md` §8.3): `online` si `now() - last_heartbeat_at < umbral` (por defecto 3× el intervalo de heartbeat). Se muestra en el backoffice como **"agente en línea / desconectado"**.
- **Reintentos**: el agente reintenta el `POST` de resultado con backoff exponencial (1s → 2s → 4s → 8s, tope 5) porque el servidor puede estar caído un instante. **El servidor debe tolerar resultados duplicados** (idempotencia: un segundo `result` sobre un trabajo ya `printed` es un no-op).
- **Idempotencia del lado agente (crítico):** el agente guarda un **journal local** (`job_id` + `payload_sha256` + timestamp). Si le llega de nuevo un `job_id` ya impreso, **no reimprime**: devuelve el resultado anterior. Con esto, la semántica pasa de *at-least-once* a *efectivamente-una-vez* mientras el disco del agente sobreviva. Sin esto, un agente que muere **después** de imprimir y **antes** de reportar provoca un **doble ticket** al reintentar.
- **Reimpresión manual ≠ reemisión fiscal**: una reimpresión crea un `print_job` **nuevo** contra el **mismo** `document_id`, con `reason = 'reprint'` y `reprint_of_job_id`; **no** consume correlativo y **no** modifica `fiscal_documents` (ya está en `exploration.md` §8.5 — confirmado aquí y respaldado por el carril fiscal hermano).

### 5.5 Cola de trabajos

- `print_jobs` es la **tabla de dominio** (estado, destino, payload, historial), **no** la cola de ejecución. El job de Laravel (`DispatchPrintJob`) es el ejecutor que toma trabajos `queued` y los despacha (`exploration.md` §10.2). La distinción importa: el estado de impresión es consultable por el backoffice, sobrevive a reinicios y se puede reintentar desde la UI sin depender del backend de cola.
- **El POS nunca espera a la impresora**: encola y sigue (§8.5 de `exploration.md`, §10 del mapa).

---

## 6. Empaquetado Windows y operación en terminal

### 6.1 Qué está verificado

| Hecho | Fuente |
|---|---|
| Inno Setup compila un **instalador EXE autónomo** desde un script; la sección `[Run]` lanza ejecutables tras la instalación, con flags `postinstall`, `nowait`, **`skipifsilent`** y `Tasks:` | E11 |
| En **modo silencioso** el instalador **cierra automáticamente** las aplicaciones que tienen archivos en uso, salvo que se pase `/NOCLOSEAPPLICATIONS` | E11 |
| Las entradas `[Run]` con `postinstall` se procesan en la página de finalización; con `skipifsilent` se omiten en instalación silenciosa | E11 |
| Una **impresora compartida de Windows** se puede alcanzar como `smb://user:pass@host/printer` o por **nombre local de impresora**, y también con `net use LPT1 \\server\printer` + `copy archivo LPT1` | E3, E4 |

### 6.2 Lo que no es verificable en este entorno (con plan de cierre)

| Punto | Estado | Fuente alternativa / plan | Impacto en Fase 0 |
|---|---|---|---|
| **Flags exactos** de línea de comandos de Inno Setup (`/VERYSILENT`, `/SUPPRESSMSGBOXES`, `/NORESTART`) | **No verificable** (la clase admite el repo `issrc`, no su manual) | Manual de Inno Setup (`jrsoftware.org/ishelp`), descargable desde la LAN del cliente. **Alternativa suficiente**: el comportamiento de `[Run]` + `skipifsilent` **ya está verificado** (E11) y es lo que decide si el servicio arranca en modo silencioso | **Ninguno** — el instalador es Fase 1 |
| **Comparativa MSI / Inno Setup / NSIS** | **No verificable** (sólo Inno Setup está indexado) | Comparativa por ensayo en Fase 1. **No hace falta para decidir**: Inno Setup ya tiene fuente admisible y cubre el caso | **Ninguno** |
| **Arranque automático en Windows** (Windows Service, Task Scheduler, `Run` key) | **No verificable** externamente | Documentación de Windows Installer / Task Scheduler, accesible desde la LAN. **Alternativa suficiente**: arranque vía el propio `[Run]` del instalador (E11) como **mecanismo mínimo**, elevable a servicio en Fase 1 | **Ninguno** — el agente es Fase 1 |
| **Comportamiento del spooler de Windows** ante un puerto RAW y una impresora compartida | **No verificable** (sólo la API de la librería) | Documentación de impresión de Windows / "Print server", accesible desde la LAN. **Alternativa suficiente**: la API de la librería está verificada (E3, E4) y es la que se usará | **Ninguno** — el destino es un **dato** por `print_target` |

### 6.3 Decisiones de diseño (marcadas como tales)

> **Todo este bloque es `DECISIÓN NUESTRA`.** Ninguna de estas recomendaciones tiene norma detrás: son decisiones de producto/ingeniería de este proyecto. Lo `VERIFICADO` que las sostiene es de dónde salen (E3, E4, E11, E13) y está citado en la columna "Razón".

| Decisión | Recomendación | Razón |
|---|---|---|
| **El agente NO es un endpoint PHP bajo Apache/XAMPP** | Servicio Windows autónomo | (a) La impresión debe funcionar **aunque el servidor web esté caído**; (b) en el modelo nuevo el servidor es remoto → el agente es un proceso **de la LAN**, no del servidor web; (c) desacopla la impresión de la migración de XAMPP a otro servidor; (d) permite que el agente viva en **otra máquina** de la LAN (p. ej. un PC en el mostrador), que es el caso real |
| **Empaquetado** | **Inno Setup** | Es el único instalador con **fuente admisible** en esta ejecución; además permite `[Run]` con `skipifsilent` para decidir si el servicio arranca en modo silencioso (E11). La comparación con MSI/NSIS queda abierta pero **no bloquea** (Fase 1) |
| **Arranque automático** | **Windows Service** con reinicio automático | Las cajas se apagan y encienden; el agente debe volver solo y **sin depender de que haya sesión de usuario abierta** |
| **Configuración** | Archivo propio del agente (`agent.json`) con `server_url`, `agent_id`, `token`, `printers[]` (recurso de Windows + perfil + `char_per_line`) | El token **no** va en el instalador ni en el repositorio: se entrega en el **primer arranque** (código de un solo uso) o por política de dominio |
| **Permisos** | El usuario del servicio necesita **escritura en el spooler** y **acceso a la impresora** | Sin esto el agente escribe bytes y no ocurre nada |
| **Sin dependencia de la nube** | El agente guarda el **journal local** de trabajos impresos (§5.4) | Es lo que convierte *at-least-once* en *efectivamente-una-vez* |
| **Destino de impresión: TCP 9100 o impresora compartida de Windows; NUNCA USB directo** | Restricción, no preferencia | En Windows el acceso USB directo exige **instalar WinUSB/libusb con Zadig** — imposible en un despliegue desatendido (E13, E14). La impresora USB local **compartida** en Windows (`LPT1` / recurso de impresora) sí funciona sin driver adicional (E3, E4) |

---

## 7. Implicaciones para el diseño

### 7.1 Contrato de API del agente

```
POST /api/print-agents/register
  body: { agent_id, name, version, capabilities[], token }
  →    200 { status: 'registered', agent_id, server_time, poll_after_ms }
  →    409 { status: 'conflict' }            ← agent_id ya registrado con otro instance_id

POST /api/print-agents/heartbeat
  body: { agent_id, instance_id, version, capabilities[],
          printers: [ { key, name, profile, char_per_line, status } ] }
  →    200 { status: 'ok', pending_jobs: N, poll_after_ms: 2000, server_time }

GET  /api/print-agents/jobs?limit=N            ← long-poll, máx 30 s; devuelve al expirar con []
  →    200 { jobs: [ { job_id, target_id, printer_key, payload_b64,
                     payload_sha256, copies, charset, paper_width, char_per_line } ] }

POST /api/print-jobs/{id}/result
  body: { agent_id, job_id, payload_sha256, status: 'done'|'error',
          error?, printed_at, bytes_written }
  →    200 { status: 'ok' }                     ← idempotente: repetir sobre 'printed' es no-op
  →    422 { errorMsg: ... }                    ← token inválado / agente revocado / job desconocido

POST /api/print-agents/rotate                  ← rotación de token
  body: { agent_id, current_token }
  →    200 { status: 'ok', token: '<nuevo>', token_valid_until: '<grace>' }
```

**Reglas transversales** — con la **procedencia** de cada una:

| Regla | Detalle | Procedencia |
|---|---|---|
| **Auth** | `Authorization: Bearer <agent_token>` + `X-Agent-Id` en todas. El servidor fuerza el scope: el `agent_id` del token **debe** coincidir con el del body/path | **DECISIÓN NUESTRA** (patrón tomada del sync v2 del legacy, que sí es convención del proyecto) |
| **Scope de tienda** | El servidor resuelve la tienda **desde el `agent_id`**, nunca desde el body → un token no puede imprimir en otra tienda | **DECISIÓN NUESTRA** |
| **Idempotencia de resultados** | Un `result` repetido sobre un trabajo ya `printed` devuelve `200` y no cambia nada | **DECISIÓN NUESTRA** |
| **Idempotencia en el agente** | Journal local por `job_id` (§5.4) | **DECISIÓN NUESTRA** |
| **Transporte** | TLS obligatorio; el payload viaja **base64** (`payload_b64`) porque son bytes binarios, no texto | **DECISIÓN NUESTRA** (el base64 sí es necessity técnica: ESC/POS no es texto UTF-8) |
| **Verificación de integridad** | `payload_sha256` en la request y en el `result`: detecta corrupción o manipulación sin cifrar nada extra | **DECISIÓN NUESTRA** (el hash sí es práctica de la librería/proyecto: `getCodePageCacheKey()` usa MD5 para lo mismo, E5) |
| **Vocabulario de estados** | El `status` del `result` es `done` \| `error`, **distinto** del `status` del trabajo (`queued`/`claimed`/`printing`/`printed`/`failed`/`cancelled`) — ver §7.3 | **DECISIÓN NUESTRA** |
| **Sin push** | No hay endpoint de push. El agente siempre inicia | **DECISIÓN NUESTRA** (con el razonamiento en §5.2) |
| **`printer_profile` es dato, no código** | El backend consulta las capacidades del perfil (`getSupportsQrCode()`, `getCodePages()`) y **falla en el servidor** si el destino no soporta lo pedido | **VERIFICADO** (E5) + **DECISIÓN NUESTRA** en modelarlo como columna |
| **`charset` / `char_per_line` son datos** | Vienen del destino y se congelan en el trabajo | **VERIFICADO** (E5, E7, E14) |
| **Destinos válidos: TCP 9100 o impresora compartida de Windows** | USB directo queda excluido | **VERIFICADO** (E13, E14) |

### 7.2 Dónde se generan los bytes ESC/POS — decisión y coste

> **Reformulación de la premisa de `exploration.md` §8.4.** La premisa original era *"un único lugar genera el ticket → el mismo formato en navegador, LAN y servidor"*. **Es imposible si "formato" significa binario**: el navegador **no consume ESC/POS** — verificado en el legacy, donde el modo "web browser" era impresión **HTML** (`popupWindow()`, L6; confirmado por E14). La reformulación correcta es:
>
> **Lo unificable es el ORIGEN DEL CONTENIDO, no el binario.** Es decir: un **único modelo de plantilla del documento** (`document_template` + los datos del documento fiscal congelados) del que salen (a) el render **HTML/PDF** para el modo `browser` y (b) el flujo **ESC/POS** para los modos `lan_agent` y `server_socket`. El binario **depende del transporte**; el **contenido** no.

Las **dos alternativas de dónde renderizar**:

| | **A — Render en el servidor** (ADOPTADA) | **B — Render en el agente** |
|---|---|---|
| **Qué hace el servidor** | Renderiza la plantilla **y produce el flujo ESC/POS** (`mike42/escpos-php`); el agente lo escribe byte a byte | Envía plantilla + datos; **el agente** genera el ESC/POS |
| **Qué recibe el agente** | Bytes (base64) | Datos + instrucciones de render |
| **Ventaja** | Un único formateador → **el mismo ticket** en LAN y en servidor; coherente con el legacy; **el agente es agnóstico a la librería** (puede estar en Node o en lo que sea); el servidor puede **rechazar** un ticket no representable **antes** de enviarlo | El servidor no carga con el render ni con el perfil de impresora |
| **Coste** | El servidor necesita el **perfil de impresora** (dato, no código) → no puede encolar hasta saber qué impresora hay, y el desajuste perfil↔impresora real debe **detectarse** (heartbeat + `failed` explícito) | **Varias implementaciones del render → varios formatos**: el agente y (para `server_socket`) el servidor. Y el agente pasa a depender de la librería ⇒ **cada terminal puede imprimir un ticket distinto** |
| **Veredicto** | **ADOPTADA** — el coste es un dato; el de B es una divergencia de formato | **Rechazada** |

**Variantes descartadas, con su motivo:**

| Variante | Motivo del descarte |
|---|---|
| **B′ — el servidor manda un render intermedio (HTML/CSS o DSL de ticket) y el agente lo convierte a ESC/POS** | Hay que escribir un **motor de render→ESC/POS** (de nuevo, varios formatos). Queda como palanca si el perfil resultara inmanejable |
| **C — el servidor genera para un ancho canónico y el agente re-ajusta** | Exige un **parser ESC/POS** en el agente que re-alinee y re-envuelva líneas → complejidad innecesaria y riesgo de corromper el ticket |

**Por qué A y no B, en una frase:** el agente es un componente **desplegado en cada terminal y que hay que mantener**; cuanto menos lógica de negocio tenga, menos divergencia hay entre impresoras y menos superficie de soporte.

**El coste real de A, y cómo se mitiga:**

| Coste | Mitigación |
|---|---|
| El servidor necesita el **perfil ESC/POS** de la impresora destino antes de encolar | `print_targets.printer_profile` (dato) + el **heartbeat del agente reporta el inventario real**; si el perfil declarado ≠ el reportado, el trabajo se marca `failed` con motivo explícito → **desajuste visible, no silencioso** |
| Un error de render (p. ej. un `€` fuera de tabla) se detecta **en el servidor** (el buffer avisa) | **Test de dominio**: el render del ticket falla si el texto no es representable en el perfil → error en el backoffice, no un `?` en el papel |
| El servidor carga con la generación | Coste trivial (bytes por ticket); el trabajo va por la cola, no en el ciclo de la request |
| El servidor necesita una **librería ESC/POS** en producción | `mike42/escpos-php`, **ya vendorizada en el legacy** ⇒ el coste de adopción es copiar un paquete conocido, no escribir un driver |

**Y la consecuencia operativa de la reformulación:**

- `transport = 'lan_agent'` y `transport = 'server_socket'` → **ESC/POS**, generado por el servidor (opción A).
- `transport = 'browser'` → **HTML/PDF**, generado desde la **misma plantilla y los mismos datos**, pero con otro renderizador.

Lo que se unifica es **el origen del contenido** (plantilla + datos + documento fiscal), **no el binario**. La premisa de `exploration.md` §8.4 ("el mismo formato en navegador, LAN y servidor") debe **reescribirse** así, o el diseño se promete algo imposible.

### 7.3 Modelo `print_jobs` con estados

> **El vocabulario de estados es `DECISIÓN NUESTRA`.** No hay norma detrás: es diseño de dominio de este proyecto. Lo `VERIFICADO` que lo sostiene es (a) que la **reimpresión no consume correlativo ni toca el documento fiscal** (coherente con la regla fiscal del carril hermano `senat-venezuela.md` §5.1: el hueco se consume y no se reutiliza), y (b) que `print_jobs` es **tabla de dominio** y no cola de ejecución (`exploration.md` §10.2). Lo que **no** está verificado es el vocabulario en sí → **decisión de producto**, se propone y se confirma.

```
queued      ──► claimed ──► printing ──► printed
   ▲             │            │
   │             │            └──► failed  (attempts agotados)
   │             │
   └── lease_expires_at (agente muerto) ◄┘
                                       
   └─► cancelled   (cancelado desde la UI)
```

| Estado | Significado | Quién lo pone | Notas |
|---|---|---|---|
| `queued` | Encolado, pendiente de entrega | Servidor | **Estado inicial**. Puede pasar por `claimed` → `lease_expires_at` → `queued` (con `attempts++`) |
| `claimed` | Un agente lo tomó (con **lease**) | Agente (en la respuesta del long-poll) | `lease_expires_at` = ahora + X. Si el agente muere, el trabajo **vuelve** a `queued` |
| `printing` | El agente está escribiendo los bytes | Agente | Poca duración; útil para diagnóstico |
| `printed` | El agente confirmó la escritura | Servidor (tras el `result`) | `printed_at` + `bytes_written`. **Terminal** |
| `failed` | Error tras N intentos | Servidor | `last_error`, `attempts`. **No** es terminal en el sentido de negocio: la UI ofrece reintentar |
| `cancelled` | Cancelado desde la UI | Servidor | Terminal |

**Columnas** (extensión de `exploration.md` §8.2):

| Columna | Tipo | Por qué |
|---|---|---|
| `id` | bigint PK | |
| `store_id` | FK | |
| `target_id` | FK → `print_targets` | destino lógico |
| `agent_id` | FK → `print_agents`, **nullable** | `NULL` mientras el trabajo no se ha entregado a ningún agente |
| `document_type` / `document_id` | | referencia al documento fiscal **congelada** |
| `reason` | `sale` \| `reprint` \| `manual` | **`reprint` nunca consume correlativo ni toca `fiscal_documents`** |
| `reprint_of_job_id` | FK nullable | trazabilidad de la reimpresión |
| `payload` | jsonb | **metadatos**: plantilla, datos, perfil, charset, copias |
| `payload_bytes` | **bytea** | el flujo ESC/POS ya generado (para reintento **sin regenerar** y para auditar) |
| `payload_sha256` | char(64) | integridad extremo a extremo |
| `copies` | smallint | |
| `printer_profile` / `char_per_line` / `charset` | | **congelados en el trabajo**, no leídos del destino al reintentar |
| `status` | enum | la máquina de estados de arriba |
| `attempts` | smallint | |
| `last_error` | text nullable | |
| `claimed_at` / `lease_expires_at` / `printed_at` | timestamptz nullable | |
| `created_by` / `created_at` | | |

**Mutaciones del esquema que este carril propone** (respecto de §8.2 de `exploration.md`):

| Tabla | Cambio | Motivo |
|---|---|---|
| `print_targets` | `+ printer_profile` (perfil ESC/POS), `+ char_per_line`, `+ transport` (`browser` \| `lan_agent` \| `server_socket`) | El perfil es **dato** y la opacidad del desajuste es el riesgo |
| `print_agents` | `+ instance_id`, `+ agent_version`, `+ token_hash`, `+ token_valid_until`, `+ last_heartbeat_at`, `+ status` (`online`/`offline`/`revoked`), `+ capabilities` jsonb, `+ printers` jsonb (inventario reportado) | El heartbeat es lo que hace posible mostrar "en línea / desconectado" y detectar el conflicto de `instance_id` |
| `print_jobs` | `+ reason`, `+ reprint_of_job_id`, `+ payload_sha256`, `+ agent_id`, `+ lease_expires_at`, `+ printer_profile`, `+ char_per_line`, `+ charset`; `payload` como `jsonb` + `payload_bytes` como `bytea` | Idempotencia, auditoría y reintento sin regenerar |

**Invariante que debe tener un test (Fase 0):** *un `print_job` en estado `printed` **no** ha modificado `fiscal_documents` ni ha consumido un correlativo*. Y: *una reimpresión crea un `print_job` nuevo y **cero** filas nuevas en `fiscal_document_reservations`.*

### 7.4 Qué queda fuera de alcance de Fase 0

| Entregable | Fase | Nota |
|---|---|---|
| Tablas `print_jobs` / `print_targets` / `print_agents` + estados + invariantes | **Fase 0** | Contrato y esquema fijados aquí |
| Renderizador ESC/POS en el servidor + tests de perfil/representabilidad | Fase 1 | Depende de elegir librería y perfiles reales |
| El agente (proceso, instalador, journal, heartbeat, long-poll) | **Fase 1** | `exploration.md` §8.6 y P12: "esquema + contrato en Fase 0; instalador en Fase 1" |
| Integración con el comprobante fiscal (QR, hash) | Fase 1-2 | Depende del carril `senat-venezuela` (T5/T9 → **R9**) |

---

## 8. Riesgos residuales y puntos abiertos

### 8.1 Lo que **no** es verificable en este entorno — con plan de cierre y severidad

> **Criterio de severidad**: `ALTA` = bloquea el esquema o el contrato de Fase 0 · `MEDIA` = afecta a un parámetro operativo que ya está modelado como dato · `BAJA` = afecta a la calibración o a la experiencia de despliegue, no al contrato. **Ninguno de los riesgos residuales es `ALTA`**: ninguno de ellos impide definir las tablas, los estados ni el contrato de API.

| # | Tema | Estado en este entorno | **Fuente alternativa** (cómo se cierra) | Severidad | Impacto en Fase 0 |
|---|---|---|---|---|---|
| **R1** | **Especificación de fabricante del protocolo ESC/POS**: comandos ESC/GS con bytes exactos, nomenclatura del "puerto RAW" vs "Open", **puerto 9090**, **ESC/POS-LAN / NetPrint / Impression Server** | **No alcanzable**: el único PDF de referencia identificado (`https://download4.epson.biz/sec_pubs/pos/reference/escpos.pdf`) devuelve **HTTP 403** (E14) | (a) **Descarga manual** del PDF (navegador de un equipo con salida a Internet) → incorporation como anexo; (b) **fuente alternativa inmediata**: la especificación de la librería `mike42/escpos-php`, etiquetada como *"derivado de librería, no de fabricante"* — que es la que producirá el flujo en producción | **MEDIA** | **Ninguno**: los bytes del subconjunto usado están verificados (E1, E2, E12, L3) y los propietarios son una **optimización de despliegue**, no un requisito (§3.5) |
| **R2** | **Columnas de 58 mm** (y bytes por línea) | **No verificable**: no hay perfil de 58 mm en la base de capacidades y el manual es 403 | La *"TM-T88II/T88III Technical Reference Guide"* de Epson, **citada por la propia nota del perfil TM-T88III** (L4:1046), más la ficha del modelo de cada tienda | **BAJA** | **Ninguno**: `print_targets.char_per_line` es un **dato** que se carga al dar de alta la impresora |
| **R3** | **Semántica oficial del puerto de impresora compartida de Windows** (`net use LPT1`, puertos RAW del spooler, permisos) | **No verificable**: sólo la API de la librería está disponible | Documentación de impresión de Windows / "Print server", accesible desde la LAN del cliente. **Alternativa suficiente**: la API de la librería está verificada (E3, E4, E14) y es la que se usará | **BAJA** | **Ninguno**: el destino es un **dato** por `print_target` |
| **R4** | **Límite de payload del QR** y del buffer `GS ( k` | **No verificable**: manual 403 | Manual ESC/POS de Epson (tabla de QR) o **prueba empírica contra la impresora real** en la aceptación de Fase 1. **Alternativa suficiente**: `print_targets.max_qr_payload_bytes` como **dato**, con valor por defecto conservador | **MEDIA** | **Ninguno**: es un parámetro del destino, no del esquema |
| **R5** | **Protocolo ESC/POS de red de Star** | **No verificable**: manual 403 | Manual de red de Star Micronics. **Lo ya verificado**: Star cambia el comando de tabla a `ESC GS t n` (E2, L3:745-750) y el flag `starCommands` ya es un **dato** del perfil | **BAJA** | **Ninguno** |
| **R6** | **NAT / firewall y sincronización de agentes**: comportamiento del long-poll tras proxies que cortan conexiones inactivas | **No verificable**: sin fuente de red admisible; es `[INFERENCIA]` | **Prueba en la LAN real** de la tienda (el long-poll con `poll_after_ms` configurable y timeout de 30 s ya tolera cortes: el agente reintenta con backoff) | **MEDIA** | **Ninguno**: el mecanismo (heartbeat + long-poll + backoff) ya está especificado |
| **R7** | **Instalación silenciosa**: flags exactos de Inno Setup, comparación MSI/NSIS, instalación desatendida de un **servicio** Windows | **No verificable**: la clase admite el repo `issrc`, no su manual | Manual de Inno Setup (`jrsoftware.org/ishelp`), descargable. **Alternativa suficiente**: `[Run]` + `skipifsilent` **verificados** (E11) → el mecanismo mínimo de arranque ya está especificado | **BAJA** | **Ninguno**: el instalador es **Fase 1** |
| **R8** | **Códigos de barras**: symbologies exactas soportadas por cada modelo (`GS k` vs función B de `GS k`) | **No verificable**: manual 403; la librería define 9 symbologies (E12) | Manual del modelo. **Alternativa suficiente**: el backend valida contra el **perfil** (`getSupportsBarcodeB()`, E5) | **BAJA** | **Ninguno**: el legacy tampoco imprimía códigos de barras |
| **R9** | **Impresión fiscal venezolana**: QR, contenido, obligatoriedad, factura física vs. comprobante digital | **No verificable en este carril** | Carril hermano `research/senat-venezuela.md` T5/T9 — también `[NO VERIFICADO]`, pendiente del *Manual Técnico* de SENIAT (art. 41) | **MEDIA** | **Ninguno**: `fiscal_documents.qr_content` / `.qr_url` son **opcionales** y el ticket se modela con QR nullable |

### 8.2 Contradicciones detectadas

| # | Contradicción | Resolución |
|---|---|---|
| C1 | `exploration.md` §8.1 dice que `stores.remote_printing` = "0 = Web Browser, 1 = PHP Server". El código dice lo contrario para los botones manuales: `remote_printing != 1` → `$.get('_inc/print.php')` (**servidor**), `remote_printing == 1` → `popupWindow()` (**navegador**) (`PosController.js:970-998`) | **Verificado en el código**: la etiqueta del mapa está invertida para los botones manuales. Además, con `auto_print == 1 && remote_printing == 1` la factura **se imprime por el servidor** (`PaymentFormModal.js:102-104` → `PrintReceiptModal` → `_inc/print.php`). El valor por defecto del dump **debe verificarse** antes de migrarlo |
| C2 | `exploration.md` §8.4 (Opción A) promete "el mismo formato en navegador, LAN y servidor" | **Reformulado** en §7.2: lo unificable es el **origen del contenido** (el modelo de plantilla del documento), **no el binario**. El navegador no consume ESC/POS (verificado en el legacy: el modo "web browser" imprimía HTML — L6, E14) |
| C3 | `exploration.md` §8.3 propone "token por tienda"; este carril propone "token por agente" | **Refinamiento**, no contradicción: el token por agente acota la explosión y el servidor sigue forzando el scope de tienda. Requiere decisión del orquestador |
| C4 | El dump siembra `printers.char_per_line = 200` (L5) mientras la clase `Escpos` usa 42 (L2) | Dato basura; hay que **migrar con criterio** (42/48/32 según el perfil real), no copiar |
| C5 | `exploration.md` §1.2 registra **un** `dd()` que rompe la impresión; hay **dos** (`_inc/print.php:29` y `_inc/lib/escpos.php:32`) | **Hallazgo nuevo**: quitar el primero no arregla nada. Refuerza "no hay baseline que portar" |

### 8.3 Lo que esta ejecución **sí** resolvió

| Tema | Estado |
|---|---|
| Secuencias de bytes del juego de comandos esencial | **Resuelto** (E1, E2, E12, L3). Procedencia: *derivado de librería*, no de fabricante (R1) |
| Framing de QR/PDF417 (`GS ( k`) y de gráficos (`GS ( L`) | **Resuelto** (E1, L3) |
| Tablas de caracteres: número → CP, y **cuáles son codificables** | **Resuelto** (E5, L4, E14). Hallazgo: **CP858 no es codificable** → CP850 para Venezuela |
| UTF-8: qué se acepta y qué se envía | **Resuelto** (E6) |
| Columnas por perfil y modelo de "el ancho es dato" | **Resuelto**; 58 mm → R2 (**BAJA**, dato por destino) |
| Mecanismos de transporte y conectores | **Resuelto** (E3, E9, E10, E13, E14); lo propietario → R1/R5 (**MEDIA/BAJA**) |
| **USB directo en Windows exige un driver extra (Zadig/WinUSB)** → el destino del agente debe ser TCP 9100 o impresora compartida | **Resuelto** (E13, E14) |
| API de QR de las dos librerías | **Resuelto**; límite de payload → R4 (**MEDIA**, dato por destino) |
| Comparación de librerías y recomendación | **Resuelto** (§4.2) |
| Por qué hace falta un agente; pull vs push | **Resuelto** (§5.1, §5.2). La parte de red es `[INFERENCIA]` → R6 |
| Contrato de API del agente | **Resuelto** (§7.1), con la procedencia de cada regla marcada |
| Máquina de estados de `print_jobs`, idempotencia, reimpresión | **Resuelto** (§7.3) |
| Mecanismo de instalador y flags de `[Run]` | **Resuelto** para el mecanismo (E11); flags exactos → R7 (**BAJA**, Fase 1) |
| **Premisa de `exploration.md` §8.4** | **Reformulada** (§7.2): lo unificable es el **origen del contenido**, no el binario |
| QR fiscal venezolano | **No verificable en este carril** → R9 (carril hermano) |

---

## 9. Metadatos del artefacto

| Campo | Valor |
|---|---|
| `artifact_id` | `gentle-ai.sdd-research/v1` |
| `change` | `fase0-fundaciones` |
| `lane` | `escpos-agente-lan` |
| `revision` | `2` |
| `outcome` | **`done`** — las 13 preguntas tienen respuesta verificada **o** plan de cierre con fuente alternativa; ningún residual es estructural (§8.1) |
| `questions` | 13 (T1-T13 de la petición): **todas respondidas**. T1 comandos (verificado) · T2 anchos (verificado salvo 58 mm → R2) · T3 codepáginas (verificado) · T4 QR (verificado salvo límite de payload → R4) · T5 transporte (verificado) · T6 librerías (verificado + recomendación) · T7 por qué el agente (inferencia documentada) · T8 pull vs push (inferencia documentada) · T9 seguridad (decisión nuestra, §5.3) · T10 heartbeat/cola (decisión nuestra + contrato cerrado) · T11 empaquetado Windows (mecanismo verificado; flags → R7) · T12 estados de `print_jobs` (decisión nuestra, contrato cerrado) · T13 fiscal VE (R9, carril hermano) |
| `admission` | `documentation` admitida (de facto, §2.1) · `open-web` **denegada al agente**, **recuperada por el orquestador** (E14) · `bash`/`task` denegadas |
| `sources` | **14** (E1-E13 `documentation` + E14 `open-web` recuperada), todas de **terceros**; **0 fuentes de fabricante transcritas** (el PDF de Epson es 403) |
| `internal references` | 8 (L1-L8) + 1 carril hermano (S1) — **no son evidencia externa** |
| `claims validadas` | Las que citan un ID de fuente (E* o L*); el resto marcado `[INFERENCIA]` o con plan de cierre en §8.1 |
| `contradicciones` | 5 (C1-C5, §8.2) |
| `hallazgos nuevos` | **2º `dd()`** en `_inc/lib/escpos.php:32` (C5) · **CP858 no codificable** en `escpos-php` (§3.3) · **el navegador nunca consumió ESC/POS** (C2, §7.2) |
| `decisiones de producto` (todas **nuestras**, ninguna con norma detrás) | Bytes en el **servidor** (opción A) · **pull** (long-poll) · token **por agente** · `transport='browser'` = **HTML**, no ESC/POS · perfil de impresora, charset, ancho y límite de QR como **datos** · agente = proceso Windows autónomo, no endpoint PHP |
| `verificado vs decidido` | §7.1 marca la **procedencia** de cada regla del contrato; §5.3 y §6.3 declaran sus bloques como `DECISIÓN NUESTRA`; §3.1 declara la procedencia de los bytes |
| `riesgos residuales` | 9 (R1-R9, §8.1): severidad **MEDIA** R1/R4/R6/R9 · **BAJA** R2/R3/R5/R7/R8 · **ninguno ALTA** |
| `propuesta lista` | **Sí** — `outcome: done`, sin huecos estructurales. Los residuales R1-R9 son de **calibración y despliegue (Fase 1)** o de otro carril (R9) |
| **store** | `hybrid` — OpenSpec (`openspec/changes/fase0-fundaciones/research/escpos-agente-lan.md`) + Engram (`sdd/fase0-fundaciones/research-escpos-agente-lan`) |
