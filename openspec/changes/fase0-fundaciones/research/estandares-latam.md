# Research: Estándares fiscales de Latinoamérica

> **Carril**: `fase0-fundaciones` · **Pregunta**: ¿qué tienen en común y en qué difieren los estándares de documento electrónico fiscal de **Venezuela, Chile, México, Colombia y Panamá**, y qué modelo de datos mínimo los soporta a todos **sin código por país**?
>
> **Estado**: `success` · **proposal_ready**: `true` · **Alcance de la evidencia**: los cinco países tienen **especificación oficial localizada y citable**. Lo que queda pendiente es de **transcripción** (XSD/PDF), no de localización — y eso se distingue con precisión en §6.
>
> **Artefacto de detalle**: la evidencia oficial supplementaria (URLs de SII/SAT/DIAN/DGI/SENIAT) está en `research/evidence/latam-escpos.md` y `research/evidence/senat-venezuela.md`. Este documento es la **síntesis de diseño**.

---

## 1. Resumen ejecutivo

1. **Los cinco países comparten un mismo esqueleto**: documento XML firmado, un identificador fiscal de emisor y receptor (RIF / RUT / RFC / NIT / RUC), una numeración sobre un **rango autorizado**, un bloque de impuestos, una firma digital, un estado de ciclo de vida, un canal de transmisión contra la autoridad y un ambiente de pruebas propio.
2. **La evidencia externa confirma el diseño de `exploration.md` §4.2** (`fiscal_documents` + `fiscal_profiles` + `fiscal_series`) y no exige ninguna tabla adicional obligatoria: las divergencias caben en extensiones por país.
3. **México es el país que rompe el modelo simple**: su nodo `cfdi:Impuestos` aparece *tanto* a nivel de comprobante *como* de concepto, y cada uno lleva `Traslados` **y** `Retenciones` con base, tasa e importe propios [M1, M2]. Los impuestos **no** pueden vivir en columnas fijas: exigen `fiscal_document_taxes` multinivel.
4. **Venezuela aporta el hallazgo más fuerte y más nuevo**: hay **DOS numeraciones distintas con autoridades distintas** — la numeración del documento (propia del emisor) y el **Nº de Control** (asignado por la *imprenta digital* autorizada, `NN` + 8 dígitos) [V1 art. 8.3, 9.1, 27; V2 art. 30]. El `fiscal_series` del exploration modela **una sola** → hay que separar **dos reservas atómicas independientes**. Además Venezuela exige un **actor intermedio obligatorio**: emisor → imprenta digital → SENIAT [V1 art. 42.1; V2 art. 18.4, 18.6].
5. **El estado del documento no es un enum universal**: Chile tiene 4 estados de envío (`E`/`A`/`R`/`X`) [S5]; México expone *tres campos independientes* (`Estado`, `EsCancelable`, `EstatusCancelacion`) [M6]; Colombia reporta `IsValid` + rechazos/avisos + eventos RADIAN [C1]; Venezuela usa **documentos de corrección** (nota de crédito/débito que referencian fecha, nº correlativo, nº de control y monto del original) [V1 art. 12]; Panamá tiene "anulación" como documento [O20–O22].
6. **La referencia de verificación es polimórfica**: Chile = bloque `TED` (hash + firma + CAF) [S1]; México = `UUID` + `SelloSAT` de un PAC [M3]; Colombia = `CUFE` SHA-384 + `SoftwareSecurityCode` + QR [C1]; Panamá = `CUFE` [O22]; Venezuela = por definir en el Manual Técnico del art. 41 [V1]. → par **valor + esquema**, nunca una columna `uuid`.
7. **El QR NO es un denominador común**: sólo Colombia lo tiene verificado como obligatorio [C1]; en Venezuela el QR **no aparece** en el texto normativo de ninguna de las dos providencias [V1, V2]. El campo queda **opcional por perfil**.
8. **Panamá sí tiene especificación oficial**: Portal FEPA de la DGI, **Ficha Técnica de Factura Electrónica v1.10 (Resolución 201-5784 del 31/08/2018)** y **Ficha Técnica para Proveedores de Autorización Calificados V1.00 — Abril 2025** [O20–O23]. XML firmado electrónicamente conforme a la **Ley 51 de 22/07/2008**, RUC, **ITBMS 7 %**, CUFE, PAC o facturador gratuito de la DGI. **Corrección a la versión anterior de este artefacto**: Panamá no es `[NO VERIFICADO]`; su perfil se diseña desde la Ficha Técnica cuando se transcriba, **no se descarta**.
9. **La versión del esquema DTE chileno debe quedar parametrizada, no fijada a "2.2" por inercia**: la URL canónica vigente es `formato_dte_202602.pdf` (versión 2026-02) [O3]. La evidencia recuperada por el carril leyó el esquema **v1.0** [S1, S8] — luego la versión es un **dato de `fiscal_profiles.schema_version`**, no una constante.

---

## 2. Metodología y fuentes

### 2.1 Admisión de evidencia

| Clase | Mecanismo | Estado | Consecuencia |
|---|---|---|---|
| `documentation` | Context7 MCP | **Admitida** | única clase utilizable por el agente `sdd-research`. Aporta la transcripción técnica de CL, MX y CO. |
| `open-web` | `webfetch` | **DENEGADA para el agente** (`webfetch: deny`, `websearch: deny` en `opencode.json` → `agent.sdd-research.permission`) | **Recuperada por el orquestador**, que sí dispone de `open-web`. Sus hallazgos están en `research/evidence/latam-escpos.md` y `research/evidence/senat-venezuela.md`, y se incorporan aquí como §2.4. |
| `bash` / `task` | — | DENEGADA | Sin shell: no se pudo abrir ni un XSD ni un PDF. |

**Limitación honesta y su alcance exacto:** `webfetch` **no parsea PDFs** — devuelve bytes crudos. Por tanto:

- **Localizada ≠ transcrita.** Una URL de PDF está *confirmada como existente y oficial*; su *contenido* no está en la evidencia. Se marca así, con precisión, en cada punto afectado.
- **Ningún XSD oficial fue inspeccionado.** Los esquemas se conocen por lo que las librerías indexadas declaran usar (namespace + `schemaLocation`) [M2, M7] y por las URL oficiales confirmadas [O15].
- La **evidencia venezolana** (providencias SNAT/2026/00093 y SNAT/2024/000102) llega como **texto íntegro republicado por terceros**; el PDF original de la Gaceta Oficial 43.484 y `seniat.gob.ve` **no fueron alcanzados** (HTTP 403) [V1, V2].

### 2.2 Fuentes del carril `documentation` (transcripción técnica)

`Clase` = clase concedida. `Autoridad` = (a) oficial, (b) tercero. No hay fuentes de clase (c) en la tabla: las inferencias van marcadas como tales en el cuerpo.

| ID | Clase | Autoridad | Título | Publicador | URL | Consultado | Qué respalda |
|---|---|---|---|---|---|---|---|
| S1 | documentation | **oficial** | Formato XML de Documentos Electrónicos | SII Chile | https://www.sii.cl/factura_electronica/factura_mercado/formato_xml.htm | 2026-10-30 | Namespace `http://www.sii.cl/SiiDte`; 4 XSD (`EnvioDTE_v10`, `DTE_v10`, `SiiTypes_v10`, `xmldsignature_v10`); RUT; CAF con rango `RNG/D\|H`; `FRMA`/`FRMT`; `IVA` en `Totales` |
| S2 | documentation | **oficial** | Ejemplo de carga de DTE (HTTP POST) | SII Chile | https://www.sii.cl/factura_electronica/factura_mercado/ejem_upload.txt | 2026-10-30 | `POST /cgi_dte/UPL/DTEUpload` multipart; `EnvioDTE`/`Caratula` (`RutEmisor`, `RutEnvia`, `RutReceptor`, `FchResol`, `NroResol`, `SubTotDTE`); `ID="F996286T33"`; `TrackId` |
| S3 | documentation | tercero | `CodigoDocumento` enum | LibreDTE | https://github.com/libredte/libredte-lib-core/blob/master/_autodocs/types.md | 2026-10-30 | Tipos: 33 factura afecta, 34 factura exenta, 39/41 boleta, 43 liquidación, 46 factura de compra, 52 guía, 56 nota de débito, 61 nota de crédito, 110/111/112 exportación |
| S4 | documentation | tercero | Entidad `Caf` y `CafManager` | LibreDTE | https://github.com/libredte/libredte-lib-core/blob/master/_autodocs/api-reference/identifier-component.md | 2026-10-30 | `CAF{tipo}D{desde}H{hasta}`; caducidad = autorización + 180 días y **sólo** para tipos 33/43/46/56/61; `consume()` lanza `RuntimeException` sin folios; `getAvailableRange()` → `"1-3,5"`; `markConsumed()` |
| S5 | documentation | tercero | `EstadoEnvioSii`, `SiiRequest`, `SobreEnvio` | LibreDTE | https://github.com/libredte/libredte-lib-core/blob/master/_autodocs/api-reference/integration-component.md | 2026-10-30 | Estados `E`/`A`/`R`/`X` e `isFinal()`; `Caratula` completa |
| S6 | documentation | tercero | Manual de usuario `l10n_cl_fe` | Odoo Chile | https://github.com/cesar250101/l10n_cl_fe/blob/16.0/MANUAL_USUARIO.md | 2026-10-30 | CAF autoriza rangos con vigencia de 6 meses; anular rangos no utilizados; consulta de anulados; certificación/producción; Timbre Electrónico (PDF417); Libro CV; **MEPCO**; RUT; `apicaf.cl` |
| S7 | documentation | tercero | HTTP DTE (API sobre LibreDTE) | `gepd/http-dte` | https://context7.com/gepd/http-dte/llms.txt | 2026-10-30 | `Sii::setAmbiente(CERTIFICACION\|PRODUCCION)`; servidores `maullin`/`palena`; `schemaValidate()` → `enviar()` → `TrackId`; set básico de pruebas |
| S8 | documentation | tercero | `ValidatorWorker::validateSchema()` | LibreDTE | https://github.com/libredte/libredte-lib-core/blob/master/_autodocs/api-reference/document-workers-pipeline.md | 2026-10-30 | Validación contra `resources/schemas/DTE_v10.xsd` |
| M1 | documentation | tercero | Elementos XML (`cfdi:Impuestos`, `cfdi:Retenciones`) | `eclipxe13/cfdiutils` | https://github.com/eclipxe13/cfdiutils/wiki/Elements | 2026-10-30 | `cfdi:Impuestos` representa **a la vez** `Comprobante/Impuestos` y `Concepto/Impuestos`; expone `getTraslados()` y `getRetenciones()`; `cfdi:Retenciones.addRetencion()` |
| M2 | documentation | tercero | Crear un CFDI 4.0 | `eclipxe13/cfdiutils` | https://github.com/eclipxe13/cfdiutils/blob/master/docs/crear/crear-cfdi-40.md | 2026-10-30 | Namespace `http://www.sat.gob.mx/cfd/4`; `schemaLocation` → `cfdv40.xsd`; atributos del `Comprobante`; `Emisor/RegimenFiscal`; `Receptor/Rfc`+`DomicilioFiscalReceptor`+`RegimenFiscalReceptor`+`UsoCFDI`; `Concepto` con `ObjetoImp`; `Traslado` con `Base`, `Impuesto`(`002`=IVA), `TipoFactor`, `TasaOCuota`, `Importe` |
| M3 | documentation | tercero | `TimbreFiscalDigital` (PAC) | `sat-cfdi/python-satcfdi` | https://github.com/sat-cfdi/python-satcfdi/blob/main/satcfdi/create/cfd/tfd11.py | 2026-10-30 | Namespace `http://www.sat.gob.mx/TimbreFiscalDigital` v1.1; `UUID`, `FechaTimbrado`, `RfcProvCertif`, `SelloCFD`, `NoCertificadoSAT`, `SelloSAT`, `Leyenda`; cadena de validación completa; **ventana de 72 horas** |
| M4 | documentation | tercero | Guía de creación CFDI 4.0 | `sat-cfdi/python-satcfdi` | https://github.com/sat-cfdi/python-satcfdi/blob/main/docs/pages/getting_started/40_cfdi_create_guide.rst | 2026-10-30 | `serie`/`folio`; `Emisor`/`Receptor`; complemento `nomina12` (`percepciones` con `importe_gravado`/`importe_exento`, `deducciones` "ISR A CARGO"); complemento `pago20` (`num_parcialidad`, `imp_pagado`, `imp_saldo_ant`) |
| M5 | documentation | tercero | Cancelación (`cancel()`, `CancelReason`) | DeepWiki satcfdi | https://deepwiki.com/SAT-CFDI/python-satcfdi/6.3-cancellation-and-acceptreject | 2026-10-30 | Motivo `COMPROBANTE_EMITIDO_CON_ERRORES_SIN_RELACION`; `substitution_id` **obligatorio** con motivo `01`; respuesta con `code` y `acuse` |
| M6 | documentation | tercero | `SAT.status()` y estados | DeepWiki satcfdi | https://deepwiki.com/SAT-CFDI/python-satcfdi/7-sat-direct-integration | 2026-10-30 | `Estado` = `Vigente`\|`Cancelado`; `EsCancelable` = `Cancelable sin aceptación`\|`Cancelable con aceptación`\|`No cancelable`; `EstatusCancelacion`; `ValidacionEFOS` = `200` |
| M7 | documentation | tercero | Schema Location / XSD | DeepWiki satcfdi | https://deepwiki.com/SAT-CFDI/python-satcfdi/3.1-cfdi-documents-and-xelement-base | 2026-10-30 | `xsi:schemaLocation="http://www.sat.gob.mx/cfd/4 http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd"` |
| M8 | documentation | tercero | Endpoints SOAP del SAT | `luisiturrios1/python-cfdiclient` | https://github.com/luisiturrios1/python-cfdiclient/blob/master/_autodocs/endpoints.md | 2026-10-30 | `Autenticacion.svc`, `SolicitaDescargaService.svc`, `VerificaSolicitudDescargaService.svc`, `DescargaMasivaService.svc`, **`ConsultaCFDIService.svc`** en `consultaqr.facturaelectronica.sat.gob.mx` |
| M9 | documentation | tercero | PAC `SWSapien` | `sat-cfdi/python-satcfdi` | https://github.com/sat-cfdi/python-satcfdi/blob/main/satcfdi/pacs/swsapien.py | 2026-10-30 | Ambientes `PRODUCTION`/`TEST`; `services.sw.com.mx` / `services.test.sw.com.mx`; token Bearer; `cancel()` → `cfdi33/cancel/{rfc}/{uuid}/{reason}` |
| M10 | documentation | tercero | Descarga masiva de CFDI | `sat-cfdi/python-satcfdi` | https://github.com/sat-cfdi/python-satcfdi/blob/main/docs/pages/getting_started/45_cfdi_descarga_massiva.rst | 2026-10-30 | `EstadoSolicitud.TERMINADA`; `EstadoComprobante.VIGENTE` obligatorio; `IdSolicitud`/`IdsPaquetes` |
| C1 | documentation | tercero | README de `cofacture` | `movaltech/cofacture` | https://github.com/movaltech/cofacture/blob/main/README.md | 2026-10-30 | UBL 2.1 + extensión DIAN; `ProfileID`; `EnvironmentCode` 1/2; `OperationTypeCode` 10; `DocumentTypeCode` 01/91/92/05/95/20/25; `HashType` CUFE/CUDE-SHA384; rango autorizado (prefix, from/to, resolución, vigencia); CUFE/CUDE/CUDS/CUNE; XAdES-EPES con `.p12`; `sts:SoftwareSecurityCode`; QR `qr.URL(env, CUFE)`; SOAP `WcfDianCustomerServices`; `Result.IsValid`; RADIAN 030–034; ZIP |

