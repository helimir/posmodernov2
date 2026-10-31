# Calidad continua — Especificación

## Purpose

Puerta de calidad ejecutable desde el primer commit: CI con PostgreSQL, suite de tests del dominio que documenta las reglas de negocio, ADRs y README de arranque.

## Requirements

### Requirement: CI con PostgreSQL, lint, typecheck, tests y build

La integración continua MUST ejecutar sobre PostgreSQL 17 las etapas de lint, typecheck, tests y build. `typecheck` MUST formar parte del gate de CI, no sólo del pipeline local. La suite MUST ejecutarse después de `npm ci && npm run build`: las pruebas de página requieren el manifiesto de Vite.

**Trazabilidad:** S10 · Criterios C2, C3 y C11 · openspec/config.yaml → commands, testing.preconditions

#### Scenario: Pull request en verde

- GIVEN un pull request con cambios
- WHEN corre la integración continua
- THEN lint, typecheck, tests y build pasan sobre PostgreSQL con el bundle compilado
- AND Regla fijada: el typecheck está en el gate, no fuera

#### Scenario: Bundle ausente

- GIVEN una ejecución de la suite sin `public/build/manifest.json`
- WHEN arranca el job de tests
- THEN el job falla con un error explícito de precondición, no con un fallo de render sin causa
- AND Regla fijada: `npm ci && npm run build` precede a la suite

### Requirement: Suite Pest del dominio

El sistema MUST contar con una suite Pest del dominio, en `tests/Unit` y `tests/Feature`, que cubra el checkout, el FIFO, los impuestos, la reserva fiscal, las cuotas, el saldo de caja y la devolución que repone stock. La suite MUST poder ejecutarse con `./vendor/bin/pest` desde la raíz.

**Trazabilidad:** S10 · Criterio C2 · exploration.md §11.3 (E8)

#### Scenario: Suite del dominio verde

- GIVEN el esquema y los seeds sembrados
- WHEN se ejecuta `./vendor/bin/pest`
- THEN la suite del dominio pasa en verde
- AND Regla fijada: el dominio se prueba sin interfaz

### Requirement: Cada test nombra la regla de negocio que fija

Cada test MUST nombrar en su descripción la regla de negocio que fija, de modo que el test sea su documentación y el nombre del test MUST ser legible sin abrir el cuerpo.

**Trazabilidad:** S10 · Criterio C2 · openspec/config.yaml → rules.specs · mapa-funcional.md §10.6

#### Scenario: Test autodescriptivo

- GIVEN un test de la reserva fiscal
- WHEN se lee su nombre en el reporte de la suite
- THEN enuncia la regla de negocio que fija, sin ambigüedad
- AND Regla fijada: el test es la documentación de la regla

### Requirement: Seis ADRs y README de arranque

El repositorio MUST contener seis ADR en `docs/adr/`, uno por cada decisión estructural de la fase: numeración fiscal atómica, capa de país/fiscal, stock por lotes, agente de impresión, protocolo de sync v2 y traducción del esquema destino. Cada ADR MUST documentar la decisión, su motivo y sus consecuencias. El repositorio MUST incluir además un README de arranque.

**Trazabilidad:** S10 · Criterio C10 · exploration.md §11.3 (E10, E11)

#### Scenario: Decisión estructural trazable

- GIVEN una decisión sobre la numeración fiscal atómica
- WHEN se busca su registro
- THEN existe un ADR que expone la decisión, el motivo y las consecuencias
- AND Regla fijada: cada decisión estructural queda documentada

### Requirement: Baseline verde sin fallos preexistentes

La puerta de calidad MUST ejecutarse sin fallos preexistentes: un fallo ajeno al cambio MUST corregirse o aislarse antes de dar la fase por verde. El dominio MUST estar cubierto por tests antes que la interfaz, de modo que el equipo herede el sistema sin heredar el conocimiento tácito.

**Trazabilidad:** S10 · Criterio C2 · openspec/config.yaml → rules.verify.notes · mapa-funcional.md §10.6

#### Scenario: Fallo preexistente en la suite

- GIVEN un fallo preexistente ajeno al cambio
- WHEN corre la puerta de calidad
- THEN la fase no se declara verde hasta que el fallo se corrige o se aísla con justificación
- AND Regla fijada: la suite debe estar en verde sin fallos preexistentes
