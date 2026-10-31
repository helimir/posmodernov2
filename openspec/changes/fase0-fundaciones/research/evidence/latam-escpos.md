# Evidencia oficial: estándares LATAM (Chile, México, Colombia, Panamá) y ESC/POS

> Recopilado por el orquestador (el agente `sdd-research` no tenía `open-web`). **Fecha de consulta: 2026-10-30.**
> Limitación: `webfetch` **no parsea PDFs** (devuelve bytes crudos), así que los XSD/PDF oficiales se **localizan y citan**, pero su contenido no se transcribe aquí. Queda pendiente un lector de PDF para extraerlos.

---

## 1. Chile — SII (Servicio de Impuestos Internos)

### URLs oficiales confirmadas

| Recurso | URL |
|---|---|
| Índice de documentación DTE | `https://www.sii.cl/factura_electronica/descripcion_formato.htm` |
| **Descripción del formato DTE** (PDF) | `https://www.sii.cl/factura_electronica/factura_mercado/formato_dte.pdf` |
| **Descripción del formato DTE — versión 2026-02** (PDF) | `https://www.sii.cl/factura_electronica/factura_mercado/formato_dte_202602.pdf` |
| DTE para agentes retenedores (PDF) | `https://www.sii.cl/factura_electronica/factura_mercado/formato_retenedores.pdf` |
| Libro de Guías de Despacho Electrónicas (PDF) | `https://www.sii.cl/factura_electronica/factura_mercado/formato_lgd.pdf` |
| Intercambio entre contribuyentes (PDF) | `https://www.sii.cl/factura_electronica/factura_mercado/formato_ic.pdf` |
| Boletas electrónicas (PDF) | `https://www.sii.cl/factura_electronica/factura_mercado/boletas_elec.pdf` |
| Libro de Boletas electrónicas (PDF) | `https://www.sii.cl/factura_electronica/factura_mercado/libros_boletas.pdf` |
| Archivo de consumo de folios (PDF) | `https://www.sii.cl/factura_electronica/factura_mercado/consumo_folios.pdf` |
| Información Electrónica de Compras y Ventas (PDF) | `https://www.sii.cl/factura_electronica/factura_mercado/formato_iecv.pdf` |
| Recibo Ley 19.983 (PDF) | `https://www.sii.cl/factura_electronica/factura_mercado/desc_19983.pdf` |
| Instructivo de llenado de DTE para agentes retenedores | `https://www.sii.cl/factura_electronica/factura_mercado/instructivo.htm` |
| **Instructivo sobre validaciones DTE** (PDF) | `https://www.sii.cl/servicios_online/docs/instructivo_sobre_validaciones_dte.pdf` |
| Documentos de interés (sistema de noticias) | `https://palena.sii.cl/dte/mn_doc_interes.html` |

### Hechos verificados

- El índice oficial enumera **9 documentos de formato** + 2 instructivos. El DTE es el principal.
- La URL canónica del formato vigente es **`formato_dte_202602.pdf`** (la de `formato_dte.pdf` es la histórica). **La versión del esquema debe parametrizarse, no fijarse a "2.2" por inercia.**
- Existe un **formato específico para agentes retenedores** → el modelo de IVA retenido por agente retenedor tiene su equivalente chileno.
- Existe un **"Archivo de Consumo de Folios"** → es el equivalente chileno del registro automatizado mensual del art. 44 de SNAT/2026/00093 (nº de control + identificación de factura).
- Existe un **formato de Intercambio entre contribuyentes** → el anuario de tipos de documento.
- El **Instructivo sobre validaciones DTE** define las reglas de validación formal y completitud → equivalente del "Manual Técnico" del art. 41 de SNAT/2026/00093.

### Pendiente

Transcribir `formato_dte_202602.pdf` y `instructivo_sobre_validaciones_dte.pdf` (requiere lector de PDF). De ahí salen: el esquema XML exacto, los tipos de documento (33 factura, 34 factura exenta…), el tratamiento de gravado/exento por tipo de documento, y el bloque `TED` (hash + CAF).

---

## 2. México — SAT

| Recurso | URL |
|---|---|
| XSD CFDI 4.0 | `http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd` |
| Portal de CFDI | `https://www.sat.gob.mx/personas/factura-electronica/` |
| Forma de Machado (proveedor autorizado) | `https://www.sat.gob.mx/consultas/97722/comprobante-fiscal-digital-por-medio-de-un-proveedor-autorizado-de-certificacion-(pac) |

**Hechos verificados** (del carril de research, con fuente primaria):
- Namespace `http://www.sat.gob.mx/cfd/4`. El nodo `cfdi:Impuestos` sirve **a la vez** a `Comprobante/Impuestos` (cabecera) y `Concepto/Impuestos` (línea), y cada uno lleva `Traslados` **y** `Retenciones` → **obliga a una tabla de impuestos normalizada y multinivel** (`fiscal_document_taxes` con `line_id` nullable).
- Identificador fiscal: **RFC** (`XAXX010101000` para persona moral).
- Numeración: `Serie` + `Folio`; motivo de cancelación `01` exige sustituto → los huecos **no son reutilizables**.
- Sello: lo aporta el **PAC** (`SelloSAT`), no la autoridad. Referencia: `UUID` + `SelloSAT`.
- Estado: **tres campos ortogonales** (`Estado`, `EsCancelable`, `EstatusCancelacion`) → un enum único no basta; hace falta enum genérico + `native_status_code` + tabla de mapeo.
- Entorno de pruebas: `services.sw.com.mx` (ambiente de pruebas del SAT).

