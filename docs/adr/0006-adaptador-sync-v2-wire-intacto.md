# ADR-0006 · Adaptador del protocolo sync v2 con wire intacto, traducción por datos sembrados e idempotencia por `sync_batches`

- **Estado**: Aceptado
- **Fecha**: 2026-10-31
- **Ámbito**: Fase 0 — Fundaciones (`fase0-fundaciones`)
- **Diseño**: `openspec/changes/fase0-fundaciones/design.md` §D.6 · §A.1 (M24) · §E.2

## Contexto

Los terminales offline **siguen siendo legacy** durante la transición (decisión de producto P1): el nuevo sistema reemplaza al legacy y `sync.php` viejo se apaga el mismo día. El payload que el terminal empuja al maestro es **SQL crudo de MySQL con nombres del legacy**: `_inc/lib/database.php:79` escribe `sql . '|' . serialize($args)` en `storage/logs/sql.txt`, y `ReadSyncData()` (`_inc/model/cron.php`) lo lee y lo arma como `[{"sql": …, "args": […]}]`. El maestro nuevo es PostgreSQL 17 con un esquema de nombres nuevos ⇒ **hay que traducir identificadores**, no sólo verificar firmas.

Dos fallos operacionales del protocolo, verificados, que el maestro nuevo debe tapar **sin tocar el contrato**:

- **S1 — no es idempotente**: el maestro confirma (`sync.php:141-148`) y el cliente **sólo trunca su log si la respuesta fue exitosa**. Una respuesta perdida provoca un reenvío idéntico ⇒ violación de PK ⇒ el lote falla **para siempre** y el terminal queda congelado (hallazgo H3; es el riesgo operativo nº 1 de la ventana de corte).
- **S2 — nonces en sistema de ficheros**: `storage/sync-nonces/` (`_inc/helper/sync.php:180,191-227`) no escala a varios contenedores ni se puede compartir (H4).

Además, `ReadSyncData()` parte el log con `explode('|', …)`: un `|` dentro de un literal trunca el `serialize()` y la operación **se pierde en silencio** (H7) ⇒ el adaptador debe validar `sql`/`args` y **rechazar el lote entero**.

## Decisión

1. **El contrato de wire queda intacto**: mismas claves JSON, mismo canonizado (`username \n action \n timestamp \n nonce \n sha256(data)`), misma firma `hash_hmac('sha256', …)`, mismo límite `SYNC_MAX_AGE`, mismo orden de verificación — cliente conocido → timestamp → `hash_equals` de la firma → **sólo después** consumo del nonce — y misma forma de respuesta.
2. **La traducción de identificadores es un dato sembrado**: `legacy_translation_map` (`legacy_table`, `legacy_column`, `new_table`, `new_column`, `transform`), dirección **legacy → destino**, cubriendo las ~18 tablas del terminal. El reescritor es ligero: sólo identificadores, no un parser SQL completo.
3. **El lote se aplica en una única transacción**, sólo `INSERT|UPDATE|DELETE`, sin sentencias apiladas. Una operación sin `sql`/`args` válidos ⇒ se **rechaza el lote entero**, sin aplicación parcial.
4. **Idempotencia en el maestro**: `sync_batches` con `UNIQUE (client_id, payload_sha256)`. Un reenvío con el mismo contenido responde **éxito** sin volver a aplicarse.
5. **Los nonces viven en Redis** (`SET NX EX`), mismo contrato, mejor implementación. La creación es atómica y el consumo sigue ocurriendo después de validar la firma.
6. El des-escapeado de los valores que el legacy HTML-escapea (`Request`) se normaliza **una vez, en la frontera** del adaptador.

## Consecuencias

**Positivas**
- El terminal legacy **no cambia**: cero coste de despliegue y cero riesgo de congelamiento en la ventana de corte.
- El fallo S1 desaparece sin tocar el protocolo: es una **adición de servidor**, no un cambio de wire.
- El mapa es auditable y ajustable sin desplegar código, coherente con la regla "datos, no código".
- La traducción falla **cerrada**: un lote que no se entiende no se aplica a medias.

**Costes**
- Hay que **mantener el mapa sincronizado con el esquema**: un mapa desalignado rompe el lote. Mitigación: un test que compara el mapa sembrado con las tablas y columnas destino.
- El reescritor no es un parser SQL: si el terminal emite una forma no contemplada (función específica, `ON DUPLICATE KEY`, sintaxis no estándar), el lote se rechaza. Se acepta: el legacy verificado no usa `ON DUPLICATE KEY UPDATE` ni `REPLACE INTO`, y PostgreSQL acepta los backticks como delimitadores.
- Redis pasa a ser dependencia del sync (ya está en el stack: `CACHE_STORE=redis`, `QUEUE_CONNECTION=redis`).
- La idempotencia por hash cubre reenvíos **idénticos**: un lote con el mismo contenido pero distinto `client_id` sí se aplica (correcto: son clientes distintos).

**Riesgo residual**
- La traducción es el punto de mayor riesgo de la fase (R4): un mapeo mal escrito puede aplicar un `INSERT` en la columna equivocada. Mitigación: tests de traslación con casos reales del log del terminal y rechazo del lote completo ante cualquier operación no reconocida.

## Alternativas descartadas

| Alternativa | Por qué se descarta |
|---|---|
| Reescribir el protocolo v2 | El contrato es fijo y los terminales son legacy: cambiarlo los deja sin poder sincronizar en la ventana de corte. |
| Traducir por tabla en código (una clase por tabla legacy) | 18 tablas × código; dispersa el conocimiento y contradice la regla "datos, no código". |
| Tablas o vistas espejo del legacy en el esquema de negocio | Duplican el modelo (dos fuentes de verdad) y `INSERT` sobre una vista no es válido en PostgreSQL. |
| Normalizar el log del terminal antes de enviarlo | Exige tocar el terminal legacy, que es un sistema en producción que no se modifica en esta fase. |
| Resolver la idempotencia en el cliente (que no trunque hasta confirmar) | Cambia el comportamiento del terminal: es una modificación del cliente, no del maestro. |
| Mantener los nonces en ficheros | No escala a varios contenedores ni se puede compartir; Redis ya está en el stack. |
| Consumir el nonce antes de validar la firma | Permite que una petición no autenticada queme nonces ajenos; el orden correcto es validar la firma y luego consumir. |
