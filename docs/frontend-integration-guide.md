# Tewtora Backend — Frontend Integration Guide

**Status as of 2026-09-22.** This describes what's actually implemented and
callable right now — not the full product vision. It's derived from
`docs/api-contract.md` (the original FE→BE contract) and
`docs/api-gap-analysis.md`, but where those describe the target, this
describes reality: 49 working endpoints across two services, and an
explicit list of what isn't built yet so you don't integrate against
something that doesn't exist.

Two separate backend services, two separate base URLs:

| Service | Local URL | Covers |
|---|---|---|
| **Core** (`tewtora-core`) | `http://localhost:8000/api/v1` | Auth, learners, assessments, teachers, plans, move-requests, trial-requests, feedback, match-requests, all `/internal/*` admin routes |
| **Payment** (`tewtora-payment`) | `http://localhost:8001/api/v1` | Teacher earnings ledger only, for now |

---

## 1. Auth

There's no registration endpoint yet — accounts have to exist already
(seeded/created directly for now). Everything else works:

### `POST /auth/login`
```json
// request
{ "email": "parent@example.test", "password": "secret123" }
// response 200
{ "token": "1|abcdef123456..." }
// response 401 on bad credentials
{ "error": { "code": "invalid_credentials", "message": "...", "request_id": "req_..." } }
```

Send the token on every subsequent request:
```
Authorization: Bearer 1|abcdef123456...
```

### `POST /auth/logout` 🔒
Revokes the current token. Returns `204`, no body.

### `GET /auth/session` 🔒
```json
{
  "id": "uuid",            // account public_id
  "role": "parent",        // parent | independent_student | child | teacher | admin
  "name": "parent@example.test",   // currently just the email — no display-name field exists yet
  "acting_as_learner_id": "uuid-or-null"
}
```
`role` always comes from the token server-side — sending a different role
in a query string or body does nothing.

### `POST /auth/switch-profile` 🔒
```json
// request
{ "learner_id": "learner-public-id" }
// response 200
{ "acting_as_learner_id": "learner-public-id" }
```
Also sets a **plain, non-httpOnly cookie** `acting_as_learner_id` (24h TTL)
so a Next.js Server Component can read it directly without a round trip.
403 if the caller isn't the owning parent or the linked child login for
that learner.

---

## 2. Error envelope (every error, both services)

```json
{ "error": { "code": "teacher_not_verified", "message": "Human-readable.", "request_id": "req_..." } }
```
Validation failures (422) look slightly different:
```json
{ "error": { "code": "validation_failed", "message": "...", "request_id": "req_...", "fields": { "email": ["The email field is required."] } } }
```
`code` is stable and safe to branch on. `request_id` also comes back as an
`X-Request-Id` response header on every request (send one yourself on the
way in and it'll be echoed back, for tracing a specific user report).

---

## 3. ID convention

Every resource is addressed by a UUID `public_id` in the URL — **never**
the internal numeric id. Two known exceptions where a route currently
takes a raw numeric id instead (flagged as inconsistent, not yet fixed):
- `POST /teachers/{teacherId}/trial-requests` — `teacherId` is numeric.
- Payment's `GET /teachers/{teacherId}/ledger` — same.

Everywhere else, `{id}` in the paths below is the UUID.

---

## 4. Learners

### `GET /learners` 🔒
All learners under the caller's account, **excluding archived ones** (see
below). `LearnerProfileResource[]`:
```json
{ "id": "uuid", "name": "Ada", "initials": "A", "grade_label": "grade-7", "curriculum": "ib", "has_pin": false, "archived": false, "assessment_complete": false }
```

### `GET /learners/{id}` 🔒
Same shape, single object. `404` if you don't own it or aren't the linked
child login (the ownership check happens at the query level, so a stranger
gets a 404, not a 403).

