# Local test accounts

Seeded 2026-09-23 against the local WAMP MySQL databases (`auth`,
`recommendation`, `core`) described in `docs/frontend-integration-guide.md`.
**Local dev only — not real users, not for anything other than a local
`tewtora-core` pointed at your own machine's database.** Password for every
account below: `password123`.

## Accounts

| Role | Email | `public_id` |
|---|---|---|
| parent | `parent@tewtora.test` | `1f5cfce3-b6da-11f1-ad43-00090faa0001` |
| child (linked-login, view-only) | `child@tewtora.test` | `1f75aa8d-b6da-11f1-ad43-00090faa0001` |
| independent_student | `student@tewtora.test` | `1f8ea03c-b6da-11f1-ad43-00090faa0001` |
| teacher | `teacher@tewtora.test` | `1fa7b15d-b6da-11f1-ad43-00090faa0001` |
| admin | `admin@tewtora.test` | `1fc10de9-b6da-11f1-ad43-00090faa0001` |

Login: `POST /api/v1/auth/login` with `{ "email": ..., "password":
"password123" }` → returns a Sanctum token, use as `Authorization: Bearer
<token>` on everything else. Verified working for all five as of the seed
date above.

## Supporting data

| Record | `public_id` | Numeric id | Notes |
|---|---|---|---|
| Learner profile — Ada (child) | `1fc42bb5-b6da-11f1-ad43-00090faa0001` | `1` | Owned by `parent@tewtora.test`, linked login is `child@tewtora.test` |
| Learner profile — Sam (own) | `1fc4f4c9-b6da-11f1-ad43-00090faa0001` | `2` | Owned by `student@tewtora.test` |
| Teacher profile | `1fc604a0-b6da-11f1-ad43-00090faa0001` | `1` | Linked to `teacher@tewtora.test`; `verification_status: approved`, all 3 verification checks `approved`; teaches Mathematics, `ib` curriculum, ₦5,000/session (`rate_minor: 500000`) |

The numeric ids are only needed for the endpoints that still take a raw
numeric id instead of `public_id` (a known inconsistency —
`docs/frontend-integration-guide.md` §3/§7):
- `POST /teachers/{teacherId}/trial-requests` (`tewtora-core`) — `teacherId` in the URL is `1`; the request body's `learner_profile_id` is `1` (Ada) or `2` (Sam), whichever account you're testing as
- `GET /teachers/{teacherId}/ledger` and `.../commission` (`tewtora-payment`) — `teacherId` is `1`, but note this service also needs the `X-Gateway-*` headers instead of a Bearer token (see the integration guide §13) — there's no seeded data in Payment's own database yet, so the ledger will come back empty

## Re-seeding

If the local database ever gets wiped (`php artisan migrate:fresh`,
switching MySQL instances, etc.), these don't come back automatically —
there's no seeder class for them yet. Re-run the same `tinker --execute`
script used to create them, or ask for it again; the `public_id`s above
will change on a re-seed since they're DB-generated (`DEFAULT (UUID())`),
not fixed values.
