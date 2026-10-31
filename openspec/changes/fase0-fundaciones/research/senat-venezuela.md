<!-- artifact_id: gentle-ai.sdd-research/v1 -->
<!-- change: fase0-fundaciones · lane: senat-venezuela · revision: 2 · outcome: done -->
<!-- supersedes: revision 1 (2026-10-30, partial, solo clase documentation) -->
<!-- canonical_path: openspec/changes/fase0-fundaciones/research/senat-venezuela.md -->
<!-- store: hybrid · evidence: documentation (V*) + texto oficial de providencia (F*) -->

# Research: Fiscalidad venezolana (SENIAT)

> **Carril**: `fase0-fundaciones` · **Pregunta**: qué exige realmente la normativa venezolana de facturación electrónica, y qué implica eso para el contrato de `ElectronicInvoiceProvider` y para las decisiones **P3** (IVA retenido en el esquema) y **P4** (redondeo por línea con ajuste visible en el total).
>
> **Resultado**: `done` (fase: `success`). La revisión 1 quedó en `partial` porque la clase `open-web` está **denegada** para el agente `sdd-research` y no había ninguna fuente oficial. La revisión 2 incorpora el texto oficial de las dos providencias recuperado por el orquestador (`research/evidence/senat-venezuela.md`). **Los 9 temas tienen ahora respuesta**: 7 con artículo citado y 2 con **hallazgo negativo verificado** (QR y redondeo).
>
> **Impacto en el diseño**: el modelo de `exploration.md` §5 estaba equivocado en un punto estructural — **hay dos numeraciones, no una**. El correlativo fiscal se descompone en *numeración del documento* (del emisor) y *Número de Control* (de la imprenta digital). El flujo de emisión tiene un **actor intermedio obligatorio**. Y P3 pasa a estar **confirmado por fuente oficial** (art. 18 SNAT/2026/00093). P4 queda **confirmado como decisión de producto, no como requisito normativo**.

---

## 1. Resumen ejecutivo

1. **Dos providencias, no una.** Hoy rige **SNAT/2024/000102** (G.O. 43.032, 19/12/2024); el sustituto es **SNAT/2026/00093** (G.O. 43.484, 21/10/2026), **vigente desde el 19/04/2027**. El sistema debe **satisfacer ambos durante el solapamiento**.
2. **Hay DOS numeraciones.** La *numeración del documento* es del emisor, consecutiva y única, precedida de la palabra **"serie"** si no hay sistema centralizado (SNAT/2026/00093 art. 8.2 y 20). El **Número de Control** lo asigna la **imprenta digital**: consecutivo y único por emisor, `NN` + hasta 8 dígitos, primera asignación `00` + `1`, impreso como «N° de Control… desde el N° X hasta el N° Y» (art. 8.3, 9.1, 27; SNAT/2024/000102 art. 30). → **Dos series y dos reservas atómicas independientes.**
3. **Actor intermedio obligatorio.** El emisor **no puede emitir solo**: SNAT/2024/000102 art. 18.4 exige **contratar una imprenta digital autorizada** y el 18.6 **remitirle la estructura para su validación**; la imprenta valida estructura y autenticidad (SNAT/2026/00093 art. 42.1; SNAT/2024/000102 art. 29.4). Alternativa: **Portal Web y Aplicativo Móvil del SENIAT**, gratuitos (art. 4.1.1).
4. **P3 (IVA retenido) CONFIRMADO por fuente oficial.** El art. 18 de SNAT/2026/00093 obliga a los agentes de retención del IVA y del ISLR a emitir **comprobantes propios**, numeración de **14 caracteres** `AAAAMMSSSSSSSS`, con nº de control, nº de factura, período de imposición, base imponible, impuesto causado y monto retenido. El IVA retenido es **documento fiscal de primera clase**.
5. **P4 (redondeo) CONFIRMADO como decisión de producto.** La providencia **no menciona el redondeo** → no es requisito normativo. `fiscal_profiles.rounding_rule` + `roundingAdjustment` se conservan **declarados como decisión de producto**.
6. **Hallazgos negativos verificados:** el **QR no aparece** en ninguna de las dos providencias → no es requisito legal; **no se fija algoritmo de firma** (exige integridad, autenticidad y trazabilidad, art. 18.2) → `hash_algorithm` y `signature_scheme` parametrizados sin valor por defecto; y **no se fija la tasa de IVA** (remite al Decreto del IVA, arts. 54 y 57; Reglamento arts. 63–66) → el 16 % se siembra como pendiente de confirmar.
7. **Obligaciones de plataforma nuevas** que el legacy no tiene: **auditoría electrónica append-only** de toda acción (art. 18.2); **acceso al SENIAT durante 10 años** (art. 18.7); **remisión mensual dentro de los 10 días** (art. 29.7 y 44); **conciliación del papel de contingencia al día siguiente** (art. 7); **numeración propia de los comprobantes de retención** (art. 18.1); **multipágina conserva el mismo número de documento y de control** (art. 9.5); **las notas referencian fecha, nº correlativo, nº de control y monto del original** (art. 12).
8. **Papel = sólo contingencia.** Los talonarios quedan reservados **única y exclusivamente** a contingencia (art. 6, 8 causales), el emisor debe **mantenerlos en el local** (art. 6 parágrafo único) y su numeración va precedida de la palabra **"contingencia"** (SNAT/2024/000102 art. 16.3). **No hay dos documentos fiscales paralelos**: hay un documento digital y un mecanismo de contingencia conciliado.

---

## 2. Metodología y fuentes consultadas

### 2.1 Admisión de evidencia

| Tramo | Clase | Mecanismo | Estado | Fuentes |
|---|---|---|---|---|
| **A — Normativo** | `official` | Texto íntegro de las dos providencias, recuperado por el orquestador en `research/evidence/senat-venezuela.md` (el agente `sdd-research` no tiene `open-web`: `webfetch`/`websearch`/`bash` = `deny` en `opencode.json` → `agent.sdd-research.permission`) | **Admitida** | F1–F5 |
| **B — Terceros** | `documentation` | Context7 MCP (`resolve-library-id` + `query-docs`) | **Admitida**, con cobertura VE casi nula | V1–V14 |
| **C — open-web** | `open-web` | `webfetch` / `websearch` / `bash` | **DENEGADA** | — |
| **D — interno** | — | `exploration.md`, `mapa-funcional.md` | No es evidencia externa | — |

**Precedente de la revisión 1 (auditable).** La revisión 1 declaraba `documentation=[]; open-web=[]` en el prompt de lanzamiento y admitió la clase `documentation` de facto por Context7, con la misma asignación documentada por el carril hermano `research/estandares-latam.md` bajo configuración idéntica. Esa discrepancia quedó declarada en el §2.1 de la revisión 1 y **queda resuelta** por el tramo normativo A.