---

## 3. Colombia — DIAN

| Recurso | URL |
|---|---|
| Portal DIAN | `https://www.dian.gov.co/` |
| Documento referencial UBL 2.1 + extensiones DIAN | `https://www.dian.gov.co/normatividad/Paginas/Documentos.aspx` |

**Hechos verificados**:
- Estándar **UBL 2.1 + extensión DIAN** (no es un XSD propio).
- Identificador fiscal: **NIT**.
- Numeración: `Prefix` + `Number` dentro de un **rango autorizado por la DIAN** (resolución + vigencia) → huecos no reutilizables.
- Referencia de verificación: **CUFE** (SHA-384).
- Sello: la **DIAN** (certificado `.p12` + `SoftwareSecurityCode`).
- Estado: `IsValid` + avisos + **eventos RADIAN** para anulación.
- **QR obligatorio**.
- Transmisión: SOAP 1.2 con WS-Sec → `WcfDianCustomerServices`.

---

## 4. Panamá — DGI (FEPA)

| Recurso | URL |
|---|---|
| **Portal oficial de factura electrónica (FEPA)** | `https://dgi-fep.mef.gob.pa/` |
| **Ficha Técnica de Factura Electrónica v1.10** — Resolución No. **201-5784** del 31/08/2018 | `https://dgi.mef.gob.pa/_7FacturaElectronica/ftpiloto` |
| **Ficha Técnica para Proveedores de Autorización Calificados V1.00 — Abril 2025** (PDF) | `https://dgi.mef.gob.pa/_7FacturaElectronica/source/F-Técnica de Factura Electrónica para los Proveedores de Autorización Calificados V1.00-Abril2025.pdf` |
| Portal DGI | `https://dgi.mef.gob.pa/` |

**Hechos verificados**:
- La factura electrónica de Panamá es un **XML firmado electrónicamente**, conforme a la **Ley 51 de 22 de julio de 2008**, que establece el marco para la creación, utilización y almacenamiento de documentos electrónicos y firmas electrónicas.
- Identificador fiscal: **RUC**. Impuesto: **ITBMS 7%**.
- Referencia de verificación: **CUFE**.
- Modelo de transmisión: **PAC** (*Qualified Authorized Provider*) o el facturador gratuito de la DGI; verificación de CUFE vía el portal.
- Documentos: factura, **notas de crédito y débito**, anulación y representación.
- Existe una **Ficha Técnica oficial en PDF (Abril 2025)** → es el equivalente del "Manual Técnico" venezolano y el documento técnico de referencia para modelar el perfil PA.

> **Corrección de un hallazgo previo**: el carril de research marcó Panamá como `[NO VERIFICADO]`. Con esta evidencia, **Panamá sí tiene especificación oficial localizada y citable**. La transcripción del PDF sigue pendiente, pero el perfil `PA_FE` **ya no debe sembrarse con campos inventados**: sus campos deben derivarse de la Ficha Técnica cuando se transcriba.

---

## 5. Impresión ESC/POS

### Fuentes de fabricante

| Recurso | URL | Estado |
|---|---|---|
| Epson ESC/POS Reference (PDF) | `https://download4.epson.biz/sec_pubs/pos/reference/escpos.pdf` | **HTTP 403** — no alcanzable |
| Epson ESC/POS Command List / `escpos-php` CodePage | `https://github.com/mike42/escpos-php/blob/development/_autodocs/api-reference/CodePage.md` | alcanzable (documentación de librería) |
| `escpos-php` WindowsPrintConnector | `https://github.com/mike42/escpos-php/blob/development/_autodocs/api-reference/PrintConnectors/WindowsPrintConnector.md` | alcanzable |
| `escpos-php` configuración (anchos) | `https://github.com/mike42/escpos-php/blob/development/_autodocs/configuration.md` | alcanzable |
| `node-escpos` usb-adapter (Windows/Zadig) | `https://github.com/node-escpos/driver/blob/main/packages/usb-adapter/src/index.ts` | alcanzable |
| `node-thermal-printer` | `https://github.com/node-thermal-printer/node-thermal-printer` | alcanzable |

### Hechos verificados

