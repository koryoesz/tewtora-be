# API contract gap analysis

Written against `docs/api-contract.md` (frontend's endpoint/data contract,
2026-09-19) and the schema as it stood in `docs/database-design.md` v2.1 +
`docs/microservices-architecture.md` v2.0. Section numbers below match the
contract doc.

> **Status: implemented as migrations + models**, in the priority order the
> "Priority read" section below lays out. `docs/database-design.md` (now
> v2.2) and `docs/microservices-architecture.md` (now v2.1) are the
> up-to-date source of truth for the resulting schema — this document is
> kept as-is below as the historical record of the reasoning, not updated
> in place. Repositories, policies, exceptions, controllers/routes, and the
> outbox listeners that actually wire the new events together (e.g.
> `SessionFeedbackFiled` → releasing a `payment_line_items` row) are still
> outstanding — this pass was schema + models only, consistent with every
> round before it.
>
> One correctness bug was caught and fixed while documenting this in
> `database-design.md`: `assessments`' `chk_learning_goals_present_if_submitted`
> needed an explicit `learning_goals IS NOT NULL` guard — `JSON_LENGTH(NULL) > 0`
> evaluates to `NULL`, which MySQL's `CHECK` treats as *passing*, not
> failing. Fixed in the migration and covered by
> `AssessmentDraftTest::test_submitting_without_learning_goals_is_rejected`.

