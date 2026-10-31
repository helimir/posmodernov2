<!--
  Template de PR para la cadena `stacked-to-main` del change SDD `fase0-fundaciones`.
  Cada PR es una work unit encadenada: este template obliga a declarar el Chain Context
  que exige la skill `chained-pr` (inicio, fin, dependencias previas, follow-up, fuera
  de alcance) y el diagrama de dependencias con el PR actual marcado.
-->

## Linked Issue

<!-- Obligatorio: el issue debe llevar la etiqueta `status:approved`. -->
Closes #

## PR Type

- [ ] Bug fix
- [ ] New feature
- [ ] Documentation only
- [ ] Code refactoring
- [ ] Maintenance/tooling
- [ ] Breaking change

## Summary

<!-- 1-3 viñetas con lo que hace este PR. -->

## Chain Context

<!--
  Obligatorio en cada PR de la cadena. No lo sustituyas por el resumen.
  La estrategia de la cadena es `stacked-to-main`: cada PR se abre contra `main`
  y se fusiona en orden; el diff debe mostrar SÓLO esta work unit.
-->

| Campo | Valor |
|---|---|
| **PR** | PR# de esta cadena |
| **Work unit** | Fase y tareas (`0.F.N`) que cubre |
| **Inicio** | Qué existe al abrir este PR |
| **Fin** | Qué queda delivering al cerrarlo |
| **Dependencias previas** | PRs de la cadena que deben estar fusionados antes |
| **Trabajo posterior** | Qué hará el siguiente PR de la cadena |
| **Fuera de alcance** | Qué NO hace este PR (y quién lo hace) |
| **Presupuesto de revisión** | Líneas de cambio (adiciones + borrados) y veredicto contra el techo de 800 |

### Diagrama de dependencias

<!-- Marca el PR actual con 📍. -->

```
PR#  ─┬─► PR#
     ├─► PR#
     └─► PR#
          📍 = PR actual
```

### Plan de verificación

- [ ] `npm ci && npm run build` (precondición: `public/build/manifest.json`)
- [ ] `vendor/bin/pint --test`
- [ ] `npm run lint:check`
- [ ] `npm run typecheck`
- [ ] `./vendor/bin/pest` — suite focalizada de esta work unit
- [ ] `./vendor/bin/pest` — suite completa
- [ ] Harness de runtime de esta work unit
- [ ] El diff muestra **sólo** esta work unit (sin ruido de la base)

### Rollback

<!-- Alcance de rollback de ESTE PR. Para la numeración fiscal: apagar, nunca renumerar. -->

## Changes Table

| File | Change |
|---|---|
| `path/to/file` | Qué cambió |

## Test Plan

- [ ] Tests escritos primero (rojo) y luego la implementación (verde) — `strict_tdd: true`
- [ ] Cada test nombra la regla de negocio que fija
- [ ] Scripts run without errors: `shellcheck scripts/*.sh` *(N/A: este repo no tiene scripts shell)*
- [ ] Manually tested the affected functionality
- [ ] Skills load correctly in target agent

## Contributor Checklist

- [ ] Linked an approved issue (`status:approved`)
- [ ] Added exactly one `type:*` label
- [ ] Ran shellcheck on modified scripts *(N/A)*
- [ ] Skills tested in at least one agent
- [ ] Docs updated if behavior changed
- [ ] Conventional commit format
- [ ] No `Co-Authored-By` trailers
- [ ] Chain Context completo (inicio, fin, dependencias, follow-up, fuera de alcance)
