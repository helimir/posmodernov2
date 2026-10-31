# ADR-0001 · Numeración fiscal de dos series con reserva atómica

- **Estado**: Aceptado
- **Fecha**: 2026-10-31
- **Ámbito**: Fase 0 — Fundaciones (`fase0-fundaciones`)
- **Diseño**: `openspec/changes/fase0-fundaciones/design.md` §D.1 · §B.3 · §A.1 (M21, M22)

## Contexto

SNAT/2026/00093 distingue **dos** numeraciones con autoridades distintas: la del **documento**, consecutiva y única, a cargo del emisor (art. 8.2), precedida de la palabra "serie" si no hay sistema centralizado (art. 20); y el **Nº de Control**, consecutivo y único por emisor, a cargo de la **imprenta digital** (art. 8.3, 9.1, 27; SNAT/2024/000102 art. 30). El número consumido **no se libera** (art. 8.2, 21, 27; F2 art. 34).

El legacy no reserva nada. `generate_invoice_id()` (`_inc/helper/invoice.php:139-172`) deriva del "último `invoice_id`" leído y le suma 1 —la reintento recursivo de la línea 160 además descarta el valor devuelto—; `unique_transaction_ref_no()` (`:95-109`) usa `rowCount()` de un `SELECT`, que en PDO no es el número de filas afectadas. Con dos cajas simultáneas, dos documentos reciben el mismo número.

## Decisión

1. `fiscal_series` se distingue por `series_role` (`document` | `control` | `retention_receipt`) y por `assigned_by` (`issuer` | `authority` | `printer` | `psp`).
2. Cada documento fiscal tiene **dos reservas atómicas independientes**, una por serie, dentro de la misma transacción que el documento:
   `UPDATE fiscal_series SET next_number = next_number + 1 WHERE id = ? AND next_number <= to_number RETURNING next_number - 1`.
3. El **Nº de Control se reserva localmente como espejo** del rango autorizado de la imprenta (`from_number`/`to_number`/`credential_ref` sembrados) y se **verifica** contra lo que la imprenta devuelve. La discrepancia se registra como evento en `fiscal_document_events`.
4. La traza vive en `fiscal_document_reservations` (`series_id`, `number`, `series_role`, `reserved_by`, `reserved_at`, `voided_at`).
5. **La anulación no libera el número**: el documento queda `status='void'` y su reserva con `voided_at`. **El rollback sí lo devuelve**: la transacción fallida revierte el contador.
6. El rollback operativo de la numeración es **apagar, nunca renumerar**: desde el primer documento emitido la numeración es irrecuperable por diseño; un `git revert` no borra `fiscal_document_reservations`, desactiva la emisión (`config('fiscal.emission_enabled')`) y conserva la traza.

## Consecuencias

**Positivas**
- La unicidad del correlativo es correcta por construcción, no por disciplina del operador.
- Las dos autoridades quedan separadas: cada serie tiene rango, vigencia y ciclo de vida propios.
- El número quemado es auditable (quién, cuándo, por qué) y defendible ante el SENIAT.
- Verificable con T1–T3 sobre PostgreSQL real, sin `RefreshDatabase`.

**Costes**
- Dos reservas ⇒ doble superficie de bloqueo: el checkout bloquea dos filas de `fiscal_series`.
- El emisor necesita el **rango de control sembrado** antes de operar; si la imprenta renueva el rango hay que re-sembrar.
- Irreversibilidad desde el primer documento emitido: obliga a ser conservador con los interruptores de emisión.

**Riesgo residual**
- La divergencia entre el control reservado localmente y el devuelto por la imprenta se detecta **después** de emitir (T2). Se mitiga con el evento de discrepancia y el estado `sales.status='fiscal_failed'` con reutilización de la reserva.

## Alternativas descartadas

| Alternativa | Por qué se descartó |
|---|---|
| Una sola serie / un solo correlativo | `exploration.md` §5 modelaba una numeración; contradice arts. 8.2, 8.3, 9.1 y 27. |
| `SELECT` + `rowCount()` + `+1` | `rowCount()` de un `SELECT` no es el nº de filas afectadas; lectura y escritura no son atómicas. Prohibido por `openspec/config.yaml → rules.design`. |
| `SELECT … FOR UPDATE` en lugar de `RETURNING` | Serializable, pero no devuelve el valor reservado en la misma sentencia; `RETURNING` cierra la ventana entre leer y escribir. |
| Esperar el control de la imprenta antes de numerar el documento | Rompe la consecutividad sin huecos del emisor y mantiene bloqueada la caja durante I/O externo. |
| Reciclar o renumerar el correlativo tras una anulación | Prohibido: arts. 8.2, 21 y 27; F2 art. 34. El hueco queda consumado. |
| Liberar la reserva cuando la emisión falla en T2 | El número ya puede haberse publicado; la recuperación es **reutilizar la reserva**, no devolverla. Decisión C5. |