### 2.3 Frescura

Todas las fuentes fueron consultadas el **2026-10-30**. Advertencias:

- **S1 y S2** son la página histórica "Factura Mercado" y describen el esquema **v1.0** [S1, S8]. La versión vigente es **dato**, y su URL canónica es `formato_dte_202602.pdf` [O3].
- **C1** es el README de una librería de terceros que afirma equivalencia con los ejemplos oficiales de la DIAN. Evidencia de **forma y estructura**, no especificación normativa.
- **M1/M2** y **M3/M7** son dos librerías independientes que coinciden en namespace y XSD del CFDI 4.0. Esa concordancia es el argumento de solidez disponible sin acceso al XSD oficial (que sí está localizado en [O15]).

### 2.4 Evidencia oficial supplementaria (recuperada por el orquestador con `open-web`)

> Todas consultadas el **2026-10-30**. `PDF oficial localizada; contenido no transcrito` = la URL existe y es oficial, pero el lector no parsea PDF, así que **no** hay transcripción del cuerpo. `HTML transcrito` = el cuerpo sí está disponible.

#### Chile — SII (14 URLs oficiales)

| ID | Recurso | URL | Estado |
|---|---|---|---|
| O1 | Índice de documentación DTE | https://www.sii.cl/factura_electronica/descripcion_formato.htm | HTML transcrito |
| O2 | Descripción del formato DTE (histórico) | https://www.sii.cl/factura_electronica/factura_mercado/formato_dte.pdf | PDF localizada; no transcrito |
| O3 | **Descripción del formato DTE — versión 2026-02 (canónica vigente)** | https://www.sii.cl/factura_electronica/factura_mercado/formato_dte_202602.pdf | PDF localizada; no transcrito |
| O4 | **DTE para agentes retenedores** | https://www.sii.cl/factura_electronica/factura_mercado/formato_retenedores.pdf | PDF localizada; no transcrito |
| O5 | Libro de Guías de Despacho Electrónicas | https://www.sii.cl/factura_electronica/factura_mercado/formato_lgd.pdf | PDF localizada; no transcrito |
| O6 | Intercambio entre contribuyentes | https://www.sii.cl/factura_electronica/factura_mercado/formato_ic.pdf | PDF localizada; no transcrito |
| O7 | Boletas electrónicas | https://www.sii.cl/factura_electronica/factura_mercado/boletas_elec.pdf | PDF localizada; no transcrito |
| O8 | Libro de Boletas electrónicas | https://www.sii.cl/factura_electronica/factura_mercado/libros_boletas.pdf | PDF localizada; no transcrito |
| O9 | **Archivo de consumo de folios** | https://www.sii.cl/factura_electronica/factura_mercado/consumo_folios.pdf | PDF localizada; no transcrito |
| O10 | Información Electrónica de Compras y Ventas | https://www.sii.cl/factura_electronica/factura_mercado/formato_iecv.pdf | PDF localizada; no transcrito |
| O11 | Recibo Ley 19.983 | https://www.sii.cl/factura_electronica/factura_mercado/desc_19983.pdf | PDF localizada; no transcrito |
| O12 | Instructivo de llenado de DTE para agentes retenedores | https://www.sii.cl/factura_electronica/factura_mercado/instructivo.htm | HTML transcrito |
| O13 | **Instructivo sobre validaciones DTE** | https://www.sii.cl/servicios_online/docs/instructivo_sobre_validaciones_dte.pdf | PDF localizada; no transcrito |
| O14 | Documentos de interés (noticias del sistema) | https://palena.sii.cl/dte/mn_doc_interes.html | HTML transcrito |

#### México — SAT

| ID | Recurso | URL | Estado |
|---|---|---|---|
| O15 | **XSD CFDI 4.0** | http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd | XSD localizado; no transcrito |
| O16 | Portal de CFDI | https://www.sat.gob.mx/personas/factura-electronica/ | HTML transcrito |
| O17 | Forma de Machado (proveedor autorizado) | https://www.sat.gob.mx/consultas/97722/comprobante-fiscal-digital-por-medio-de-un-proveedor-autorizado-de-certificacion-(pac) | HTML transcrito |

#### Colombia — DIAN

| ID | Recurso | URL | Estado |
|---|---|---|---|
| O18 | Portal DIAN | https://www.dian.gov.co/ | HTML transcrito |
| O19 | Documento referencial UBL 2.1 + extensiones DIAN | https://www.dian.gov.co/normatividad/Paginas/Documentos.aspx | HTML transcrito |

#### Panamá — DGI (FEPA)

| ID | Recurso | URL | Estado |
|---|---|---|---|
| O20 | **Portal oficial de factura electrónica (FEPA)** | https://dgi-fep.mef.gob.pa/ | HTML transcrito |
| O21 | **Ficha Técnica de Factura Electrónica v1.10 — Resolución No. 201-5784 del 31/08/2018** | https://dgi.mef.gob.pa/_7FacturaElectronica/ftpiloto | URL localizada; no transcrita |
| O22 | **Ficha Técnica para Proveedores de Autorización Calificados V1.00 — Abril 2025** | https://dgi.mef.gob.pa/_7FacturaElectronica/source/F-Técnica de Factura Electrónica para los Proveedores de Autorización Calificados V1.00-Abril2025.pdf | PDF localizada; no transcrita |
| O23 | Portal DGI | https://dgi.mef.gob.pa/ | HTML transcrito |

#### Venezuela — SENIAT

| ID | Recurso | URL | Estado |
|---|---|---|---|
| V1 | Providencia **SNAT/2026/00093**, Gaceta Oficial N.º 43.484 del 21/10/2026 (**texto íntegro republicado por tercero**) | https://adacontable.com/seniat/providencia-snat-2026-00093-gaceta-oficial-43484/ | Texto transcrito vía tercero; **PDF oficial de la G.O. no alcanzado** |
| V2 | Providencia **SNAT/2024/000102**, Gaceta Oficial N.º 43.032 del 19/12/2024 (**texto íntegro republicado por tercero**) | https://finanzasdigital.com/gaceta-oficial-n-43-032-medios-digitales-emision-facturas/ | Texto transcrito vía tercero; **PDF oficial no alcanzado** |
| V3 | SENIAT en línea | https://seniatenlinea.seniat.gob.ve/ | Localizado; no alcanzado en detalle |
| V4 | Portal fiscal SENIAT (declaraciones) | https://decaraciones.seniat.gob.ve | Citado en la disposición final SEXTA de [V2] |
| V5 | Análisis legal independiente (Marambio HLB) | https://www.marambio-hlb.com/seniat-dicta-la-providencia-snat-2026-00093-sobre-emision-de-facturas-y-documentos-fiscales-gaceta-oficial-n-43-484/?lang=es | Transcrito |

---

## 3. Ficha por país

> Convención: `[OFICIAL]` = fuente oficial; `[TERCERO]` = fuente de tercero; `[INFERENCIA]` = deducción del autor.
> **Nueva precisión de marcaje**: `⚠ UBICADA-NO-TRANSCRITA` = la fuente oficial existe y está citada, pero su contenido (PDF/XSD) no está en la evidencia. **Esto es distinto de `[NO VERIFICADO]`**, que significa "no se encontró fuente".

### 3.1 Chile — DTE

