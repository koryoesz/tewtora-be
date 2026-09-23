# Microservices Architecture

Backend decomposition — Auth & Onboarding, Recommendation, Main/Core, Payment

> v2.1 note: this doc, and `docs/database-design.md`, were updated to
> implement the gap analysis run against `docs/api-contract.md` (the
> frontend's endpoint/data contract) — see `docs/api-gap-analysis.md` for
> the reasoning behind each addition below. The ownership table (§2), the
> Payment service's ledger (new §7.6), and the event catalog (§5) all
> changed; the saga and outbox mechanics (§4) didn't.
>
> v2.2 note: §4.2's broker was a placeholder ("the broker") until now —
> it's RabbitMQ, concretely, with the reasoning (and why Redis pub/sub was
> rejected) written up there.

---

## 1. Decomposition overview

The backend splits into four services, not three. Auth & Onboarding, Recommendation, and Main/Core form one natural boundary around the product experience; Payment is deliberately isolated as its own service because it carries a different compliance perimeter (PCI-adjacent obligations), a different failure mode (money must never silently double-process), and a different traffic shape (bursty webhook delivery vs. steady booking traffic). Each service owns its own database — no cross-service foreign keys. Anything one service needs to know about another's data arrives as an event or an API call, never a join.

### 1.1 Recommended rollout

- Deploy Payment as its own service and its own database from day one — this is the boundary that matters most and is cheapest to get right early.
- Auth & Onboarding, Recommendation, and Main/Core can ship as one deployable unit with three internal modules and three separate schemas at first — under MySQL, "schema" and "database" are the same thing, so this is three MySQL databases (`auth`, `recommendation`, `core`) on one instance, referenced with database-qualified table names (`auth.accounts`, `core.sessions`, ...). Splitting them into independent deployments later is a redeploy, not a redesign, provided the schema and API boundaries below are respected from day one.

---

## 2. Service boundaries & data ownership

| Service | Owns | Database |
|---|---|---|
| **Auth & Onboarding** | `accounts`, `learner_profiles`, `assessments`, `assessment_subjects`, `teachers`, `teacher_subjects`, `teacher_curricula`, `teacher_availability`, `teacher_verification_checks`, `teacher_time_off`, `safeguarding_incidents`, `audit_log`, `subjects`, `curricula` | MySQL |
| **Recommendation** | `matches`, `recruitment_targets`, plus local read models: `learner_profile_view`, `teacher_profile_view` (synced via events, never queried live from Auth) | MySQL (+ OpenSearch/a vector store if matching becomes similarity-based — MySQL has no pgvector equivalent) |
| **Main / Core** | `plans`, `plan_goals`, `sessions`, `trial_requests`, `move_requests`, `move_approvals`, `feedback`, `progress_reports`, `ratings` | MySQL |
| **Payment** | `payments`, `payouts`, `payment_methods`, `payment_providers`, `payment_quotes`, `payment_line_items`, `commission_tiers`, `payment_audit_log` | MySQL — isolated instance/cluster, minimal IAM surface, append-only ledger discipline |

`teacher_verification_checks`, `audit_log`, and `safeguarding_incidents` (Auth), and `payment_audit_log` (Payment) were added implementing `docs/api-contract.md` §3/§14-18 — see §7.6 and `docs/database-design.md` §3.7 for the DDL. `plans` (§4/§6 below) is the single largest addition: almost every other new table in Core hangs off it.

Recommendation and Main never query Auth's tables directly. Auth publishes `LearnerProfileUpdated` and `TeacherVerified` events; each downstream service keeps only the denormalized fields it actually needs to do its job.

### 2.1 Recommendation's read models

Never DDL'd anywhere before v2.1 — they existed only as migrations with no documentation. `id` mirrors the source row's id in Auth (a mirrored identity, not a live FK across schemas). `account_id` on `teacher_profile_view` was added once the match-accept/decline endpoints needed a policy to decide "is the authenticated account this match's teacher" *without* importing Auth's `Teacher` model into a Recommendation policy — the event catalog's payload list (§5) is illustrative, not exhaustive; this field was always implicitly required for the API to authorize anything against this table.