Severity key: **New table** (concept doesn't exist at all) / **Extend**
(existing table needs columns/constraints changed) / **Decision** (an
architectural call is needed before anything can be built) / **None** (fully
covered already, or purely a read-model/API-resource-layer concern).

---

## 0. Auth & session — **Extend + Decision**

- `accounts.account_type` CHECK currently allows only `('parent',
  'independent_student','teacher')`. The contract's role set is `parent |
  independent-student | child | teacher | admin`. **`child` and `admin` are
  missing.** A child's own login is `learner_profiles.linked_login_account_id`
  → an `accounts` row — that row's `account_type` has nowhere to go today.
  `admin` (staff) isn't modeled as an account type at all.
- No session/token mechanism exists yet — Sanctum isn't installed (deferred
  from the earlier migrations-only pass, see `Account` model's docblock).
- **Decision needed:** is `admin` a fifth `account_type` value on the same
  `accounts` table, or a separate staff table? Given admins need the same
  login mechanics (email/password, Sanctum tokens) and CLAUDE.md's policy
  model already gates by account type, reusing `accounts` with
  `account_type = 'admin'` is the smaller change — but it puts a
  Payment/safeguarding-adjacent trust boundary in the same table as parents.
  Flag for a product/security call before implementing.
- `switch-profile` (acting-as-child) is a session-shaped concern (a claim on
  the Sanctum token or a signed cookie), not a schema concern — no new table
  needed, just an implementation decision at the auth layer.

## 1. Learners — **None**

`auth.learner_profiles` already carries everything (`full_name`,
`grade_level`, `curriculum_id`). `assessmentComplete` is derived (`EXISTS`
against `auth.assessments`), not a stored column — correct as a read-model
concern, no gap.

## 2. Assessment & matching — **Extend**

- The contract wants a **draft, resumable, autosaved** assessment (`PUT`
  before `submit`). The current `auth.assessments` table is write-once:
  `learning_goals JSON NOT NULL`, `availability JSON NOT NULL`,
  `preferred_format`/`session_frequency`/`budget_tier` all `NOT NULL` with
  `CHECK`s. None of that tolerates a partial draft.
  **Options:** (a) add a `status ENUM-as-VARCHAR('draft','submitted')`
  column and relax the `NOT NULL`s to nullable-until-submitted, enforcing
  "required at submit time" at the application layer instead of the schema;
  or (b) a separate `assessment_drafts` table with looser constraints that
  gets promoted into an `assessments` row on submit. (a) is less schema
  churn and keeps one history; (b) keeps `assessments` write-once-and-clean
  the way `database-design.md` §1.4 intends for evidentiary tables. Leaning
  (a) since an assessment isn't evidentiary the way a payment/session is,
  but flagging as a decision.
- `matches.match_reasoning` already covers `matchReason` per teacher — no
  gap there. `matchCount`/`matchSessionId` from the submit response are
  computable (`COUNT` of `recommendation.matches` rows for the assessment;
  the assessment's own id can serve as `matchSessionId`) — no new column.

## 3. Teachers & matches — **New table + Extend + Decision**

- **Verification is a real mismatch, not just a naming difference.** The
  contract wants three *independently* tracked checks, each with a 4-value
  state (`pending | in-review | approved | rejected`) plus `checkedAt`.
  Today `auth.teachers` has: `id_verified_at` / `credentials_verified_at`
  (timestamps only — no in-review/rejected state), `demo_status` (its own
  4-value vocabulary: `not_submitted/submitted/scheduled/completed`, which
  doesn't map onto `pending/in-review/approved/rejected` at all), and one
  overall `verification_status`. This can't be patched with a rename — it
  needs a **new `auth.teacher_verification_checks` table**: `teacher_id,
  kind ENUM('government_id','credentials','teaching_demo'), state, evidence,
  checked_by_account_id, checked_at`. This table is also exactly what Admin
  §14's `VerificationCheck`/`VerificationApplication` need — one schema
  change serves both. `teachers.verification_status` can stay as the
  derived overall status (all three `approved` → `approved`), or become a
  generated/cached rollup.
- **`freeTrialSlots` on `Teacher` and the whole `/trial-requests` flow is a
  new concept** — nothing today models a pre-payment, teacher-confirmed
  trial slot. `core.sessions.is_trial` already exists as a flag for the
  *confirmed* trial session, but the request/negotiation stage (pending →
  teacher accepts/declines, 12h expiry) needs its own table:
  `trial_requests(learner_profile_id, teacher_id, slot_starts_at,
  duration_minutes, status, expires_at)`. **Decision:** which domain owns
  it? It's pre-booking (closer to Recommendation) but produces a real
  `core.sessions` row on acceptance (closer to Core). Recommend **Core**,
  since accepting a trial request's side effect is "create a session," and
  Core already owns the session lifecycle — Recommendation would otherwise
  need a synchronous call into Core anyway, which is the exact pattern
  `MatchAccepted` already uses (microservices-architecture.md §3).
- `Teacher.groupSize` (a cap on group size) has no home — `teachers` only
  has `preferred_format IN ('one_on_one','group','both')`, no numeric
  ceiling. Small **Extend**: add `max_group_size SMALLINT UNSIGNED NULL` to
  `auth.teachers`.

## 4. Plans — **New table (largest gap in the contract)**

**There is no `plans` table anywhere in the current schema.** This is the
single biggest gap — `Plan` (recurring subscription: teacher/subject/
days/time, `active`/`paused` status, rate, `sessionsPerMonth`,
`sessionsRemaining`, `renewsAt`, `reference`) is a first-class entity the
whole app hangs off (Home, Plans, Progress, Rebook, Move-a-lesson all read
it). Today `core.sessions` are individual bookings with no concept of a
recurring parent, and `payments.plan_type` (`per_session|weekly|monthly`)
only hints at billing cadence without an actual plan row to attach to.

**Recommended shape**, owned by **Core** (it's session-scheduling state, and
Core already owns `sessions`/`feedback`/`progress_reports`):

```
core.plans (
  id, public_id, learner_profile_id, teacher_id, subject_id,
  format, days JSON, time_of_day TIME, status ('active'|'paused'),
  rate_minor, currency_code, sessions_per_month, sessions_remaining,
  renews_at, reference, paid_to_date_minor, created_at, updated_at
)
```

`core.sessions.match_id` becomes `core.sessions.plan_id` (or both — a
session is created from a match once, then recurs under a plan) — needs a
concrete decision on whether `match_id` is retained as history-only or
dropped in favor of `plan_id` as the operative link. `learner_profile_id`/
`teacher_id` stay cross-schema plain columns per the existing pattern.

Everything else in §4 (`next-sessions`, `goals`, `history`) is a read
projection over `plans` + `sessions` + `feedback` + `progress_reports` —
no additional storage once `plans` exists, except **`PlanGoal`**
(`label, pct`) which isn't derivable from anything currently stored; that's
either a new small `core.plan_goals` table or a JSON column on `plans` if
goals are simple and don't need independent history — lean toward a table
since `progress_reports` already treats rollups as first-class rows, not
JSON blobs (consistency with the existing pattern in
`database-design.md` §3.6).

## 5. Move a lesson — **New tables**, owned by Core

Nothing here exists yet. Needs:

```
core.move_requests (
  id, plan_id, kind ('move'|'renewal'), route, reason,
  from_day, from_starts_at, to_day, to_starts_at,
  outside_teacher_hours, status, expires_at, gross_minor,
  created_at
)
core.move_approvals (
  id, move_request_id, party_account_id, party_label, role ('teacher'|'parent'),
  state, responded_at
)
```

`outsideTeacherHours` is computed at request-creation time against
`auth.teacher_availability` — cross-schema read, not a stored FK (consistent
with the existing hard rule). `candidate-slots`' server-computed verdict
(`ok|tight|bad`) is pure business logic over `teacher_availability` +
`sessions` + `plans`, no new storage.

## 6. Rebook / renewal — **None beyond §4/§7**

`RenewalContext` is fully computed (diff against `plans`' last paid block,
which needs §7's payment/ledger data to exist first). No new table once
`plans` and the Payment ledger (§7/§10 below) exist.

## 7. Payment / checkout — **New table(s) + Decision**, Payment domain

Two real gaps, both in `Payment`:

1. **No checkout-quote concept.** `/checkout/quote` returning a
   server-computed total the confirm screen trusts implies either an
   ephemeral (cached, short-TTL) quote or a lightweight `payment_quotes`
   table (`id, expires_at, line_items JSON, total_minor, ...`) that
   `/checkout/pay` redeems by id. Given money correctness matters, a real
   table (not just a signed token) is the safer default — it's auditable
   and survives a slow client.
2. **No per-session escrow/release tracking.** The product rule — "hold the
   full amount, release one session's fee after that session is taught and
   fed back on" — cannot be represented by today's `payments` table, which
   is one row per charge with a single `status`. This needs a
   **`payment_line_items`** (or `session_releases`) table: one row per
   session covered by a `payments` row, each with its own
   `status ('held'|'released'|'refunded')` and `released_at`. This is the
   same underlying data §10's teacher-facing `LedgerEntry` needs — build it
   once, project it two ways (teacher ledger view, admin stuck-money view).
   The release is triggered by Core's `feedback` write — fits the existing
   outbox pattern exactly: Core emits an event on feedback submission,
   Payment's listener flips the matching line item to `released` (this is
   the natural extension of the already-planned `SessionCompleted` event,
   or a new `SessionFeedbackFiled` event next to it in the catalog).

**Update, found while actually trying to implement §10's ledger and §7's
checkout in the next pass:** the blocker here is sharper than "Payment
can't look up a teacher's rate." `payments.payer_account_id`,
`payment_line_items.teacher_id`, `payment_quotes.teacher_id` etc. are all
typed `BIGINT` — Auth's *internal* numeric id, denormalized in at creation
time. But CLAUDE.md's "no exposing internal `id` (BIGINT) in any API
response — `public_id` (UUID) only" rule means no legitimate call across
the service boundary — not the gateway, not a public REST call to Auth —
can ever hand Payment that numeric id. The schema was designed assuming a
single, shared numeric identity space (true while everything sat in one
conceptual database), and that assumption quietly survived into a schema
meant for genuinely separate services.

Two ways out, neither implemented:
- Move these columns to `CHAR(36)` holding Auth's `public_id`, and accept
  the join cost of Payment occasionally needing to resolve a `public_id`
  back to *its own* internal id for its own primary keys (never Auth's).
- Introduce an internal-only, network-restricted API between services that
  *does* expose internal ids for exactly this purpose — the public API
  stays public_id-only, but a service-to-service call on a private network
  segment is allowed more.

Implemented as a stand-in for this pass: `tewtora-payment` has no
authentication of its own at all (no Sanctum, no `Account` model) — a
`GatewayPrincipal`/`TrustGatewayIdentity` pair (`app/Shared/Auth`) trusts
`X-Gateway-Account-Id`/`X-Gateway-Teacher-Id` headers a real API gateway
would set after validating the caller's Sanctum token against Auth. Those
headers carry the numeric id directly — which is exactly the assumption
flagged above, made explicit and documented rather than hidden. Checkout
(quote/pay) was **not built** on top of this; only §10's ledger (which
only needs to compare the caller's own forwarded `teacherId` against
already-denormalized data, not resolve anyone else's identity) went ahead.

## 8. Live session — **None** (mostly out of scope for this schema)

Video/whiteboard are provider-owned per the contract's own note. The only
persistent piece worth having is `core.sessions` already existing as the
anchor `LiveSession.id`/`planId` maps onto. `providerJoinToken` is minted
on `/join`, not stored. No schema change needed unless/until a provider is
chosen and turns out to need server-side participant-join logging beyond
what's already implicit in `sessions`.

## 9. Teacher: match requests inbox — **Extend**, Recommendation domain

Good news: `recommendation.learner_profile_view` already stores no PII
(no name field at all) — the anonymization requirement is already
structurally satisfied by the existing read-model design, not something
that needs building. Gaps are additive fields the view doesn't carry yet:
`challenges`, `goals`, `preferred_slots` (currently only `grade_level`,
`curriculum_id`, `budget_tier`, `subject_ids` are synced from
`LearnerProfileUpdated`). `budget_tier` is a single enum
(`basic|standard|premium`) but the contract wants a concrete
`budgetMinKobo`/`budgetMaxKobo` range — needs either a tier→range lookup
table or resolving the range at event-publish time in Auth and carrying it
in the event payload. `matches` also needs `matchesTeacherRate`/
`outOfSubject` — both computable at read time (compare `budget_tier` range
against `teachers.rate_minor`, compare requested subject against
`teacher_subjects`), not necessarily new columns.

## 10. Teacher: earnings ledger — **Extend**, Payment domain

Same underlying gap as §7.2 — `LedgerEntry` and `TeacherCommissionInfo` need
the per-session line-item table from §7, plus a **commission/rate-tier
concept that doesn't exist anywhere today**: no column or table currently
records a platform commission percentage at all. Needs a small
`commission_rate` concept — either a config table (`commission_tiers:
threshold_sessions, rate`) or, simplest, two settings (`standard_rate`,
`reduced_rate`, `reduced_tier_at`) plus a computed
`completed_sessions_lifetime` (derivable via `COUNT` on Core's
`sessions`/`feedback`, read cross-schema at query time — or synced into
Payment as a denormalized counter the same way Recommendation keeps read
models, if that count is queried often enough to matter).

## 11. Teacher: feedback — **Extend**, Core domain

Close to already covered. `core.feedback` has `session_notes`,
`progress_rating`, `next_steps` — maps to `note`, `ratingOutOf5`,
`nextSteps`. Two gaps: (1) no `attendance` column — small **Extend**, add
`attendance VARCHAR(...)` or a proper enum-as-CHECK; (2) no draft/autosave
state — `feedback` is currently a single insert (`session_id NOT NULL
UNIQUE`), no room for "save draft, payment stays held" vs. "submit,
payment releases." Needs a `status ('draft'|'submitted')` column, with the
`UNIQUE(session_id)` constraint still holding (one feedback row per
session, draft or final) and the Payment-release side effect gated on
`status = 'submitted'`, not on row existence.

## 12. Teacher: learners roster / stats — **None** (read-model)

Fully derivable from `plans` (once it exists) + `ratings` + `feedback` +
`sessions`. "Recognition/badge progress" is the one soft spot — if badges
need their own criteria/history (not just a computed percentage), that's a
small optional `teacher_badges` table; not clear from the contract that
it's needed yet, so not flagging as required.

## 13. Teacher: schedule diary — **New table (already flagged)**

`DiaryCell` is computable from `teacher_availability` + `sessions` +
`move_requests` — no gap there. `block-time-off`, though, is exactly the
gap `database-design.md` §7 already called out as a known future need:
*"`teacher_availability` models weekly recurring slots only; if the product
needs date-specific exceptions... that's an additive table."* The contract
confirms this is needed now. Add `auth.teacher_time_off(teacher_id,
starts_at, ends_at, reason)` — same schema (Auth), separate table from the
recurring `teacher_availability`, not a rework of it (per that same note).

## 14. Admin: verification — **Covered by §3's new table**

`VerificationApplication`/`VerificationCheck` are exactly
`auth.teacher_verification_checks` from §3, grouped by teacher. No
additional schema beyond that table plus the audit log (§18 below).

## 15. Admin: matching ops — **Mostly read-model + one small table**

`UnfilledAssessment` is a query over `auth.assessments` (once §2's draft
distinction exists, filtered to submitted-but-unmatched) joined against
`recommendation.matches`. `SupplyGap` is an aggregate over
`teacher_subjects`/`teacher_curricula` vs. assessment demand — no new
storage for the read side. `recruitmentOpen` + "must also pause new
bookings for that subject/curriculum" implies a small state table:
`recommendation.recruitment_targets(subject_id, curriculum_id,
bookings_paused BOOLEAN, opened_at)` — this is the one piece that's actual
mutable state, not just a query.

## 16. Admin: money — **Covered by §7/§10's ledger**

`StuckMoneyItem` (`refund|held-too-long|failed-payout|dispute`) is a filtered
view over the `payment_line_items`/ledger table from §7, plus `payouts`
(already exists) for the `failed-payout` case. No new table beyond what §7
already requires; `resolve` needs an audit trail (§18) more than new schema.

## 17. Safeguarding — **New table + Decision**

Entirely new concept, and genuinely doesn't fit cleanly into any of the four
existing domains as currently scoped:

- It reads/writes across Auth (`teachers.verification_status`-adjacent
  suspension), Core (which session/plan triggered it), and needs its own
  audit trail.
- `suspend-new-matches` is explicitly **not** the same as existing
  `teachers.deleted_at`/`verification_status` — it's a third, narrower
  state ("stop new matches, keep existing lessons running") that doesn't
  fit `verification_status`'s `pending/approved/rejected` vocabulary either.

**Decision needed:** does Trust & Safety become a fifth logical domain (own
table, own service eventually), or does `safeguarding_incidents` live in
Auth (closest to the teacher-suspension side effect) with Core/Payment
reached via events the way every other cross-domain need already works?
Given the existing architecture doc is explicit that a "fifth domain" was
already rejected once for reference data (microservices-architecture.md §9)
on cost grounds, and this is a much higher-stakes, lower-volume concern,
leaning toward **Auth owns `safeguarding_incidents` + a
`teachers.new_matches_suspended_at` column**, with Recommendation checking
that column (read-model sync, same as everything else it reads from Auth)
before proposing a suspended teacher in new matches. Flagging for an
explicit decision rather than assuming.

## 18. Admin: accounts / audit log — **New table, overdue**

`docs/database-design.md` §5 already flags this: *"every write to
`learner_profiles`, `payments`, and `verification_status` on `teachers`
should emit to an append-only `audit_log` table... required before
production launch."* That table has never actually been built (no migration
exists for it in either project). The contract now makes it load-bearing —
`/internal/audit-log`, and **every** `GET` under `/internal/*` needing a
`"viewed"` audit write, not just mutations. This should be built as one
shared `Shared\Logging\Auditable` trait/observer writing into a per-domain
(or single cross-cutting) `audit_log` table, per
`backend-engineering-standards.md` §7 — already designed, just never
implemented. Recommend prioritizing this alongside whichever domain change
ships first, since every other admin endpoint depends on it existing.

`act-as` (support impersonation, read-only) is a session/token concern
(§0) — the token needs a claim (`acting_as: 'support-readonly'`) that
every 🔒 policy check rejects outright, not a new table.

---

## Priority read

If I had to sequence this, the dependency order is roughly:

1. **`core.plans`** — almost everything else (§4, §5, §6, most of §7/§10)
   hangs off this. Nothing downstream is buildable without it.
2. **Payment's per-session ledger** (§7/§10) — second-most load-bearing;
   blocks checkout, rebook, teacher earnings, and admin money all at once.
3. **`audit_log`** (§18) — small, overdue, and every admin endpoint (§14–18)
   silently assumes it exists.
4. **`auth.teacher_verification_checks`** (§3/§14) — one table serves both
   the teacher-facing verification badge and the entire admin verification
   queue.
5. Everything else (trial requests, move requests, teacher time-off,
   safeguarding) is additive and can land independently once the above
   exist, roughly in the order the frontend's "NOT YET MOCKED" list
   prioritizes them (trial → match-accept → checkout → feedback).

Three items need an explicit decision before implementation, flagged above:
`admin` account-type modeling (§0), draft-assessment schema shape (§2), and
where `safeguarding_incidents` lives (§17).