**Advertencia de procedencia (riesgo R1).** Las providencias F1 y F2 se leen aquí **en transcripción de tercero** (`adacontable.com`, `finanzasdigital.com`); el PDF original de la Gaceta Oficial N.º 43.484 no fue alcanzado y `www.seniat.gob.ve` devuelve HTTP 403. El **texto de los artículos** es citable y se cita; la **versión oficial definitiva** queda pendiente (§4).

### 2.2 Fuentes normativas (tramo A)

Consultadas el **2026-10-30**. F1 y F2 son **texto oficial en transcripción de tercero**; F3 y F4 son canales oficiales del SENIAT; F5 es análisis legal de tercero.

| ID | Documento | Publicación | URL |
|---|---|---|---|
| **F1** | Providencia Administrativa **SNAT/2026/00093** | G.O. N.º **43.484**, 21/10/2026, págs. 473.382–473.387; **vigente desde 19/04/2027** | https://adacontable.com/seniat/providencia-snat-2026-00093-gaceta-oficial-43484/ |
| **F2** | Providencia Administrativa **SNAT/2024/000102** | G.O. N.º **43.032**, 19/12/2024; medios digitales en vigor | https://finanzasdigital.com/gaceta-oficial-n-43-032-medios-digitales-emision-facturas/ |
| F3 | SENIAT en línea | canal oficial de noticias (no alcanzado en detalle) | https://seniatenlinea.seniat.gob.ve/ |
| F4 | Portal fiscal SENIAT | DF Sexta de F2 | https://decaraciones.seniat.gob.ve |
| F5 | Marambio HLB — análisis legal de SNAT/2026/00093 | contraste independiente | https://www.marambio-hlb.com/seniat-dicta-la-providencia-snat-2026-00093-sobre-emision-de-facturas-y-documentos-fiscales-gaceta-oficial-n-43-484/?lang=es |

Artículos usados: F1 → 4–21, 27, 42, 44 · F2 → 7, 11, 16–21, 29–34, DT Primera, DF Sexta.

### 2.3 Fuentes de terceros (tramo B) — sin cambio respecto a la revisión 1

V1–V14 conservan sus excerpts (ver §2.2 de la rev. 1). Aporte vigente: **V1/V3/V4** validan el RIF · **V2** la autolimitación de `l10n_ve` · **V6** el hueco de serie (`made_sequence_gap`) · **V8** el patrón de impuesto retenido · **V9** Venezuela ausente de la e-invoicing de Odoo 19 · **V10–V12** el redondeo como dato configurable. **Ninguna contradice el tramo A**; V2, V9 y V10–V12 pasan a ser **contexto**, no evidencia normativa.

### 2.4 Freshness

- F2 está **en vigor**; F1 entra en vigor el **19/04/2027**. El diseño debe cubrir ambos regímenes (reglas de contenido casi idénticas; F1 añade notas de crédito/débito, comprobantes de retención y numeración del documento como "serie").
- **Discrepancia de transcripción detectada:** el art. 11 de F2 transcribe la numeración de comprobantes de retención como `AAAAMMSSSSS555`, mientras el art. 18 de F1 dice `AAAAMMSSSSSSSS`. Se **adopta la de 2026** por ser posterior; queda marcada como pendiente de confirmar contra el PDF (riesgo R1).
- V1–V14 consultadas el 2026-10-30. La relectura de la revisión 2 mantiene el mismo corte de frescura para las fuentes de terceros.

---

## 3. Hallazgos por tema

> Convención: `[F#]` = texto oficial de providencia, con artículo citado · `[V#]` = fuente de tercero · `[INTERNO]` = documento del proyecto · `[INFERENCIA]` = deducción marcada.

### T1. Comprobantes digitales — **RESUELTO** (art. 4–9, 19, 42)

- **Qué es y por qué medios**: los sujetos pasivos deben emitir facturas y documentos fiscales por **medios digitales o electrónicos** (Portal Web y Aplicativo Móvil del SENIAT; **imprenta digital** u otro proveedor tecnológico autorizado) o por **máquinas fiscales** (SNAT/2026/00093 art. 4). La **adopción del medio es de libre elección** (art. 5).
- **Componentes obligatorios** (art. 8): denominación **"Factura"**; **numeración consecutiva y única**; **Número de Control** único por emisor; razón social, domicilio fiscal y **RIF del emisor** (8.4); **fecha `DDMMAAAA` y hora `HH.MM.SS` con a.m/p.m** (8.5); razón social y **RIF del adquirente**, o cédula/pasaporte si es persona natural sin factura tributaria (8.6); descripción con cantidad y monto y **marcador `(E)`** en exento/exonerado/no gravado (8.7); conceptos y descuentos (8.8); **base imponible discriminada por alícuota** (8.9); **IVA discriminado por alícuota** (8.10); **valor total** (8.11); en moneda extranjera, **ambas cantidades y el tipo de cambio** (8.12). Parágrafo único: **"Contribuyentes Formales del IVA"** en exentas o exoneradas de contribuyentes formales.
- **Requisitos adicionales de medios digitales** (art. 9): el rango total de control como **«N° de Control… desde el N° … hasta el N° …»** con identificador de 2 dígitos + secuencial de hasta 8 (9.1); **código de la operación** (9.2); **datos de la imprenta** (razón social, RIF, nomenclatura y fecha de la providencia de autorización) (9.3); **fecha de asignación** del número de control (9.4); **multipágina conserva el mismo número de documento y de control** (9.5).
- **Verificabilidad por el consumidor**: la **imprenta valida la estructura y la autenticidad** de los documentos transmitidos (art. 42.1) y garantiza acceso al SENIAT 365 días/año (art. 42.2); el emisor debe **facilitar la consulta del usuario final** por su página web o a través de la imprenta (SNAT/2024/000102 art. 18.10) y **remitir el comprobante digital** a correo o ponerlo a disposición (art. 18.9). Todo documento debe indicar que se emite **conforme a la providencia** (art. 19).
- **QR**: **no figura en el texto de F1 ni de F2** → no es requisito legal de la providencia (véase T5).
- **Tamaño de letra**: datos de la imprenta **≥ 6 pt**; datos del emisor y número de control **≥ 8 pt** (arts. 10, 11 y 42.4).

### T2. Numeración de control — **RESUELTO, Y CORRIGE EL DISEÑO** (art. 8.2, 8.3, 9.1, 20, 27, 30, 34)

- **Son dos numeraciones, con autoridades distintas**:
  | Concepto | Autoridad | Regla | Artículo |
  |---|---|---|---|
  | **Numeración del documento** | el emisor | Consecutiva y única; **precedida de la palabra "serie"** si el emisor no tiene sistema centralizado | F1 art. 8.2, art. 20 |
  | **Número de Control** | la **imprenta digital** | Consecutivo y único por emisor; `NN` (2 dígitos) + secuencial de hasta 8; primera asignación **`00` + `1`**; se imprime «N° de Control… desde el N° X hasta el N° Y» | F1 art. 8.3, 9.1, 27; F2 art. 30 |
