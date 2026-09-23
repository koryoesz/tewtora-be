# Backend Engineering Standards — Laravel Implementation

Applies to all four logical services (Auth & Onboarding, Recommendation, Main/Core, Payment), whether deployed as the initial two-service bundle or split later. These are the conventions every module follows so the codebase stays consistent as it grows and eventually splits.

---

## 1. Project structure — domain-first, not framework-first

Laravel's default `app/Http`, `app/Models` layout encourages everything to know about everything else, which is exactly what the microservices architecture doc warns against ("no cross-service foreign keys, no live joins"). Structure by domain instead, so a module boundary in code maps to a service boundary later:

```
app/
  Domains/
    Auth/            # accounts, learner_profiles, assessments, teachers (profile half)
    Recommendation/  # matches, local read models
    Core/             # sessions, feedback, progress_reports, ratings
    Payment/          # payments, payouts, payment_providers
  Shared/
    Exceptions/
    Logging/
    Support/          # base repository, base service, response envelope
```

Each `Domains/{X}` folder owns its own `Models`, `Repositories`, `Services`, `Http/Controllers`, `Http/Requests`, `Http/Resources`, `Events`, `Listeners`, and `Notifications`. A domain **never** imports another domain's Eloquent models directly — it depends on that domain's **service interface** instead. This is the code-level enforcement of the "no live joins across service boundaries" rule from the architecture doc, and it's what makes the later split into real services a redeploy, not a rewrite.

---

## 2. Repository pattern

Every table from the database design gets a repository interface + Eloquent implementation. Controllers and services never call `Model::query()` directly.

```php
// app/Domains/Core/Repositories/SessionRepositoryInterface.php
interface SessionRepositoryInterface
{
    public function find(int $id): ?Session;
    public function findByPublicId(string $publicId): ?Session;
    public function upcomingFor(int $learnerProfileId): Collection;
    public function create(array $data): Session;
    public function transitionStatus(Session $session, string $status): Session;
}

// app/Domains/Core/Repositories/EloquentSessionRepository.php
class EloquentSessionRepository implements SessionRepositoryInterface
{
    public function find(int $id): ?Session
    {
        return Session::find($id);
    }
    // ...
}
```

Bind interfaces to implementations in each domain's service provider (`AuthServiceProvider`, `RecommendationServiceProvider`, `CoreServiceProvider`, `PaymentServiceProvider`) — not one giant `AppServiceProvider`, so each domain's bindings travel with it when it's eventually extracted.