| # | Eje | Evidencia |
|---|---|---|
| 1 | **Estándar y schema** | **DTE (Documento Tributario Electrónico)**, SII. Namespace `http://www.sii.cl/SiiDte` [S1, S2]. El esquema son **4 XSD**: `EnvioDTE_v10.xsd`, `DTE_v10.xsd`, `SiiTypes_v10.xsd`, `xmldsignature_v10.xsd` — "es necesario contar con los 4 archivos para una validación correcta" [S1]; se valida contra `resources/schemas/DTE_v10.xsd` [S8]. **URL canónica del formato vigente: `formato_dte_202602.pdf` (versión 2026-02)** [O3] ⚠ UBICADA-NO-TRANSCRITA. **La versión del esquema es un dato de `fiscal_profiles.schema_version`, no una constante** — la evidencia transcrita es v1.0 [S1, S8]. El **índice oficial** [O1] y el **Instructivo sobre validaciones DTE** [O13] ⚠ UBICADA-NO-TRANSCRITA definen las reglas de validación formal. |
| 2 | **Identificación fiscal** | **RUT** (`Rol Único Tributario`), formato `cuerpo-dígito verificador` con guion: `77777777-7`, `60803000-K` [S1, S2]. El DTE transporta **tres** RUT: `RutEmisor`, `RutReceptor`, **`RutEnvia`** (mandatario) [S1, S5]; el sobre añade `rutSender`/`dvSender`/`rutCompany`/`dvCompany` [S2]. |
| 3 | **Numeración** | **Tipo de documento + folio correlativo** sobre el **CAF** (`Certificado de Autorización de Folios`), `CAF{tipo}D{desde}H{hasta}` [S1, S4]. La resolución habilitadora viaja en la carátula como `FchResol` + `NroResol` [S1, S2, S5]. **Rango autorizado: sí — el CAF *es* el rango** [S4]. **Huecos: no reutilizables** — `markConsumed()` marca folios usados y `getAvailableRange()` devuelve `"1-3,5"`; `consume()` lanza `RuntimeException` sin folios [S4]. **Vigencia**: autorización + 180 días, y **sólo** los tipos 33/43/46/56/61 caducan [S4]; el manual de Odoo lo dice "6 meses" [S6] — *conciliación*: 180 días ≈ 6 meses. El **Archivo de Consumo de Folios** [O9] es el registro mensual del consumo. |
| 4 | **Impuestos** | **IVA** a nivel de documento: `Totales/IVA`, con `MntNeto` y `MntTotal` [S1]. **Gravado/exento por tipo de documento**: 33 factura afecta vs 34 factura exenta; 39/41 boleta afecta/exenta [S3]. Además el manual de Odoo registra el impuesto **MEPCO** (combustibles) [S6]. **Existe formato oficial para agentes retenedores** [O4] e instructivo de llenado [O12] → el retenedor chileno tiene su propio camino documental ⚠ UBICADA-NO-TRANSCRITA. **IVA retenido en el DTE de venta, "hecho generador" y débito fiscal: `[NO VERIFICADO]`** (no transcrito en la evidencia). |
| 5 | **Firma y sello** | **Dos firmas**: `FRMT` (documento) y `FRMA` (del CAF, es decir del SII) [S1]. El bloque `TED` lleva `RE`, `TD`, `F`, `FE`, `RR`, `RSR`, `MNT`, el `CAF` completo (con `RSAPK` e `IDK`) y `TSTED` [S1]. XML-DSig (`http://www.w3.org/2000/09/xmldsig#`) con `RSAKeyValue` embebida [S1]; esquema `xmldsignature_v10.xsd` [S1]. |
| 6 | **Estado del documento** | Ciclo de envío con 4 estados: `ENVIADO='E'`, `ACEPTADO='A'`, `REPARO='R'`, `RECHAZADO='X'`; `isFinal()` = aceptado\|reparo\|rechazado [S5]. **Anulación**: dos operaciones distintas — **anular rangos de folios no utilizados** y la **consulta de documentos anulados ante el SII** [S6]. *Inferencia*: anular en Chile es una operación de rango (CAF) más una consulta; el hueco queda consumado, no se reutiliza. |
| 7 | **Formato de QR** | **`[NO VERIFICADO]`**: ningún QR en la evidencia transcrita. Lo documentado es el **Timbre Electrónico en PDF417** [S6] y la **representación gráfica** (obligada por el formato, ⚠ UBICADA-NO-TRANSCRITA). *Inferencia*: si el QR es exigible, es requisito de la **representación gráfica**, fuera del XML firmado. |
| 8 | **Transmisión** | **`POST /cgi_dte/UPL/DTEUpload`**, `multipart/form-data`, con `rutSender`/`dvSender`/`rutCompany`/`dvCompany` + `archivo` (XML firmado) → **`TrackId`** [S2, S7]. **Autenticación**: firma digital del emisor (certificado `.p12` con contraseña) + el `CAF` que autoriza rango y folios [S4, S7]. **Ambientes**: `Sii::setAmbiente(CERTIFICACION\|PRODUCCION)`, servidores `maullin` (certificación) y `palena` (producción) [S7]. **Emisión local: sí, con certificación** — exige enviar el **set básico de pruebas** por tipo [S7]. |

### 3.2 México — CFDI 4.0

| # | Eje | Evidencia |
|---|---|---|
| 1 | **Estándar y schema** | **CFDI 4.0**, SAT. Namespace `http://www.sat.gob.mx/cfd/4`; **XSD oficial `http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd`** [O15] ⚠ UBICADA-NO-TRANSCRITA, con `xsi:schemaLocation` declarado así [M2, M7]. Namespaces de complemento: `http://www.sat.gob.mx/TimbreFiscalDigital` (v1.1) [M3] y `http://www.sat.gob.mx/leyendasFiscales` [M2]. Portal oficial [O16]. |
| 2 | **Identificación fiscal** | **RFC**: persona moral `XAXX010101000`, persona física `AAA010101AAA` [M2]; normalizado a mayúsculas [M8]. Emisor: `Rfc` + `Nombre` + **`RegimenFiscal`** (`601` = General de Ley Personas Morales) [M2, M4]. Receptor: **`DomicilioFiscalReceptor`**, **`RegimenFiscalReceptor`** y **`UsoCFDI`** (`S01` sin efectos fiscales, `G03` gastos en general) [M2, M4]. |
| 3 | **Numeración** | **`Serie` + `Folio`** como atributos del comprobante, texto del emisor [M2, M4]: `Serie='A'`, `Folio='123456'` o `'0000123456'` [M2, M4]. **Rango autorizado por la autoridad: `[NO VERIFICADO]`** — no aparece trámite equivalente al CAF o al rango DIAN. *Inferencia*: el control de continuidad es del emisor; el sistema debe **permitir** la restricción sin exigirla. **Huecos: no reutilizables** — cancelar con motivo `01` ("comprobante emitido con errores sin relación") **exige** un CFDI sustituto (`substitution_id`) [M5]. |
| 4 | **Impuestos** | **El país que rompe el modelo.** `cfdi:Impuestos` representa **a la vez** `Comprobante/Impuestos` y `Concepto/Impuestos` — "la misma estructura de datos puede usarse porque no está restringida por sus atributos o hijos" [M1]. Cada uno expone **`Traslados`** y **`Retenciones`** [M1]; `cfdi:Retenciones` contiene nodos `Retencion` [M1]. `Traslado` = `Base`, **`Impuesto`** (`002` = IVA), **`TipoFactor`** (`Tasa`), **`TasaOCuota`** (`0.160000`), `Importe` [M2]. **ISR retenido** en nómina: `deducciones` con `tipo_deduccion='002'`, `clave='300'`, concepto "ISR A CARGO" [M4]. Percepciones de nómina distinguen `importe_gravado` / `importe_exento` [M4]. **Gravado/exento por concepto**: `ObjetoImp` (`'02'` = sí objeto de impuesto) [M2]. **Códigos `001` (ISR) y `003` (IEPS) como `Impuesto` de un `Traslado`: `[NO VERIFICADO]`** (no transcrito). |
| 5 | **Firma y sello** | **Doble firma**: el emisor firma con su **CSD** produciendo el `Sello` (`SelloCFD`); luego un **PAC** estampa el **`TimbreFiscalDigital`** con `UUID`, `FechaTimbrado`, `RfcProvCertif`, `SelloCFD`, `NoCertificadoSAT`, **`SelloSAT`**, `Leyenda` [M3]. Cadena de validación: certificado del emisor → `Sello` vs `cadena_original` → certificado del SAT → `SelloSAT` vs `cadena_original` del TFD → **ventana de emisión de 72 horas** [M3]. Forma de Machado (PAC) en [O17]. |
| 6 | **Estado del documento** | **Tres campos independientes** [M6]: `Estado` = `Vigente` \| `Cancelado`; `EsCancelable` = `Cancelable sin aceptación` \| `Cancelable con aceptación` \| `No cancelable`; `EstatusCancelacion`. Más `CodigoEstatus` y `ValidacionEFOS` = `200` [M6]. **Cancelación** con `CancelReason` y **acuse**; el motivo `01` exige `substitution_id` [M5, M9]. |
| 7 | **Formato de QR** | **`[NO VERIFICADO]`**: ninguna evidencia de QR en CFDI 4.0. *Inferencia*: el mecanismo público es el servicio **`ConsultaCFDIService`** + `UUID` + `SelloSAT` [M6, M8], no un QR embebido. `Leyenda` es texto, no QR. **El QR no puede asumirse como denominador común.** |
| 8 | **Transmisión** | **La emisión no va directa al SAT: pasa por un PAC.** SW sapien: producción `https://services.sw.com.mx`, test `https://services.test.sw.com.mx`, token Bearer; cancelación en `cfdi33/cancel/{rfc}/{uuid}/{reason}` [M9]. **Servicios directos del SAT** [M8]: `Autenticacion.svc`, `SolicitaDescargaService.svc`, `VerificaSolicitudDescargaService.svc`, `DescargaMasivaService.svc` (hosts `cfdidescargamasiva*.clouda.sat.gob.mx`) y **`ConsultaCFDIService.svc`** en `https://consultaqr.facturaelectronica.sat.gob.mx/ConsultaCFDIService.svc` (SOAPAction `http://tempuri.org/IConsultaCFDIService/Consulta`). **Autenticación**: FIEL/CSD firmado en el cuerpo SOAP + token para descarga masiva [M8, M10]. **Emisión local: sí, con test** (PAC `TEST`) [M9] y estados de prueba del SAT (`EstadoSolicitud`, `EstadoComprobante.VIGENTE`) [M10]. **URL del servicio de emisión CFDI 4.0 del SAT: `[NO VERIFICADO]`** — no necesaria si la emisión va por PAC. |

### 3.3 Colombia — DIAN UBL 2.1

