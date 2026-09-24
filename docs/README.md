# Documentation Index

Online Marketplace and Customer Retention Platform — working codename **SokoLink** (placeholder).

## Phase 0 deliverables

| Document | What it answers | Read it if you want to check |
|---|---|---|
| [PROJECT_REQUIREMENTS.md](PROJECT_REQUIREMENTS.md) | What we are building and what we are not | Scope, 120+ numbered requirements, assumptions, the 10 open questions |
| [SYSTEM_ARCHITECTURE.md](SYSTEM_ARCHITECTURE.md) | How it is structured and why | Layers, folder structure, module dependencies, **order lifecycle diagrams**, security architecture |
| [USER_ROLES_AND_PERMISSIONS.md](USER_ROLES_AND_PERMISSIONS.md) | Who can do what, and what data they can see | Full permission matrix, data-visibility boundaries, seed accounts |
| [USER_FLOWS.md](USER_FLOWS.md) | How each journey actually runs | Flows A–O with failure branches, the ~85-screen inventory |
| [DEVELOPMENT_ROADMAP.md](DEVELOPMENT_ROADMAP.md) | The plan and the exit criteria per phase | Phase breakdown, risk register, what is needed to start Phase 1 |
| [DESIGN_SYSTEM.md](DESIGN_SYSTEM.md) | The visual language | Tokens, two-track rules, component mapping, accessibility adaptations |
| [reference/DESIGN.source.md](reference/DESIGN.source.md) | The unmodified source design spec you supplied | Original tokens, for diffing against our adaptation |

## Suggested reading order for review

1. `PROJECT_REQUIREMENTS.md` §7 Assumptions and §8 Open questions — the decisions that need you.
2. `SYSTEM_ARCHITECTURE.md` §6 Order lifecycle — the core model everything else rests on.
3. `DESIGN_SYSTEM.md` §5 and §7 — the two places I deliberately depart from the supplied design.
4. `DEVELOPMENT_ROADMAP.md` — the note on phase ordering, and the Phase 1 scope question at the end.

## Phase 1 deliverable

| Document | What it answers |
|---|---|
| [FRONTEND_PAGES.md](FRONTEND_PAGES.md) | All 85 page templates, how to reach every state, the Phase 4 data contracts, and the full verification record (including the 17 bugs the audits caught) |

## Phase 2 deliverables

| Document | What it answers |
|---|---|
| [DATABASE_DESIGN.md](DATABASE_DESIGN.md) | Why the schema is shaped this way — the four guarantees enforced in storage, the two-level order model, timezone and money conventions, what is deliberately **not** stored — then all 44 tables column by column, generated from the live database |
| [ER_DIAGRAM.md](ER_DIAGRAM.md) | Nine entity-relationship diagrams (spine, then eight domains), all 75 foreign keys, and the `ON DELETE` policy with the reasoning behind each group |
| [../database/README.md](../database/README.md) | How to import, verify, reset and back up; the 14 test accounts; the connection settings the application **must** use |

Also in Phase 2: `database/schema.sql`, `database/seed.sql`,
`tests/Concurrency/oversell_test.php`, `tests/Integration/schema_contracts_test.php`.

### Suggested reading order for Phase 2 review

1. `DATABASE_DESIGN.md` §2 — the four things the storage layer guarantees, and how each is proved.
2. `DATABASE_DESIGN.md` §3 — the parent-order / seller-sub-order split. Everything else follows from it.
3. `DATABASE_DESIGN.md` §6 and §7 — oversell prevention and the retention engine, the two hardest requirements.
4. `ER_DIAGRAM.md` §10 — the `ON DELETE` policy, which is where data loss would come from if it were wrong.
5. Run both test scripts yourself. `database/README.md` §4 says how.

## Phase 4 deliverables (current)

| Document | What it answers |
|---|---|
| [INTEGRATION.md](INTEGRATION.md) | How a screen reaches the database: the presenter layer, the write path, what the browser is not trusted with, and exactly which screens are wired and which still show sample data |

## Phase 3 deliverables

| Document | What it answers |
|---|---|
| [BACKEND_MODULES.md](BACKEND_MODULES.md) | Every service and repository: what it does, what it **refuses** to do, where authorisation actually happens, the scheduled tasks and their schedule, and what the assertions cover |

Also in Phase 3: `app/Core/` (database, auth, validation, middleware, audit),
`app/Domain/` (35 enums and the order state machine), `app/Repositories/` (18),
`app/Services/` (20), `bin/console.php`, and 8 new test suites.

### Suggested reading order for Phase 3 review

1. `BACKEND_MODULES.md` § "Where authorisation actually happens" — the three layers and why all three are needed.
2. `InventoryService::reserveAll()` — the oversell guard as code, and the deterministic lock order.
3. `app/Domain/OrderStateMachine.php` — the transition table, read as a table.
4. `PaymentService::confirmPayment()` — the four rules that stop an order being told it was paid.
5. `Retention/ConsumptionEstimator.php` — ranked evidence, and the branch that schedules nothing.
6. Run `php tests/run.php` yourself.

## Written in later phases

`TEST_PLAN.md` · `SECURITY_REVIEW.md` · `TEST_RESULTS.md` (Phase 5) ·
`INSTALLATION.md` · `CONFIGURATION.md` · `CRON_SETUP.md` · `TROUBLESHOOTING.md` ·
`BACKUP_RESTORE.md` · `PRODUCTION_CHECKLIST.md` · `TEST_ACCOUNTS.md` (Phase 6)

## Viewing the diagrams

All diagrams are Mermaid inside fenced code blocks. They render in GitHub, in VS Code with the
Markdown Preview Mermaid Support extension, and at <https://mermaid.live> by pasting a block.