**Why this matters here specifically:** the database design already separates "owns" from "reads" (e.g. Recommendation keeps a local `learner_profile_view` rather than querying Auth's table). A repository interface is the natural seam for that — `LearnerProfileReadRepository` in the Recommendation domain reads from the local projection, while `LearnerProfileRepository` in the Auth domain owns the real table. Swapping the projection's storage (cache, search index, different DB) later never touches calling code.

A thin `BaseRepository` (in `Shared/Support`) can provide `find`, `create`, `update`, `delete` generics — but domain repositories should still expose intention-revealing methods (`upcomingFor`, `pendingVerification`, `withOpenSlot`) rather than leaking raw query building into services.

---

## 3. Custom exception classes

One base exception, domain-specific subclasses, and a single place that turns any of them into an HTTP response — mirroring the "flag the trade-off, don't silently fix it" discipline from the database and architecture docs: an exception name should say exactly what business rule was violated.

```php
// app/Shared/Exceptions/AppException.php
abstract class AppException extends Exception
{
    abstract public function statusCode(): int;
    abstract public function errorCode(): string;      // machine-readable, e.g. 'teacher_not_verified'
    public function context(): array { return []; }     // extra fields for logging, never sent to the client
}
```

Domain exceptions, one per business rule that can realistically fail — pulled straight from what's already been specified:

| Exception | Domain | Raised when |
|---|---|---|
| `ConsentRequiredException` | Auth | Assessment submitted for a child profile without consent |
| `TeacherNotVerifiedException` | Auth | A match/session attempted against an unverified teacher |
| `MatchAlreadyDecidedException` | Recommendation | Accept/decline called on a match not in `proposed` |
| `SessionNotReschedulableException` | Core | Reschedule attempted inside the cancellation window |
| `PaymentProviderException` | Payment | Adapter call to Paystack/Flutterwave/Stripe fails |
| `DuplicateWebhookException` | Payment | `(provider_id, provider_reference)` already processed |
| `UnauthorizedPayerException` | Payment | Payment attempted by an account that isn't the profile's owner |

A single `Shared/Exceptions/Handler.php` (extending Laravel's) catches `AppException` and renders the standard error envelope (section 6) — domain code just throws, it never formats HTTP responses itself.

---

## 4. Authentication & authorization (Laravel Sanctum)

**Token generation:** use **Sanctum**, not Passport — there's no third-party OAuth client consuming this API, just first-party web/mobile clients, which is exactly what Sanctum is built for. Issue personal access tokens on login, scoped by ability where it matters:

```php
$token = $account->createToken('web', match ($account->account_type) {
    'parent', 'independent_student' => ['learner:*', 'session:*', 'payment:pay'],
    'teacher' => ['match:respond', 'session:*', 'feedback:submit', 'payout:read'],
});
```

**Authorization is policy-based, and encodes the account-model rules directly:**

```php
// app/Domains/Auth/Policies/LearnerProfilePolicy.php
class LearnerProfilePolicy
{
    public function view(Account $account, LearnerProfile $profile): bool
    {
        return $profile->owner_account_id === $account->id
            || $profile->linked_login_account_id === $account->id; // view-only child login
    }

    public function manage(Account $account, LearnerProfile $profile): bool
    {
        // Only the owner can book, pay, or edit — never the linked view-only login.
        return $profile->owner_account_id === $account->id;
    }
}
```

This is the code-level twin of the database's `payer_account_id → accounts` rule and the `linked_login_account_id` split — **the policy is where "a managed child can see but never decide" actually gets enforced**, not just documented.

Add a global Eloquent scope on `LearnerProfile` and `Session` restricting queries to the authenticated account's own/linked profiles by default, so a missing `->where()` in a new endpoint fails closed, not open. Staff/admin bypass goes through a dedicated Gate (`Gate::define('staff-access', ...)`), never by omitting the scope.

**Middleware**, applied in route groups per account type:
- `auth:sanctum` — base authentication
- `ability:learner:*` / `ability:match:respond` etc. — token scope check
- `account.type:teacher` — hard account-type gate for teacher-only routes, in addition to policies

---

## 5. Email templating

Use Laravel **Mailables** with Markdown mail templates (`Illuminate\Mail\Mailable` + `markdown()`), queued by default — never sent synchronously in a request. One Mailable per event that genuinely needs to notify a human, mapped from the event catalog already defined:

| Mailable | Triggered by | Recipient |
|---|---|---|
| `TeacherVerificationDecision` | `verification_status` changes | Teacher |
| `MatchRecommended` | New `matches` row created | Parent / independent student |
| `TrialConfirmed` | Trial session booked | Parent/student + teacher |
| `PaymentReceipt` | `PaymentSucceeded` | Payer account |
| `PaymentFailed` | `PaymentFailed` | Payer account |
| `SessionReminder` | Scheduled job, N minutes before `scheduled_at` | Parent/student + teacher |
| `FeedbackAvailable` | `feedback` row created | Parent / independent student |
| `PayoutProcessed` | `payouts.status = 'paid'` | Teacher |

Each Mailable's Blade template lives under its owning domain (`app/Domains/Payment/Mail/PaymentReceipt.php` + matching Markdown view), not a shared global `resources/views/emails` dump — keeps the domain boundary intact for email too. A shared base layout (`Shared/Mail/layout.blade.php`) provides brand-consistent header/footer (logo, colors) so individual templates don't each reimplement it.

Money and dates in emails are formatted at render time only, from the stored `amount_minor`/`currency_code` — never pre-formatted and stored, per the database design's money-handling rule.

---

## 6. Error handling & response envelope

One consistent JSON shape for every error response, regardless of which domain threw it:

```json
{
  "error": {
    "code": "teacher_not_verified",
    "message": "This teacher hasn't completed verification yet.",
    "request_id": "req_01HXYZ..."
  }
}
```

- `code` — machine-readable, stable, safe to branch on in a client. Comes from `AppException::errorCode()`.
- `message` — human-readable, client-safe. Never a raw exception message or SQL error.
- `request_id` — correlates the response to a log line (section 7); always returned, even on success, as a response header (`X-Request-Id`), so support can find the exact log entry for any report a parent or teacher sends in.

Laravel's default validation exceptions (`ValidationException`) get their own envelope shape (`error.code = "validation_failed"`, plus a `fields` map) — don't force them into the same shape as domain exceptions, they're a different kind of failure.

**Rule:** any exception that isn't an `AppException` or a framework validation exception is a bug, not a business rule — log it with full context and return a generic `internal_error` to the client. Never let an uncaught exception's message reach a response body in production.

---

## 7. Logging

Structured (JSON) logging, one custom channel per domain, so log volume and access can be scoped independently — the Payment channel, in particular, should be more restricted than the others given what the database design already flags about PII/compliance.

```php
// config/logging.php
'channels' => [
    'auth'           => ['driver' => 'daily', 'path' => storage_path('logs/auth.log')],
    'recommendation' => ['driver' => 'daily', 'path' => storage_path('logs/recommendation.log')],
    'core'           => ['driver' => 'daily', 'path' => storage_path('logs/core.log')],
    'payment'        => ['driver' => 'daily', 'path' => storage_path('logs/payment.log'), 'days' => 90],
    'audit'          => ['driver' => 'daily', 'path' => storage_path('logs/audit.log'), 'days' => 365],
],
```

**Request correlation:** a middleware (`AssignRequestId`) generates or forwards an `X-Request-Id` early in the pipeline and pushes it into every log line for that request (`Log::withContext(['request_id' => $id])`) — this is the practical, in-code version of the "distributed tracing is not optional once a booking spans four services" note from the architecture doc. Pass the same ID through to any outbound call to another domain/service.

**Audit logging** is its own channel and, per the database design's security section, its own append-only table (`audit_log`) — not just a log file. A `Shared/Logging/Auditable` trait or model observer writes to it on every write to `learner_profiles`, `payments`, and `teachers.verification_status`: actor account, action, before/after values, timestamp. This is a compliance requirement, not a debugging convenience — treat it as such (no soft-delete, no rotation-based deletion).

**What never gets logged:** password hashes, full payment card data (shouldn't exist in this system at all, per the database design), raw webhook secrets, a child's full `date_of_birth` in a general-purpose log line (audit log is the one place it's justified, and even there, access-controlled).

**Log levels:** `info` for normal state transitions (session confirmed, payment succeeded), `warning` for recoverable/expected failures (payment declined, teacher declined a match), `error` for anything requiring engineering attention (unhandled exception, failed outbox publish), `critical` reserved for anything touching money or a stuck saga step.

---

## 8. Async work: queues, jobs, and the outbox relay

The saga and outbox pattern from the architecture doc need real queue infrastructure, not synchronous calls:

- Laravel Queues (Redis or SQS driver) for all cross-domain side effects — sending emails, publishing outbox events, calculating payouts.
- A scheduled Artisan command (`outbox:relay`), run every few seconds via the scheduler or a dedicated worker, that reads unpublished rows from each domain's `outbox_events` table and publishes them to the broker — implementing the relay process the architecture doc specifies, as actual code rather than a diagram.
- Every Job class that consumes an event must be **idempotent** — checked against the "at-least-once delivery" assumption in the event catalog. A `PaymentSucceededListener` that updates `sessions.status` should check current status before transitioning, not blindly overwrite it, so a redelivered event is a no-op the second time.
- Failed jobs go to Laravel's `failed_jobs` table with retry/backoff configured per job type; anything touching Payment gets alerting on failure, not just silent retry.

---

## 9. Validation

Form Requests per endpoint, not inline `$request->validate()` — keeps validation rules testable and reusable. Custom rules for the business logic the database design already encodes as constraints, so the same rule isn't defined twice and drifting:

```php
class ConsentRequiredIfMinor implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->learnerProfile->profile_type === 'child' && ! $value) {
            $fail('Parental consent is required for a child profile.');
        }
    }
}
```

---

## 10. API resources & versioning

- Laravel API Resources (`JsonResource`) for every response — never return an Eloquent model directly, so internal columns (`id`, soft-delete state) never leak into a response by accident. Expose `public_id`, not `id`, per the database design's key-strategy section.
- Version the API from day one (`/api/v1/...`) even with one client — the cost of adding it later, once mobile apps are in the wild pinned to unversioned routes, is much higher than doing it now.

---

## 11. Testing

- Repository interfaces make services testable without a database — mock the interface in unit tests for business logic (matching rules, saga transitions, consent checks).
- Feature tests hit real routes against a test database per domain, verifying policies and the error envelope shape, not just happy paths.
- The saga (section 8) gets explicit tests for the failure branch — a `PaymentFailed` event must be proven to cancel the session, not just assumed to.

---

## 12. Things flagged for a decision, not assumed here

- **Sanctum vs. a full OAuth2 setup** — Sanctum is recommended above because there's no current third-party API consumer; revisit if Tewtora ever exposes an API to school/corporate partners directly.
- **Single Laravel app vs. four** — this document assumes the architecture doc's "bundle three, isolate Payment" recommendation; if Payment is built as a fully separate Laravel app, it needs its own copy of the Shared exception/logging conventions, not a shared package, unless a private Composer package is set up for that purpose.
- **Log retention and access control specifics** (who can read the `payment` and `audit` channels) should be confirmed with whoever owns compliance before launch — the structure above supports restricting it, but the actual access policy isn't a code decision.