| # | Eje | Evidencia |
|---|---|---|
| 1 | **Estándar y schema** | **DIAN 2.1 sobre UBL 2.1** con extensiones propias [C1]; no es un XSD propio. `ProfileID` = `"DIAN 2.1: Factura Electrónica de Venta"` / `"DIAN 2.1: Documento Equivalente POS"`; `OperationTypeCode` = `10` → `cbc:CustomizationID` [C1]. Nómina es un esquema **distinto, no UBL** (`NominaIndividual`) [C1]. **Documento referencial oficial UBL 2.1 + extensiones DIAN** localizado en el portal de normatividad [O18, O19] ⚠ UBICADA-NO-TRANSCRITA. **URIs exactas de namespace: `[NO VERIFICADO]`**. |
| 2 | **Identificación fiscal** | **NIT**. El cliente SOAP consulta el rango autorizado con **dos** NIT — emisor y proveedor de software — más el `softwareUUID`: `GetNumberingRange("900123456", "900123456", softwareUUID)` [C1]. La identidad del emisor es `Party` con `TaxScheme`/`CompanyID` [C1]. **ID fiscal de persona natural en Colombia: `[NO VERIFICADO]`** (¿RUT?). |
| 3 | **Numeración** | **Rango autorizado por la DIAN**: `Prefix`, `FromNumber`, `ToNumber`, **`ResolutionNumber`**, `ValidDateFrom`, `ValidDateTo`, y una `technicalKey` que además alimenta el CUFE [C1]. Autorizado **por par emisor + software**, cacheable porque "no cambia por documento" [C1]. **Huecos: `[NO VERIFICADO]`** si la DIAN los tolera; *inferencia*: al ser un intervalo cerrado y por software, la reserva atómica del correlativo es **obligatoria**. |
| 4 | **Impuestos** | El dominio declara `Tax`, `Line`, `Party`; el documento lleva `HeaderTaxes` y `Totals` [C1]. Estructura UBL estándar (`TaxTotal`/`TaxCategory`/`TaxScheme`) con el esquema de impuesto como `TaxScheme` de la parte. **Tasa de IVA (19 %) y demás impuestos: `[NO VERIFICADO]`** — no transcrito. *Inferencia*: el modelo es de **impuestos a nivel de documento** (`HeaderTaxes`). |
| 5 | **Firma y sello** | **XAdES-EPES** (C14N 1.0, RSA-SHA256) con certificado **`.p12` emitido por la DIAN**; el mismo certificado firma el documento y arma la cabecera **WS-Security** del SOAP [C1]. `sts:SoftwareSecurityCode` a partir del `softwareID` y el `PIN` asignados por la DIAN [C1]. |
| 6 | **Estado del documento** | `Result.IsValid`, `StatusDescription` y `Messages` que distingue **rechazos** de **avisos**, con `ApplicationResponse` embebido [C1]. Tipos que absorben estado: `01` factura, `91` nota de crédito, `92` nota de débito, `05` documento soporte, `95` ajuste, `20`/`25` documento equivalente (POS) [C1]. **RADIAN** transporta los eventos: `030` acuse, `031` reclamo, `032` recibo del bien, `033` aceptación expresa, `034` aceptación tácita [C1]. *Inferencia*: en Colombia la anulación es un **evento RADIAN** dirigido al documento, cuyo QR usa el CUFE del documento referenciado [C1]. |
| 7 | **Formato de QR** | **Sí, y es obligatorio en la representación gráfica**: el paquete `qr` construye "la URL/contenido del QR requerido en cada tipo de documento", `qr.URL(EnvironmentCode, CUFE)` [C1]. En eventos RADIAN el QR se construye con el CUFE del **documento referenciado** [C1]. |
| 8 | **Transmisión** | **SOAP 1.2 + WS-Security** contra `WcfDianCustomerServices`, con `soap.HabilitacionURL` (certificación) y el de producción [C1]. Operaciones `GetNumberingRange` y `SendBillSync` [C1]. Empaquetado **ZIP** con convención de nombre del servicio receptor [C1]. **Autenticación**: certificado de la DIAN + WS-Security + `softwareID`/`PIN`/`softwareUUID` [C1]. **Emisión local: sí, con habilitación** — `EnvironmentCode` `1`=producción, `2`=certificación; la tabla del README distingue qué tipos fueron aceptados en certificación [C1]. |

### 3.4 Panamá — factura electrónica (DGI / FEPA)

> **Corrección de la versión anterior de este artefacto.** Panamá **no** es `[NO VERIFICADO]`: tiene especificación oficial localizada y citable. Lo pendiente es la **transcripción** del PDF, que es una categoría distinta (§6.1).

| # | Eje | Evidencia |
|---|---|---|
| 1 | **Estándar y schema** | **Factura Electrónica de Panamá**, portal oficial **FEPA** de la DGI [O20]. Marco normativo: **Ficha Técnica de Factura Electrónica v1.10**, aprobada por la **Resolución No. 201-5784 del 31/08/2018** [O21]. Marco legal de documentos y firmas electrónicas: **Ley 51 de 22/07/2008** [O20, O22]. Norma complementaria: **Ficha Técnica para Proveedores de Autorización Calificados V1.00 — Abril 2025** [O22]. La factura electrónica es un **XML firmado electrónicamente** [O20, O22]. **Estructura XML, namespace y XSD: `⚠ UBICADA-NO-TRANSCRITA`** [O21, O22]. |
| 2 | **Identificación fiscal** | **RUC** [O20, O22]. **Formato/dígito verificador: `⚠ UBICADA-NO-TRANSCRITA`** (no transcrito en la Ficha Técnica). |
| 3 | **Numeración** | **Secuencial** + **CUFE** [O20, O22]. **Si hay rango autorizado por la DGI, quién lo asigna y con qué vigencia: `⚠ UBICADA-NO-TRANSCRITA`**. |
| 4 | **Impuestos** | **ITBMS 7 %** [O20, O22]. **Categorías de exento/no gravado y retenciones: `⚠ UBICADA-NO-TRANSCRITA`**. |
| 5 | **Firma y sello** | **Firma electrónica del XML** conforme a la **Ley 51 de 22/07/2008** [O20, O22]. **Algoritmo, cadena de confianza y formato de la firma: `⚠ UBICADA-NO-TRANSCRITA`**. |
| 6 | **Estado del documento** | El conjunto documental incluye **factura, notas de crédito y débito, anulación y representación** [O20, O22]. **Codificación de estados y cómo se representa la anulación en el XML: `⚠ UBICADA-NO-TRANSCRITA`**. |
| 7 | **Formato de QR** | **`[NO VERIFICADO]`** — ninguna evidencia de QR en la documentación localizada. |
| 8 | **Transmisión** | **PAC** (*Qualified Authorized Provider*) **o el facturador gratuito de la DGI**; **verificación del CUFE vía el portal** [O20, O22]. **Contrato técnico, endpoints y autenticación: `⚠ UBICADA-NO-TRANSCRITA`**. |

### 3.5 Venezuela — SENIAT

| # | Eje | Evidencia |
|---|---|---|
| 1 | **Estándar y schema** | **Providencia Administrativa SNAT/2026/00093**, Gaceta Oficial N.º **43.484** del **21/10/2026**, págs. 473.382–473.387, **vigente desde el 19/04/2027** [V1]. Régimen de medios digitales **actualmente en vigor**: **SNAT/2024/000102**, Gaceta Oficial N.º **43.032** del 19/12/2024 [V2]. **Medios de emisión (art. 4)**: (1.1) portal web y aplicativo móvil del SENIAT, (1.2) **imprenta digital** o proveedor tecnológico autorizado, (2) máquinas fiscales [V1]. La **estructura XML exacta** la fija el **Manual Técnico del art. 41** ⚠ **no localizado** (pendiente: medios electrónicos del SENIAT) [V1]. **El Manual Técnico es el equivalente del "Manual Técnico" del exploration y el documento que define el payload que se remite a la imprenta.** |
| 2 | **Identificación fiscal** | **RIF** (Registro Único de Información Fiscal) del emisor (art. 8.4) y del adquirente/receptor (art. 8.6) [V1]. **Se puede prescindir del RIF** cuando el adquirente es persona natural que no requiere factura a efectos tributarios → en ese caso, mínimo, **cédula de identidad o pasaporte** [V1, art. 8.6]. *Consecuencia de diseño*: el RIF del receptor **es opcional** en Venezuela, a diferencia de los otros cuatro países. |
| 3 | **Numeración** | **SON DOS NUMERACIONES, con autoridades distintas** [V1, V2]: (a) **numeración del documento**, propia del emisor, **consecutiva y única**, precedida de la palabra **"serie"** si el emisor no tiene sistema centralizado (art. 8.2 y art. 20) [V1]; (b) **Número de Control**, **asignado por la imprenta digital autorizada**, **consecutivo y único para cada emisor**, con **identificador de 2 dígitos + secuencial de hasta 8 dígitos** (`NN`+`SSSSSSSS`); la primera asignación empieza en `00` y `1` (art. 8.3, 9.1, 27; y art. 30 de [V2]). La factura debe expresar el **total de Números de Control asignados: «N° de Control… desde el N° X hasta el N° Y»** (art. 9.1) [V1]. **Rango autorizado: sí**, y lo asigna un tercero (la imprenta), no la autoridad directamente [V1, art. 42.1]. **Huecos: la numeración de control es consecutiva y única** [V1 art. 27] → no reutilizables. **Multipágina**: se emiten tantas páginas como haga falta **manteniendo el mismo número de documento y de control** (art. 9.5) [V1]. |
| 4 | **Impuestos** | **IVA**, con **base imponible discriminada por alícuota** (porcentaje) y **monto total del IVA también discriminado por alícuota** (art. 8.9 y 8.10) [V1]. El artículo 8.7 obliga a marcar **`(E)`** junto a la descripción o al precio cuando el bien o servicio sea **exento, exonerado o no gravado**. El parágrafo único obliga a la frase **"Contribuyentes Formales del IVA"** en exentos/exoneradas de contribuyentes formales. **La tasa de IVA NO aparece en el texto de la providencia**: remite al Decreto del IVA (arts. 54 y 57) y a su Reglamento (arts. 63–66) [V1] → el 16 % es el dato conocido pero **no confirmado por esta fuente**. **Comprobantes de retención** (art. 18): agentes de retención de **IVA** y de **ISLR**, con numeración propia de **14 caracteres `AAAAMMSSSSSSSS`**, período de imposición, base imponible, impuesto causado y monto retenido, y referencia a la factura o nota de débito [V1]. |
| 5 | **Firma y sello** | La providencia **NO fija un algoritmo de firma digital** (ni SHA-256 del XML ni nada): exige **integridad, autenticidad y trazabilidad** y **auditoría electrónica de toda acción** de emisión, modificación y anulación [V2 art. 18.2]. La **imprenta digital autorizada** debe **validar la estructura y autenticidad** de los documentos transmitidos [V1 art. 42.1; V2 art. 29.4] → el "sello" es la validación de la imprenta, no una firma criptográfica especificada. → **`hash_algorithm` y `signature_scheme` quedan parametrizados por perfil/canal, sin valor por defecto asumido.** |
| 6 | **Estado del documento** | **No hay un estado "cancelado"**: la corrección se hace por **documentos** (art. 12–13). Las **notas de débito y crédito** deben emitirse **por el mismo medio** que la factura que modifican, cumplir los requisitos de la factura ajustada salvo la denominación "Factura", y **hacer referencia obligatoria a la fecha, número correlativo, número de control y monto de la factura original**; el original y las copias llevan el enunciado "Nota de Débito" o "Nota de Crédito" [V1]. Los originales anulados **se conservan** a disposición del SENIAT mientras no se prescriba la obligación (art. 21) [V1]. |
| 7 | **Formato de QR** | **El QR NO aparece en el texto de SNAT/2026/00093 ni en el de SNAT/2024/000102** [V1, V2]. → **No es un requisito legal de la providencia**; el contrato de emisión **no debe exigir QR fiscal**. El campo queda **opcional y ligado al perfil/canal**. |
| 8 | **Transmisión** | **Cadena con actor intermedio obligatorio**: `Emisor (POS) → Imprenta Digital autorizada → SENIAT` [V1 art. 42.1; V2 art. 18.4 y 18.6]. **El emisor NO puede emitir por su cuenta** en el régimen de medios digitales: hay que **contratar una imprenta digital autorizada** y **remitirle la estructura** para su validación [V2 art. 18.4, 18.6]. **Alternativa**: el **portal web y la aplicativo móvil del SENIAT**, gratuitos y de uso general [V1 art. 4.1.1]. **Contingencia**: los formatos (talonarios) de imprentas autorizadas quedan reservados **exclusivamente como mecanismo de contingencia** ante 8 supuestos tasados (falla eléctrica, falla de internet, etc.) [V1 art. 6]. **Conciliación**: superada la contingencia, se registra la totalidad de los documentos físicos y se remiten **al día siguiente** en PDF a la imprenta o a la **API del SENIAT** [V1 art. 7]. **Portal fiscal**: `https://decaraciones.seniat.gob.ve` [V2, disposición final SEXTA; V4]. **API del SENIAT: `[NO VERIFICADO]`** (contrato técnico no localizado). **Registro automatizado mensual** (art. 44): la imprenta lleva un registro con RIF del emisor, fecha de asignación, tipo de documento, numeración de control asignada, numeración de cada formato e identificación de la factura, y lo remite al SENIAT **dentro de los 10 días continuos** siguientes al cierre de cada mes [V1 art. 44; V2 art. 29.7 y 32] → **equivalente del "Archivo de Consumo de Folios" chileno** [O9]. |