```sql
CREATE TABLE learner_profile_view (
  id             BIGINT UNSIGNED PRIMARY KEY,
  public_id      CHAR(36) NOT NULL,
  grade_level    VARCHAR(60) NOT NULL,
  curriculum_id  BIGINT UNSIGNED NOT NULL,
  challenges     JSON NOT NULL DEFAULT (JSON_ARRAY()),
  goals          JSON NOT NULL DEFAULT (JSON_ARRAY()),
  preferred_slots JSON NOT NULL DEFAULT (JSON_ARRAY()),
  budget_min_minor BIGINT NOT NULL DEFAULT 0,
  budget_max_minor BIGINT NOT NULL DEFAULT 0,
  subject_ids    JSON NOT NULL DEFAULT (JSON_ARRAY()),
  synced_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6)
) ENGINE=InnoDB;

CREATE TABLE teacher_profile_view (
  id                    BIGINT UNSIGNED PRIMARY KEY,
  public_id             CHAR(36) NOT NULL,
  account_id            BIGINT UNSIGNED NOT NULL,  -- v2.1: see note above
  verification_status   VARCHAR(10) NOT NULL CHECK (verification_status IN ('pending','approved','rejected')),
  subject_ids           JSON NOT NULL DEFAULT (JSON_ARRAY()),
  curriculum_ids        JSON NOT NULL DEFAULT (JSON_ARRAY()),
  synced_at             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY ix_teacher_profile_view_verification_status (verification_status),
  KEY ix_teacher_profile_view_account (account_id)
) ENGINE=InnoDB;
```

---

## 3. Inter-service communication

| Interaction | Pattern | Why |
|---|---|---|
| Client → any service | Sync REST/gRPC via API gateway | Normal request/response; the user is waiting. |
| Auth → Recommendation | Async event (`LearnerProfileUpdated`, `TeacherVerified`) | Not time-critical; decouples Recommendation's availability from Auth's. |
| Recommendation → Main (match accepted) | Sync call to create a pending session | Simplest correctness story for the first hop of the saga; see §4. |
| Main → Payment (session booked) | Sync call to initiate payment | User is on a payment screen waiting for a result. |
| Payment → Main (charge outcome) | Async event (`PaymentSucceeded`, `PaymentFailed`) | Payment gateways are themselves async (webhooks); Main reacts, doesn't poll. |
| Main → Payment (session completed) | Async event (`SessionCompleted`) | Drives payout calculation; not on the user's critical path. |

### 3.1 Client → Payment identity (v2.2)