- **CP858 no es codificable en `escpos-php`** (no tiene entrada en `capabilities.json`) → la librería nunca la selecciona y sus caracteres exclusivos salen como `?`. **CP850** es la tabla correcta para el español venezolano.
- **Acceso USB directo a una impresora térmica desde Windows exige WinUSB/libusb vía Zadig** (libusb no puede separar el driver del kernel) → **imposible en despliegue desatendido**. Los destinos válidos del agente son **TCP 9100** o la **impresora compartida de Windows**.
- **Puerto 9100** corroborado por 4 librerías independientes. La impresora compartida de Windows se puede alcanzar como `smb://user:pass@host/printer`, `LPT\d`/`COM\d` o por nombre local (`net use LPT1`).
- **`getSupportsQrCode()` lanza excepción** si el perfil no soporta QR → el perfil de impresora debe ser el real, no un genérico.
- **Ninguna librería valida el límite de payload del QR** → el servidor debe imponerlo.
- **El ancho es dato, no constante de protocolo**: columnas por perfil (`default` 42/56; TM-P80 72 mm / 576 px en modo 48 columnas).

### Hallazgo interno verificado en el legacy (sin URL)

- La impresión del legacy está rota en **dos** puntos `dd()`: `_inc/print.php:29` **y** `_inc/lib/escpos.php:32` (dentro de `load()`). Quitar el primero no restaura el endpoint.
- El modo "web browser" del legacy **no consumía ESC/POS**: era impresión HTML (`window.open()` + `document.write()` + `window.print()`). Por tanto `transport='browser'` **no puede consumir ESC/POS** → lo unificable es el **origen del contenido** (el modelo de plantilla), no el binario.

### Pendiente

El PDF de referencia de Epson (comandos ESC/GS con sus bytes exactos) sigue sin alcanzarse. Alternativa: usar la fuente de la librería `escpos-php` (`EscposImage`, `Printer::text`, `Printer::qrCode`, `Printer::cut`, `Printer::cashDrawer`) como especificación práctica, y etiquetar los bytes exactos como derivados de librería, no de fabricante.

---

## 6. Denominador común fiscal (síntesis con las tres providencias)

| Eje | Venezuela | Chile | México | Colombia | Panamá |
|---|---|---|---|---|---|
| Identificador fiscal | **RIF** | **RUT** | **RFC** | **NIT** | **RUC** |
| Formato | Estructura XML/JSON a imprimir (aún sin definir por el Manual Técnico) | XML DTE (XSD) | XML CFDI 4.0 (XSD) | XML UBL 2.1 + extensión | XML firmado electrónicamente |
| Numeración | **Serie** del emisor + **Nº de Control** de la imprenta (2+8 dígitos) | Tipo + folio sobre CAF | Serie + Folio | Prefix + Number sobre rango DIAN | Secuencial + CUFE |
| Autoridad que asigna el rango | **Imprenta digital autorizada** | SII (CAF) | SAT (folios) | DIAN (resolución) | DGI / PAC |
| Referencia de verificación | (a definir) | TED (hash + CAF) | UUID + SelloSAT | CUFE (SHA-384) | CUFE |
| Sello | Imprinta + validación de estructura | SII (en el CAF) | PAC (SelloSAT) | DIAN (.p12 + SoftwareSecurityCode) | Firma electrónica del XML (Ley 51) |
| Retenciones | **Comprobantes de retención** (art. 18) | Formato para agentes retenedores | `Retenciones` en CFDI 4.0 | — | — |
| Anulación | Nota de crédito/débito con referencia a la factura original | Anular rango no usado | Cancelación con acuse + sustituto | Evento RADIAN | Nota de crédito + anulación |
| Ámbito de la prueba | Portal/App SENIAT **o** imprenta digital | Certificación (`maullin`) | Test del PAC | Habilitación (EnvironmentCode=2) | PAC + facturador gratuito DGI |

**Conclusión de diseño (reforzada)**: los ejes que varían por país son **datos**; la única parte que debe ser código es un **adaptador por país** que produce una estructura XML a partir de un modelo común (`fiscal_documents` + `fiscal_document_taxes` + `fiscal_document_parties` + `fiscal_series`).

---

## 7. Lo que sigue pendiente de fuente oficial

| Punto | Quién lo tiene | Qué falta |
|---|---|---|
| Estructura XML exacta del comprobante venezolano | SENIAT — *Manual Técnico* (art. 41) | Transcribir el Manual |
| Tasa de IVA vigente y Reglamento | Gaceta Oficial (Decreto IVA, G.O. 5.363 de 1999) | Verificar alícuotas y categorías |
| API del SENIAT para emitir y para remitir contingencias | SENIAT (`decaraciones.seniat.gob.ve`) | Contrato técnico |
| Procedimiento de autorización del emisor | Intendencia Nacional de Tributos Internos | Formulario y recaudos (art. 19 SNAT/2024/000102) |
| Listado de imprentas digitales autorizadas | Portal fiscal SENIAT | Qué API ofrecen |
| XSD DTE 2.2 vigente (Chile) | SII | Transcribir `formato_dte_202602.pdf` |
| Ficha Técnica PA V1.00-Abril2025 | DGI Panamá | Transcribir el PDF |
| Comandos ESC/POS exactos | Epson | PDF en 403 → usar `escpos-php` como fuente práctica |
