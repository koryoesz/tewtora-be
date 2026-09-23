# Tewtora API — endpoints needed

> **This document is a guide, not a spec to implement literally.** It exists
> to tell the backend team what data and behavior the frontend needs — not
> to dictate how the backend should be built. **The backend team has full
> authority to rename endpoints, change HTTP verbs, restructure resource
> nesting, switch to RPC/GraphQL, split or merge routes, or organize this
> however fits their existing conventions and architecture.** Treat every
> `/path` and `GET`/`POST` below as illustrative shorthand for "a way to do
> this," not a naming convention to match. Please push back on anything here
> that doesn't fit how the backend is actually built — this should flex to
> the backend's plans, not the other way around.

This is the contract the frontend needs from a real backend. It's derived from
what `lib/mock/api.ts` currently fakes, plus a handful of flows that only
exist as client-side toasts today (marked **NOT YET MOCKED** below) and a few
that don't exist at all yet (auth, payment provider, video provider).

**This is a contract, not an implementation plan.** Paths, verbs, and grouping
below are a reasonable REST shape but are not load-bearing — if the backend
already has its own routing conventions (RPC, GraphQL, different resource
nesting), map to those. What *is* load-bearing is:

- **Every field listed.** The frontend needs this data to render the screen it's attached to — the field can be named or shaped differently on the wire (with an adapter on the frontend side), but the information itself has to come from somewhere.
- **The enforcement notes.** These are product rules (see `CHANGES_SINCE_HANDOFF.md` and
  `CHANGES_ROUND_2.md`), not suggestions — a child role that can call the pay
  endpoint because only the button was hidden is a real bug.
- **Money as integer kobo**, always. Never a float, never a pre-formatted string.

What's explicitly **not** load-bearing: endpoint paths, HTTP verbs, request/response
envelope shape, resource nesting, and naming conventions. All of that is the
backend's call — the tables below just need to be read as "here's a thing
that needs to exist and here's the data/behavior it needs," not "implement
exactly this URL."

## A note on "label" fields

Today's mock data stores a lot of pre-formatted display strings directly on
the model — `dueLabel: "22 hours left"`, `scheduleLabel: "Tue & Thu, 4:00–5:00 pm"`,
`askedAgoLabel: "2 hours ago"`. That's a mock-only shortcut. **A real backend
should return raw data** (ISO timestamps, numeric durations, structured
day/time values) and let the frontend format it — timezones, "2 hours ago"
style relative time, and locale-formatted currency are presentation concerns,
not storage concerns. Each section below gives the *raw* shape the endpoint
should actually return, with a note where the current mock instead stores a
baked-in string.

---

## 0. Auth & session

Not modeled at all yet — there's no login screen in this build. Needed before
anything else, since every other endpoint assumes a resolved identity + role.

| Endpoint | Method | Notes |
|---|---|---|
| `/auth/login` | POST | However the product does auth (password, magic link, OTP). Returns a session token/cookie. |
| `/auth/logout` | POST | |
| `/auth/session` | GET | Returns the current user's `id`, `role` (`parent \| independent-student \| child \| teacher \| admin`), and display name/initials. Every page load needs this server-side — see enforcement note below. |
| `/auth/switch-profile` | POST | Body: `{ learnerId }`. Sets which child a parent is "acting for." **Must be a server-readable session/cookie value, not client state** — Server Components render the acting-as name on first paint, so a flash of the wrong child's name is a trust failure. |

**Enforcement (applies everywhere below):** `role` must be resolved server-side
from the session on every request, never trusted from a request body. A
`child` role must be rejected server-side on every mutating endpoint below
marked 🔒 — hiding the button client-side is not enforcement.

---

## 1. Learners

| Endpoint | Method | Auth | Notes |
|---|---|---|---|
| `/learners` | GET | parent, independent-student | All learners under this account (children + self, if set up). |
| `/learners/:id` | GET | parent, independent-student, child (self only) | `{ id, name, initials, gradeLabel, curriculum, assessmentComplete }` |
| `/learners` | POST 🔒 | parent | Add a child. Body: `{ name, gradeLabel, curriculum }`. |
| `/learners/:id` | PATCH 🔒 | parent | Rename, change grade/curriculum, archive. |

---

## 2. Assessment & matching **(NOT YET MOCKED — currently a client-only toast)**

The assessment wizard (`/assessment/[step]`) currently submits nothing. It
needs:

