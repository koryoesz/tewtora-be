# CLAUDE.md

Guidance for Claude Code (and any other agent reading this) when working in the Tewtora backend repository. Read this before making changes — it encodes decisions already made in the product, architecture, and database design docs, not preferences to re-litigate per task.

## What this is

Tewtora is a managed tutoring marketplace: parents (or independent adult students) get matched with verified teachers for 1-on-1 or small-group online lessons. The backend is Laravel/PHP, MySQL, and a queue-backed event flow between domains.

Reference docs (keep these in `docs/` in this repo, and treat them as source of truth, not this file):
- `docs/database-design.md` — full schema, keys, constraints, indexing strategy
- `docs/microservices-architecture.md` — service boundaries, saga, event catalog, payment provider integration
- `docs/backend-engineering-standards.md` — the detailed version of the conventions summarized below

If something in this file and one of those docs conflict, the doc wins and this file needs updating.

## Architecture at a glance

Four logical service boundaries; two deployed at launch:

- **Deployed separately: Payment** — `payments`, `payouts`, `payment_methods`, `payment_providers`. Isolated for compliance and blast-radius reasons. Never merge this back into the bundle below, even under deadline pressure.
- **Deployed together, three internal modules: Auth & Onboarding, Recommendation, Main/Core.**
  - Auth & Onboarding: `accounts`, `learner_profiles`, `assessments`, `teachers` (profile half), verification.
  - Recommendation: `matches`, plus local read-model copies of learner/teacher data (never a live query into Auth's tables).
  - Main/Core: `sessions`, `feedback`, `progress_reports`, `ratings`.

**Hard rule:** no domain queries another domain's Eloquent models directly, even though they currently share a process and a MySQL instance. Cross-domain data needs go through that domain's service interface, or an event. This is what makes the later split into real separate services a redeploy, not a rewrite — treat the module boundary as if the network call already existed.

## Directory structure

```
app/
  Domains/
    Auth/            Recommendation/     Core/              Payment/
      Models/           Models/            Models/            Models/
      Repositories/     Repositories/      Repositories/      Repositories/
      Services/         Services/          Services/          Services/
      Policies/         Policies/          Policies/          Policies/
      Http/             Http/              Http/              Http/
      Events/           Events/            Events/            Events/
      Mail/             Mail/              Mail/              Mail/
  Shared/
    Exceptions/       # AppException base + Handler
    Logging/          # channel config, Auditable trait
    Support/          # BaseRepository, response envelope helpers
```

A new table or feature goes into exactly one `Domains/{X}` folder. If you're unsure which domain a new table belongs to, check the ownership table in `docs/microservices-architecture.md` before creating it — don't guess.

## Required patterns (see the skill for the step-by-step)

- **Repository pattern** — every Eloquent model has an interface + implementation; controllers/services depend on the interface, never call `Model::query()` directly.
- **Custom exceptions** — every business-rule failure is a named subclass of `Shared\Exceptions\AppException`, not a generic `Exception` or an inline `abort()`. See the exception table in `docs/backend-engineering-standards.md` §3 for the ones already defined — extend that table, don't duplicate a rule under a new name.
- **Authorization via Policies** — `LearnerProfilePolicy`, `SessionPolicy`, `PaymentPolicy`, etc. This is where "a managed child can see feedback but never decide anything" and "the payer is always the account owner" are actually enforced. Add a global Eloquent scope on any new model that's owned by an account, so a missing `->where()` fails closed.
- **Money** — always `amount_minor` (BIGINT, smallest currency unit) + `currency_code`. Never a float, never a pre-formatted string in the database. Format to major units only at the view/API-resource/email layer.
- **Soft deletes** — only on `accounts`, `learner_profiles`, `teachers`. Never add `SoftDeletes` to `sessions`, `payments`, `payouts`, `feedback`, or `ratings` — those are append-only; state changes are status transitions, not deletions.
- **IDs** — every table exposes `public_id` (UUID) externally; `id` (BIGINT) never leaves the API response. Use `public_id` in routes and API Resources.
- **Events & the outbox** — anything another domain needs to react to is written to that domain's `outbox_events` table in the same transaction as the state change, then relayed by the `outbox:relay` scheduled command. Don't publish directly to the queue from inside a request — that's exactly the failure mode the outbox pattern exists to close.
- **Idempotency** — any listener/job consuming an event assumes at-least-once delivery. Check current state before transitioning; don't assume an event arrives exactly once. Payment webhook handling in particular relies on the unique `(provider_id, provider_reference)` constraint — never bypass it "just this once."

## Error responses

Every API error, from any domain, uses the same envelope:

```json
{ "error": { "code": "teacher_not_verified", "message": "...", "request_id": "req_..." } }
```

`code` comes from the exception's `errorCode()`. Validation errors are the one exception with a different shape (`fields` map). Anything else reaching the client as `internal_error` is a bug — it should be logged with full context, not just returned generically and forgotten.

## Logging

Per-domain channels (`auth`, `recommendation`, `core`, `payment`, `audit`). Every request gets an `X-Request-Id`, pushed into log context, and carried through to any cross-domain call. The `audit` channel is append-only and covers writes to `learner_profiles`, `payments`, and `teachers.verification_status` — this is a compliance requirement, treat it as one (don't rotate it out, don't skip it to save a query).

Never log: password hashes, card data (shouldn't exist here at all), webhook secrets, a full `date_of_birth` outside the audit channel.

## Commands you'll actually run

```bash
composer install
cp .env.example .env && php artisan key:generate
php artisan migrate
php artisan test                 # full suite
php artisan test --filter=Core   # one domain
./vendor/bin/pint                # code style — run before committing
php artisan outbox:relay         # manual trigger for local testing of async events
```

## Before you open a PR

- New table → migration, model, repository interface + implementation, policy (if account-owned), API Resource, Form Request, feature test. All in the same domain folder.
- New cross-domain interaction → check whether it's sync (user is waiting) or async (event via outbox) per the pattern table in `docs/microservices-architecture.md` §3 — don't default to a direct call because it's easier to write.
- `./vendor/bin/pint` and `php artisan test` both pass.
- If you touched anything in `Domains/Payment`, double-check the change doesn't widen what any other domain can reach inside it — that network segment is deliberately the narrowest of the four.