- **El número de control lo asigna la imprenta**, no el emisor: la imprenta "debe asignar el número de control a cada documento" (F2 art. 29; F1 art. 42.1) y debe **solicitar al emisor el comprobante digital del RIF** (F2 art. 29.2; F1 art. 42.2).
- **El hueco no se reutiliza**: la numeración es *consecutiva y única* (F1 art. 8.2, 27; F2 art. 30) y la **revocación** de la imprenta procede cuando "se elaboren dos o más números de control distintos para una misma factura" (F2 art. 34) → **el número consumido no se libera**.
- **Anulación**: los originales de documentos anulados **deben conservarse** por el emisor a disposición del SENIAT (F1 art. 21). La providencia **no** ordena liberar el correlativo.
- **Emisor sin sistema centralizado** (multi-sucursal o multi-área): debe emitir con numeración consecutiva y única **precedida de la palabra "serie"** (F1 art. 20). → **Nuestro POS, al servir varias tiendas, cae en este supuesto**: el prefijo "serie" no es opcional.

### T3. Formato del XML / estructura del comprobante — **PARCIAL** (contenido sí, formato técnico no)

- **Lo que la providencia fija son requisitos de CONTENIDO**, no un esquema XML: no hay namespace, ni XSD, ni versión de formato en F1 ni en F2.
- **El formato técnico** (la estructura que el emisor remite a la imprenta para validación, art. 18.6 de F2 y art. 42.1 de F1) está en el **Manual Técnico del art. 41**, **no alcanzado** (§4).
- **Campos del enunciado, resueltos por artículo**: RIF emisor (F1 art. 8.4) · RIF receptor, o cédula/pasaporte (8.6) · Número de Control (8.3, 9.1) · fecha/hora `DDMMAAAA` / `HH.MM.SS` a.m-p.m (8.5) · base imponible por alícuota (8.9) · IVA por alícuota (8.10) · IVA retenido (art. 18, comprobantes de retención) · total (8.11) · estado (no hay enum normativo; sí hay conservación de anulados, art. 21, y auditoría de anulación, art. 18.2).
- **Formas de pago: no aparecen** en el texto de F1 ni de F2 → no es un campo normado por la providencia.
- **Código de la operación** (F1 art. 9.2, y F2 art. 7): debe relacionarse con la codificación del inventario de bienes o con la prestación de servicio → **nuestro `product_stores` necesita un código de operación**, no sólo nombre.

### T4. Firma digital — **HALLAZGO NEGATIVO VERIFICADO** (F2 art. 18.2, F1 art. 42.1)

- La providencia **no fija ningún algoritmo de firma digital** (ni SHA-256 del XML, ni XML-DSig, ni XAdES). No lo hace F1 ni F2.
- Lo que sí exige: el sistema del emisor debe garantizar la **integridad, la autenticidad y la trazabilidad** de los datos registrados, con **auditoría electrónica de toda acción** de emisión, modificación o anulación (F2 art. 18.2), y la **imprenta digital debe validar la estructura y la autenticidad** de los documentos transmitidos (F1 art. 42.1; F2 art. 29.4).
- **Consecuencia de diseño**: `hash_algorithm` y `signature_scheme` quedan **parametrizados por perfil/canal, sin valor por defecto asumido** (esto **confirma** lo que la revisión 1 ya dejó como pendiente) y el hash es **práctica de las imprentas**, no texto normativo.

### T5. QR — **HALLAZGO NEGATIVO VERIFICADO**

- El **QR no aparece** en el texto de SNAT/2026/00093 **ni** en el de SNAT/2024/000102. No es un requisito legal de la providencia. Puede existir por los canales del SENIAT, pero eso **no está en la norma** y no se afirma.
- **Consecuencia de diseño**: el contrato `issue()` **no debe exigir QR fiscal**. `qr_content` / `qr_url` quedan **opcionales**, ligados al **perfil/canal**, no a la norma. La revisión 1 ya los tenía como opcionales; ahora la razón es más fuerte: **ausencia verificada en la norma**, no "no verificado".

### T6. Anulación — **RESUELTO** (F1 art. 12, 21; F2 art. 18.2, 34)

- Las **notas de débito y de crédito** deben emitirse **por el mismo medio** que la factura que las origina, cumplir los requisitos de la factura ajustada **salvo la denominación "factura"** (F1 art. 13), y llevar el enunciado literal **"Nota de Débito"** o **"Nota de Crédito"** (art. 12).
- Una nota **referencia obligatoriamente** la **fecha, el número correlativo, el número de control y el monto** de la factura original (F1 art. 12).
- Los **originales anulados se conservan** a disposición del SENIAT (F1 art. 21).
- Toda anulación queda en la **auditoría electrónica** de acciones (F2 art. 18.2).
- **Efecto sobre el correlativo**: ninguno favorable — la numeración es consecutiva y única (F1 art. 8.2, 27; F2 art. 30) y reutilizarla es causal de revocación (F2 art. 34). → **el número no se libera; el documento queda `void` y el hueco queda consumado.**

### T7. Impuestos: IVA, categorías e IVA retenido — **RESUELTO**, con la tasa y las alícuotas pendientes (F1 art. 8.7, 8.9, 8.10, 18)

- **Categorías**: la providencia reconoce **exento, exonerado y no gravado** con el IVA (art. 8.7), exige el **marcador `(E)`** junto a la descripción o al precio (art. 8.7) y la frase **"Contribuyentes Formales del IVA"** para exentos/exonerados de contribuyentes formales (art. 8 parágrafo único). → **Las cuatro categorías del modelo (`iva_gravable`, `iva_exento`, `iva_no_gravado`, `iva_exonerado`) existen en la norma.** La distinción de **`exploration.md` §4.3 queda respaldada**.
- **Base imponible y IVA discriminados por alícuota** (art. 8.9, 8.10) → el documento fiscal **debe** llevar el desglose por alícuota, no sólo el total.
- **Tasa de IVA**: **no aparece** en el texto de la providencia; remite al **Decreto del IVA** (arts. 54 y 57) y a su **Reglamento** (arts. 63–66). El 16 % es el dato vigente conocido pero **no está confirmado por esta fuente** → se siembra **pendiente de confirmar**.
- **IVA retenido — P3 CONFIRMADO**: los **agentes de retención del IVA y del ISLR** deben emitir **comprobantes** propios (F1 art. 18), con: numeración consecutiva de **14 caracteres `AAAAMMSSSSSSSS`** (AAAA+MM+8 dígitos, el secuencial se reinicia al superar 8 dígitos) (art. 18.1); **nº de control** de la factura o nota de débito (art. 18.5); **nº de la factura o nota de débito** (art. 18.6); **período de imposición** (art. 18.7); **monto total, base imponible, impuesto causado y monto del impuesto retenido** (art. 18.8); y **datos de la imprenta digital** cuando aplique (art. 18.9).
  → **Implicación que la revisión 1 no anticipó**: el IVA retenido no es sólo `is_withholding = true` sobre una línea — **es un tipo de documento fiscal con numeración propia, período de imposición y referencia a la factura de origen**. El esquema necesita un `document_type` nuevo y una serie de 14 caracteres.