Payment has no Sanctum/`Account` model of its own — accounts live in Auth's database, and there is no legitimate call (public REST, or otherwise) by which Payment could learn Auth's *internal* numeric id for a caller, only their `public_id` (CLAUDE.md's "never expose `id`" rule holds across the service boundary, not just within one app). The gateway row in the table above assumes it validates the caller's Sanctum token against Auth once and forwards a **trusted identity** to whichever service handles the request next — for Payment, that's `X-Gateway-Account-Id` / `X-Gateway-Account-Type` / `X-Gateway-Teacher-Id` headers (`tewtora-payment/app/Shared/Auth/{GatewayPrincipal,TrustGatewayIdentity}.php`), carrying the numeric id directly since this is an internal, network-restricted hop, not a public one.

This is a stand-in, not a finished design — no gateway process exists in this codebase to actually set those headers, and forwarding a numeric id at all only works because the schema still assumes a single, shared numeric identity space left over from when every domain sat in one conceptual database. `docs/api-gap-analysis.md` §7's update has the fuller writeup and the two ways out that weren't implemented (move the denormalized columns to `public_id`, or stand up a real internal-only API that's allowed to expose numeric ids). Checkout was left unbuilt rather than papered over with this stand-in; the teacher ledger (§10 of the contract) went ahead since it only needs to compare the caller's own forwarded id against already-denormalized data.

---

## 4. Booking saga & compensation

No transaction can span three databases, so booking a session is a choreographed saga:

1. Recommendation marks the match `accepted`.
2. Main creates a `sessions` row with `status = 'pending'` — booked, not yet confirmed.
3. Payment attempts the charge against the provider and emits `PaymentSucceeded` or `PaymentFailed`.
4. Main reacts: success → `sessions.status = 'confirmed'`; failure → `sessions.status = 'cancelled'` (the compensating action — there is nothing to roll back because nothing was ever finalized).

### 4.1 Idempotency

Payment gateways retry webhooks. The unique index on `(provider_id, provider_reference)` — see §7.2 — is what stops a retried webhook from charging or confirming a session twice. Every cross-service call in this saga should carry an idempotency key, not just the payment step.

### 4.2 The outbox pattern

If a service writes a status change and then crashes before publishing the event that should follow it, you get a stuck record — a session marked pending forever with no charge ever attempted. The fix: write the event to a local outbox table in the same transaction as the status change; a separate relay process publishes from the outbox to the broker. Each service that publishes events owns its own outbox table.

**The broker is RabbitMQ** (v2.1 decision — see `docker-compose.yml` at the repo root, shared by both deployed units, distinct from each project's own MySQL/Redis compose files which stay per-service). A single durable topic exchange (`tewtora.events`), routed by `event_type` — `LearnerProfileUpdated`, `SessionFeedbackFiled`, etc. are each a routing key, not a separate exchange. Redis pub/sub was considered first and rejected: it drops a message outright if no consumer is subscribed at publish time, which defeats the entire point of an outbox (surviving a consumer that's briefly down). RabbitMQ's durable, named, per-consumer queues hold a published message until it's acknowledged, so a redeploy or crash on the consuming side doesn't silently lose events. `Auth`'s and `Core`'s `outbox:relay` commands publish (persistent delivery mode); `Payment`'s `outbox:consume` declares its own durable queue bound to the routing keys it cares about and only acknowledges a message after its handler succeeds — a failure leaves it for redelivery, same at-least-once idempotency discipline as the webhook handler (§7.3). Both commands talk to RabbitMQ directly via `php-amqplib`, not through Laravel's own Queue abstraction — a job dispatched via `Job::dispatch()` serializes a PHP class reference, which only resolves inside the app that defined that class; there is no way for Core to dispatch a job that only exists in Payment's codebase, so the outbox commands publish/consume plain JSON and each service dispatches its own local job after receiving it.

```sql
CREATE TABLE outbox_events (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  aggregate_type  VARCHAR(50) NOT NULL,      -- e.g. 'session', 'payment'
  aggregate_id    BIGINT UNSIGNED NOT NULL,
  event_type      VARCHAR(100) NOT NULL,     -- e.g. 'PaymentSucceeded'
  payload         JSON NOT NULL,
  created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  published_at    DATETIME(6),
  CONSTRAINT chk_published_after_created
    CHECK (published_at IS NULL OR published_at >= created_at),
  KEY ix_outbox_unpublished (published_at, created_at)  -- unfiltered index (MySQL has no partial-index WHERE clause); relay process still queries WHERE published_at IS NULL
) ENGINE=InnoDB;
```

---

## 5. Event catalog

| Event | Producer | Consumer(s) | Key payload fields | Delivery |
|---|---|---|---|---|
| `AssessmentSubmitted` | Auth | Recommendation | learner_profile_id, assessment_id | ≥ once |
| `LearnerProfileUpdated` | Auth | Recommendation | profile_id, grade, subjects, curriculum_id, budget_tier | ≥ once |
| `TeacherVerified` | Auth | Recommendation | teacher_id, verification_status, subjects, curricula | ≥ once |
| `MatchAccepted` | Recommendation | Main | match_id, learner_profile_id, teacher_id | sync call |
| `PaymentSucceeded` | Payment | Main | payment_id, session_id, provider_reference, amount_minor | ≥ once |
| `PaymentFailed` | Payment | Main | payment_id, session_id, provider_reference, failure_reason | ≥ once |
| `SessionCompleted` | Main | Payment | session_id, teacher_id, scheduled_at | ≥ once |
| `SessionFeedbackFiled` | Main | Payment | session_id, feedback_id | ≥ once |

"≥ once" (at-least-once) delivery is assumed throughout — every consumer must be written to safely process the same event twice.

`SessionFeedbackFiled` (v2.2) is deliberately separate from `SessionCompleted`: the product's payment rule releases a session's held fee only once its feedback is *filed*, not merely once the session has happened (`docs/api-contract.md` §11) — a teacher who skips feedback keeps that session's fee held indefinitely, which is the intended pressure, not a bug to route around by releasing on `SessionCompleted` alone. Payment's listener must only transition a `payment_line_items` row `held → released`, never re-apply to an already-`released` row — the same idempotency discipline as the webhook handler (§7.3).

`AssessmentSubmitted` (v2.2) exists because "kick off matching and report a match count" (`docs/api-contract.md` §2) can't be computed synchronously inside Auth's submit endpoint — that would mean Auth's controller querying Recommendation's `matches` table directly, exactly the cross-domain read `LearnerProfileView`/`TeacherAccountLink`-style read models exist to avoid everywhere else. The submit endpoint returns immediately once this event is written to the outbox; the frontend gets a real match count from a follow-up `GET /matches?assessmentId=` against Recommendation's own endpoint once it's consumed the event and run matching — not built this pass.

---

## 6. API contracts (representative)

### 6.1 Auth & Onboarding

```
POST   /accounts
POST   /accounts/login
POST   /learner-profiles
POST   /learner-profiles/{id}/assessments
GET    /teachers/{id}/verification-status
POST   /teachers/{id}/verify              (staff-only)
```

### 6.2 Recommendation

```
POST   /matches                            (trigger matching for a learner_profile_id)
GET    /matches?learner_profile_id=
POST   /matches/{id}/accept
POST   /matches/{id}/decline
```

### 6.3 Main / Core

```
POST   /sessions                           (from an accepted match)
GET    /sessions/{id}
PATCH  /sessions/{id}/status
POST   /sessions/{id}/feedback
GET    /learner-profiles/{id}/progress
```

### 6.4 Payment

```
POST   /payments                           (initiate charge for a session or plan)
GET    /payments/{id}
POST   /webhooks/{provider}                (inbound: paystack, flutterwave, stripe)
POST   /payouts                            (staff/scheduled)
GET    /teachers/{id}/earnings
```

---

## 7. Payment provider integration

This is the part of the Payment service that touches the outside world, and the part most likely to cause a silent financial bug if under-specified.

### 7.1 Recommended providers

| Market | Provider(s) | Notes |
|---|---|---|
| Nigeria (launch) | Paystack (primary), Flutterwave (fallback) | Both cover card, bank transfer, USSD, and mobile money — the full method list from the guided assessment / payment step. |
| Ghana, Kenya, South Africa | Flutterwave | Broadest single-provider coverage across these markets; avoids a third integration at expansion time. |
| UK, Canada, US (diaspora) | Stripe | Neither Paystack nor Flutterwave has full first-party coverage in these markets. |

### 7.2 The provider_reference field

`payments.provider_reference` stores the payment processor's own transaction identifier — Paystack's reference, Flutterwave's `tx_ref`/`flw_ref`, Stripe's `payment_intent` id. This is the reconciliation key: when finance or support investigates a dispute, they match this value against the provider's own dashboard, not the internal `payments.id`, which the provider has never seen.

One refinement over the base schema in `docs/database-design.md`: separate how a parent paid (card, bank transfer, USSD, mobile money — `payment_methods`) from who processed it (Paystack, Flutterwave, Stripe — a `payment_providers` table). The same method can flow through different providers, and `provider_reference` should be unique per provider, not globally, in case two providers ever coincidentally issue an overlapping reference string.

```sql
CREATE TABLE payment_providers (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code                VARCHAR(30) NOT NULL UNIQUE,     -- 'paystack' | 'flutterwave' | 'stripe'
  display_name        VARCHAR(60) NOT NULL,
  webhook_secret_ref   VARCHAR(120) NOT NULL,           -- pointer into a secrets vault, never the raw secret
  is_active            BOOLEAN NOT NULL DEFAULT true
) ENGINE=InnoDB;

ALTER TABLE payments
  ADD COLUMN provider_id BIGINT UNSIGNED NOT NULL,
  ADD CONSTRAINT fk_payments_provider FOREIGN KEY (provider_id) REFERENCES payment_providers(id) ON DELETE RESTRICT;

ALTER TABLE payments
  ADD CONSTRAINT ux_payments_provider_ref_per_provider
  UNIQUE (provider_id, provider_reference);          -- scoped per provider, not global; MySQL allows unlimited NULLs here, same effect as the Postgres partial-index version
```

### 7.3 Webhook handling flow

1. Client initiates payment → Payment service calls the provider's initialize/charge API → receives a `provider_reference` immediately → writes a `payments` row with `status = 'processing'`.
2. The provider processes the charge and calls back to `POST /webhooks/{provider}`.
3. Payment service verifies the webhook signature (provider-specific HMAC secret, retrieved via `webhook_secret_ref`) before trusting the payload — an unverified webhook is not evidence of anything.
4. Payment service looks up the `payments` row by `(provider_id, provider_reference)`, updates status, and writes an outbox event: `PaymentSucceeded` or `PaymentFailed`.
5. If the same `provider_reference` arrives twice (a provider retry, which will happen), the unique index plus an idempotent update (only transition from `'processing'`, never re-apply to an already-`'success'` row) prevents double-processing.

### 7.4 Reconciliation

Webhooks can be missed — network blips, a deploy mid-flight. A nightly reconciliation job calls each active provider's list-transactions API and diffs the result against the local `payments` table by `(provider_id, provider_reference)`, flagging any row where the provider shows a final state but the local row is still `'processing'`. This is the safety net underneath the webhook flow, not a replacement for it.

### 7.5 Provider abstraction

The Payment service should define a thin adapter interface — `initialize()`, `verify()`, `refund()` — implemented once per provider, rather than branching on provider code throughout the business logic. This keeps Paystack, Flutterwave, and Stripe pluggable: adding a provider later, or running two concurrently for reliability (Paystack primary, Flutterwave automatic fallback on repeated initialize failures), becomes a new adapter, not a rewrite.

### 7.6 Checkout quotes, the per-session ledger, and commission (v2.2)

`docs/api-contract.md` §7 requires `/checkout/quote` to return a server-computed total the confirm screen trusts, and `/checkout/pay` to redeem it by id rather than accept a client-computed number. That's `payment_quotes`, a real table rather than a signed token — auditable, and it survives a slow client past its TTL without losing the record of what was quoted:

```sql
CREATE TABLE payment_quotes (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id         CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
  payer_account_id  BIGINT UNSIGNED NOT NULL,  -- cross-service reference into Auth's database, not a FK
  teacher_id        BIGINT UNSIGNED NOT NULL,  -- cross-service reference into Auth's database, not a FK
  plan_kind         VARCHAR(15) NOT NULL CHECK (plan_kind IN ('per_session','weekly','monthly')),
  session_count     SMALLINT UNSIGNED NOT NULL CHECK (session_count > 0),
  line_items        JSON NOT NULL,
  total_minor       BIGINT NOT NULL CHECK (total_minor >= 0),
  currency_code     CHAR(3) NOT NULL DEFAULT 'NGN',
  expires_at        DATETIME(6) NOT NULL,
  created_at        DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY ix_payment_quotes_payer (payer_account_id, created_at)
) ENGINE=InnoDB;

ALTER TABLE payments
  ADD COLUMN quote_id BIGINT UNSIGNED NULL,
  ADD CONSTRAINT fk_payments_quote FOREIGN KEY (quote_id) REFERENCES payment_quotes(id) ON DELETE SET NULL;
```

The bigger gap: `payments.status` is one value per charge, but the product rule is per-session — "hold the full amount, release one session's fee after that session is taught and fed back on" (§7 above, `docs/api-contract.md` §7/§10). One `payments` row (a monthly charge, say) needs to release in installments as each covered session gets fed back on. That's `payment_line_items`, one row per session a payment covers, released by the `SessionFeedbackFiled` event (§5):

```sql
CREATE TABLE payment_line_items (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  payment_id    BIGINT UNSIGNED NOT NULL,
  session_id    BIGINT UNSIGNED,   -- cross-service reference into Main/Core's database, not a FK
  teacher_id    BIGINT UNSIGNED NOT NULL,  -- denormalized at creation time; the teacher ledger (§10) filters by it and Payment has no other way to know it
  amount_minor  BIGINT NOT NULL CHECK (amount_minor >= 0),
  status        VARCHAR(10) NOT NULL DEFAULT 'held' CHECK (status IN ('held','released','refunded')),
  released_at   DATETIME(6),
  created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_payment_line_items_payment FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE RESTRICT,
  CONSTRAINT chk_payment_line_items_released_at CHECK (status != 'released' OR released_at IS NOT NULL),
  KEY ix_payment_line_items_payment (payment_id),
  KEY ix_payment_line_items_session (session_id),
  KEY ix_payment_line_items_teacher (teacher_id, status),
  KEY ix_payment_line_items_status (status)
) ENGINE=InnoDB;
```

The teacher-facing earnings ledger (`docs/api-contract.md` §10's `LedgerEntry`) and the admin stuck-money view (§16) both project off `payment_line_items` rather than each keeping their own copy — one source of truth for "what's held, what's released."

`docs/api-contract.md` §10's `TeacherCommissionInfo` (a standard rate, a reduced rate past a lifetime-session threshold) needed a place to live — nothing recorded a platform commission anywhere before this:

```sql
CREATE TABLE commission_tiers (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  rate_type           VARCHAR(10) NOT NULL UNIQUE CHECK (rate_type IN ('standard','reduced')),
  rate                DECIMAL(4,3) NOT NULL CHECK (rate >= 0 AND rate <= 1),
  threshold_sessions  INT UNSIGNED
) ENGINE=InnoDB;
```

A teacher's `completed_sessions_lifetime` (needed to pick a tier) is computed by reading Core's session/feedback counts at request time, not stored here — this table is purely the rate config, same "lookup table for a domain that will genuinely change" rule as `subjects`/`curricula`/`payment_methods` (`docs/database-design.md` §1.6).

Finally, Payment gets its own `payment_audit_log`, structurally identical to Auth's `audit_log` (`docs/database-design.md` §3.7) but a **separate table**, not a cross-service write into it — no domain writes into another service's tables, even for logging, once Payment is genuinely a separate service/database:

```sql
CREATE TABLE payment_audit_log (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_account_id  BIGINT UNSIGNED NOT NULL,  -- cross-service reference into Auth's database, not a FK
  action            VARCHAR(20) NOT NULL
                      CHECK (action IN ('viewed','approved','rejected','refunded','acted','closed')),
  subject_type      VARCHAR(50) NOT NULL,
  subject_id        VARCHAR(120) NOT NULL,
  reason            TEXT,
  before_state      JSON,
  after_state       JSON,
  occurred_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  KEY ix_payment_audit_log_subject (subject_type, subject_id, occurred_at),
  KEY ix_payment_audit_log_actor (actor_account_id, occurred_at)
) ENGINE=InnoDB;
```

---

## 8. Operational notes

- Each service migrates its own schema independently, with its own versioned migration history — no shared migration run touches two services' tables.
- Distributed tracing (OpenTelemetry) is not optional once a booking spans four services — a stack trace from one service no longer tells the whole story.
- The Payment service's network segment should be the narrowest of the four: it needs to reach the payment providers and RabbitMQ (§4.2), and nothing else needs to reach it except through the API gateway and RabbitMQ.
- Route dashboard/reporting reads to a replica per service, as already noted in `docs/database-design.md` §6 — this holds independently once each service has its own database.

---

## 9. Open decisions

- Recommendation → Main for `MatchAccepted` is modeled as synchronous above for simplicity. If matching volume grows large enough that Main's availability shouldn't gate Recommendation's, this can move to an event with Main creating the pending session asynchronously — revisit once real traffic patterns exist, not preemptively.
- Whether Paystack or Flutterwave is primary for the Nigerian launch is a commercial decision (settlement speed, fee structure, support responsiveness) as much as a technical one — the adapter pattern in §7.5 means this choice isn't a lock-in either way.
- A dedicated reference-data service for subjects/curricula was considered and rejected at this scale — Auth & Onboarding owns them and publishes changes; revisit only if a fifth domain genuinely needs them independently of Auth.
- **v2.2:** a dedicated Trust & Safety service for `safeguarding_incidents` was considered and rejected the same way, for the same reason — Auth owns it instead (`docs/database-design.md` §3.7). Also left open: whether `payment_line_items.session_id` is assigned at quote time or lazily as sessions get scheduled off a plan (§7.6) — an application-layer decision, not a schema one.