---

## 4. Denominador común vs. divergencias

### 4.1 Tabla comparativa (5 países)

| Eje | Chile (DTE) | México (CFDI 4.0) | Colombia (DIAN 2.1) | Panamá (FEPA) | Venezuela (SENIAT) |
|---|---|---|---|---|---|
| **Estándar** | DTE (SII) [O1,O3] | CFDI 4.0 (SAT) [O15,O16] | DIAN 2.1 sobre UBL 2.1 [O18,O19] | Ficha Técnica FE v1.10, Res. 201-5784 [O21] | SNAT/2026/00093 + SNAT/2024/000102 [V1,V2] |
| **Namespace / XSD** | `http://www.sii.cl/SiiDte`; 4 XSD `_v10` [S1] | `http://www.sat.gob.mx/cfd/4` → `cfdv40.xsd` [M2,M7,O15] | UBL 2.1 + extensión DIAN (URIs `[NO VERIFICADO]`) [C1] | XML firmado (namespace `⚠ no transcrito`) [O20,O22] | **Por definir** en el Manual Técnico art. 41 [V1] |
| **ID fiscal** | RUT `77777777-7` [S1] | RFC `XAXX010101000` [M2] | NIT [C1] | **RUC** [O20] | **RIF** (opcional si es persona natural → cédula/pasaporte) [V1 art. 8.6] |
| **Numeración** | tipo + folio sobre CAF [S1,S4] | `Serie`+`Folio` [M2,M4] | `Prefix`+`Number` sobre rango DIAN [C1] | secuencial + CUFE [O20] | **serie del emisor + Nº de Control de la imprenta** (2+8 dígitos) [V1 art. 8.3,9.1,27] |
| **Rango autorizado** | **sí** — el CAF [S4] | `[NO VERIFICADO]` | **sí** — resolución + intervalo + vigencia [C1] | `⚠ no transcrito` | **sí** — lo asigna la **imprenta digital** [V1 art. 42.1] |
| **Huecos** | **no reutilizables** [S4] | **no** (motivo `01` exige sustituto) [M5] | `[NO VERIFICADO]` | `⚠ no transcrito` | **no** — control consecutivo y único [V1 art. 27] |
| **Impuestos por línea** | no documentado | **sí** — `Concepto/Impuestos` [M1] | `[NO VERIFICADO]` (agrupa en `HeaderTaxes`) | `⚠ no transcrito` | no por línea; base y IVA **por alícuota** en cabecera [V1 art. 8.9-8.10] |
| **Impuestos por cabecera** | **sí** — `Totales/IVA` [S1] | **sí** — `Comprobante/Impuestos` [M1] | **sí** — `HeaderTaxes` [C1] | **sí** — ITBMS 7 % [O20] | **sí** — discriminados por alícuota [V1] |
| **Retenciones** | formato para **agentes retenedores** [O4] | **sí** — `Retenciones/Retencion` [M1,M4] | `[NO VERIFICADO]` | `⚠ no transcrito` | **sí** — comprobantes de retención IVA/ISLR, numeración `AAAAMMSSSSSSSS` [V1 art. 18] |
| **Múltiples impuestos** | no documentado | **sí** — N traslados + N retenciones [M1,M2] | `[NO VERIFICADO]` | `⚠ no transcrito` | **sí** — varias alícuotas de IVA [V1 art. 8.9-8.10] |
| **Gravado / exento** | por **tipo de documento** (33 vs 34) [S3] | por **concepto** (`ObjetoImp`) [M2] | `[NO VERIFICADO]` | `⚠ no transcrito` | por **alícuota + marca `(E)`** en la descripción [V1 art. 8.7] |
| **Firma del emisor** | `FRMT` + `FRMA` del CAF [S1] | `Sello` con CSD + `cadena_original` [M3] | XAdES-EPES con `.p12` DIAN [C1] | firma electrónica del XML (Ley 51) [O20] | **integridad/autenticidad/trazabilidad**; la imprenta valida estructura [V1,V2 art. 18.2, 29.4] |
| **Sello de autoridad / tercero** | SII (dentro del CAF) [S1] | **PAC** (`SelloSAT`) [M3] | DIAN (`.p12` + `SoftwareSecurityCode`) [C1] | firma electrónica conforme a Ley 51 [O20] | **imprenta digital** valida estructura y autenticidad [V1 art. 42.1] |
| **Referencia de verificación** | bloque `TED` (hash + CAF) [S1] | `UUID` + `SelloSAT` [M3] | `CUFE` (SHA-384) + `sts:SoftwareSecurityCode` [C1] | **CUFE** [O20] | por definir en el Manual Técnico [V1] |
| **Estados** | `E`/`A`/`R`/`X` [S5] | `Estado` + `EsCancelable` + `EstatusCancelacion` [M6] | `IsValid` + avisos + RADIAN [C1] | `⚠ no transcrito` | **por documento**: notas de crédito/débito [V1 art. 12] |
| **Anulación** | anular rango no usado [S6] | cancelar con acuse + sustituto [M5,M9] | evento RADIAN [C1] | **documento de anulación** [O20] | **nota de crédito/débito que referencia fecha, nº correlativo, nº de control y monto del original** [V1 art. 12] |
| **QR** | `[NO VERIFICADO]` (hay PDF417) [S6] | `[NO VERIFICADO]` | **sí, obligatorio** — `qr.URL(env, CUFE)` [C1] | `[NO VERIFICADO]` | **no aparece en la norma** [V1,V2] |
| **Transmisión** | HTTP multipart `/cgi_dte/UPL/DTEUpload` → `TrackId` [S2,S7] | vía **PAC** [M9] | SOAP 1.2+WS-Sec → `WcfDianCustomerServices` [C1] | **PAC o facturador gratuito DGI** [O20] | **emisor → imprenta digital → SENIAT**; o portal/app del SENIAT [V1,V2] |
| **Ambiente de pruebas** | **certificación** (`maullin`) + set básico [S7] | **test** del PAC [M9] | **habilitación** (`EnvironmentCode=2`) [C1] | **PAC + facturador gratuito DGI** [O20] | **portal/app SENIAT o imprenta digital** [V1] |
| **Registro mensual** | **Archivo de Consumo de Folios** [O9] | descarga masiva del SAT [M8,M10] | RADIAN + libros [C1] | `⚠ no transcrito` | **registro automatizado** de la imprenta (art. 44), remisión ≤ 10 días [V1,V2 art. 29.7] |

### 4.2 Denominador común (los 10 elementos que los 5 países comparten)

1. **Un XML con esquema versionado y namespace propio**, firmado electrónicamente.
2. **Un identificador fiscal de emisor y receptor**, con formato y catálogo propios (`RIF`/`RUT`/`RFC`/`NIT`/`RUC`).
3. **Una numeración correlativa y única** del documento, sobre un **rango autorizado** con vigencia (CAF, resolución DIAN, o rango asignado por la imprenta digital).
4. **Un bloque de impuestos** con base, tasa e importe, y la distinción gravado/exento/no gravado.
5. **Una firma o validación de integridad** del emisor, verificable contra la autoridad o un tercero autorizado.
6. **Un sello de la autoridad o de un tercero autorizado** que respalda la integridad (CAF del SII, `SelloSAT` del PAC, `.p12` de la DIAN, firma Ley 51, validación de la imprenta).
7. **Una referencia de verificación** que permite a un tercero validar el documento (hash+CAF / UUID+SelloSAT / CUFE+QR).
8. **Un estado de ciclo de vida** con, al menos, emitido, aceptado/rechazado y cancelado o corregido.
9. **Un par de ambientes** (certificación/habilitación/test ↔ producción) y un canal de transmisión propio por autoridad.
10. **Un mecanismo de corrección/anulación** y, en tres de los cinco, un **registro/reporte periódico** de consumo (Archivo de Folios [O9], registro automatizado [V1 art. 44], descarga masiva [M8]).

### 4.3 Divergencias que obligan a **diseño**, no a *if* por país

| # | Divergencia | Consecuencia de diseño |
|---|---|---|
| D1 | México anida impuestos **en línea y en cabecera**, con **traslados y retenciones** en el mismo nodo [M1, M2] | `fiscal_document_taxes` **normalizada y multinivel** (`line_id` nullable). Sin esto, México no cabe. |
| D2 | Gravado/exento se modela de **tres** maneras: por **tipo de documento** (CL 33/34) [S3], por **concepto** (MX `ObjetoImp`) [M2] y por **alícuota + marca `(E)`** (VE) [V1] | La categoría fiscal vive en **dos** sitios: `fiscal_profile_document_types.tax_treatment` **y** `tax_categories` por línea. Un solo campo no basta. |
| D3 | Vocabularios de estado incomparables: 4 códigos (CL) vs 3 campos ortogonales (MX) vs bool+eventos (CO) vs documentos de corrección (VE) vs documentos de anulación (PA) [S5, M6, C1, V1, O20] | `fiscal_documents.status` = enum **genérico**; el código nativo en `native_status_code`; el mapeo en `fiscal_status_vocabularies`; los **documentos de corrección** en `fiscal_document_links`. |
| D4 | La referencia de verificación tiene **cuatro** formas (hash+CAF / UUID+SelloSAT / CUFE+QR / por definir) [S1, M3, C1, O20, V1] | Par **`authority_reference` + `authority_reference_scheme`**; nunca una columna `uuid`. |
| D5 | Chile tiene **tres** roles de parte (emisor, receptor y **mandatario**) [S1, S5] | `fiscal_document_parties` con `role`, no columnas `issuer_*`/`receiver_*`. |
| D6 | El sello lo aporta la autoridad (CL, CO), un tercero pagador (MX) o el marco legal de firma electrónica (PA), y en Venezuela **una imprenta que valida estructura** [S1, M3, C1, O20, V1] | `fiscal_profiles.signer_model` = `authority` \| `psp` \| `printer` \| `legal_signature`; `fiscal_documents.provider` para el tercero. |
| D7 | El rango autorizado se autoriza por **emisor** (CL), por **emisor + software** (CO) o por **imprenta digital** (VE) [S4, C1, V1] | La unicidad de la serie debe incluir **el par de autorización**, no sólo la tienda. |
| D8 | La anulación es de **cuatro** naturalezas: anular rango (CL), cancelar con acuse+sustituto (MX), evento RADIAN (CO), documento de anulación (PA), y en VE un **documento de corrección que referencia el original** [S6, M5, C1, O20, V1] | El verbo "anular" **no** es una operación única cross-país → tabla genérica de links + eventos + acción por perfil. |
| D9 | Sólo Colombia tiene **QR obligatorio** verificado [C1]; en Venezuela el QR **no aparece en la norma** [V1, V2] | El QR es un **dato opcional por perfil**, no un supuesto global. |
| **D10** | **Venezuela tiene DOS numeraciones con autoridades distintas**: la del documento (emisor) y el **Nº de Control** (imprenta digital) [V1 art. 8.3, 9.1, 27; V2 art. 30] | **El `fiscal_series` del exploration modela una sola numeración** → hay que separar **dos series con dos reservas atómicas independientes**. Es el cambio de esquema más grande que aporta esta investigación. |
| **D11** | **Venezuela exige un actor intermedio obligatorio**: emisor → imprenta digital → SENIAT [V1 art. 42.1; V2 art. 18.4, 18.6] | La transmisión **no** es "emisor → autoridad": hay un **proveedor** en medio, que además asigna una numeración. → tabla `fiscal_providers`. |
| **D12** | **El RIF del receptor es opcional** en Venezuela cuando el adquirente es persona natural que no requiere factura a efectos tributarios → cédula o pasaporte [V1 art. 8.6] | `fiscal_document_parties.fiscal_id` **nullable**, con un `fiscal_id_kind` (`rif` \| `cedula` \| `pasaporte` \| `other`). |