### T8. Redondeo — **HALLAZGO NEGATIVO VERIFICADO**

- La providencia **no menciona el redondeo**, ni por línea ni por total. No hay regla de redondeo que extraer.
- **Consecuencia de diseño**: **P4 es una decisión de producto**, y ahora está **confirmada como tal** — la norma no obliga en ningún nivel. Se mantiene `fiscal_profiles.rounding_rule` ∈ {`line`,`total`} + `tax_rates.rounding_rule` + el campo `roundingAdjustment`, **declarados explícitamente como decisión de diseño**, no como requisito normativo. Si el Manual Técnico o el Decreto del IVA fijan una regla, se añade como dato.
- La forma que toma la industria (redondeo por línea o por impuesto configurable, diferencia visible como línea) sigue siendo sólo **referencia de analogía** del tramo B, ahora sin ninguna regla que la contradiga.

### T9. Factura física y comprobante digital — **RESUELTO, Y CORRIGE EL DISEÑO** (F1 art. 4, 5, 6, 7; F2 art. 16)

- **El papel no es un documento paralelo**: los formatos (talonarios) de imprentas autorizadas quedan reservados **única y exclusivamente como mecanismo de contingencia** (F1 art. 6), ante 8 causales taxadas: falla prolongada de energía, interrupción severa de internet, inoperancia o avería técnica comprobada del medio de facturación o de la máquina fiscal, mantenimiento crítico no programado del sistema digital central, imposibilidad de emitir notas y órdenes de entrega o guías de despacho, **operaciones por cuenta de terceros**, **operaciones de exportación**, y **emisión de más de una copia** por exigencia legal o por solicitud de órganos públicos.
- **Obligación operativa**: todos los sujetos pasivos deben **mantener de forma permanente, en el establecimiento, los formatos de contingencia debidamente autorizados** (art. 6, parágrafo único). Es una obligación del contribuyente en el local, no del software — pero el sistema debe **registrar los talones** que el emisor solicita a la imprenta indicando el **nº de control inicial y final y el nº de documento inicial y final** (art. 27).
- **Conciliación**: al cesar la contingencia, el contribuyente registra la totalidad de los documentos físicos y **remite al día siguiente** la información en **PDF** a la imprenta digital o a la **API del SENIAT** (F1 art. 7). Bajo F2 art. 16, además debe **notificar por escrito** a la Gerencia Regional de Tributos Internos y a la imprenta digital, con relación detallada de los comprobantes físicos.
- **Numeración de contingencia**: el comprobante en formato talonario se emite con el número de documento **precedido de la palabra "contingencia"**, seguida de caracteres que lo identifiquen (F2 art. 16.3).
- **Conclusión**: no hay dos documentos fiscales equivalentes en paralelo, sino **un documento digital** y **un mecanismo de contingencia** conciliado (ver §1.8).

---

## 4. Puntos abiertos / no verificados

### 4.1 Pendientes de fuente oficial (los seis que lista el fichero de evidencia)

| # | Punto | Severidad | Plan de cierre |
|---|---|---|---|
| **O1** | **Manual Técnico** del art. 41 — formato técnico de la estructura que el emisor remite a la imprenta (F2 art. 18.6). **Sin él no se puede implementar el adaptador** | **Alta** (bloquea Fase 1/2, **no** Fase 0) | Medios electrónicos del SENIAT; pedirlo al cliente o al PSCE antes de la Fase 1 |
| **O2** | **PDF de la Gaceta Oficial 43.484** — el texto es transcripción de tercero; aclararía la discrepancia `AAAAMMSSSSS555` (F2 art. 11) vs `AAAAMMSSSSSSSS` (F1 art. 18) | **Media** | Gaceta Oficial / SENIAT: descargar el PDF y contrastar los 20 artículos citados |
| **O3** | **Tasa de IVA vigente** y su Reglamento (G.O. 5.363, 1999) — el 16 % no está en la providencia (arts. 54 y 57; Reglamento arts. 63–66) | **Media** (dato de seed) | Gaceta Oficial: sembrar el 16 % con `evidence_status='pending_confirmation'` y la referencia al Decreto del IVA |
| **O4** | **Portal / API del SENIAT** — contrato técnico de integración y remisión de contingencias (F1 art. 7; DF Sexta de F2) | **Media** (Fase 1/2) | https://decaraciones.seniat.gob.ve: especificación de la API. Fase 0 no la consume |
| **O5** | **Procedimiento de autorización** ante la Intendencia Nacional de Tributos Internos — requisitos, formulario y plazo de **30 días hábiles** (F2 art. 17, 20); causales de revocación del emisor (art. 21) | **Media** (go-live, no software) | SENIAT: checklist de onboarding; el software sólo debe poder **exportar el paquete de autorización** |
| **O6** | **Listado de imprentas digitales autorizadas** — cuáles existen, qué API ofrecen, y si el sistema debe ser una de ellas (F1 art. 39) | **Media** (decisión de proveedor) | Portal fiscal SENIAT: dependencia del cliente. **Decide el actor del §5.2** |

### 4.2 Lo que la providencia **no** resuelve (y hay que modelar como decisión, no como norma)

| Punto | Situación | Decisión |
|---|---|---|
| **Tasa y alícuotas concretas** | La providencia remite al Decreto del IVA | Seed con `evidence_status`; `tax_rates` con vigencia |
| **Estructura XML/JSON** | No hay esquema; el formato técnico está en el Manual Técnico (O1) | `xml_namespace` / `.xsd_url` quedan **NULL** hasta O1 |
| **Algoritmo de hash y firma** | No hay algoritmo; sólo integridad/autenticidad/trazabilidad | `hash_algorithm` / `signature_scheme` **parametrizados, sin default** |
| **QR** | No aparece | **Opcional por canal**, nunca exigido por la norma |
| **Formas de pago** | No aparecen | `payment_methods` es catálogo propio, no campo normado |
| **Estado del documento** | No hay enum normativo | Enum genérico + `native_status_code` (carril hermano) |
| **Redondeo** | No se menciona | **Decisión de producto** (P4) |

### 4.3 Afirmaciones internas que la evidencia **sí** corrige