| Endpoint | Method | Auth | Request | Response |
|---|---|---|---|---|
| `/learners/:id/assessment` | GET | parent, independent-student | — | Current draft, if one exists (for resuming on another device — the UI already promises "Saved a moment ago"). |
| `/learners/:id/assessment` | PUT | parent, independent-student | Full draft: level/grade, curriculum, subject, challenges (multi-select), goals (multi-select), free-text notes, budget tier, format, frequency, availability grid | Autosaves on every step change. |
| `/learners/:id/assessment/submit` | POST 🔒 | parent, independent-student | `{ consentGiven: boolean }` — **reject if the learner is a child and `consentGiven` is not true.** Consent is only collected/required when the learner is a minor; an independent student never sees or sends this field. | Kicks off matching. Returns `{ matchCount }` so the "14 teachers fit so far" style copy has a real number, and a `matchSessionId` used to fetch results. |
| `/matches?assessmentId=` | GET | parent, independent-student | — | Ranked `Teacher[]` (shape below) with a `matchReason` per teacher, computed server-side from the assessment answers. |

**Enforcement:** consent must be re-validated server-side even if the client
gated the button — the assessment's final submit is a no-op without it for a
child learner.

---

## 3. Teachers & matches

```ts
type Teacher = {
  id: string;
  name: string; initials: string;
  subjects: string[]; curricula: string; yearsTeaching: number;
  ratingAvg: number; ratingCount: number;
  pricePerSessionKobo: number;
  format: "one-to-one" | "small-group"; groupSize: number | null;
  verification: {
    governmentId: "pending" | "in-review" | "approved" | "rejected";
    credentials: "pending" | "in-review" | "approved" | "rejected";
    teachingDemo: "pending" | "in-review" | "approved" | "rejected";
    checkedAt: string; // ISO
  };
  matchReason: string; // only present when returned from /matches, not a plain teacher lookup
  about: string;
  freeTrialSlots: { id: string; startsAt: string /* ISO */; durationMinutes: number }[];
};
```

| Endpoint | Method | Auth | Notes |
|---|---|---|---|
| `/teachers/:id` | GET | any authenticated | Full profile as above. |
| `/matches` | GET | parent, independent-student | See §2 — ranked list from the last submitted assessment. |
| `/teachers/:id/trial-requests` | POST 🔒 | parent, independent-student | Body: `{ learnerId, slotId }`. **This must never create a payment intent — the trial is free.** Returns the created trial request (`pending`, `expiresAt` = now + 12h). |
| `/trial-requests/:id` | DELETE 🔒 | parent, independent-student | Cancel a pending trial request. |
| `/trial-requests/:id/respond` | POST 🔒 | teacher | Body: `{ decision: "accept" \| "decline" }`. Teacher-side confirmation of a trial slot. |

**Enforcement:** `POST /teachers/:id/trial-requests` and everything under it —
🔒 child cannot call. Booking *regular* lessons (as opposed to a free trial)
is the `/checkout` flow in §7, which is where real money moves.

---

## 4. Plans (a learner's lessons)

A learner holds many plans concurrently (one per subject/teacher) — this is
the structural piece, not a nice-to-have. Never collapse this back to one
plan per learner.

```ts
type Plan = {
  id: string;
  learnerId: string;
  teacherId: string;
  subject: string;
  format: "one-to-one" | "small-group";
  days: ("mon"|"tue"|"wed"|"thu"|"fri"|"sat"|"sun")[];
  timeOfDay: string; // "16:00" 24h, not a pre-formatted label
  status: "active" | "paused";
  rateKobo: number;
  sessionsPerMonth: number;
  sessionsRemaining: number;
  renewsAt: string | null; // ISO, null while paused
  reference: string; // e.g. TWT-2026-04817
  paidToDateKobo: number;
  attended: number;
  ofSessions: number;
  ratingAvg: number;
};

type PlanNextSession = { id: string; startsAt: string /* ISO */; isLive: boolean };

type PlanGoal = { id: string; label: string; pct: number };

type PlanHistoryEntry = {
  id: string;
  sessionDate: string; // ISO
  topic: string | null;
  status: "scheduled" | "completed" | "no-show" | "cancelled";
  scoreOutOf5: number | null; // null until feedback is filed
  note: string | null;
  nextSteps: string | null;
};
```