---

## 5. Modelo de datos mínimo propuesto

> Principio: *«cambiar de país = insertar filas»*. Un país nuevo se añade con filas y seeds, **sin migración de esquema**.

### 5.1 `fiscal_documents` — la cabecera fiscal común (base)

```
id                       bigserial PK
fiscal_series_id         FK → fiscal_series.id            NOT NULL  -- numeración del documento
control_series_id        FK → fiscal_series.id            NULL      -- Nº de Control (sólo perfiles que lo exigen, p.ej. VE)
store_id                 FK → stores.id                   NOT NULL
document_type_id         FK → fiscal_profile_document_types.id  NOT NULL
series                   text        -- 'serie' del emisor (VE) o prefijo (CO) o tipo (CL)
number                   text        -- folio/correlativo
issued_number            text        -- número público completo (prefijo+serie+folio)
control_number           text        -- 'NNSSSSSSSS' (VE). Congelado en el documento.
control_number_from      text        -- "desde el N° X" (VE art. 9.1)
control_number_to        text        -- "hasta el N° Y" (VE art. 9.1)
issue_date               date        NOT NULL
issue_time               time
currency_code            char(3)
exchange_rate            numeric(25,8) NULL   -- VE art. 8.12: ambas cantidades + tipo de cambio
subtotal                 numeric(25,4) NOT NULL
discount_total           numeric(25,4) NOT NULL DEFAULT 0
tax_total                numeric(25,4) NOT NULL DEFAULT 0
total                    numeric(25,4) NOT NULL
rounding_adjustment      numeric(25,4) NOT NULL DEFAULT 0
status                   fiscal_status  NOT NULL DEFAULT 'draft'
native_status_code       text        -- código crudo de la autoridad
native_status_label      text
authority_reference      text        -- CUFE | UUID | hash TED
authority_reference_scheme text     -- 'CL.TED' | 'MX.UUID' | 'CO.CUFE' | 'PA.CUFE' | 'VE.PENDING'
qr_content               text        -- opcional por perfil
qr_url                   text
provider_id              FK → fiscal_providers.id  NULL   -- PAC / imprenta digital
provider_response        jsonb
xml_path                 text
pdf_path                 text
representation_path      text        -- HTML/PDF de la representación gráfica (con QR y marca (E))
created_by               FK → users.id
created_at               timestamptz NOT NULL
```

**Decisiones y su evidencia:**

| Decisión | Evidencia |
|---|---|
| `authority_reference` + `..._scheme` en vez de `uuid` | D4 [S1, M3, C1, O20]. |
| `status` genérico **y** `native_status_code` | D3 [S5, M6, C1]. |
| `qr_content`/`qr_url` **opcionales** | D9 [C1, V1]. |
| `control_series_id` + `control_number*` | **D10** — Venezuela [V1 art. 8.3, 9.1, 27]. |
| `provider_id` | **D11** — Venezuela [V1 art. 42.1; V2 art. 18.4]. |
| `exchange_rate` | VE art. 8.12: en moneda extranjera deben constar **ambas cantidades** + tipo de cambio [V1]. |
| `rounding_adjustment` | `exploration.md` §6.3.5 — sin él "los totales no cuadran" no es explicable. |
| **Sin** columnas de partido | D5 — Chile tiene tres roles [S1, S5]. |
| **Sin** columnas de impuesto | D1 — México exige N impuestos en dos niveles [M1, M2]. |

### 5.2 `fiscal_document_taxes` — impuestos multinivel (obligatorio)

```
id                   bigserial PK
fiscal_document_id   FK → fiscal_documents.id  ON DELETE CASCADE
line_id              FK → fiscal_document_lines.id  NULL   -- NULL ⇒ nivel cabecera
tax_scheme_code      text NOT NULL   -- 'IVA' | 'ISR' | 'ISLR' | 'IEPS' | 'MEPCO' | 'ITBMS' | 'ICA' | …
                                   -- DATO. El catálogo se inserta por país.
tax_category_code    text NOT NULL   -- 'gravado' | 'exento' | 'no_gravado' | 'exonerado' | 'retenido'
tax_type             text NOT NULL   -- 'transfer' | 'withholding'
base_amount          numeric(25,4) NOT NULL
rate                 numeric(25,6) NOT NULL   -- 0.160000 | 0.070000
tax_amount           numeric(25,4) NOT NULL
tax_rate_id          FK → tax_rates.id  NULL
```

| Caso | Representación |
|---|---|
| **México: IVA + ISR + IEPS** | Una fila por impuesto y por nivel. Cabecera = `line_id IS NULL`; concepto = `line_id` apuntando a la línea. IVA trasladado → `tax_type='transfer'`, `tax_scheme_code='IVA'`, `rate=0.160000`. ISR retenido → `tax_type='withholding'`. [M1, M2] |
| **México: retenciones** | `tax_type='withholding'`; el `Base` es la base de la retención [M1]. |
| **México: gravado vs exento** | `ObjetoImp` por concepto → `tax_category_code` de las filas de esa línea [M2]. |
| **Chile: factura afecta vs exenta** | `fiscal_profile_document_types.tax_treatment` para el tipo; el IVA de `Totales` es la fila `line_id IS NULL`. El tipo 34 (exenta) **no** genera fila de IVA [S1, S3]. |
| **Colombia: `HeaderTaxes`** | Fila `line_id IS NULL` por impuesto de cabecera [C1]. |
| **Panamá: ITBMS 7 %** | Fila `line_id IS NULL`, `tax_scheme_code='ITBMS'`, `rate=0.070000` [O20]. |
| **Venezuela: varias alícuotas de IVA** | **Una fila por alícuota** con su base y su importe [V1 art. 8.9-8.10]. El marcador `(E)` se deriva de `tax_category_code` en el render. |
| **Venezuela: comprobante de retención** | `tax_type='withholding'`, con **período de imposición** como campo propio del documento de retención (`fiscal_document_retentions` o columnas en la extensión VE), no del impuesto. |

### 5.3 `fiscal_document_parties` — las partes, con roles del país

```
id                   bigserial PK
fiscal_document_id   FK → fiscal_documents.id  ON DELETE CASCADE
role                 text NOT NULL  -- 'issuer' | 'receiver' | 'sender' | 'mandate'
fiscal_id            text NULL      -- RIF | RUT | RFC | NIT | RUC  (NULL si persona natural en VE)
fiscal_id_kind       text NOT NULL  -- 'rif' | 'rut' | 'rfc' | 'nit' | 'ruc' | 'cedula' | 'pasaporte' | 'other'
name                 text
tax_residence        text
attributes           jsonb NOT NULL DEFAULT '{}'
```

`attributes` recoge los campos exclusivos de cada país: Chile `giro_emis`, `acteco`, `cmna_origen` [S1]; México `regimen_fiscal`, `regimen_fiscal_receptor`, `uso_cfdi`, `domicilio_fiscal_receptor` [M2, M4]; Colombia `tax_scheme`/`company_id` [C1]. Cada clave se documenta en `fiscal_profile_fields`.

### 5.4 `fiscal_profiles` y tablas satélite

```
fiscal_profiles
  id, country_id FK, code UNIQUE(country_id, code), name,
  document_standard   text   -- 'sii_dte' | 'sat_cfdi_4_0' | 'dian_ubl_2_1' | 'dgi_fepa' | 'seniat_means'  (DATO)
  schema_version      text   -- '1.0' | '4.0' | '2.1' | '1.10' | NULL   (DATO — CL queda parametrizado [O3])
  schema_reference_url text  -- URL canónica del formato/Manual (p.ej. formato_dte_202602.pdf [O3]; Manual Técnico VE [V1])
  xml_namespace       text
  xsd_url             text
  id_scheme_code      text   -- 'CL.RUT' | 'MX.RFC' | 'CO.NIT' | 'PA.RUC' | 'VE.RIF'
  id_scheme_pattern   text   -- regex de validación
  numbering_scheme    text   -- 'tipo_folio_caf' | 'serie_folio' | 'prefijo_rango' | 'serie_emisor+control'
  requires_control_number boolean   -- TRUE para VE [V1 art. 8.3]
  authorized_range_required boolean
  holes_allowed       boolean NOT NULL DEFAULT true
  signer_model        text   -- 'authority' | 'psp' | 'printer' | 'legal_signature'   (D6)
  hash_algorithm      text NULL   -- SIN valor por defecto asumido [V1]
  signature_scheme    text NULL   -- SIN valor por defecto asumido [V1]
  status_vocabulary_id FK → fiscal_status_vocabularies.id
  rounding_rule       text   -- 'line' | 'total'  -- la providencia VE NO fija regla [V1]
  tax_inclusive_default boolean
  default_currency_id FK → currencies.id
  effective_from date, effective_to date NULL

-- ambientes = FILAS (certificación | producción | habilitación | test)
fiscal_profile_environments
  id, fiscal_profile_id FK, code ('CERT'|'PROD'|'HABILITACION'|'TEST'|'SII_PORTAL'), label,
  base_url text, auth_scheme text, is_testing boolean, is_production boolean

-- tipos de documento = FILAS (CL 33/34/39/41/43/46/52/56/61…; CO 01/91/92/05/95/20/25…)
fiscal_profile_document_types
  id, fiscal_profile_id FK, code text, name text,
  direction text        -- 'sale' | 'purchase' | 'credit_note' | 'debit_note' | 'pos_equivalent'
  tax_treatment text    -- 'taxable' | 'exempt'          (D2)
  requires_authorized_range boolean
  allows_cancellation boolean
  requires_parent_reference boolean   -- TRUE para notas VE [V1 art. 12]
  exemption_marker text   -- '(E)' para VE [V1 art. 8.7]; NULL en los demás
  UNIQUE(fiscal_profile_id, code)

-- esquemas de tax = FILAS
fiscal_tax_schemes
  id, fiscal_profile_id FK, code text, name text, is_withholding boolean,
  UNIQUE(fiscal_profile_id, code)

-- vocabulario de estados y mapeo al enum genérico
fiscal_status_vocabularies
  id, fiscal_profile_id FK, native_code text, native_label text,
  generic_status fiscal_status, is_final boolean, is_cancellation boolean,
  UNIQUE(fiscal_profile_id, native_code)

-- catálogos de las claves del jsonb
fiscal_profile_fields
  id, fiscal_profile_id FK, key text, data_type text, label text,
  required boolean, applies_to text ('document'|'line'|'party'),
  UNIQUE(fiscal_profile_id, key)
```

