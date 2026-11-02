# AGENTS.md — posmodernov2

Replacement **greenfield** for the legacy POS: **Laravel 12 + Inertia 2 + React 19 +
Tailwind 4 + Vite 6 + TypeScript 5.7**, **PostgreSQL 17 + Redis 7**, **Pest 3.8**.
No frontend test runner, no E2E, no coverage driver local.

This repo **replaces** the legacy (`D:\htdocs\pos_moderno`, PHP 8.2 + AngularJS 1.6). The
legacy is a **read-only functional reference**: `D:\htdocs\pos_moderno\Documentacion\mapa-funcional.md`
(1550 lines, Spanish) is the map of screens, the 73-table data model and the 94 prioritised
defects. Do not port its code; do reuse its domain rules.

## The plan lives here, not in your head

The active SDD change is **`fase0-fundaciones`**, in `openspec/changes/fase0-fundaciones/`:
`proposal.md`, `design.md` (906 lines), `tasks.md` (152 tasks), `exploration.md`,
`apply-progress.md`, 10 capability specs under `specs/`, and research under `research/`.

Read `tasks.md` and `apply-progress.md` **before** touching code: they carry the task
numbers, the dependencies, the per-PR chain and the evidence of the units already done.
`openspec/config.yaml` holds the machine-readable context: stack, `strict_tdd: true`, the
test command, the testing preconditions and the `rules` for each phase.

**Tasks are numbered `0.F.N` and each has a twin `0.F.N-T`** — the test that fixes the
rule the implementation serves. Both close together or not at all.

## The chain: 18 stacked PRs, and how it advances

`stacked-to-main`, **800 changed lines per PR**. Each PR opens against `main` **after the
previous one merged**, and is implemented on the previous PR's branch, then rebased onto
`main` after the merge. `tasks.md` §"Corte final" is the authoritative list with the
per-PR status; the older 16-PR tables in the same file are the historical measurement —
use the 18-PR one.

Non-negotiable rules of the chain:

- **One work unit per PR.** The diff must show *only* that unit. A polluted diff is a
  base bug: retarget or rebase.
- **The attempt ledger gates every unit.** Before any runtime-bearing work:
  `gentle-ai sdd-attempt acquire --cwd <repo> --change fase0-fundaciones --request-id <id>
  --work-unit <label> --evidence-goal <goal> --max-attempts <n> --max-changed-lines <n>`.
  Launch only on `state: proceed`; keep the token. Afterwards `… settle` with
  `--outcome passed|failed|interrupted`, `--evidence-revision`, `--diagnosis`,
  `--harness-disposition`, `--cleanup-evidence`, `--process-evidence`. **Merge before
  begin or after finish** — never rebase with an attempt open.
- **Every PR needs** its linked issue with `status:approved`, `Closes #N`, exactly one
  `type:*` label, the repo's PR template with the full **Chain Context** (start, end,
  prior dependencies, follow-up, out of scope), the dependency diagram marking the
  current PR with 📍, the verification plan, the rollback scope and the Changes Table.
- **Rollback for the fiscal numbering is "turn it off, never renumber".**
  `fiscal.emission_enabled=false`; **never** delete `fiscal_document_reservations` or
  `fiscal_series` — once a document is issued the numbering is unrecoverable by design
  (SNAT/2026/00093 arts. 8.2, 21, 27).

## Strict TDD is on

`strict_tdd: true`. Write the test first, watch it fail, then implement.

```bash
./vendor/bin/pest                                  # suite (needs the Vite manifest)
./vendor/bin/pest --filter="NumberSeries"         # one unit
```

**Precondition:** `public/build/manifest.json` must exist, or the Feature tests that
render pages fail with `ViteManifestNotFoundException`. Run `npm run build` first.

Other gates: `vendor/bin/pint --test` · `npm run lint:check` · `npm run typecheck`.
`pint --test` currently fails on 8 pre-existing Laravel-starter files, identical to
`main` — that is the baseline, not your diff. **No coverage driver locally** (CI installs
xdebug).

PostgreSQL is the production engine, so tests run on it, never SQLite: row-level
locking and the atomic fiscal reservation cannot be proven otherwise.

## The concurrency harness