| # | Afirmación interna | Veredicto oficial |
|---|---|---|
| A2 | *"Correlativo consecutive por tienda, sin saltos (`000-00000000`), con rango autorizado"* | **Parcialmente corregida**: consecutividad y unicidad **son norma** (F1 art. 8.2, 27; F2 art. 30) y el prefijo **"serie"** es **obligatorio** sin sistema centralizado (art. 20). El formato `000-00000000` **no aparece** → sigue configurable, y ahora hay **dos** series |
| A3 | *"IVA 16% (gravable), exento / no gravado / exonerado"* | **Las 4 categorías son norma** (F1 art. 8.7, 8.9, 8.10); **la tasa no** (remite al Decreto del IVA) |
| A1 | *"Comprobante digital (XML + PDF + QR + hash) ante SENIAT"* | **Corrección fuerte**: el QR **no está** en la norma; el hash **tampoco**; la norma exige **integridad, autenticidad y trazabilidad** (F2 art. 18.2) y la validación por la imprenta (F1 art. 42.1). El **contenido** sí está exhaustivamente normado (art. 8) |
| A4 | *"Identificación del emisor: RIF + `vat_reg_no`"* | **Confirmada**: RIF del emisor (F1 art. 8.4) y del adquirente, con cédula/pasaporte como alternativa (art. 8.6) |
| A6 | *"Impuesto retenido: IVA retenido"* | **Confirmada y ampliada**: el retenido exige un **documento propio** (F1 art. 18) |
| A8 | *"En Venezuela el correlativo no retrocede"* | **Confirmada**: numeración consecutiva y única; reutilizar es causal de revocación (F2 art. 34) |
| A10 | *"Régimen fiscal: Ordinario, simplificado"* | **No verificada**: no aparecen en estas providencias. Queda como dato de `fiscal_profiles` a confirmar por otra fuente |
| **A9** | *"Las compras también consumen correlativo fiscal"* | **No resuelta**: las providencias regulan documentos fiscales en general (art. 4) pero no distinguen compra de venta en la numeración. Sigue siendo pregunta de producto |

### 4.4 Contradicciones detectadas

- **C1** — La revisión 1 modelaba **una sola** numeración (`fiscal_series`): son **dos**, con autoridades distintas (F1 art. 8.2/8.3/9.1/20/27; F2 art. 30) → §5.1.
- **C2** — La revisión 1 modelaba un emisor que emite solo: hay **actor intermedio obligatorio** (F2 art. 18.4, 18.6; F1 art. 42.1) → §5.2.
- **C3** — La revisión 1 confirmaba el formato `000-00000000` como "factual": no hay formato normado; hay consecutividad + unicidad + prefijo "serie" → §5.1.
- **C4** — La revisión 1 trataba el QR como "pendiente": el QR **no está** en la norma → verificado como ausente → §5.1.
- **C5** — F2 art. 11 transcribe `AAAAMMSSSSS555`; F1 art. 18 dice `AAAAMMSSSSSSSS`: se adopta la de 2026 (posterior), pendiente de PDF (O2).
- **C6** — V2 (`l10n_ve`) usaba grupos IVA 0/8/12/22 %: **descartada como evidencia de tasas** (plan de cuentas de plataforma); la tasa real está en el Decreto del IVA (O3).

---

## 5. Implicaciones concretas para el diseño

### 5.1 Dos series y dos reservas atómicas independientes (corrección estructural)

`exploration.md` §5 modela **una** numeración. La providencia exige **dos**, con autoridades y ciclos de vida distintos.

```
fiscal_series
  id, store_id, fiscal_profile_id, document_type, prefix,
  series_kind 'document'|'control'   -- NUEVO: distingue las dos
  assigned_by 'issuer'|'printer'     -- NUEVO: quién asigna
  next_number, from_number, to_number, valid_from, valid_to, status
  -- sólo series_kind='control': control_identifier char(2) ('00' inicial),
  --                               control_sequence_length int (≤ 8)
```

| Regla oficial | Fuente | Campo / restricción |
|---|---|---|
| Numeración del documento consecutiva y única | F1 art. 8.2 | `fiscal_series(series_kind='document')` + reserva atómica `UPDATE … RETURNING` |
| Precedida de la palabra **"serie"** si no hay sistema centralizado | F1 art. 20 | `prefix` **debe** incluir `serie` (caso multi-tienda); **validación** en el dominio |
| Número de Control consecutivo y único **por emisor** | F1 art. 8.3, 27; F2 art. 30 | `fiscal_series(series_kind='control', assigned_by='printer')` |
| Identificador 2 dígitos + secuencial hasta 8; primera asignación `00` + `1` | F1 art. 9.1; F2 art. 30 | `control_identifier` / `next_number`; **restricción de primera asignación** |
| «N° de Control… desde el N° X hasta el N° Y» | F1 art. 9.1 | **El rango total se imprime en la factura** → `control_range_from` / `control_range_to` en el documento |
| Fecha de asignación del nº de control | F1 art. 9.4 | `control_assigned_at` |
| El número **no** se libera | F1 art. 8.2, 27; F2 art. 30, 34 | `fiscal_document_reservations.voided_at`; el hueco queda consumado |
| Numeración propia de comprobantes de retención, 14 chars `AAAAMMSSSSSSSS` | F1 art. 18.1 | **Serie nueva** `document_type='retention_receipt'`; el secuencial se reinicia al superar 8 dígitos → **regla de reinicio** en el motor de series |

**Nueva tabla** `fiscal_document_control_assignments`: `fiscal_document_id`, `control_number`, `control_range_from`, `control_range_to`, `control_assigned_at`, `printer_id`, `printer_rif`. **El control se recibe de la imprenta, no se genera en el POS** → el flujo de emisión es *solicitar → recibir → asignar*.

### 5.2 Actor emisor vs. actor imprenta digital (contrato de canales)

```
fiscal_profiles_environments      -- o tabla nueva equivalente
  id, fiscal_profile_id,
  code  'SENIAT_PORTAL' | 'DIGITAL_PRINTER' | 'TECH_PROVIDER' | 'FISCAL_MACHINE'
  role  'issuer' | 'printer'          -- NUEVO: qué papel cumple ESTE despliegue
  provider_ref, base_url, auth_scheme, is_testing, is_production
```

| Hecho oficial | Fuente | Consecuencia de diseño |
|---|---|---|
| El emisor **debe contratar** una imprenta digital autorizada | F2 art. 18.4 | `issuer` **no** puede emitir solo en canal digital → `issue()` siempre pasa por el proveedor |
| El emisor **remite la estructura** a la imprenta para su validación | F2 art. 18.6; F1 art. 42.1 | `issue()` tiene **dos fases**: `submit()` → validación → respuesta con el Nº de Control |
| La imprenta **asigna** el número de control | F2 art. 29; F1 art. 42.1 | El Nº de Control es **entrada**, no salida, del emisor |
| La imprenta valida **estructura y autenticidad** | F1 art. 42.1; F2 art. 29.4 | El proveedor devuelve estado de validación; `fiscal_documents.printer_validation_status` |
| La imprenta pide al emisor el **comprobante digital del RIF** | F2 art. 29.2; F1 art. 42.2 | `issuer_fiscal_id` + **comprobante digital del RIF** como artefacto propio |
| Acceso al SENIAT **365 días/año** | F1 art. 42.2 | Requisito de proveedor, no nuestro |
| El sistema puede **actuar como imprenta** (persona jurídica domiciliada en el país) | F1 art. 39 | **Rol dual**: `role='printer'` → obligaciones de registro automatizado mensual (art. 44) y causas de revocación (art. 34) |
| Portal Web y Aplicativo Móvil del SENIAT, **gratuitos** | F1 art. 4.1.1 | **Canal alternativo sin intermediario** → `ElectronicInvoiceProvider` debe soportar un canal sin imprenta (el control lo asigna el propio SENIAT) |
| Máquinas fiscales como medio | F1 art. 4 | **Tercer canal**; el perfil debe poder declararlo |