### 5.5 Tablas nuevas que aporta Venezuela (y que el exploration no tenía)

```
-- D11: el actor intermedio. En Venezuela es la imprenta digital; en México, el PAC.
fiscal_providers
  id, fiscal_profile_id FK, code text, name text,
  kind text          -- 'printer' (imprenta digital) | 'pac' | 'portal' | 'authority'
  base_url text, auth_scheme text, credentials_ref text,
  is_active boolean, UNIQUE(fiscal_profile_id, code)

-- D10: las DOS series. Una tabla, discriminadas, con reservas independientes.
fiscal_series
  id, store_id FK, fiscal_profile_id FK, document_type_id FK NULL,
  series_role text NOT NULL   -- 'document_number' | 'control_number' | 'retention_receipt'
  assigned_by text NOT NULL    -- 'issuer' | 'authority' | 'printer' | 'psp'
  assigned_by_provider_id FK → fiscal_providers.id NULL
  prefix text, next_number bigint NOT NULL,
  from_number bigint, to_number bigint,
  valid_from date, valid_to date,
  credential_ref text NULL,   -- referencia al CAF / resolución / N° de Control inicial y final
  status text NOT NULL, UNIQUE(store_id, fiscal_profile_id, document_type_id, series_role, assigned_by)

-- D8: notas de crédito/débito que referencian el original
fiscal_document_links
  id, fiscal_document_id FK, link_type text,   -- 'corrects' | 'cancels' | 'replaces' | 'radian_event'
  parent_document_id FK → fiscal_documents.id, parent_reference text,
  UNIQUE(fiscal_document_id, link_type, parent_document_id)

-- D8 + normativa: el registro/reporte periódico (Archivo de Folios [O9]; registro automatizado VE [V1 art. 44])
fiscal_periodic_reports
  id, fiscal_profile_id FK, store_id FK, provider_id FK NULL,
  report_type text,   -- 'consumo_folios' | 'registro_automatizado' | 'descarga_masiva'
  period_start date, period_end date,
  due_at date, status text, artifact_path text, submitted_at timestamptz
```

### 5.6 Lo que **NO** se propone (y por qué)

| Alternativa | Por qué se rechaza |
|---|---|
| Tabla `fiscal_documents_mx`/`_cl`/`_co`/`_pa`/`_ve` por país | Exige **migración de esquema** por país nuevo → viola "cambiar de país = insertar filas". |
| EAV puro (`fiscal_document_field_values`) | Sin tipos ni índices, joins caros. Se prefiere **jsonb + catálogo** (`fiscal_profile_fields`). |
| Columnas fijas para impuestos (`iva_amount`, `isr_amount`, `ieps_amount`) | Contradicho por México (conjunto abierto y anidado) [M1, M2] y por Venezuela (varias alícuotas) [V1]. |
| `is_cancelled` / `is_expired` como booleanos | El estado es multi-eje en México [M6] y documental en Venezuela [V1]. |
| **Una sola** numeración (como el `fiscal_series` original del exploration) | Contradicho por Venezuela: dos numeraciones con autoridades y ciclos de vida distintos [V1 art. 8.3, 27]. |
| Fijar `hash_algorithm` o `signature_scheme` por defecto | La providencia venezolana **no fija algoritmo** [V1]; Chile usa SHA1 en el ejemplo [S1] y Colombia SHA-256 [C1]. → dato por perfil/canal. |
| Fijar `schema_version = '2.2'` para Chile | **Inercia.** La URL canónica vigente es `formato_dte_202602.pdf` [O3]; la evidencia transcrita es v1.0 [S1, S8]. → dato. |

### 5.7 Semilla mínima propuesta (Fase 0)

| Perfil | Estado | Condición |
|---|---|---|
| `VE_MEDIOS_DIGITALES` | **sembrable** | Especificación **transcrita** (arts. 4–44 de SNAT/2026/00093 y SNAT/2024/000102) [V1, V2]. `schema_version` queda **NULL** hasta transcribir el Manual Técnico del art. 41. `hash_algorithm`/`signature_scheme` quedan **NULL**. La tasa de IVA queda pendiente de confirmar contra el Decreto del IVA. |
| `CL_DTE` | **sembrable** | `schema_version` = `'1.0'` (lo transcrito) y `schema_reference_url` = `formato_dte_202602.pdf` [O3]. Actualizar `schema_version` cuando se transcriba el PDF. |
| `MX_CFDI40` | **sembrable** | Namespace, XSD [O15], impuestos, estados, cancelación y transmisión verificados [M1–M10, O15–O17]. |
| `CO_DIAN21` | **sembrable** | Estructura, numeración, firma, hash, QR y transporte verificados [C1, O18, O19]. URIs de namespace pendientes de transcripción. |
| `PA_FE` | **sembrable en estructura, no en detalle** | El perfil, el estándar y los ejes de alto nivel están verificados [O20–O23]. **Los campos de detalle (namespace, XSD, formato del RUC, códigos de estado, estructura del CUFE) se siembran como NULL y se rellenan al transcribir la Ficha Técnica V1.00-Abril2025 [O22]** — no se inventan. |

### 5.8 Lo que debe ser **DATO** y no CÓDIGO en la cabecera fiscal

| Eje | Dónde vive como dato |
|---|---|
| **Numeración** | `fiscal_profiles.numbering_scheme` + `requires_control_number` + `fiscal_series` (`series_role`, `assigned_by`, `prefix`, `next_number`, `from_number`, `to_number`, `valid_from`, `valid_to`, `credential_ref`) + `fiscal_profile_document_types` |
| **Identificadores** | `fiscal_profiles.id_scheme_code` + `id_scheme_pattern` + `fiscal_document_parties.fiscal_id_kind` + `fiscal_profile_fields` para los códigos de catálogo (`RegimenFiscal`, `UsoCFDI`, `GiroEmis`, `Acteco`) |
| **Impuestos** | `fiscal_tax_schemes` + `tax_categories` + `tax_rates` (con vigencia) + `fiscal_document_taxes` (congelados en el documento) |
| **Estados** | `fiscal_status_vocabularies` (nativo → genérico, `is_final`, `is_cancellation`) + `fiscal_document_links` para las correcciones |
| **Referencia de verificación** | `fiscal_documents.authority_reference` + `authority_reference_scheme`; QR sólo si el perfil lo exige |
| **Firma y sello** | `fiscal_profiles.signer_model` + `hash_algorithm` + `signature_scheme` + `fiscal_providers` |
| **Transmisión** | `fiscal_profile_environments` (base_url, auth_scheme) + `fiscal_providers` (el actor intermedio) |
| **Ambiente de pruebas** | Filas en `fiscal_profile_environments`, no un booleano `is_sandbox` |
| **Exigencias de render** | `fiscal_profile_document_types.exemption_marker` (`(E)` en VE) y `fiscal_profile_fields` (frases obligatorias, p.ej. "Contribuyentes Formales del IVA") |
| **Reporte periódico** | `fiscal_periodic_reports` (`report_type`, `due_at`) — el equivalente del Archivo de Folios [O9] y del registro automatizado [V1 art. 44] |

---

## 6. Puntos abiertos

> **Distinción clave**: (A) *fuente oficial localizada pero no transcrita* ≠ (B) *fuente no localizada*. (A) se cierra con un lector de PDF/XSD; (B) requiere buscar el documento.

### 6.1 (A) Fuente oficial **localizada**, pendiente de transcripción

| # | Documento | URL | Qué sale de ahí | Impacto |
|---|---|---|---|---|
| A1 | **Formato DTE vigente 2026-02** | https://www.sii.cl/factura_electronica/factura_mercado/formato_dte_202602.pdf | esquema XML exacto, tipos de documento, tratamiento gravado/exento, bloque `TED` | fija `fiscal_profiles.schema_version` de CL y valida los tipos ya transcritos |
| A2 | **Instructivo sobre validaciones DTE** | https://www.sii.cl/servicios_online/docs/instructivo_sobre_validaciones_dte.pdf | reglas de validación formal y completitud | reglas del serializador/validador CL |
| A3 | **Formato DTE para agentes retenedores** | https://www.sii.cl/factura_electronica/factura_mercado/formato_retenedores.pdf | retenciones chilenas | `fiscal_tax_schemes` de CL |
| A4 | **Archivo de consumo de folios** | https://www.sii.cl/factura_electronica/factura_mercado/consumo_folios.pdf | contenido del reporte periódico | `fiscal_periodic_reports` |
| A5 | **XSD CFDI 4.0** | http://www.sat.gob.mx/sitio_internet/cfd/4/cfdv40.xsd | cardinalidades y tipos exactos | validación estricta del XML MX |
| A6 | **Documento referencial UBL 2.1 + extensiones DIAN** | https://www.dian.gov.co/normatividad/Paginas/Documentos.aspx | URIs de namespace y extensiones | `fiscal_profiles.xml_namespace` de CO |
| A7 | **Ficha Técnica FE v1.10 (Res. 201-5784)** | https://dgi.mef.gob.pa/_7FacturaElectronica/ftpiloto | estructura del comprobante PA | namespace, elementos, estados |
| A8 | **Ficha Técnica para PAC V1.00 — Abril 2025** | https://dgi.mef.gob.pa/_7FacturaElectronica/source/F-Técnica de Factura Electrónica para los Proveedores de Autorización Calificados V1.00-Abril2025.pdf | contrato técnico del PAC, firma, CUFE, anulación | completa el perfil `PA_FE` |
| A9 | **Manual Técnico del art. 41** (Venezuela) | medios electrónicos del SENIAT (no publicada como URL) | estructura XML a remitir a la imprenta | `fiscal_profiles.schema_version`/`xml_namespace` de VE y el payload del proveedor |
| A10 | **Gaceta Oficial N.º 43.484 (PDF)** | no alcanzada (SENIAT HTTP 403) | texto oficial de SNAT/2026/00093 | validar la transcripción de terceros [V1] |

### 6.2 (B) Fuente **no localizada**

| # | Punto | Dónde buscar |
|---|---|---|
| B1 | **API del SENIAT** para emitir y para remitir contingencias | `https://decaraciones.seniat.gob.ve` [V4] |
| B2 | Procedimiento de **autorización del emisor** ante la Intendencia Nacional de Tributos Internos (formulario y recaudos) | SENIAT [V2 art. 17, 20] |
| B3 | Listado de **imprentas digitales autorizadas** y qué API ofrecen | Portal fiscal SENIAT |
| B4 | **Tasa de IVA vigente** y su Reglamento (G.O. 5.363 de 1999, arts. 54 y 57 / Reglamento 63–66) | Gaceta Oficial [V1] |
| B5 | **Códigos `Impuesto`** de México (`001` ISR, `002` IVA, `003` IEPS) | catálogo oficial SAT [O16, O17] |
| B6 | **Tasa de IVA de Colombia** (19 %) y otros impuestos (ICA, ReteICA) | [O18, O19] |
| B7 | URIs de namespace **UBL 2.1** y de la **extensión DIAN** | [O19] |
| B8 | **ID fiscal de persona natural en Colombia** (¿RUT?) | [O18] |
| B9 | **URL del servicio de emisión CFDI 4.0 del SAT** | [O16] (no necesaria si la emisión va por PAC [M9]) |
| B10 | **QR** en Chile y México | pendiente de A1/A5 |
| B11 | **"Hecho generador" / débito fiscal** en Chile | pendiente de A1 |
| B12 | Comandos **ESC/POS** del fabricante (PDF Epson → HTTP 403) | alternativa: `escpos-php` como fuente práctica |