### `POST /learners` 🔒 — parent only
```json
// request
{ "name": "Ada", "grade_level": "grade-7", "curriculum": "ib" }
```
`curriculum` must be an existing curriculum `code`. Returns `201` +
`LearnerProfileResource`. `403` if the caller isn't `role: parent`
(independent students don't add children).

### `PATCH /learners/{id}` 🔒 — owner only
```json
{ "name": "New Name", "grade_level": "grade-8", "curriculum": "us_common_core" }
```
All fields optional (`sometimes`). The linked-login child can `view` but
gets `403` here.

### `POST /learners/{id}/pin` 🔒 — owner only
```json
{ "pin": "7391" }
```
Sets or resets the child's sign-in PIN. Exactly 4 digits; a short blocklist
of trivially weak PINs (`0000`, `1234`, `4321`, etc. — every repeated digit
and simple run) is rejected with `422`. Stored hashed, **never returned in
any response** — `LearnerProfileResource.has_pin` (boolean) is the only
signal. Resetting immediately revokes the linked child login's existing
Sanctum tokens, matching "they're signed out everywhere" — the child has to
sign in again with the new PIN. The linked-login child itself gets `403`
here; only the owning parent can call this.

**Not implemented:** the actual child-sign-in-via-PIN endpoint. This only
covers the parent-side management half — there's no `POST /auth/child-login`
(or similar) yet that accepts a sign-in name + PIN and issues a token.
Setting a PIN here doesn't yet let anyone use it to log in. Ask backend
before building the sign-in screen against this.

### `POST /learners/{id}/archive` 🔒 — owner only
Soft-deletes the learner (existing `deleted_at`/`SoftDeletes` mechanism, not
a separate flag). Returns the updated `LearnerProfileResource`
(`archived: true`). Archived learners drop out of `GET /learners` and
`GET /learners/{id}` automatically (`404` on the latter). Plans, sessions,
feedback, and payment history are untouched — this only hides the profile
itself.

### `POST /learners/{id}/restore` 🔒 — owner only
Reverses an archive. Returns the updated `LearnerProfileResource`
(`archived: false`).

---

## 5. Assessment

### `GET /learners/{id}/assessment` 🔒
Latest draft or submitted assessment. `404` if none exists yet.
```json
{
  "status": "draft",
  "academic_challenges": ["fractions"],
  "learning_goals": ["pass the term exam"],
  "budget_tier": "standard",
  "preferred_format": "one_on_one",
  "session_frequency": "weekly",
  "availability": [],
  "consent_given": false,
  "submitted_at": null
}
```

### `PUT /learners/{id}/assessment` 🔒 — autosave
Any subset of: `grade_level`, `curriculum`, `subject_ids`,
`academic_challenges`, `learning_goals`, `budget_tier`
(`basic|standard|premium`), `preferred_format`
(`one_on_one|group|no_preference`), `session_frequency`
(`weekly|twice_weekly|custom`), `availability`. Nothing is required —
save whatever the current wizard step has.

`availability` is now validated (previously accepted as opaque
`unknown[]`), one entry per selected weekday:
```json
{ "availability": [
  { "day": "tue", "starts_at": "16:00", "ends_at": "18:00" },
  { "day": "thu", "starts_at": "16:00", "ends_at": "18:00" }
] }
```
`day`: `mon|tue|wed|thu|fri|sat|sun`. `starts_at`/`ends_at`: 24h `HH:mm`,
`ends_at` must be after `starts_at`. This is the *offered* window — the
matched teacher picks the actual class time within it, same as before.
**The old `{day, band, state}` shape is no longer accepted** — nothing
server-side depended on it, since the column was unvalidated until now.

### `POST /learners/{id}/assessment/submit` 🔒
Same fields as above, all now conceptually "final," plus:
```json
{ "consent_given": true }
```
**`consent_given` is required and must be `true` if the learner is a
`child` profile — omit it (or send `false`) for a minor and you get a
`422` with a `consent_given` field error.** Independent students / adult
learners never need this field.

Response:
```json
{ "assessment": { "status": "submitted", ... } }
```
**Important:** there's no `match_count` in this response. Kicking off
matching is async (an event to the Recommendation side) — not built yet on
the consuming end. Don't build UI that expects a live match count from
this call; it'll need a follow-up `GET /matches?assessmentId=` once that
exists (not built either).

---

## 6. Teachers

### `GET /teachers/{id}` 🔒 — any authenticated account
```json
{
  "id": "uuid",
  "years_teaching": 5,
  "about": "bio text",
  "format": "both",
  "group_size": null,
  "price_per_session_minor": 500000,
  "currency_code": "NGN",
  "rating_avg": null,
  "verification": { "government_id": "approved", "credentials": "in_review", "teaching_demo": "pending" },
  "subjects": ["mathematics"],
  "curricula": ["ib"]
}
```
Note the verification states are `pending|in_review|approved|rejected` —
**not** the `confirmed|outstanding|problem` wording the original contract
used in its admin section (§14); we standardized on one vocabulary
backend-side. Map these values in the UI, don't expect the other set.

**Not implemented:** teacher search/listing, `/matches` (ranked results
from an assessment).

---

## 7. Trial requests

### `POST /teachers/{teacherId}/trial-requests` 🔒 — note: numeric id, see §3
```json
// request
{ "learner_profile_id": 123, "slot_starts_at": "2026-10-01T16:00:00Z", "duration_minutes": 30 }
```
`learner_profile_id` here is also the **numeric** id (not public_id) and
must belong to the caller. `slot_starts_at` must be in the future.
Response `TrialRequestResource`:
```json
{ "id": "uuid", "status": "pending", "slot_starts_at": "...", "duration_minutes": 30, "expires_at": "...", "session_id": null }
```
Expires 12h after creation (fixed, not configurable per request).

**Simplification vs. the original contract:** there's no `slotId` concept
— free-trial-slot computation against a teacher's availability isn't built
yet, so the caller picks the exact time directly.

### `DELETE /trial-requests/{id}` 🔒 — requester only
Cancels a pending request.

### `POST /trial-requests/{id}/respond` 🔒 — teacher only
```json
{ "decision": "accept" }  // or "decline"
```
Accepting creates a real `session` (free, `is_trial: true`) and sets
`session_id` on the response.

---

## 8. Match requests (teacher-facing)

### `GET /teachers/{teacherPublicId}/match-requests` 🔒 — teacher (self) only
Pending inbox. `TeacherMatchResource[]`, deliberately carries **no learner
name/contact info** (still anonymous at this stage):
```json
{ "id": "uuid", "status": "proposed", "match_reasoning": "...", "decline_reason": null, "created_at": "..." }
```

### `POST /match-requests/{id}/accept` 🔒
### `POST /match-requests/{id}/decline` 🔒
```json
{ "reason": "Schedule doesn't work for this term." }  // required on decline
```
`409` with `error.code: "match_already_decided"` if it's not still
`proposed`.

---

## 9. Plans

### `GET /learners/{learnerPublicId}/plans` 🔒
All plans for a learner, any status. `PlanResource[]`:
```json
{
  "id": "uuid", "format": "one_on_one", "days": ["tue"], "time_of_day": "16:00:00",
  "status": "active", "rate_minor": 500000, "currency_code": "NGN",
  "sessions_per_month": 4, "sessions_remaining": 4, "renews_at": "...",
  "reference": "TWT-...", "paid_to_date_minor": 0
}
```

### `GET /plans/{id}`, `GET /plans/{id}/next-sessions`, `GET /plans/{id}/goals`, `GET /plans/{id}/history` 🔒
Owner, linked child (view-only), or the plan's teacher can all view.
- `next-sessions`: `[{ "id": "uuid", "starts_at": "...", "is_live": false }]`
- `goals`: `[{ "id": "uuid", "label": "...", "pct": 40.0 }]`
- `history`: `[{ "id": "uuid", "session_date": "...", "status": "completed", "score_out_of_5": 5, "note": "...", "next_steps": "..." }]` — `score_out_of_5`/`note`/`next_steps` are `null` until that session's feedback is submitted.

### `POST /plans/{id}/pause` 🔒 — owner only
Returns the updated `PlanResource` (`status: "paused"`, `renews_at: null`).
`409` (`invalid_plan_transition`) if it's not currently `active`.

### `POST /plans/{id}/rebook` 🔒 — owner only
```json
{ "session_count": 4, "note_to_teacher": "optional message" }
```
**This is a payment in the real product spec — it is not one here yet.**
It transitions the plan (carries over `sessions_remaining` if it was
paused) as if payment already succeeded. `note_to_teacher` is accepted and
validated but goes nowhere — no messaging system exists. Don't wire this
up as a real checkout flow; treat it as a placeholder until Payment's
checkout is built.

### `DELETE /plans/{id}` 🔒 — owner only
Ends the plan (status → `ended`, not deleted — history/progress survive).
```json
{ "refund_minor": 1500000 }
```

---

## 10. Move a lesson

### `POST /plans/{id}/move-requests` 🔒 — owner only
```json
{ "route": "move_learner", "to_day": "wed", "to_starts_at": "16:00", "reason": "Clashes with a school trip." }
```
`route`: `move_learner | move_group | to_one_to_one`. Creates the request
with **only the teacher's approval pending** — nothing changes yet.

**Not implemented:** `move_group` doesn't actually find and add the other
affected families' approvals (creates the same single teacher-only
approval regardless of route). `GET /plans/{id}/candidate-slots`
(server-computed verdict per slot) isn't built at all — it needs teacher
availability data this service doesn't have access to yet. Don't build a
slot-picker UI against it.