**Contrato `ElectronicInvoiceProvider` — actor emisor vs. actor imprenta.** El contrato pasa de "una llamada emite" a **dos papeles**:
- `submit(DocumentDraft): SubmissionReceipt` — el emisor entrega la estructura; devuelve estado de validación.
- `assignControl(SubmissionReceipt): ControlAssignment` — **sólo en canal con imprenta**: llega el Nº de Control, el rango y la fecha de asignación.
- `issue(DocumentDraft, ?ControlAssignment): FiscalDocument` — consolida y persiste.
- `cancel(FiscalDocument): CancellationReceipt` — notas de crédito/débito con referencia al original (F1 art. 12).
- `queryStatus(FiscalDocument): ProviderStatus` · `downloadXml(FiscalDocument): string` · **nuevo** `deliver(FiscalDocument, Recipient)` — remisión al usuario final (F2 art. 18.9).

### 5.3 Obligaciones de plataforma que el legacy no tiene

| Obligación | Artículo | Entregable de Fase 0 |
|---|---|---|
| **Auditoría electrónica de toda acción** (emisión, modificación, anulación) | F2 art. 18.2 | **Tabla nueva** `fiscal_document_events` — **append-only**: `fiscal_document_id`, `action` (`issue`/`modify`/`cancel`), `actor`, `occurred_at`, `payload` jsonb. **No** es un `updated_at` |
| **Acceso al SENIAT durante 10 años** | F2 art. 18.7 | **Retención ≥ 10 años** de comprobantes (no 5). Afecta `storage/` y el borrado de `fiscal_documents` |
| **Remisión del registro dentro de los 10 días** del fin de mes | F2 art. 29.7; F1 art. 44 | **Job mensual** (Fase 1) + **tabla** `fiscal_printer_monthly_reports` si el rol es `printer`: RIF del emisor, fecha de asignación, tipo de documento, numeración de control, numeración de cada formato, identificación de la factura (art. 44) |
| **Conciliación del papel de contingencia al día siguiente** | F1 art. 7 | **Tabla** `fiscal_contingency_documents` + **job D+1** que reenvía los **PDF** a la imprenta o a la API del SENIAT; además **notificación escrita** a la Gerencia Regional y a la imprenta (F2 art. 16) |
| **Formatos de contingencia autorizados y presentes en el local** | F1 art. 6 parágrafo único | Requisito **operativo del cliente**. El software sólo **registra** los talones: `contingency_forms` con nº de control inicial/final y de documento (art. 27) |
| **Numeración de comprobantes de retención** `AAAAMMSSSSSSSS` | F1 art. 18.1 | `document_type` nuevo + serie propia con **reinicio** del secuencial a los 8 dígitos |
| **Multipágina: mismo nº de documento y de control** | F1 art. 9.5 | Un documento = N páginas; el control **no** se consume por página → `fiscal_document_pages` |
| **Notas referencian fecha, nº correlativo, nº de control y monto del original** | F1 art. 12 | **FK obligatoria** a la factura original + **snapshot** de esos 4 datos en la nota |
| **Comprobante al usuario final** | F2 art. 18.9, 18.10 | **Entrega** por correo electrónico **o** **página web de consulta**: el POS debe **servir consulta pública** y **enviar por email** |
| **Código de la operación** | F1 art. 9.2 | `product_stores` necesita un **código de operación** relacionado con el inventario o con la prestación |

### 5.4 Requisitos de renderizado normados → el perfil fiscal lleva la plantilla

| Literal / regla (artículo) | Dónde vive |
|---|---|
| Denominación **"Factura"** / **"Nota de Débito"** / **"Nota de Crédito"** (art. 8.1, 12, 13) | `fiscal_profiles.document_label` / `document_type` |
| Fecha **`DDMMAAAA`**, hora **`HH.MM.SS`** con `a.m`/`p.m` (art. 8.5) | `number_formats.date_format` / `time_format` + `am_pm` |
| Marcador **`(E)`** junto a descripción o precio (art. 8.7) | `tax_categories.exempt_marker` (valor `' (E)'`) |
| Frase **"Contribuyentes Formales del IVA"** (art. 8 parágrafo único) | `fiscal_profiles.formal_iva_contributor_phrase` |
| Base imponible e IVA **discriminados por alícuota** (art. 8.9, 8.10) | `fiscal_document_taxes` multinivel **por alícuota** → **obligatorio** |
| Moneda extranjera: **ambas cantidades + tipo de cambio** (art. 8.12) | `fiscal_documents.exchange_rate` + moneda original y su conversión |
| **"N° de Control… desde el N° X hasta el N° Y"** (art. 9.1) | Plantilla del documento |
| Datos de la **imprenta**: razón social, RIF, nomenclatura y fecha de la providencia (art. 9.3) | `fiscal_profiles_environments` / `fiscal_printers` |
| Tamaño de letra: **imprenta ≥ 6 pt**, **emisor y nº de control ≥ 8 pt** (art. 10, 11, 42.4) | `fiscal_profiles.layout_requirements` (mínimo de puntos por bloque) |
| Mención de **conformidad con la providencia** (art. 19) | Literal obligatorio en el pie del documento |
| **RIF del receptor o cédula/pasaporte** (art. 8.6) | `fiscal_document_parties.fiscal_id` + `fiscal_id_kind` + alternativa `identity_document` |

### 5.5 Veredicto sobre P3 y P4 (re-evaluado)

| Decisión | Veredicto **revisado** | Fundamento normativo |
|---|---|---|
| **P3** — IVA retenido modelado en el esquema | **CONFIRMADO POR FUENTE OFICIAL.** Pasa de "ni confirmado ni contradicho" a confirmado, y **se amplía**: el retenido exige un **documento fiscal propio** con numeración de 14 caracteres, período de imposición, referencia a la factura de origen, base imponible, impuesto causado y monto retenido | F1 art. 18 (arts. 18.1, 18.5–18.9) |
| **P4** — redondeo por línea con `roundingAdjustment` visible | **CONFIRMADO COMO DECISIÓN DE PRODUCTO, NO COMO REQUISITO NORMATIVO.** La providencia **no** fija regla de redondeo: no hay nada que contradiga P4, y tampoco nada que la imponga | F1 y F2: ausencia verificada del tema |

