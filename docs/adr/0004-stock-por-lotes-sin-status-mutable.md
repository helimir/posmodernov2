# ADR-0004 · Stock por lotes sin `status` mutable + `FOR UPDATE SKIP LOCKED`

- **Estado**: Aceptado
- **Fecha**: 2026-10-31
- **Ámbito**: Fase 0 — Fundaciones (`fase0-fundaciones`)
- **Diseño**: `openspec/changes/fase0-fundaciones/design.md` §D.4 · §B.2 · §A.1 (M14, M27)

## Contexto

El FIFO del legacy tiene tres defectos, verificados en `_inc/model/invoice.php`:

1. El lote consumido **no es determinista**: el `SELECT` de lotes disponibles (`:237`, `:244`) **no tiene `ORDER BY`**.
2. El `UPDATE` de consumo **toca todos los lotes activos del producto**: `UPDATE purchase_item SET total_sell = total_sell + … WHERE store_id = ? AND item_id = ? AND status = ?` (`:262-263`), **sin `id = ?`**. Con más de un lote activo, el consumo se contabiliza en todos. Este defecto **no está** en el catálogo de trampas del mapa funcional (hallazgo H1 de `exploration.md`).
3. La cantidad se interpola en el SQL (`:262`, `:294`).

La tríada `qty_in`/`qty_out`/`status` del legacy codifica "disponible" con un enum que hay que mantener sincronizado con dos contadores, y la devolución actualiza `purchase_item` con dos claves distintas (`_inc/sell_return.php:142-153`), lo que deja lotes inconsistentes.

## Decisión

1. `inventory_lots` **no tiene columna `status` mutable**. La disponibilidad es
   `available = quantity_received − quantity_consumed + quantity_returned`,
   materializada como `GENERATED ALWAYS AS (…) STORED`: exacta por definición, no puede desincronizarse.
2. Todo consumo deja traza en `sale_item_lot_consumptions` (`sale_item_id`, `lot_id`, `quantity`, `unit_cost`).
3. El consumo ordena `ORDER BY received_at ASC, id ASC` y bloquea con `FOR UPDATE SKIP LOCKED`.
4. La devolución repone **el lote exacto** del que salió la línea, leyendo la traza de consumos. No repone el lote más antiguo disponible ni crea un lote nuevo.
5. `product_stores.quantity_in_stock` es un **caché** (el requisito lo permite si hay test de invariante), con índice parcial `inventory_lots (product_id, store_id, received_at, id) WHERE available > 0`.
6. Un producto de servicio (`products.type='service'`) no genera lote ni consume stock.

## Consecuencias

**Positivas**
- No existe estado que pueda quedar desincronizado: la aritmética es la fuente de verdad.
- El FIFO es determinista y auditable: cada consumo dice de qué lote salió.
- El COGS se calcula desde la traza (`unit_cost`), sin recalcular; la devolución devuelve el coste al lote correcto.
- Con `SKIP LOCKED`, dos cajas venden a la vez sin bloquearse entre sí y sin sobreventa.
- El defecto H1 del legacy es estructuralmente imposible en el destino: el `UPDATE` va por `id` de lote.

**Costes**
- `SKIP LOCKED` puede devolver "stock insuficiente" **aunque exista stock**, si el único lote con disponibilidad está bloqueado por otra caja. Se acepta: es preferible a la sobreventa, y el error es reintentable. Hay que distinguirlo en el mensaje de error.
- `available` es una columna GENERATED: PostgreSQL no la indexa por sí sola en un índice compuesto ⇒ índice parcial con predicado `available > 0`.
- El caché `quantity_in_stock` exige un **test de invariante** permanente: `quantity_in_stock == SUM(available)` tras cada operación de stock. Si el test no se mantiene, el requisito de "una sola fuente de verdad" se erosiona.
- `quantity_returned` es una tercera aritmética que el legacy no tenía: exige que la devolución sea exacta o el lote queda inflado.

**Riesgo residual**
- El test de invariante comprueba el caché, no la realidad del conteo físico: un error de captura en la compra no lo detecta. Se acepta; el dominio es verde y auditable.

## Alternativas descartadas

| Alternativa | Por qué se descartó |
|---|---|
| `status` enum (`stock`/`active`/`sold`) + `UPDATE` del consumo | Es el defecto del legacy: estado mutable que hay que sincronizar y que el `UPDATE` sin `id` corrompe. |
| `SELECT … FOR UPDATE` sin `SKIP LOCKED` | Serializa las cajas sobre el mismo lote y puede agotar `lock_timeout` en el punto de venta. |
| Elegir el lote "más antiguo disponible" al devolver | Rompe la traza: el coste y el margen de la venta quedarían ligados a un lote que no es el consumido. |
| Crear un lote nuevo por cada devolución | Infla el número de lotes y pierde la correspondencia entre consumo y reposición. |
| Derivar el stock siempre por `SUM(available)` | O(lotes) en cada lectura de stock, con bloqueo en el punto de venta; el requisito permite cachear si hay test de invariante. |
| Mantener `quantity_available` como columna mantenida a mano | Reintroduce exactamente el estado desincronizable que se quiere eliminar; `GENERATED` da lo mismo sin mantenimiento manual. |