### 6.3 (C) Decisiones de **producto** (no de norma) — las deuda el usuario, no la evidencia

| # | Decisión | Estado |
|---|---|---|
| C1 | **Regla de redondeo** línea vs total | La providencia venezolana **no fija ninguna** [V1] → sigue siendo decisión de producto (P4 del exploration). |
| C2 | **Algoritmo de hash y esquema de firma** | Ninguna norma recuperada lo fija para VE [V1]; CL usa SHA1 en el ejemplo [S1] y CO SHA-256 [C1] → `hash_algorithm`/`signature_scheme` como dato por perfil/canal. |
| C3 | **Si el sistema puede ser *imprenta digital*** | Si el cliente opera una red y factura por cuenta de terceros, el propio sistema puede candidate a imprenta digital autorizada (personas jurídicas domiciliadas en el país) → cambia el rol de "emisor" a "emisor + imprenta", con obligaciones adicionales (registro automatizado mensual, revocación). **Decisión de negocio.** |
| C4 | **QR fiscal obligatorio** | No en VE [V1, V2]; obligatorio en CO [C1] → campo opcional por perfil. |
| C5 | Formato del correlativo: `000-00000000` (factura) y el de compra (P6 del exploration) | Ahora informado por el dato venezolano real: **serie del emisor + Nº de Control de 2+8 dígitos** [V1]. |

---

## 7. Veredicto, riesgos y plan de cierre

### 7.1 Veredicto

| Campo | Valor |
|---|---|
| **status** | `success` |
| **proposal_ready** | `true` |
| **Países con especificación oficial localizada y citable** | **5 / 5** (VE, CL, MX, CO, PA) |
| **Países con esquema/estructura transcrita** | 3 / 5 (CL parcial v1.0, MX, CO parcial) + VE (norma transcrita, esquema pendiente) |
| **Divergencias que fuerzan diseño** | 12 (D1–D12) |
| **Tablas nuevas que aporta esta investigación** | 5 (`fiscal_document_taxes`, `fiscal_document_parties`, `fiscal_providers`, `fiscal_document_links`, `fiscal_periodic_reports`) + discriminadores en `fiscal_series` |
| **Pendiente bloqueante de diseño** | **ninguno** — los pendientes son de transcripción e implementación |

**Por qué `success` y no `partial`**: el objetivo del carril era *producir evidencia externa verificable para diseñar la capa de país configurable*. Está cubierto para los cinco países: cada eje (1–8) tiene fuente, URL y fecha. Lo que falta es la **transcripción** de documentos ya localizados, que es una tarea de implementación, no una laguna de evidencia. Ningún punto de §6.1/§6.2 cambia la **forma** del modelo de datos propuesto.

### 7.2 Riesgos y plan

| # | Riesgo | Severidad | Plan |
|---|---|---|---|
| R1 | **Panamá sembrado con campos incorrectos** si se rellenan por suposición en vez de por la Ficha Técnica | **Alta** | Los campos de detalle de `PA_FE` se siembran **NULL** hasta transcribir [O22]. Regla: *nada de PA se afirma sin transcripción*. |
| R2 | **`schema_version` de CL fijado a "2.2"** por inercia en el código | **Media** | Queda como columna de `fiscal_profiles` con `schema_reference_url = formato_dte_202602.pdf` [O3]; test de arquitectura que prohíba literales de versión en el dominio. |
| R3 | La **doble numeración venezolana** no se implementa y se emite un correlativo único | **Alta** | `fiscal_series` con `series_role` + `assigned_by`; dos reservas atómicas independientes; test de invariante que compruebe que un documento tiene **exactamente** un `control_number` cuando el perfil lo exige. |
| R4 | El **actor intermedio** (imprenta digital / PAC) se omite en la arquitectura de transmisión | **Alta** | `fiscal_providers` con `kind`; el perfil declara `signer_model='printer'` en VE y `'psp'` en MX. |
| R5 | **Tasa de IVA de VE (16 %)** sembrada sin confirmar contra el Decreto del IVA | **Media** | Se marca como pendiente de confirmar (B4); `tax_rates` con `valid_from`/`valid_to` y `source_ref` para que el dato sea rastreable. |
| R6 | **Transcripción de 10 documentos oficiales** pendiente (A1–A10) | **Media** | Tarea de Fase 0/1 con lector de PDF y XSD; cada transcripción actualiza el artefacto con el `source_id` y la fecha. **No bloquea la propuesta**: bloquea la implementación del serializador de cada país. |
| R7 | `RIF` **opcional** en VE mal modelado como obligatorio | **Media** | `fiscal_id` nullable + `fiscal_id_kind` con `cedula`/`pasaporte` [V1 art. 8.6]. |
| R8 | **Complementos CFDI** (pago20, nómina) no caben en `fiscal_documents` | **Media** | Hueco de diseño declarado en §3.2; resolver como documento/extension hijo antes del serializador MX. |
| R9 | El **texto venezuelano** proviene de transcripciones de terceros, no del PDF de la Gaceta Oficial | **Media** | Marcar cada cita con la procedencia [V1, V2]; validar contra el PDF oficial cuando se alcance (A10). |

---

## 8. Glosario

| Término | Significado | País |
|---|---|---|
| **RIF** | Registro Único de Información Fiscal | VE |
| **Nº de Control** | Numeración **consecutiva y única por emisor**, asignada por la **imprenta digital**; `NN` (2 dígitos) + 8 dígitos de secuencial; primera asignación `00`+`1` | VE |
| **Imprenta digital** | Proveedor tecnológico autorizado que **valida la estructura y autenticidad** de los documentos y asigna el Nº de Control | VE |
| **Medios de emisión** | Portal/app SENIAT, **imprenta digital**, o máquina fiscal (art. 4) | VE |
| **Contingencia** | Uso de talonarios impresos por imprentas autorizadas, **reservado exclusivamente** a 8 supuestos tasados (art. 6) | VE |
| **Registro automatizado** | Registro mensual de la imprenta: RIF, fecha de asignación, tipo de documento, numeración de control y de formato, identificación de la factura (art. 44) | VE |
| **Comprobante de retención** | Documento del agente de retención de IVA/ISLR; numeración `AAAAMMSSSSSSSS` (14 caracteres) | VE |
| **`(E)`** | Marcador obligatorio junto a la descripción o al precio de bienes/servicios exentos, exonerados o no gravados | VE |
| **DTE** | Documento Tributario Electrónico | CL |
| **CAF** | Certificado de Autorización de Folios: autoriza un rango de folios por tipo de documento, con vigencia | CL |
| **Folio** | Número correlativo dentro del CAF | CL |
| **Timbre Electrónico** | Código PDF417 de la representación gráfica (no es un QR) | CL |
| **Archivo de Consumo de Folios** | Reporte periódico de consumo de folios | CL |
| **MEPCO** | Impuesto a combustibles | CL |
| **Factura afecta / factura exenta** | Tipos 33 y 34: gravado y exento se distinguen por **tipo de documento** | CL |
| **CFDI** | Comprobante Fiscal Digital por Internet | MX |
| **CSD / FIEL** | Certificado digital con clave privada del contribuyente; genera el `Sello` | MX |
| **PAC** | Proveedor Autorizado de Certificación; estampa el `TimbreFiscalDigital` | MX |
| **Timbre Fiscal Digital (TFD)** | Complemento con `UUID`, `SelloCFD`, `SelloSAT`, `NoCertificadoSAT` | MX |
| **cadena original** | Substring del XML que se firma; base de la verificación de integridad | MX |
| **`UsoCFDI`** | Catálogo que declara el uso fiscal del CFDI para el receptor | MX |
| **`ObjetoImp`** | Indica si el concepto es objeto de impuesto | MX |
| **UBL 2.1** | Estándar XML genérico de factura (OASIS) que Colombia adopta | CO |
| **DIAN 2.1** | Perfil colombiano sobre UBL 2.1 con extensiones propias | CO |
| **CUFE** | Código Único de Factura Electrónica: hash SHA-384 (CO) y referencia de verificación (PA) | CO, PA |
| **CUDE / CUDS / CUNE** | Variantes del hash para nota crédito/débito, documento soporte y nómina | CO |
| **RADIAN** | Sistema de eventos (acuse, reclamo, aceptación) que reemplaza la anulación directa | CO |
| **Documento equivalente electrónico** | Ticket de POS con validez fiscal en Colombia (tipos `20`, `25`) | CO |
| **XAdES-EPES** | Firma XML avanzada, C14N 1.0 y RSA-SHA256 | CO |
| **`WcfDianCustomerServices`** | Servicio SOAP de recepción de documentos de la DIAN | CO |
| **habilitación** | Ambiente de certificación/homologación de la DIAN (`EnvironmentCode=2`) | CO |
| **certificación** | Ambiente de pruebas del SII (servidor `maullin`) | CL |
| **TrackId** | Identificador devuelto por el SII al aceptar un envío de DTE | CL |
| **FEPA** | Plataforma de factura electrónica de la DGI de Panamá | PA |
| **RUC** | Registro Único de Contribuyentes | PA |
| **ITBMS** | Impuesto a la Transferencia de Bienes Corporales Muebles y la Prestación de Servicios (7 %) | PA |
| **Ley 51 de 22/07/2008** | Ley panameña sobre documentos y firmas electrónicas (marco de la FE) | PA |
| **Proveedor de Autorización Calificado (PAC)** | Entidad que autoriza la emisión de FE en Panamá | PA |
| **RUT** | Rol Único Tributario (`77777777-7`) | CL; persona natural en CO (pendiente B8) |
| **RFC** | Registro Federal de Contribuyentes (`XAXX010101000`) | MX |
| **NIT** | Número de Identificación Tributaria | CO |

---

## Anexo A — Trazabilidad requisito → evidencia (5 países)

| Requisito | CL | MX | CO | PA | VE |
|---|---|---|---|---|---|
| 1. Estándar y schema | S1, S2, O1–O3 | M2, M7, O15, O16 | C1, O18, O19 | O20, O21, O22 ⚠ | V1, V2, V3 |
| 2. Identificación fiscal | S1, S2 | M2, M4 | C1 | O20 ⚠ | V1 art. 8.4/8.6 |
| 3. Numeración | S1, S4, S6 | M2, M4, M5 | C1 | O20 ⚠ | V1 art. 8.2/8.3/9.1/27, V2 art. 30 |
| 4. Impuestos | S1, S3, S6, O4 | M1, M2, M4 | C1 | O20 ⚠ | V1 art. 8.7/8.9/8.10/18 |
| 5. Firma y sello | S1 | M3, O17 | C1 | O20, O22 ⚠ | V1 art. 42.1, V2 art. 18.2/29.4 |
| 6. Estado y anulación | S5, S6 | M5, M6, M9 | C1 | O20 ⚠ | V1 art. 12/13/21 |
| 7. QR | S6 (PDF417, no QR) | — | C1 (obligatorio) | — | V1, V2 (no aparece) |
| 8. Transmisión | S2, S7 | M8, M9, M10 | C1 | O20 | V1 art. 4/6/7/42/44, V2 art. 29 |
| Denominador común | §4.2 (10 elementos, 5 países) | | | | |
| Divergencias | §4.3 (D1–D12) | | | | |
| Modelo mínimo | §5 (base + 5 tablas nuevas + 5 satellite) | | | | |

**Leyenda**: `⚠` = fuente oficial localizada pero **no transcrita** (§6.1). Ausencia de marca = transcrito y citable.