Response `MoveRequestResource`:
```json
{
  "id": "uuid", "kind": "move", "route": "move_learner", "reason": "...",
  "from": { "day": "tue", "starts_at": "16:00:00" }, "to": { "day": "wed", "starts_at": "16:00" },
  "outside_teacher_hours": false, "status": "pending",
  "approvals": [{ "party_label": "Teacher", "role": "teacher", "state": "pending", "responded_at": null }],
  "expires_at": "...", "gross_minor": 500000
}
```

### `GET /move-requests` 🔒 — teacher's own list (the `teacherId` query param from the original contract is ignored; scoped to the caller regardless)
### `GET /move-requests/{id}` 🔒 — parties + the learner (read-only)
### `POST /move-requests/{id}/accept` 🔒 / `.../decline` 🔒 — the responding party
The `status` only flips to `accepted` once **every** approval row is
accepted — with only one party modeled right now, that's effectively "once
the teacher accepts."
### `POST /move-requests/{id}/propose-alternate` 🔒 — teacher
```json
{ "day": "thu", "starts_at": "17:00" }
```
### `POST /move-requests/{id}/withdraw` 🔒 — requester

---

## 11. Session feedback (teacher-facing, releases payment)

### `GET /sessions/{id}/feedback` 🔒
`404` if no draft/submission exists yet.
```json
{ "status": "draft", "attendance": null, "session_notes": null, "progress_rating": null, "next_steps": null, "submitted_at": null }
```