**Consecuencias que la evidencia añade sobre P3** (no estaban en la revisión 1):
1. `tax_categories.iva_retenido` + `is_withholding` **se mantienen**, pero **no bastan**: hace falta un `document_type` nuevo (**comprobante de retención**) con numeración propia `AAAAMMSSSSSSSS`.
2. El comprobante de retención **no es una factura**: lleva **período de imposición**, referencia la factura o nota de débito de origen y **no** lleva la denominación "Factura" (F1 art. 13).
3. El **IVA retenido es crédito del comprador**: lo emite el **agente de retención**, no el proveedor. El POS debe **emitir** comprobantes de retención (rol emisor) y **recibirlos** (rol receptor): son documentos distintos.
4. El módulo funcional de retenciones **sigue diferido** a Fase 1, pero el **esquema** (tipo de documento, serie, campos) se fija **en Fase 0** — que es lo que P3 pedía.

### 5.6 `ElectronicInvoiceProvider.issue()` — contrato revisado

| Parámetro / retorno | Estado · Fuente |
|---|---|
| `documentType` (`invoice` / `credit_note` / `debit_note` / `retention_receipt` / `contingency_invoice`) | Resuelto · F1 art. 4, 12, 13, 18 |
| `series` + `documentNumber` | Resuelto · F1 art. 8.2, 20 |
| `issuerFiscalId` (+ RIF) · `receiverFiscalId` **o** `receiverIdentityDocument` | Resuelto · F1 art. 8.4, 8.6 |
| `issueDate` (`DDMMAAAA`) · `issueTime` (`HH.MM.SS` + `a.m`/`p.m`) | Resuelto · F1 art. 8.5 |
| `lines[]` con `operationCode` | Resuelto · F1 art. 9.2 |
| `taxBreakdown[]` **por alícuota** + `exemptMarker` `(E)` | Resuelto (obligatorio) · F1 art. 8.7, 8.9, 8.10 |
| `isFormalIvaContributor` → "Contribuyentes Formales del IVA" | Resuelto · F1 art. 8 parágrafo único |
| `currencyCode` + `exchangeRate` (ambas cantidades) | Resuelto · F1 art. 8.12 |
| `originalDocumentRef` (fecha, nº correlativo, nº de control, monto) — **obligatorio** en notas | Resuelto · F1 art. 12 |
| `contingencyFlag` → prefijo **"contingencia"** | Resuelto · F2 art. 16.3 |
| `printer` (razón social, RIF, nomenclatura y fecha de la providencia) | Resuelto · F1 art. 9.3 |
| `controlRangeFrom` / `controlRangeTo` («N° de Control… desde … hasta …») | Resuelto · F1 art. 9.1 |
| `controlNumber` + `controlAssignedAt` | **Entrada**, no salida: lo asigna la imprenta · F2 art. 29, 30; F1 art. 9.4, 42.1 |
| `printerValidationStatus` | Resuelto · F1 art. 42.1; F2 art. 29.4 |
| `pages[]` (mismo nº de documento y de control) | Resuelto · F1 art. 9.5 |
| `qrContent` (nullable) | **Ausente de la norma** → opcional por canal · — |
| `hash` + `hashAlgorithm` · `signature` + `signatureScheme` | **Sin algoritmo normativo** → parametrizados, sin default · F2 art. 18.2 |
| `xml` / `xmlSchemaVersion` | **Manual Técnico (O1)** → `NULL` hasta O1 · F1 art. 41; F2 art. 18.6 |
| `delivery` (correo / página web de consulta) | Resuelto · F2 art. 18.9, 18.10 |
| `authorityReference` | Genérico (`native_status_code`); la providencia no define UUID ni CUFE · — |

### 5.7 Lo que **sí** puede avanzar en Fase 0

**Sin dependencia de O1–O6:** (1) capa de país/fiscal con `fiscal_series` de **dos** clases y **dos** reservas atómicas; (2) reserva `UPDATE … RETURNING` + tests T1–T3, incluido el de que **el control no se libera**; (3) `fiscal_document_events` append-only; (4) `fiscal_document_taxes` multinivel **por alícuota** con `tax_type='withholding'`; (5) `fiscal_document_parties`; (6) `fiscal_document_control_assignments` + control ranges; (7) tipo de documento **comprobante de retención** + serie `AAAAMMSSSSSSSS` + período de imposición; (8) contrato `ElectronicInvoiceProvider` con **rol emisor vs. rol imprenta** y canales; (9) `fiscal_profile.layout_requirements`; (10) entrega al usuario final (email + consulta pública); (11) **validación de RIF** (ya verificada en rev. 1); (12) test de arquitectura "cero literal de país".
**Parcial:** seed del perfil `VE_*` con `evidence_status` por fila — el 16 % queda `pending_confirmation` (O3).
**Bloqueado:** el **adaptador real** (imprenta digital / API del SENIAT) — O1 + O4 + O6.

### 5.8 Decisiones de producto **nuevas** que la evidencia abre (para el orquestador)

| # | Decisión | Opciones | Recomendación |
|---|---|---|---|
| **D1** | ¿El sistema es **emisor**, **imprenta digital**, o **ambos**? (F1 art. 39) | sólo emisor · sólo imprenta · **ambos** | **Ambos** si el cliente opera una red y emite por cuenta de terceros (F1 art. 6.6). El rol va **como dato** (`role` del canal), no como bifurcación del código |
| **D2** | ¿Por qué **canal** se emite? (F1 art. 4, 5) | Portal/App del SENIAT · imprenta digital · proveedor tecnológico · máquina fiscal | **Portal del SENIAT** para el smoke test (sin intermediario) + **imprenta digital** en producción, ambos como filas de `fiscal_profiles_environments` |
| **D3** | ¿Qué **prefijo de serie** se usa? (F1 art. 20) | `serie` + 3 + 8 (formato del mapa, **no normado**) vs. libre | **Parametrizado** en `fiscal_series.prefix`, con el token `serie` **obligatorio**; el formato numérico es decisión de producto |
| **D4** | ¿Se **implementa** el módulo de retenciones en Fase 1? (F1 art. 18) | sólo esquema vs. esquema + emisión | **Sólo esquema en Fase 0** (mantiene P3), **emisión en Fase 1**: el contenido normado ya se conoce |
| **D5** | ¿El POS **entrega** el comprobante por email y/o aloja **consulta pública**? (F2 art. 18.9, 18.10) | correo · web · ambos | **Ambos**: el art. 18.10 ofrece la web **propia** del emisor como alternativa a la de la imprenta |

---

## 6. Glosario de términos fiscales venezolanos

> **Regla**: la columna *"Definición oficial"* se rellena **sólo** con artículo citado de F1/F2. La columna *"Definición operativa en el proyecto"* es la del proyecto. Si no hay artículo, `[NO VERIFICADO]`.

