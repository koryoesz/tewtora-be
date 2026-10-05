# Tewtora Backend — Frontend Integration Guide

**Status as of 2026-10-05.** This describes what's actually implemented and
callable right now — not the full product vision. It's derived from
`docs/api-contract.md` (the original FE→BE contract) and
`docs/api-gap-analysis.md`, but where those describe the target, this
describes reality: 67 working endpoints across two services, and an
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
Two credential shapes, same endpoint — exactly one pair, never a mix:
```json
// request — everyone except a child
{ "email": "parent@example.test", "password": "secret123" }
// request — a child (no email/password; see §4's username+pin creation)
{ "username": "zainab_z", "pin": "7391" }
// response 200 (either shape)
{ "token": "1|abcdef123456..." }
// response 401 on bad credentials (same code/message for either shape —
// deliberately doesn't reveal which field was wrong, or whether the
// account/username exists at all)
{ "error": { "code": "invalid_credentials", "message": "Incorrect sign-in details.", "request_id": "req_..." } }
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
  "acting_as_learner_id": "uuid-or-null",
  "teacher_id": "uuid"     // role: teacher only — see below
}
```
**`teacher_id` is present only when `role: teacher`.** It's a *different*
UUID from `id` — `id` is the account's own public_id, `teacher_id` is the
linked `auth.teachers` row's, which is what `GET /teachers/{id}` and
`GET /teachers/{id}/match-requests` actually key on. This is the field to
use to resolve a logged-in teacher's own profile id — don't hardcode it,
it changes on every reseed.

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
the internal numeric id. One known exception where a route still takes a
raw numeric id (flagged as inconsistent, not yet fixed):
- Payment's `GET /teachers/{teacherId}/ledger`.