### `PUT /sessions/{id}/feedback/draft` 🔒 — teacher (own session)
All fields optional: `attendance` (`present|absent|late`), `session_notes`,
`progress_rating` (1-5), `next_steps`.

### `POST /sessions/{id}/feedback` 🔒 — submits, triggers payment release
`session_notes` and `progress_rating` are **required** here (empty note is
rejected with `422`). This writes an internal event that (once the two
services share a real message broker — see §13) releases that session's
held payment on the Payment side. No `amount_released` field comes back in
this response; don't build a toast that states an amount from this call.

---

## 12. Admin (`/internal/*`, all require `role: admin`)

Non-admin callers get a `403` from all of these.

### Verification
- `GET /internal/verification-applications?state=open|waiting|done`
- `GET /internal/verification-applications/{teacherId}`
- `POST /internal/verification-applications/{teacherId}/decide`
  ```json
  { "decision": "approved", "note": "All checks confirmed." }
  ```
  `decision`: `approved | rejected | needs_more`. `note` required,
  non-empty. **`approved` is rejected with `409`
  (`verification_checks_incomplete`) unless every one of the teacher's
  three verification checks is `approved`** — this is enforced
  server-side, not just a disabled button.

### Safeguarding
- `GET /internal/safeguarding-incidents` — open incidents only
- `GET /internal/safeguarding-incidents/{id}`
- `POST /internal/safeguarding-incidents/{id}/close` — `{ "note": "..." }`, required
- `POST /internal/teachers/{id}/suspend-new-matches` — no body; scoped narrowly (existing lessons keep running), returns `{ "new_matches_suspended_at": "..." }`

