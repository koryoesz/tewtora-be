# AGENTS.md

Operational reference for any AI coding agent working in this repository (Claude Code, Codex, Cursor, or otherwise). For the architectural reasoning behind these rules, see `CLAUDE.md` and `docs/`. This file is the quick-reference version: what to run, what to check, what never to do.

## Project

Tewtora backend — Laravel/PHP, MySQL, Redis-backed queues, RabbitMQ for cross-service outbox events. A managed tutoring marketplace matching parents/students with verified teachers. Four logical service domains (`Auth`, `Recommendation`, `Core`, `Payment`), two deployed units at present (`Payment` standalone; the other three bundled).

## Setup

```bash
composer install
cp .env.example .env
php artisan key:generate
php artisan migrate --seed
php artisan serve
```

Requires PHP 8.2+, MySQL 8.0.16+ (enforced `CHECK` constraints need 8.0.16+; `DEFAULT (UUID())` needs 8.0.13+), Redis (each app's own internal queue — mail, etc.), RabbitMQ (the shared broker `outbox:relay`/`outbox:consume` use for cross-service events — see the repo-root `docker-compose.yml`, not either project's own), and a `.env` with payment provider sandbox keys for `Domains/Payment` work (see `.env.example` for the required `PAYSTACK_*` / `FLUTTERWAVE_*` keys — never commit real keys, sandbox only in this repo).

## Dev environment tips

- Work inside one `Domains/{X}` folder per change where possible. If a task genuinely needs changes in two domains, that's a signal to check whether it should be an event instead of a direct call — see `docs/microservices-architecture.md` §3 before wiring a new synchronous dependency between domains.
- Don't add a new top-level `app/Models` or `app/Http/Controllers` — everything domain-owned lives under `app/Domains/{X}/...`. Only `app/Shared` is exempt, and only for genuinely cross-cutting concerns (exceptions, logging, base repository).
- Local async/event testing: run `php artisan outbox:relay` manually rather than waiting on the scheduler.

## Testing instructions

```bash
php artisan test                       # full suite
php artisan test --filter=Payment      # one domain
php artisan test --testsuite=Feature   # feature tests only
```

- Every new endpoint needs a feature test covering: the happy path, the policy-denied path (wrong account trying to act on a resource that isn't theirs), and the validation-failure path.
- Every new event listener/job needs a test proving it's idempotent — call it twice with the same payload, assert the second call is a no-op, not a duplicate side effect.
- The booking saga's failure branch (`PaymentFailed` → session cancelled, not left `pending`) has an existing test in `Domains/Core/Tests` — if you touch saga-adjacent code, run that test explicitly and don't just trust the full suite to catch a regression there.

## Code style

```bash
./vendor/bin/pint          # auto-fix
./vendor/bin/pint --test   # check only, CI uses this
```

PSR-12 via Laravel Pint, project defaults, no custom ruleset. Run before every commit — CI will reject unformatted code.

## PR instructions

- Title format: `[Domain] short description` — e.g. `[Payment] add Flutterwave fallback adapter`.
- One domain per PR where possible. A PR touching more than one domain should explain in its description why the change couldn't be split (usually: it's adding a new event contract, which legitimately needs both the publisher and consumer side updated together).
- Include the migration in the same PR as the code that needs it — never a "schema now, code later" PR.
- Run `./vendor/bin/pint --test` and `php artisan test` locally before opening; CI re-runs both but don't rely on CI to catch what a 30-second local run would.

## Hard rules — do not do these even if it seems locally reasonable

- No direct Eloquent relationship or query from one `Domains/{X}` folder into another domain's models. Use that domain's service interface or an event.
- No `float`/`decimal` money columns — `amount_minor` (integer) + `currency_code`, always.
- No `SoftDeletes` on `sessions`, `payments`, `payouts`, `feedback`, `ratings` — these are append-only; use a status transition instead.
- No direct-to-queue event publishing from inside a request/controller — write to the domain's `outbox_events` table in the same DB transaction as the state change, let the relay publish it.
- No exposing internal `id` (BIGINT) in any API response — `public_id` (UUID) only.
- No bypassing the `(provider_id, provider_reference)` unique constraint in `Domains/Payment` "temporarily" to unblock a webhook issue — fix the idempotency handling instead.