| Endpoint | Method | Auth | Notes |
|---|---|---|---|
| `/learners/:id/plans` | GET | parent, independent-student, child (self, read-only), teacher (own plans only) | Every plan for one learner, any status. |
| `/plans/:id` | GET | as above | Single plan. |
| `/plans/:id/next-sessions` | GET | as above | `PlanNextSession[]`. |
| `/plans/:id/goals` | GET | as above | `PlanGoal[]`. |
| `/plans/:id/history` | GET | as above | `PlanHistoryEntry[]`, newest first. |
| `/plans/:id/pause` | POST 🔒 | parent, independent-student | Confirm dialog on the frontend states the consequence in money/time — backend should return enough (remaining sessions, refund-if-any) for that copy, not just a 204. |
| `/plans/:id/resume` | POST 🔒 | parent, independent-student | See §6 — resume shares the Rebook flow, since resuming needs a session-count decision too. |
| `/plans/:id` | DELETE 🔒 | parent, independent-student | Ends the plan. Response should include `refundKobo` (unused sessions × rate) so the confirming toast can state it. Progress record and history must be retained after this — don't cascade-delete. |

**Enforcement:** every mutating endpoint here 🔒 — a child can view but never
pause/resume/end. Home, Progress, and Plans screens all read from the same
`GET /learners/:id/plans` — do not let any of them cache independently, or a
parent can see two different numbers for what they owe after an update.