### Accounts / audit
- `GET /internal/accounts?q=` — email substring search
- `GET /internal/accounts/{id}` — includes `is_minor`/`guardian_label`
- `POST /internal/accounts/{id}/act-as`
  ```json
  { "reason": "Parent asked support for help" }
  ```
  `reason` **must** be one of a fixed list (send anything else, get
  `422`): `"Parent asked support for help"`, `"Investigating a payment
  issue"`, `"Verifying a safeguarding report"`, `"Resolving a stuck-money
  case"`. Returns a token — **that token can only make `GET` requests**.
  Any mutating call with it gets a hard `403`, enforced globally, not
  per-endpoint. Build the support UI assuming this session is genuinely
  read-only, not just missing some buttons.
- `GET /internal/audit-log?subjectId=` — every admin `GET` and mutation
  writes an entry here automatically; you don't need to call anything else
  to make that happen.

**Not implemented:** the matching-ops screens (unfilled assessments,
supply gaps) and the stuck-money screen — no endpoints exist for either.

---

## 13. Payment service (separate base URL — see the table at the top)

Only the teacher earnings ledger exists:

### `GET /teachers/{teacherId}/ledger?status=` 🔒
### `GET /teachers/{teacherId}/commission` 🔒

**These need different headers than everything else** — Payment doesn't
share Auth's login system yet. Until a real API gateway exists, send:
```
X-Gateway-Account-Id: <numeric account id>
X-Gateway-Account-Type: teacher
X-Gateway-Teacher-Id: <numeric teacher id>
```
This is a known stand-in, not the final design — ask backend before
building real UI against it, since the header names/values may change
once an actual gateway is in place.

```json
// GET .../ledger
{ "data": [{ "occurred_at": "...", "learner_label": "", "detail": "Session", "reference": "1", "kind": "session", "gross_minor": 500000, "status": "held" }] }
```
`learner_label` is always `""` — Payment has no learner-name data to fill
it with.

```json
// GET .../commission
{ "teacher_id": 42, "completed_sessions_lifetime": 3, "standard_rate": 0.15, "reduced_rate": 0.12, "reduced_tier_at": 100 }
```
`completed_sessions_lifetime` is a proxy (count of released line items),
not necessarily the exact number Core would report.

**Not implemented at all: checkout (`/checkout/quote`, `/checkout/pay`),
payment methods, payouts, tax statements, ledger export.** Checkout in
particular is blocked on a real cross-service identity/pricing design, not
just unwritten code — don't estimate it as "almost done."

---

## 14. Not implemented anywhere (don't build UI expecting these yet)

- Account registration
- `/matches` (ranked teacher results from an assessment)
- Live session (join/leave/chat/whiteboard)
- Teacher roster, teacher stats, teacher schedule diary, teacher
  availability CRUD, block-time-off
- Checkout/payment (see §13)
- Admin matching-ops and stuck-money screens

---

**Questions or a mismatch between this doc and what you actually get back?**
Treat the running code as ground truth over this file, and flag it —
this was hand-written from the route list and resource classes on
2026-09-22 and will drift the moment either side changes.