`POST /teachers/{teacherId}/trial-requests` **no longer** takes a numeric
id as of 2026-10-05 — it's `{teacherPublicId}` now, resolved through
Core's own `teacher_account_links` read model. This was the exact gap
`docs/needed-endpoints-trial-requests.md` named ("no way to get a numeric
teacher id without teacher search/`/matches`, unimplemented") — now that
`GET /teachers` (§7) exists and only ever hands out `public_id`, there was
no reason to still require a numeric id here.

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
// request, also creating the child's own sign-in at the same time
{ "name": "Ada", "grade_level": "grade-7", "curriculum": "ib", "username": "ada_z", "pin": "7391" }
```
`curriculum` must be an existing curriculum `code`. Returns `201` +
`LearnerProfileResource`. `403` if the caller isn't `role: parent`
(independent students don't add children).

`username`/`pin` are optional but **each requires the other** — a
username with no PIN (or vice versa) is `422`. When both are given, the
child's own login account is created immediately (see §1's login shapes)
and linked as this profile's child login. `username`: 3-30 chars,
letters/numbers/dashes/underscores, must be unique — `422` on a
duplicate. `pin`: exactly 4 digits, **no weak-PIN blocklist at creation**
(that only applies to `POST /learners/{id}/pin`, below — a weak PIN set
here can be fixed immediately after via that endpoint). A child account
has no email at all; it never logs in with email/password.

### `PATCH /learners/{id}` 🔒 — owner only
```json
{ "name": "New Name", "grade_level": "grade-8", "curriculum": "us_common_core" }
```
All fields optional (`sometimes`). The linked-login child can `view` but
gets `403` here.

### `POST /learners/{id}/pin` 🔒 — owner only
```json
// reset — profile already has a linked child login
{ "pin": "7391" }
// first-time setup — profile has no login yet (wasn't created with one via POST /learners)
{ "pin": "7391", "username": "ada_z" }
// rename + reset together — profile already has a login
{ "pin": "7391", "username": "ada_zed" }
```
Sets or resets the child's sign-in PIN, and (if given) the username.
Exactly 4 digits; a short blocklist of trivially weak PINs (`0000`,
`1234`, `4321`, etc. — every repeated digit and simple run) is rejected
with `422` here (unlike at `POST /learners`). Stored hashed, **never
returned in any response** — `LearnerProfileResource.has_pin` (boolean)
is the only signal. Resetting immediately revokes the linked child
login's existing Sanctum tokens, matching "they're signed out
everywhere" — the child has to sign in again with the new PIN. The
linked-login child itself gets `403` here; only the owning parent can
call this.

**`422` (`child_username_required`) if this profile has no linked login
yet and you don't provide `username`** — there's nothing to attach the
PIN to otherwise. Once a login exists (either from `POST /learners` or a
previous call here), `username` becomes optional on later calls.

### `POST /learners/{id}/archive` 🔒 — owner only
Soft-deletes the learner (existing `deleted_at`/`SoftDeletes` mechanism, not
a separate flag). Returns the updated `LearnerProfileResource`
(`archived: true`). Archived learners drop out of `GET /learners` and
`GET /learners/{id}` automatically (`404` on the latter). Plans, sessions,
feedback, and payment history are untouched — this only hides the profile
itself. **`409` (`learner_has_active_plan`) if the child currently has an
active plan** — end or pause it first. A paused plan doesn't block this.

### `POST /learners/{id}/restore` 🔒 — owner only
Reverses an archive. Returns the updated `LearnerProfileResource`
(`archived: false`).

### `POST /learners/{id}/pause-sign-in` 🔒 — owner only
### `POST /learners/{id}/resume-sign-in` 🔒 — owner only
No body. Disables/re-enables the child's own `username`+`pin` login
(§1), independently of archive/restore above — the profile stays fully
visible and manageable to the parent; only the child's own sign-in stops
working. Adds `sign_in_paused: boolean` to `LearnerProfileResource`.
Pausing immediately revokes the child's existing Sanctum tokens (same
"signed out everywhere" behavior `POST /learners/{id}/pin` already has).
A paused child's `POST /auth/login` attempt — even with the correct PIN —
gets `401` with `error.code: "child_sign_in_paused"` (not the generic
`invalid_credentials`), since the credentials were actually correct.

### `GET /curricula` 🔒
```json
{ "data": [{ "code": "ib", "display_name": "International Baccalaureate" }, ...] }
```
Returns every active curriculum code, for populating a picker (e.g. "Add a
child") instead of hardcoding codes — `POST /learners`'s `curriculum`
field validates against this same table. Previously only `"ib"` was ever
seeded (a `TestAccountsSeeder` side effect that never ran outside
dev/staging); `ib`, `nigerian`, `british`, `american`, and
`us_common_core` are now seeded in every environment via a new
production-safe `CurriculumSeeder`.

### `GET /subjects` 🔒
Same shape and same fix as `GET /curricula` just above, for `auth.subjects`
— it had the identical gap (only `"mathematics"` was ever seeded outside
dev/staging). A new `SubjectSeeder` now seeds `mathematics`,
`further_maths`, `physics`, `chemistry`, `biology`, `english`,
`economics`, and `coding` in every environment. `PATCH /teachers/{id}`'s
`subjects` field (§7) validates against this same table.

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
Same fields as above (all optional — including `learning_goals`, which was
required until now but no longer is), plus:
```json
{ "consent_given": true }
```
**`consent_given` is required and must be `true` if the learner is a
`child` profile — omit it (or send `false`) for a minor and you get a
`422` with a `consent_given` field error.** Independent students / adult
learners never need this field. Whatever fields you don't resend keep
whatever was already saved from a prior draft `PUT`, if any — sending
nothing but `consent_given` submits the assessment as-is.

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

## 6. Pricing

### `GET /pricing` 🔒
Indicative per-session rate, for the assessment budget/schedule step's
cost calculator — shown **before** a teacher is matched, so it can't be a
real teacher's own rate. `PricingSettingResource[]`, one row per format:
```json
{ "data": [
  { "format": "one_on_one", "rate_minor": 500000, "currency_code": "NGN" },
  { "format": "group", "rate_minor": 250000, "currency_code": "NGN" }
] }
```
**This is a uniform platform-wide rate per format, not per-teacher/subject**
— confirmed intentional for now, not a placeholder. Configurable in the
database (`core.pricing_settings`), not hardcoded, so it can change without
a deploy — don't cache these values for longer than the session. A real
matched teacher's actual rate is still `GET /teachers/{id}.price_per_session_minor`
(§7) — this endpoint is only for the pre-match estimate.

---

## 7. Teachers

### `GET /teachers` 🔒 — any authenticated account
`docs/needed-endpoints-browse-matching.md` §1's "minimum useful version"
of `/matches` — an unranked, filtered list of real **verified** teachers,
replacing what used to be entirely fabricated client-side. Not the ranked
`GET /learners/{id}/matches` §2 of that doc asks for eventually (no
matching/scoring engine exists yet) — this is a plain filtered list.
```
GET /teachers?subject=mathematics&curriculum=british&level=year_10_11&format=group
```
Every filter is optional; an empty query returns every verified teacher.
`subject`/`curriculum` validate against the same tables §4/§7's `GET
/curricula`/`GET /subjects` read from. `level` is the same closed enum as
`PATCH /teachers/{id}`'s `levels`. `format` is `one_on_one` or `group`
only (not `both`) — a teacher whose own `format` is `both` matches either
filter value. Response is `TeacherResource[]`, same shape as `GET
/teachers/{id}` below. Excludes unverified teachers outright (not just
deprioritized) and anyone with `new_matches_suspended_at` set (the
existing safeguarding suspension — scoped to exactly this: new
matches/browse results, not existing lessons).

### `GET /teachers/{id}` 🔒 — any authenticated account
```json
{
  "id": "uuid",
  "name": "Chinedu Okafor",
  "years_teaching": 5,
  "about": "bio text",
  "format": "both",
  "group_size": null,
  "price_per_session_minor": 500000,
  "currency_code": "NGN",
  "rating_avg": null,
  "verification": { "government_id": "approved", "credentials": "in_review", "teaching_demo": "pending" },
  "subjects": ["mathematics"],
  "curricula": ["ib"],
  "levels": ["year_10_11"],
  "availability": [{ "day": "thu", "starts_at": "16:00", "ends_at": "20:00" }]
}
```
Note the verification states are `pending|in_review|approved|rejected` —
**not** the `confirmed|outstanding|problem` wording the original contract
used in its admin section (§14); we standardized on one vocabulary
backend-side. Map these values in the UI, don't expect the other set.

### `PATCH /teachers/{id}` 🔒 — the teacher themself only
A teacher editing their own profile — the onboarding wizard's steps 1–3
plus bio (`needed-endpoints-teacher-onboarding.md` §3). Every field is
optional (`sometimes`) and additive to the `GET` shape above:
```json
{
  "name": "Chinedu Okafor",
  "subjects": ["mathematics"],
  "curricula": ["british"],
  "levels": ["year_10_11"],
  "format": "both",
  "price_per_session_minor": 1400000,
  "years_teaching": 6,
  "availability": [{ "day": "thu", "starts_at": "16:00", "ends_at": "20:00" }],
  "about": "..."
}
```
`levels` is a small fixed enum — `primary_1_3 | primary_4_6 | jss_1_3 |
sss_1_3 | year_7_9 | year_10_11 | ib_a_level` — stored as JSON, not a
lookup table (it's closed and rarely changes, unlike subjects/curricula).
`availability` is the same `{day, starts_at, ends_at}` shape §5's
assessment availability uses — writes into `auth.teacher_availability`,
replacing whatever was there before (not merged).

**`name` is new** (`needed-endpoints-browse-matching.md` §1 — "a browse
list is unusable with every card anonymous"). There was genuinely nowhere
to put a teacher's real name before this: `auth.accounts` has no name
column at all (shared by every role — see §1's own `GET /auth/session`
`name`, which is just `email`/`username`, not a real name), and
`learner_profiles.full_name` is scoped to a learner. `name` lives on
`auth.teachers` instead, self-reported like `about`. `null` for any
teacher who hasn't set one yet — an honest gap, not backfilled with a
placeholder. `2`–`160` chars, same contact-info reject as `about` below
(it's just as publicly displayed).

**`about` validation:** `422` if under 40 characters, or if it contains
anything that looks like a phone number, email, or link — same
contact-info pattern messaging already strips (§13), except a bio is
rejected outright rather than silently redacted, since it's authored once
and reviewed rather than sent live.

**Never touches `verification_status` or the verification checks** —
editing a profile field isn't "re-applying." There's currently no
endpoint for a teacher to read their *own* verification-check states
(`government_id`/`credentials`/`teaching_demo`) distinct from what
`GET /teachers/{id}` already returns to anyone — flagging this as still
open from `needed-endpoints-teacher-onboarding.md` §4, not resolved by
this endpoint.

**Not built by this endpoint:** the "waiting families" matching-funnel
preview (`needed-endpoints-teacher-onboarding.md` §2) — that's the same
not-yet-built ranked-matching gap as `/matches` (§16), just hit from the
teacher side. No live counts exist to preview against yet.

**Not implemented:** teacher search/listing, `/matches` (ranked results
from an assessment).

---

## 8. Trial requests

### `POST /teachers/{teacherId}/trial-requests` 🔒 — note: numeric id, see §3
```json
// request
{ "learner_profile_id": 123, "slot_starts_at": "2026-10-01T16:00:00Z", "duration_minutes": 30 }
```
`learner_profile_id` here is also the **numeric** id (not public_id) and
must belong to the caller. `slot_starts_at` must be in the future.
Response `TrialRequestResource`:
```json
{ "id": "uuid", "status": "pending", "slot_starts_at": "...", "countered_starts_at": null, "duration_minutes": 30, "decline_reason": null, "decline_reason_message": null, "responded_at": null, "expires_at": "...", "session_id": null }
```
Expires 12h after creation (fixed, not configurable per request).

**Simplification vs. the original contract:** there's no `slotId` concept
— free-trial-slot computation against a teacher's availability isn't built
yet, so the caller picks the exact time directly.

### `GET /trial-requests/{id}` 🔒 — requester or the teacher
Check a trial request's current status — whether it's still `pending`, and
if not, when and how the teacher responded. Same `TrialRequestResource`
shape as the other endpoints in this section:
```json
{ "id": "uuid", "status": "accepted", "slot_starts_at": "...", "duration_minutes": 30, "responded_at": "2026-10-01T16:04:00+00:00", "expires_at": "...", "session_id": "uuid" }
```
`responded_at` is `null` until the teacher accepts or declines. `session_id`
is only present once `status` is `accepted`. Anyone other than the
requester or the responding teacher gets a 403.

### `DELETE /trial-requests/{id}` 🔒 — requester only
Withdraws a `pending` **or** `countered` request (not just pending — a
family can withdraw after a counter-offer too). Doesn't count against the
teacher's acceptance-rate stat.

### `POST /trial-requests/{id}/respond` 🔒 — see below for who
Who may call this, and which `decision`s are valid, depend on the
request's **current** `status`:
- **`pending`** — only the teacher. `decision`: `accept | decline | counter`.
- **`countered`** — only the family (the original requester). `decision`:
  `accept | decline`, against the *countered* time, not the original.

```json
// teacher accepts (status: pending)
{ "decision": "accept" }
// teacher declines, with an optional reason
{ "decision": "decline", "reason": "budget" }  // full | level | budget | other
// teacher counters with a different time
{ "decision": "counter", "alt_starts_at": "2026-10-09T16:00:00Z" }
// family responds to a counter-offer (status: countered)
{ "decision": "accept" }  // or "decline" — no reason; that's the teacher's content, not theirs
```
Accepting (either an original or a countered time) creates a real
`session` (free, `is_trial: true`) and sets `session_id` on the response.
Every decision stamps `responded_at`. `reason` is optional on a plain
decline — the old `{"decision":"decline"}` with no reason still works
unchanged.

**`decline_reason`/`decline_reason_message` on `TrialRequestResource`:**
`decline_reason` is the small fixed code (`full|level|budget|other`) and
is the same either side. `decline_reason_message` carries **two different
wordings depending on who's asking** — the teacher's own candid version
on the teacher's read, a softened version on the family's read of the
*same* request. The teacher's own wording is never sent to the family
under any field.

**`countered_starts_at`:** set only once a teacher counters a `pending`
request — `slot_starts_at` still holds the *original* ask, unchanged.
Once present, the family's `accept`/`decline` responds against
`countered_starts_at`, not `slot_starts_at`.

A trial's post-session note has no new endpoint — it's the existing
`PUT`/`POST /sessions/{id}/feedback` (§12) against the real (free)
session a trial's acceptance already creates. Confirmed working against
a trial session the same as a billed one.

---

## 9. Match requests (teacher-facing)

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

## 10. Plans

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

### `GET /teachers/{teacherPublicId}/next-sessions`, `GET /teachers/{teacherPublicId}/history` 🔒 — the teacher themself only
The same two shapes as above, but aggregated across **every** plan that
teacher teaches, not scoped to one plan (`needed-endpoints-classes.md`
§3's "a teacher currently has no way to list their own classes"). Reads
`core.sessions` directly by `teacher_id`, so it doesn't need to enumerate
the teacher's plans first. `{teacherPublicId}` here is `core.teacher_
account_links.public_id`, the same id `GET /teachers/{id}` uses — scoped
to the caller's own id only, even if the caller is a different
authenticated teacher.

**`plan_id` is now included on both** — `{ "id": "uuid", "starts_at": "...", "is_live": false, "plan_id": "uuid" }` /
`{ "id": "uuid", "plan_id": "uuid", "session_date": "...", ... }` — added
specifically for this teacher-aggregate case (the per-plan endpoint above
doesn't send it; the caller already has the plan). Without it there was no
way to tell which of a teacher's several plans a row belonged to, let
alone label it with anything.

### `GET /teachers/{teacherPublicId}/plans` 🔒 — the teacher themself only
Every plan this teacher teaches, any status — same `PlanResource` shape
§10 documents above. Pairs with `plan_id` on the two endpoints just
above: fetch this once, key it by `id`, and use it to label a
next-session/history row (`format`, `days`, `time_of_day`, `reference`) —
there's no subject or learner-name field here at all, by design
(`core.plans.subject_id`/`learner_profile_id` are cross-schema identities
Core doesn't resolve to a display name; see CLAUDE.md's hard rule on not
reaching into another domain's models).

**Still missing, same gap either side (§2 of the Classes doc):** a
meeting-room link and session end time/duration (only `starts_at`/`is_live`
exist) on `PlanNextSessionResource`.

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

## 11. Move a lesson

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

## 12. Session feedback (teacher-facing, releases payment)

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
services share a real message broker — see §15) releases that session's
held payment on the Payment side. No `amount_released` field comes back in
this response; don't build a toast that states an amount from this call.

---

## 13. Messaging

One thread per plan (a paused/ended plan keeps its own history, never
merges into an undifferentiated stream), plus one "Tewtora support" thread
per family (parent/independent-student ↔ any staff member, no teacher, no
plan). **Group-plan threads (more than one family) are not built** — only
1:1 plans get a thread today.

A thread's messages are visible to: the owning parent/independent-student,
the linked child login (full participant — can read, send, and report;
just can't touch anything booking/payment-shaped, and nothing here does),
the plan's teacher, and (support threads only) any admin.

### `GET /messages/threads` 🔒
All threads the caller is a party to (an admin instead gets every support
thread — there's no per-incident staff assignment). `MessageThreadResource[]`:
```json
{ "id": "uuid", "plan_id": "uuid-or-null", "learner_id": "uuid", "teacher_id": "uuid-or-null", "is_support": false, "unread_count": 2, "last_message_at": "..." }
```
No teacher/learner display name — same as `PlanResource`, correlate against
`GET /teachers/{id}` / `GET /learners` yourself.

### `GET /messages/threads/{id}` 🔒
`403` if you're not a party. Returns the thread plus every message, oldest first:
```json
{
  "thread": { "id": "uuid", ... },
  "messages": [
    { "id": "uuid", "sender_role": "teacher", "is_own": false, "body": "...", "redacted": false, "created_at": "..." }
  ]
}
```
`sender_role` is `parent|independent_student|child|teacher|admin`. No
sender account id — only role + `is_own` (whether it's the caller's own
message). **Never calls mark-read as a side effect** — call `/read`
explicitly once the user has actually opened the thread.

### `POST /messages/threads/{id}/messages` 🔒
```json
{ "text": "See you Tuesday!" }
```
Returns the created `MessageResource`. **Contact-info redaction happens
server-side, unconditionally** — phone-number-like digit runs, emails,
URLs, and `wa.me` links are replaced with `[redacted]` before the message
is ever persisted, regardless of anything the client already stripped.
`redacted: true` on the response means something was caught; the original
un-redacted text is never stored anywhere, not even for safeguarding
review.

### `POST /messages/threads/{id}/read` 🔒
No body. Returns `204`. Resets the caller's unread count for this thread to 0.

### `POST /messages/threads/{id}/report` 🔒
No body. Returns `204`. Creates a real `SafeguardingIncident` (visible
immediately in `/internal/safeguarding-incidents` — same queue, not a
separate concept), `severity: medium` by default, with an auto-composed
summary — the caller never writes free text for this. **`422`
(`support_thread_cannot_be_reported`) on a support thread** — a
parent-with-staff conversation has no teacher to attach an incident to,
and staff is already directly in that thread.

---

## 14. Admin (`/internal/*`, all require `role: admin`)

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

## 15. Payment service (separate base URL — see the table at the top)

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

## 16. Not implemented anywhere (don't build UI expecting these yet)

- Account registration
- `/matches` (ranked teacher results from an assessment)
- Live session (join/leave/chat/whiteboard)
- Teacher roster, teacher stats, teacher schedule diary, teacher
  availability CRUD, block-time-off
- Checkout/payment (see §15)
- Admin matching-ops and stuck-money screens
- Message attachments ("Attach work"), group-plan threads (more than one
  family), typing indicators, real-time delivery, and any admin-side view
  of reported-but-not-yet-a-formal-incident messages (a report always
  becomes a real `SafeguardingIncident` immediately — see §13)

---

**Questions or a mismatch between this doc and what you actually get back?**
Treat the running code as ground truth over this file, and flag it —
this was hand-written from the route list and resource classes, last
updated 2026-10-05, and will drift the moment either side changes.