| Término | Definición operativa en el proyecto | Definición oficial (artículo) | Estado |
|---|---|---|---|
| **SENIAT** | contraparte del comprobante digital | Administración tributaria; portal fiscal `https://decaraciones.seniat.gob.ve` (F2, DF Sexta) | **Verificado** |
| **RIF** | `stores.vat_reg_no` → `store_fiscal_registrations` | **Registro Único de Información Fiscal**: obligatorio del emisor (F1 art. 8.4) y del adquirente (art. 8.6); para persona natural sin factura tributaria basta cédula o pasaporte (art. 8.6). Formato y dígito verificador: [V1, V3, V4] | **Verificado** |
| **Medios digitales o electrónicos** | modo de emisión del perfil fiscal | *"Modalidad tecnológica que permite la generación, validación, transmisión y almacenamiento de documentos fiscales en forma digital, sustituyendo el uso del papel físico"* (F1 art. 4.1) | **Verificado** |
| **Imprenta digital** | actor intermedio (rol nuevo) | Proveedor autorizado que **asigna el número de control**, **valida estructura y autenticidad**, garantiza acceso al SENIAT 365 días/año y remite el registro automatizado mensual (F1 art. 42; F2 art. 29, 30, 32, 34) | **Verificado** |
| **Número de Control** | nuevo en el modelo | **Consecutivo y único por emisor**, `NN` (2 dígitos) + secuencial de hasta 8; primera asignación **`00` + `1`**; se imprime «N° de Control… desde el N° X hasta el N° Y» (F1 art. 8.3, 9.1, 27; F2 art. 30) | **Verificado** |
| **Numeración del documento** | `fiscal_series` (hoy única) | Consecutiva y única (F1 art. 8.2); precedida de la palabra **"serie"** sin sistema centralizado (art. 20) | **Verificado** |
| **Comprobante digital** | `fiscal_documents` | Factura por medios autorizados con el contenido mínimo del art. 8 (F1) y los requisitos del art. 9 (medios digitales) | **Verificado** |
| **Contingencia** | nuevo | Uso **exclusivo** de talonarios ante las 8 causales del art. 6 (F1); numeración precedida de **"contingencia"** (F2 art. 16.3); conciliación **al día siguiente** (F1 art. 7) | **Verificado** |
| **Exento / exonerado / no gravado** | `tax_categories.iva_exento` / `iva_exonerado` / `iva_no_gravado` | Categorías reconocidas por la providencia; marcador **`(E)`** junto a descripción o precio (F1 art. 8.7); "Contribuyentes Formales del IVA" en exentos/exonerados de contribuyentes formales (art. 8 parágrafo único) | **Verificado** |
| **IVA retenido** | `tax_categories.iva_retenido` con `is_withholding` | Retenido por **agentes de retención** del IVA y del ISLR, que deben emitir **comprobante propio** con numeración `AAAAMMSSSSSSSS` (F1 art. 18) | **Verificado** |
| **Comprobante de retención** | nuevo `document_type` | Documento del agente de retención: nº de control y nº de la factura o nota de débito, **período de imposición**, monto total, base imponible, impuesto causado y monto retenido (F1 art. 18.5–18.9) | **Verificado** |
| **Auditoría electrónica** | nuevo `fiscal_document_events` | *"auditoría electrónica de toda acción"* de emisión, modificación o anulación; exige integridad, autenticidad y trazabilidad (F2 art. 18.2) | **Verificado** |
| **Registro automatizado** | nuevo, si rol `printer` | Registro que la imprenta lleva y remite al SENIAT con RIF del emisor, fecha de asignación, tipo de documento, numeración de control y de formato e identificación de la factura (F1 art. 44; F2 art. 32) | **Verificado** |
| **Alícuota** | `tax_rates.percentage` | Cada porcentaje aplicable se discrimina en la **base imponible** y en el **IVA** (F1 art. 8.9, 8.10) | **Verificado** |
| **Código de la operación** | nuevo en `products` | *"relacionado con la codificación del inventario de los bienes o con la prestación de servicio"* (F1 art. 9.2) | **Verificado** |
| **Tasa de IVA** | 16 % en `tax_rates` | **No figura** en F1 ni en F2; remiten al **Decreto del IVA** (arts. 54 y 57) y a su Reglamento (arts. 63–66) | **`[NO VERIFICADO]`** → O3 |
| **Hash / firma digital** | `fiscal_documents.hash` + `hash_algorithm` | **No hay algoritmo fijado**; exige integridad, autenticidad y trazabilidad (F2 art. 18.2) y validación por la imprenta (F1 art. 42.1) | **`[NO VERIFICADO]`** → parametrizado |
| **QR** | `fiscal_documents.qr_content` (nullable) | **No figura** en F1 ni en F2 | **Ausente de la norma** (verificado) |
| **Redondeo** | `fiscal_profiles.rounding_rule` + `roundingAdjustment` | **No figura** en F1 ni en F2 | **Ausente de la norma** (verificado) |
| **Formas de pago** | `payment_methods` | **No figuran** en F1 ni en F2 | **`[NO VERIFICADO]`** → catálogo propio |
| **Régimen ordinario / simplificado** | `fiscal_profiles.code` | **No figuran** en F1 ni en F2 | **`[NO VERIFICADO]`** → confirmar por otra fuente |
| **Manual Técnico** | — | Especificaciones de imprenta digital y máquinas fiscales (F1 art. 41); define el formato técnico que el emisor remite a la imprenta (F2 art. 18.6) | **`[NO VERIFICADO]`** → O1 |

---

## 7. Metadatos del artefacto

| Campo | Valor |
|---|---|
| `artifact_id` | `gentle-ai.sdd-research/v1` |
| `change` | `fase0-fundaciones` |
| `lane` | `senat-venezuela` |
| `revision` | `2` (supersede a la rev. 1, `partial`) |
| `status` (fase) | `success` |
| `outcome` (research-lifecycle) | `done` |
| `questions` | 9 (T1–T9) — **7 resueltas por artículo**, **2 con hallazgo negativo verificado** (T5 QR, T8 redondeo) |
| `admission` | Tramo A normativo (F1–F5, vía tercero) + tramo B `documentation` (V1–V14) + tramo D interno; `open-web` **denegada** |
| `sources` | 5 normativas + 14 de terceros + 2 internas; **19 puntos de evidencia con artículo citado** |
| `correciones al exploration` | 6 (C1–C6, §4.4): dos numeraciones, actor intermedio, serie sin formato normado, QR ausente, IVA retenido = documento propio |
| `decisiones de producto` | **P3 `confirmed`** (F1 art. 18) · **P4 `confirmed` como decisión de producto** (la norma no fija regla) · **P6 `confirmed`** (consecutividad + unicidad + prefijo "serie"; formato configurable) · **P11 `confirmed`** (contrato + stub; adaptador diferido por O1/O4/O6) · **P7 `pending`** · **D1–D5 nuevas** (§5.8) |
| `pendientes` | 6 (O1–O6, §4.1) — **ninguno bloquea Fase 0**; O1 y O4 bloquean el adaptador de Fase 1/2 |
| `propuesta lista` | **Sí** (`true`) — las decisiones que condicionan la propuesta están confirmadas o explícitamente diferidas |
| **store** | `hybrid` — OpenSpec `research/senat-venezuela.md` + Engram `sdd/fase0-fundaciones/research` |
