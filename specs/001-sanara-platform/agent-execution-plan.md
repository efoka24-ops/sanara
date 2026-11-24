# Sanara Multi-Agent Execution Plan (4 agents)

Date: 2026-11-24
Source of truth: specs/001-sanara-platform/tasks.md

## 1) Current starting point (verified)

- Uncommitted baseline work exists and matches your brief:
  - apps/api/database/migrations/0006_stock.sql
  - apps/api/app/Common/Tenancy/TenantTables.php
  - apps/api/app/Common/Rbac/DefaultRoles.php
  - apps/api/app/Modules/Tenants/TenantSetupService.php
- This baseline should be committed first on a dedicated branch before parallel work starts.

## 2) Branch strategy

- Agent 1: feat/core-pos
- Agent 2: feat/desktop-sync
- Agent 3: feat/public-assistant
- Agent 4: feat/platform-final

Rule: one coherent commit at a time, pushed immediately.

## 3) Dependency-critical order

The following order is mandatory:

1. Agent 1 first delivery: T088-T091 (stock schema verification + FEFO logic + stock receive/read API)
2. Agent 1 second delivery: T103-T119 (sales + cash APIs) to unblock US23/T184 and sync assumptions
3. Agent 2 and Agent 3 proceed in parallel on non-blocked tasks while waiting for Agent 1 APIs
4. Agent 4 executes partial-fix backlog and platform quality stream in parallel

## 4) Agent execution slices

### Agent 1 (core POS)

First slice (start now):

- T089 [TDD] shared FEFO tests and logic
- T090 shared stock logic implementation
- T091 stock receive + consultation API

Concrete files for first slice:

- packages/shared/src/stock/fefo.spec.ts
- packages/shared/src/stock/index.ts
- packages/shared/src/index.ts
- apps/api/app/Modules/Stock/StockService.php
- apps/api/app/Modules/Http/Services.php
- apps/api/app/Modules/Http/Routes.php
- apps/api/tests/Stock/StockHttpTest.php

Notes:

- Keep stock as movement ledger only.
- No direct state mutation for correction; only reversal entries.

### Agent 2 (desktop + sync)

Independent start (no API dependency):

- T125-T127, T131-T133
- T134 sync scenarios as in-memory tests
- T136, T138, T141, T142

Shared parity requirement with Agent 1:

- Create fixtures in packages/shared/fixtures/*.json
- Use same fixtures from TS tests and PHP tests.

### Agent 3 (public + assistant)

Independent backend-first start:

- T144-T146 (public search read model + API + tests)
- Then T147-T153 (public list/map pages)
- Then T160-T170 and T186-T199 (assistant/presence/tracking streams not blocked by POS)

Critical invariant:

- No regulated product in any automatic quote path.

### Agent 4 (platform/admin/quality)

Start now on high-impact partials:

- Team overrides reload bug (API must return overrides/effective permissions; UI must bind real values)
- T067 preparateur role
- T045 Leaflet in public application form
- T047 captcha on public forms
- T061 remaining setup wizard parts
- T073 SMS 2FA option
- T076 workstation devices lifecycle
- T030 OpenAPI + typed client bootstrap
- T019 seed/reference-catalog closure

## 5) Contract-first coordination points

Before parallel coding, freeze these contracts in specs/001-sanara-platform/contracts/:

- Stock movement kinds and sign conventions
- Lot status lifecycle
- Sync push/pull payloads and idempotency keys
- Device identity payload for desktop
- Public pharmacy search response shape (list + map + pagination)

## 6) Human-blocked tasks (must stay unchecked)

Do not simulate completion:

- T001a (HTTPS certificate)
- T004-T006 (pilot/legal datasets and validations)
- T278 (real pilot)

## 7) Validation gate before every push

- cd apps/api && composer test
- corepack pnpm -r test
- corepack pnpm -r lint
- corepack pnpm -r typecheck
- corepack pnpm exec playwright test

Also keep these green:

- NoRawSqlTest
- TenantIsolationTest

## 8) Done criteria per task

A task can be checked only when all are true:

- Code implemented
- Tests added and passing
- FR/EN parity respected where user-visible
- tasks.md updated in same commit
- No violation of tenancy/security constraints

## 9) Immediate kickoff checklist (today)

1. Commit baseline stock migration and tenancy/rbac updates on feat/core-pos
2. Agent 1 implements T089-T091 slice with TDD
3. Agent 2 starts T125/T127/T133/T134 in parallel
4. Agent 3 starts T144-T146 backend contracts/tests
5. Agent 4 fixes team overrides bug + T067 first
6. Rebase shared files frequently (Routes.php, Services.php, tasks.md, i18n files)