**Empty/edge states the backend must support cleanly:** all plans paused for
a learner (totals should compute to zero, not error), and zero plans at all
(don't 500 — return an empty array).

---

## 5. Move a lesson (reschedule)

This is a genuine multi-party workflow, not a simple PATCH — a small group
can't just be moved because two other families booked that slot.

```ts
type MoveRoute = "move-learner" | "move-group" | "to-one-to-one"; // null for a one-to-one plan
type MoveRequestStatus = "pending" | "accepted" | "declined" | "withdrawn" | "expired";

type MoveApproval = {
  partyId: string; partyLabel: string;
  role: "teacher" | "parent";
  state: "pending" | "accepted" | "declined";
  respondedAt: string | null; // ISO
};

type MoveRequest = {
  id: string;
  planId: string;
  kind: "move" | "renewal"; // renewals surface on the same teacher Schedule screen
  route: MoveRoute | null;
  reason: string; // free text from whoever requested it, shown verbatim
  fromSlot: { day: string; startsAt: string };
  toSlot: { day: string; startsAt: string };
  outsideTeacherHours: boolean; // computed server-side against the teacher's set availability
  status: MoveRequestStatus;
  approvals: MoveApproval[];
  expiresAt: string; // ISO — 24h for a single-party move, 5 days for a group move
  grossKobo: number; // new rate if this changes format, else unchanged
};
```

| Endpoint | Method | Auth | Notes |
|---|---|---|---|
| `/plans/:id/candidate-slots` | GET | parent, independent-student | Returns the teacher's open slots **with a verdict computed server-side**: `{ day, startsAt, verdict: "ok"\|"tight"\|"bad", reason }`, checked against the learner's *other* active plans. **Never trust a browser-side collision check** — a `bad` slot is still returned (frontend renders it disabled, not hidden). |
| `/plans/:id/move-requests` | POST 🔒 | parent, independent-student | Body: `{ route, toSlot, reason }`. For `route: "move-group"`, backend resolves the other affected families and creates one `MoveApproval` per party. Creates approvals with the teacher's row `pending`. **Nothing changes until every party accepts** — this call must not touch the plan's actual schedule yet. |
| `/move-requests/:id` | GET | teacher (own), parent (own learner's), child (own, read-only) | |
| `/move-requests?teacherId=` | GET | teacher | Master list for the teacher's Schedule screen — mixes `kind: "move"` and `kind: "renewal"`. |
| `/move-requests?learnerId=` | GET | parent, child | For the "a change is waiting" banner on the child's Schedule tab and the parent's Plans screen. |
| `/move-requests/:id/accept` | POST 🔒 | teacher | Marks the teacher's own approval row accepted. If every party has now accepted, apply the change to the plan's actual schedule and set `status: "accepted"`. Otherwise stays `pending` with the teacher's row done (frontend shows "nothing more for you to do"). |
| `/move-requests/:id/decline` | POST 🔒 | teacher | Sets `status: "declined"`. Response should carry enough for the "rematch at no cost" flow to trigger — this is explicitly **not** penalised against the teacher, so don't record it against their acceptance-rate stat the same way an unfulfilled request would be. |
| `/move-requests/:id/propose-alternate` | POST 🔒 | teacher | Body: `{ slot }`. Counter-offers a different time. |
| `/move-requests/:id/withdraw` | POST 🔒 | parent, independent-student | Requester cancels before anyone's decided. |

**Enforcement:** a child can view a pending move request affecting their own
plan (read-only banner) but the create/accept/decline/propose/withdraw
endpoints are all 🔒 to parent/independent-student/teacher as marked. Verdict
computation (`candidate-slots`) must happen server-side — this is called out
twice in the product spec as a non-negotiable.

---

## 6. Rebook / renewal

Shares the plan-extension mechanics with "resume a paused plan" — same
endpoint, since a resume also needs a session-count decision.

```ts
type RenewalChange = { kind: "group-size" | "rate" | "slot" | "teacher"; label: string; affectsPrice: boolean };

type RenewalContext = {
  planId: string;
  changesSinceLastBlock: RenewalChange[]; // diff against the plan's last paid block — omit entirely if nothing changed, don't return an empty-but-present array the frontend has to know to hide
  seatHeldUntil: string; // ISO
  waitingListCount: number;
  carriedOverSessions: number; // paused plans only
};
```

| Endpoint | Method | Auth | Notes |
|---|---|---|---|
| `/plans/:id/renewal-context` | GET | parent, independent-student | Computed by diffing the plan against its last paid block server-side. |
| `/plans/:id/rebook` | POST 🔒 | parent, independent-student | Body: `{ sessionCount, noteToTeacher? }`. This is the same endpoint whether the plan was active (a straight renewal) or paused (a resume — carries over `sessionsRemaining` automatically). **This is a payment** — see §7 for how it should actually settle funds; this endpoint should return a checkout/payment intent, not silently mark the plan paid. `noteToTeacher` should be delivered to the teacher (a message, not folded into session history). |

**Enforcement:** 🔒 to parent/independent-student. A child reaching this
screen must see a read-only summary of the *result* ("Mathematics carries on
after this week — nothing changes for you"), never the form, and the endpoint
must reject a child-authored call regardless of what the UI showed them.

---

## 7. Payment / checkout **(NOT YET MOCKED — no payment provider wired up)**

Currently `CheckoutForm` is a static confirmation with no backend call at
all. This is the biggest real gap — needs a provider decision (Paystack /
Flutterwave, per the open question in the product docs) before the contract
below can be finalized, but the shape the frontend needs is:

| Endpoint | Method | Auth | Notes |
|---|---|---|---|
| `/checkout/quote` | POST | parent, independent-student | Body: `{ teacherId, planKind: "per-session"\|"monthly"\|"weekly", sessionCount }`. Returns line items + total in kobo, recomputed server-side — **the frontend must never render a total it computed itself and trust it**; this endpoint is the source of truth the confirm screen renders. |
| `/checkout/pay` | POST 🔒 | parent, independent-student | Body: `{ quoteId, paymentMethodId }`. Creates the plan (or extends it, for a rebook — see §6) only after payment succeeds. Returns the new/updated `Plan` plus a receipt reference. |
| `/payment-methods` | GET | parent, independent-student | Saved cards/accounts for "Pay with." |
| `/payment-methods` | POST | parent, independent-student | Add a new one (redirects to the provider's own tokenization flow). |

**Enforcement:** 🔒 everywhere — this is the endpoint that makes "a child
profile can never initiate a payment" a real guarantee rather than a UI
convention. The account holder identity should come from the session, never
from the request body.

**Money-moves-on-session rule:** the product's core payment model is "Tewtora
holds the full amount and releases one session's fee only after that session
is taught and its feedback is filed" — the backend needs a per-session
release mechanism, not just a lump-sum charge at checkout. See §11 (feedback
submit) for the release trigger.

---

## 8. Live session

Whiteboard/video are provider-dependent (open question in the product docs —
not decided yet). The app-level contract the frontend needs regardless of
provider:

```ts
type LiveSession = {
  id: string; planId: string; subject: string; groupSize: number;
  startsAt: string; endsAt: string; // ISO
  teacherId: string;
  participants: { learnerId: string; initials: string; joinedAt: string | null }[];
  providerJoinToken: string; // opaque — whatever the video SDK needs to join
};
```

| Endpoint | Method | Auth | Notes |
|---|---|---|---|
| `/sessions/:id` | GET | participant (learner/teacher on that plan) | |
| `/sessions/:id/join` | POST | participant | Returns `providerJoinToken`. |
| `/sessions/:id/leave` | POST | participant | |
| `/sessions/:id/chat` | GET/POST | participant | If chat isn't handled by the video provider itself. |

Whiteboard state is almost certainly provider-owned (most video SDKs ship
one) — don't build a custom whiteboard persistence layer unless the provider
decision rules that out.

---

## 9. Teacher: match requests inbox

```ts
type AnonymisedBrief = {
  id: string; subject: string; gradeLabel: string;
  curriculum: "nigerian" | "british" | "american" | "ib";
  goals: string[]; challenges: string[];
  budgetMinKobo: number; budgetMaxKobo: number;
  format: "one-to-one" | "small-group";
  preferredSlots: string[];
  expiresAt: string; // ISO
};

type MatchRequest = {
  id: string; reference: string;
  status: "pending" | "declined" | "accepted";
  outOfSubject: boolean;
  brief: AnonymisedBrief;
  ratePerSessionKobo: number;
  matchesTeacherRate: boolean;
};
```

| Endpoint | Method | Auth | Notes |
|---|---|---|---|
| `/teachers/:id/match-requests` | GET | teacher (self) | **The response shape itself must have no name/photo/contact field** — this is enforced by what the API returns, not by the frontend choosing not to render a field. Only after `/accept` does any endpoint reveal the learner's identity. |
| `/match-requests/:id/accept` | POST 🔒 | teacher | **NOT YET MOCKED** — currently a dead button. Should trigger a trial-slot offer to the family (§3) and, from this point on, the learner's identity becomes visible to this teacher. |
| `/match-requests/:id/decline` | POST 🔒 | teacher | **NOT YET MOCKED.** Triggers a no-cost rematch for the family. |

---

## 10. Teacher: earnings ledger

```ts
type LedgerEntry = {
  id: string;
  occurredAt: string; // ISO
  learnerId: string | null; // null for a payout row
  learnerLabel: string; // "" for a payout row — frontend renders "Payout"
  detail: string;
  reference: string;
  kind: "session" | "payout" | "adjustment";
  grossKobo: number; // fee/net are DERIVED client-side (or here) from the commission rate below — don't store them
  status: "released" | "held" | "paid" | "refunded";
};

type TeacherCommissionInfo = {
  teacherId: string;
  completedSessionsLifetime: number;
  standardRate: number; // 0.15
  reducedRate: number;  // 0.12
  reducedTierAt: number; // 100
};
```

| Endpoint | Method | Auth | Notes |
|---|---|---|---|
| `/teachers/:id/ledger` | GET | teacher (self) | Query params: `period` (`week\|month\|term\|all`), `learnerId`, `kind`, `status`. Filtering can happen server- or client-side; either way, **gross/fee/net/sessions-taught aggregates must always be derived from the currently filtered rows, never cached separately** — that's how the two numbers disagree bug happens. |
| `/teachers/:id/commission` | GET | teacher (self) | As above — powers "88 of 100 · 12 sessions to go." This is a stated commercial promise once shown with a progress bar; confirm the 100-session tier is real before shipping it. |
| `/teachers/:id/payout-method` | GET/PATCH | teacher (self) | Bank details, verification status. |
| `/teachers/:id/withdraw` | POST | teacher (self) | Early/manual withdrawal — note the product copy says this is free once a week, then a flat fee. |
| `/teachers/:id/ledger/export` | GET | teacher (self) | CSV, respecting the same filters as the ledger view. |
| `/teachers/:id/tax-statement` | GET | teacher (self) | |

---

## 11. Teacher: feedback **(payment-release trigger — NOT YET MOCKED)**

Currently `FeedbackForm` only validates client-side and shows a toast. This
is the endpoint that actually releases held payment, so it matters more than
its current toast-only state suggests.

| Endpoint | Method | Auth | Notes |
|---|---|---|---|
| `/sessions/:id/feedback` | GET | teacher (own session) | Draft, if one was saved. |
| `/sessions/:id/feedback/draft` | PUT | teacher (own session) | Autosave — "Save draft" keeps payment held. |
| `/sessions/:id/feedback` | POST 🔒 | teacher (own session) | Body: `{ attendance, ratingOutOf5, note, nextSteps }`. **Reject an empty `note` — this is the record the parent gets of what happened**, and filing it is what releases that session's held payment. Response should confirm the amount released so the frontend's toast can state it (not just "Success"). |
| `/teachers/:id/feedback-queue` | GET | teacher (self) | "Still to file" list on both the Feedback screen and the sidebar — sessions taught but not yet fed back on. |

---

## 12. Teacher: learners roster

| Endpoint | Method | Auth | Notes |
|---|---|---|---|
| `/teachers/:id/learners` | GET | teacher (self) | Active learners with per-learner status (on track / feedback due / paused) for the roster list. |
| `/teachers/:id/stats` | GET | teacher (self) | Rating received, completion %, retention %, request-acceptance %, recognition/badge progress. |

---

## 13. Teacher: schedule diary

```ts
type DiaryCell = { day: string; band: "morning"|"afternoon"|"evening"; state: "free"|"committed"|"requested"|"outside-hours"; label: string | null };
```

| Endpoint | Method | Auth | Notes |
|---|---|---|---|
| `/teachers/:id/diary` | GET | teacher (self, and read-only to a parent/child viewing a specific move request's impact) | Backs the "Your week" grid on both the teacher Schedule detail and (a learner-scoped equivalent) the child's own Schedule tab. |
| `/teachers/:id/availability` | GET/PATCH | teacher (self) | The set hours used to compute `outsideTeacherHours` on move requests (§5) — needs its own CRUD eventually; not yet modeled even in the mock. |
| `/teachers/:id/block-time-off` | POST | teacher (self) | Button exists in the UI; no flow behind it yet. |

---

## 14–18. Admin

Everything here sits behind its own auth guard (`role: "admin"`) and its own
audit middleware — **reads are logged exactly like writes.** Every response
below should be paired with an audit-log write; don't treat this as optional
instrumentation.

```ts
type AuditEntry = {
  actorId: string; actorName: string;
  action: "viewed" | "acted-as" | "approved" | "rejected" | "refunded" | "suspended" | "acted" | "closed";
  subjectId: string;
  reason: string | null; // required for "viewed" and "acted-as"
  at: string; // ISO
};
```

| Endpoint | Method | Notes |
|---|---|---|
| `/internal/audit-log?subjectId=` | GET | Shown to the reviewer inline on the relevant screen, not just recorded. |

### 14. Verification

```ts
type VerificationCheck = {
  kind: "government-id" | "credentials" | "teaching-demo";
  state: "confirmed" | "outstanding" | "problem";
  evidence: string;
  checkedBy: string | null; checkedAt: string | null;
};
type VerificationApplication = {
  id: string; applicantName: string; subjects: string[]; curriculum: string;
  appliedAt: string; // ISO
  checks: VerificationCheck[];
  status: "pending" | "approved" | "rejected" | "needs-more";
  flagNote: string | null; // e.g. certificate/ID name mismatch
};
```

| Endpoint | Method | Notes |
|---|---|---|
| `/internal/verification-applications` | GET | Query: `state` (`open\|waiting\|done`). |
| `/internal/verification-applications/:id` | GET | Fetching this **must** write an audit entry (`action: "viewed"`). |
| `/internal/verification-applications/:id/decide` | POST | Body: `{ decision: "approved"\|"rejected"\|"needs-more", note }`. **Reject an empty note — always.** **Reject `decision: "approved"` server-side if any check is not `"confirmed"`** — this must not be a client-side-only disabled button. Rejecting should carry a stated 90-day reapplication bar. |

### 15. Matching (ops)

```ts
type UnfilledAssessment = { id: string; learnerLabel: string; subject: string; curriculum: string; waitingSince: string /* ISO */; reasonFailed: string };
type SupplyGap = { id: string; subject: string; curriculum: string; learnersWaiting: number; teachersAvailable: number; heat: "none"|"low"|"ok"; revenueAtRiskKobo: number; recruitmentOpen: boolean };
```

| Endpoint | Method | Notes |
|---|---|---|
| `/internal/unfilled-assessments` | GET | Sorted by wait time. |
| `/internal/supply-gaps` | GET | |
| `/internal/supply-gaps/:id/open-recruitment` | POST | **Must also pause new bookings for that subject/curriculum combination** — this is a side effect the endpoint owns, not something the frontend orchestrates as two separate calls. |

### 16. Money

```ts
type StuckMoneyItem = { id: string; reason: "refund"|"held-too-long"|"failed-payout"|"dispute"; amountKobo: number; subjectLabel: string; waitingOnLabel: string; daysStuck: number; detail: string };
```

| Endpoint | Method | Notes |
|---|---|---|
| `/internal/stuck-money` | GET | Query: `kind`, `waitingOn`. |
| `/internal/stuck-money/:id/resolve` | POST | Body: `{ reason }` (required — same "why" the frontend prompts for). **Moves real money — the confirm step must name the amount and the person, and the response should make clear this cannot be undone from this endpoint** (a reversal, if ever needed, should be a distinct, separately-audited action). |

### 17. Safeguarding

```ts
type SafeguardingIncident = {
  id: string; reportedAt: string; // ISO
  severity: "high" | "medium"; status: "open" | "closed";
  learnerLabel: string; teacherLabel: string; summary: string; closedNote: string | null;
};
```

| Endpoint | Method | Notes |
|---|---|---|
| `/internal/safeguarding-incidents` | GET | First-loaded queue in the admin nav — treat as highest priority to keep fast/reliable. |
| `/internal/safeguarding-incidents/:id` | GET | Audit as `"viewed"`. |
| `/internal/teachers/:id/suspend-new-matches` | POST | **Scoped to new matches only — existing lessons must keep running.** This is a distinct, narrower action from a full account suspension; don't reuse a generic "suspend user" endpoint that would also kill live plans. |
| `/internal/safeguarding-incidents/:id/close` | POST | Body: `{ note }` — required, empty rejected. |

### 18. Accounts (support lookup)

```ts
type SupportAccount = { id: string; name: string; role: "parent"|"independent-student"|"child"|"teacher"|"admin"; email: string; isMinor: boolean; guardianLabel: string | null };
```

| Endpoint | Method | Notes |
|---|---|---|
| `/internal/accounts?q=` | GET | **This call itself must write an audit entry** (`action: "viewed"`, `subjectId: "search:<query>"`) — searching is logged, not just opening a result. |
| `/internal/accounts/:id` | GET | If `isMinor`, response should carry `guardianLabel` so the frontend can render the "handle with care — only X and safeguarding staff" panel. |
| `/internal/accounts/:id/act-as` | POST | Body: `{ reason }` — **required, from a constrained list, not free text** (`Parent asked support for help`, `Investigating a payment issue`, etc.). **Must be read-only** — this token/session should not be able to call any 🔒 mutating endpoint elsewhere in this document. A support agent taking a decision on a parent's behalf is never acceptable, and that has to be true at the API layer, not just hidden in the UI that's rendered for it. |

---

## Summary: what's real vs. mocked-as-toast today

| Area | Current frontend state |
|---|---|
| Plans (pause/resume/end) | Real mock mutation (in-memory) |
| Move a lesson (create/accept/decline/propose) | Real mock mutation (in-memory) |
| Rebook/renewal | Real mock mutation (in-memory) |
| Admin verification/matching/money/safeguarding/accounts | Real mock mutation (in-memory) + audit log |
| **Assessment submit** | Client toast only — no persistence |
| **Trial request (create/cancel/confirm)** | Client-only local state — resets on refresh |
| **Match request accept/decline (teacher side)** | Dead button — no handler at all |
| **Checkout/payment** | Static confirmation screen — no provider, no backend call |
| **Feedback submit (payment release)** | Client toast only — no persistence, no actual release |
| **Live session (join/leave/whiteboard/chat)** | Fully static mock data, no provider wired |
| **Auth/session/role resolution** | Doesn't exist — role is currently a demo nav toggle + a cookie for parent/child only |

The four bolded rows in the middle (trial, match-accept, checkout, feedback)
are the ones most worth prioritizing — they're the actual money/commitment
path (assessment → match → trial → pay → teach → feedback → payment release)
and none of them touch a backend yet.

---

**Reminder:** everything above is a guide to what the frontend needs, not a
spec the backend is bound to. Naming, routing style, request/response
envelopes, batching, pagination, versioning — all of that is the backend
team's call. If something here doesn't fit their architecture, change it;
just keep the frontend team in the loop on what the actual shape ends up
being so the two sides stay in sync.