`pgsql_b` is a **second session on the same database** (`config/database.php`): row
locking is only real across two distinct PostgreSQL sessions. Concurrency tests use
`tests/Concerns/WithoutWrappingTransaction.php` — **no `RefreshDatabase`**, because its
wrapping transaction hides the rows from `pgsql_b` and the `55P03` would never reproduce.
Isolate with `TRUNCATE … RESTART IDENTITY CASCADE` in `afterEach` (`tests/Fixtures/`).

The fiscal reservation **waits** and fails with `lock_timeout`; it does **not** use
`SKIP LOCKED` (unlike the stock FIFO). Skipping the row would turn a lock into a
randomly handed-out correlative.

## The golden rule

**The fiscal engine, the tax engine, the numbering and the formats are DATA, not code.**
Changing country = inserting rows. An `if ($country === 'VE')` inside `app/Domain/` is a
defect, and there is an architecture test for it (PR17, `NoCountryLiteralsTest`).

The correlativo is reserved with `UPDATE … RETURNING` (or `SELECT … FOR UPDATE`),
**never with `rowCount()`** — the legacy derived it from `SELECT` + `rowCount()` + 1
(`_inc/helper/invoice.php:95-109`), which is not atomic and lets two terminals hand out
the same number.

## Module switches

Three switches, all **off until the channel is validated**:
`FISCAL_EMISSION_ENABLED` (`config/fiscal.php`), `SYNC_LEGACY_ADAPTER_ENABLED`
(`config/sync.php`), `PRINT_LAN_AGENT_ENABLED` (`config/print.php`).

## Layout

```
app/Domain/          the domain: Fiscal (contracts, enums, value objects, NumberSeries), Tax
app/Models/          26 Eloquent models with real FKs and typed casts
app/Http/            controllers, requests, middleware
database/migrations/ M01–M27 (M01–M07, M21, M25–M26 so far)
database/seeders/    FiscalProfile, Taxonomy, LedgerEntryType, LegacyTranslationMap, OpeningBalances, DemoStore
tests/Unit/          pure domain, no DB
tests/Feature/       integration + concurrency (Fiscal, Inventory, Sync, Print, Accounting, Checkout)
docs/adr/            6 structural decisions, all "Aceptado"
openspec/            the SDD change (repo-local artifact store)
compose.yaml         postgres:17-alpine + redis:7-alpine  (NOT docker-compose.yml)
```

Naming is **English** for code and identifiers, **Spanish** for the domain comments and
the SDD artifacts. Commit messages are conventional commits in **English**
(`feat(fiscal): …`, `docs(tasks): …`). Never add `Co-Authored-By` trailers.

## Local environment

```bash
docker compose up -d          # postgres:17 + redis:7 (compose.yaml, not docker-compose.yml)
cp .env.example .env && php artisan key:generate
npm ci && npm run build
```

`C:\xampp\php\php.ini` must have `pdo_pgsql`, `pgsql`, `intl` and `sodium` enabled — the
same file the CLI and Apache share.

## Operating the GitHub side

`gh` is **not** authenticated on this machine. The git credential helper holds a PAT that
works against the API: `git credential fill` with `protocol=https` / `host=github.com`
returns it. Use it as `Authorization: Bearer <token>` without ever printing it.

**Gotcha that costs an hour:** PowerShell 5.1's `ConvertTo-Json` escapes the 📍 emoji as
a surrogate pair (`\ud83d\udccd`) and the GitHub API answers **HTTP 500**. Build the
payload with raw UTF-8 (PHP `json_encode($data, JSON_UNESCAPED_UNICODE)`) or drop the
emoji.

## Known gaps

- **No `README.md`** yet — it is deliverable **E9** of PR18, together with the CI
  preconditions and the `pint --test` fix.
- The CI workflows run `vendor/bin/pint` **without** `--test`, so the formatter rewrites
  instead of failing the gate, and `pest` runs before `typecheck`. Both are fixed in
  **PR18** (task 0.9.8).
- `tasks.md` still carries the old 16-PR dependency diagram and Chain Context table
  next to the authoritative 18-PR one.
- The opening balances (B1) must be **certified by a person** (`certified_at` +
  `certified_by`); no code can close that.
