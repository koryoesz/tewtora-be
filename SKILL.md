---
name: tewtora-backend-domain
description: Use when creating or modifying anything in the Tewtora Laravel backend — a new table/entity, an API endpoint, a cross-domain event, or any code touching repositories, policies, exceptions, money, or logging. Triggers on requests like "add a new endpoint for X", "create a migration for Y", "add a policy/repository for Z", or any work inside app/Domains/. Encodes the project's required patterns (see CLAUDE.md and docs/backend-engineering-standards.md) so new code matches the rest of the codebase instead of reinventing structure per task.
---

# Tewtora backend domain module skill

Follow this checklist for any change inside `app/Domains/{Auth,Recommendation,Core,Payment}`. It exists so that "add a new feature" produces the same shape of code every time, regardless of which session or which agent wrote it.

## Step 0 — Confirm the domain

Before writing anything, confirm which domain owns the table/feature using the ownership table in `docs/microservices-architecture.md` §2. If it's genuinely new (not in that table), stop and ask rather than guessing — placing a table in the wrong domain is expensive to undo once other code depends on it.

## Step 1 — Migration

- New table: follow the key-strategy convention exactly — `id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY`, `public_id CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE`, `created_at`/`updated_at` as `DATETIME(6)` (`updated_at` uses `ON UPDATE CURRENT_TIMESTAMP(6)`, no trigger needed).
- Money: `amount_minor BIGINT` + `currency_code CHAR(3)`. Never a float column.
- Only add `deleted_at DATETIME(6)` if this table is `accounts`, `learner_profiles`, or `teachers`. Everything else is append-only — model state with a `status` column and a `CHECK` constraint instead.
- Foreign keys: `ON DELETE RESTRICT` by default. Only `CASCADE` for true ownership compositions (a child row with no independent meaning, e.g. `teacher_availability`). Only `SET NULL` where the child record should survive its parent's removal as history (e.g. `sessions.match_id`).
- Index every foreign key column. Add a composite/partial index only for a query pattern that's actually needed — check `docs/database-design.md` §4 for the existing index list before adding a new one that might already be covered.

## Step 2 — Model

- Lives in `app/Domains/{X}/Models/`.
- `protected $fillable` explicit, never `$guarded = []`.
- Cast `amount_minor` as integer, JSON columns as `array`, enums-as-CHECK-constraint columns as plain strings validated at the Form Request layer, not as PHP enums the DB doesn't know about (unless you also add the matching CHECK — keep them in sync).
- Add the global scope restricting queries to the owning account, if this model is account-owned (see `LearnerProfilePolicy` pattern in `docs/backend-engineering-standards.md` §4).

## Step 3 — Repository

- Interface in `Repositories/`, e.g. `SessionRepositoryInterface`.
- Eloquent implementation, e.g. `EloquentSessionRepository`.
- Bind it in that domain's service provider, not a shared `AppServiceProvider`.
- Method names are intention-revealing (`upcomingFor`, `pendingVerification`), not generic query builders exposed as public methods.
- If another domain needs to read this data, add a **separate read-only repository** for that projection in the *consuming* domain (e.g. `Domains/Recommendation/Repositories/LearnerProfileReadRepository`) backed by a local, event-synced table — never let another domain call this repository directly.

## Step 4 — Service layer

- Business logic (the actual rules — "can this session be rescheduled", "does this match need to fall back to recommend-again") lives in a `Services/` class, not in the controller and not in the model.
- The service depends on repository *interfaces*, injected via the constructor — never resolves them from the container manually mid-method.
- If the action changes state that another domain cares about, write the outbound event to this domain's `outbox_events` table inside the same DB transaction as the state change. Do not publish directly to the queue.

## Step 5 — Exceptions

- Any way this feature can legitimately fail as a business rule (not a framework validation failure) gets a named subclass of `Shared\Exceptions\AppException`.
- Check the existing exception table in `docs/backend-engineering-standards.md` §3 first — extend it, don't create a second exception for a rule that already has one.
- Every new exception implements `statusCode()`, `errorCode()`, and, if useful for debugging, `context()` (never sent to the client, only logged).

## Step 6 — Authorization

- If the resource is owned by an account (directly or via `owner_account_id`), add or extend a Policy — don't rely on the controller checking `if ($resource->owner_id !== $user->id)` inline.
- Confirm: can a linked-login (view-only) child account reach this action? It should only ever pass a `view`-type policy method, never a `manage`-type one.
- Confirm: is the payer/actor always resolved from the account, never asked as a free-form parameter? (Mirrors the database's `payer_account_id` rule.)

## Step 7 — API layer

- Form Request per endpoint for validation; custom `ValidationRule` classes for anything that mirrors a DB constraint (e.g. consent-required-if-minor), so the rule is defined once conceptually even though it's enforced in two places (DB + app).
- API Resource class for the response — expose `public_id`, never `id`.
- Route goes under `/api/v1/...` inside that domain's route file, grouped with the correct `auth:sanctum` + `ability:` + (if teacher-only) `account.type:` middleware.

## Step 8 — Email (only if this action should notify someone)

- Check the Mailable table in `docs/backend-engineering-standards.md` §5 first — most notification-worthy events already have a defined Mailable and recipient.
- New Mailable lives under `Domains/{X}/Mail/`, extends the shared base layout, is queued (`implements ShouldQueue`), and formats money/dates at render time from raw `amount_minor`/`currency_code` — never from a pre-formatted stored string.

## Step 9 — Logging

- Nothing extra needed for normal request logging (the `X-Request-Id` middleware handles correlation automatically).
- If this action writes to `learner_profiles`, `payments`, or `teachers.verification_status`, confirm the `Auditable` trait/observer is firing — don't add a manual audit log call if the trait already covers it.

## Step 10 — Tests

- Feature test: happy path, policy-denied path, validation-failure path.
- If this introduces or consumes an event: a test proving idempotency (call the listener/job twice with the same payload, assert no duplicate side effect).
- Run `./vendor/bin/pint --test` and `php artisan test --filter={Domain}` before considering the change done.

## Red flags — stop and reconsider if you notice yourself doing any of these

- Importing a model from a different `Domains/{X}` folder.
- Adding `SoftDeletes` to a transactional table (`sessions`, `payments`, `payouts`, `feedback`, `ratings`).
- A money column typed as `float`/`decimal` instead of `amount_minor` integer.
- Publishing to the queue directly from a controller instead of via the outbox table.
- Returning `id` instead of `public_id` from an API Resource.
- Writing authorization logic inline in a controller instead of in a Policy.
