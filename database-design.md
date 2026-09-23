# Database Design

Managed tutoring marketplace — MySQL schema, v2.2

> Revision note: v2.0 targeted PostgreSQL. v2.1 retargeted MySQL 8.0+ at the
> project's request — surrogate/public key split, integer money, explicit
> soft-delete policy, full DDL with constraints and indexes, security/
> compliance and scaling notes, all translated to MySQL 8 equivalents
> (`ON UPDATE CURRENT_TIMESTAMP` instead of a trigger for `updated_at`,
> `JSON` instead of `JSONB`, generated columns standing in for Postgres's
> partial unique indexes, `UUID()` instead of `gen_random_uuid()`).
>
> v2.2 implements the gap analysis run against `docs/api-contract.md` (the
> frontend's endpoint/data contract) — see `docs/api-gap-analysis.md` for
> the reasoning behind each addition. Net new: `plans` + `plan_goals` (the
> previously-missing recurring-lesson entity almost everything else hangs
> off), `trial_requests`, `move_requests` + `move_approvals`,
> `teacher_verification_checks` (replaces the old flat verification
> columns' inability to track three independent review states),
> `teacher_time_off`, `safeguarding_incidents`, `audit_log` (flagged since
> v2.0, never built until now), and a fifth/sixth `accounts.account_type`
> (`child`, `admin`). `assessments` and `feedback` both gained draft/submit
> states. The Payment service's own ledger additions
> (`payment_quotes`/`payment_line_items`/`commission_tiers`) are documented
> in `docs/microservices-architecture.md` §7.6, following the same pattern
> `payment_providers` already used there.

---

## 1. Conventions & design principles

### 1.1 Naming

- Tables: plural, snake_case (`learner_profiles`, `teacher_availability`).
- Columns: snake_case; boolean columns prefixed `is_`/`has_`; timestamps suffixed `_at`; foreign keys named `<singular_table>_id`.
- Primary keys are always literally named `id`; do not embed the table name in the PK column.

### 1.2 Key strategy: surrogate BIGINT + public UUID

Every table uses a `BIGINT UNSIGNED AUTO_INCREMENT` column as the physical primary key (fast joins, small index footprint, sequential inserts). A separate `public_id` UUID column (`CHAR(36)`, MySQL has no native UUID type) is exposed to APIs, URLs, and clients. This avoids enumeration/scraping of internal ids (a parent's child roster, a teacher's session count) while keeping join performance on the internal key. Never expose `id` externally.

```sql
id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
public_id     CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
```

`DEFAULT (UUID())` is an expression default, supported since MySQL 8.0.13. On older MySQL this has to move to the application (or a `BEFORE INSERT` trigger) — assume 8.0.13+ throughout.

### 1.3 Timestamps & audit columns

- All timestamps are `DATETIME(6)`, stored in UTC; convert at the presentation layer, never in the database. `DATETIME` is used over `TIMESTAMP` deliberately — MySQL's `TIMESTAMP` range ends in 2038 and it auto-converts to/from the session time zone, which is exactly the kind of implicit conversion this project wants to avoid; `DATETIME` stores what's written, no more, no less.
- `created_at` defaults to `CURRENT_TIMESTAMP(6)`; `updated_at` uses MySQL's native `ON UPDATE CURRENT_TIMESTAMP(6)` column attribute, not application code or a trigger — this is simpler than the Postgres v2.0 design, which needed a shared trigger function because Postgres has no equivalent column attribute.

```sql
created_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
updated_at    DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
```

### 1.4 Soft delete: applied selectively, not globally

`deleted_at DATETIME(6) NULL` is added only to entities a person can reasonably "remove" and where recovery matters: `accounts`, `learner_profiles`, `teachers`. Transactional and evidentiary tables — `sessions`, `payments`, `payouts`, `feedback`, `ratings` — are never soft-deleted; they are append-only and move through status values instead. Financial and academic records must remain queryable for dispute resolution and audit regardless of what a user does to their profile.

### 1.5 Money: integer minor units, never FLOAT/DECIMAL-as-display

All monetary amounts are stored as `BIGINT` in the currency's smallest unit (e.g. kobo for NGN), paired with a `currency_code CHAR(3)`. This eliminates floating-point rounding entirely; formatting to major units (÷100) happens only at display time.

```sql
amount_minor     BIGINT NOT NULL CHECK (amount_minor >= 0),
currency_code    CHAR(3) NOT NULL DEFAULT 'NGN',
```

MySQL has enforced `CHECK` constraints since 8.0.16 (earlier versions parse but silently ignore them) — this schema assumes 8.0.16+.

### 1.6 Enumerated values: lookup tables for open domains, CHECK constraints for closed ones

MySQL's native `ENUM` type is avoided for the same reason it was avoided under Postgres — adding a value later is a schema change either way, and a `VARCHAR` + `CHECK` reads the same in every client without needing to inspect column metadata. The rule applied here:

- Domains that will genuinely grow over time (subjects, curricula, payment methods) are lookup tables with a surrogate key — new rows, not schema migrations, add a new subject.
- Domains that are small, stable, and part of core business logic (session status, payment status, verification status) are a `VARCHAR` with a `CHECK` constraint — a lookup table for a 4-value status most callers will hardcode in application logic anyway adds a join for no real flexibility gain.

### 1.7 Referential integrity policy

- `ON DELETE RESTRICT` (default posture) on any FK pointing at a financial or academic record — you cannot delete a teacher or learner profile out from under existing sessions/payments; deactivate (`deleted_at`) instead.
- `ON DELETE CASCADE` is used narrowly, only for true ownership compositions with no independent meaning: `teacher_availability` rows, `teacher_subjects`/`teacher_curricula` join rows, and `assessment_subjects` join rows die with their parent.
- `ON DELETE SET NULL` is used where the child record should survive its parent's removal as a historical fact: `learner_profiles.linked_login_account_id`, `sessions.match_id`.
- All of the above require InnoDB (the default engine since MySQL 5.5) — `MyISAM` does not enforce foreign keys at all. Every table in this schema is InnoDB.

### 1.8 Indexing philosophy

- Every foreign key column is indexed explicitly — InnoDB does add an index automatically for FK columns, but composite/covering indexes for actual hot read paths still need to be added by hand.
- Composite indexes are built for the actual hot read paths (a learner's upcoming sessions, a teacher's pending matches), not speculatively for every column combination.
- **No partial (filtered) indexes.** Postgres's `CREATE INDEX ... WHERE <condition>` has no MySQL equivalent. Two different translations are used here depending on whether the partial index was load-bearing for correctness or just a scan-size optimization:
  - Where it only narrowed a scan (e.g. "teacher's pending-match inbox", "unpublished outbox rows"), it becomes an ordinary, unfiltered index — MySQL scans a slightly larger index range, but the query's `WHERE` clause still returns the correct rows.
  - Where the partial index was enforcing a **uniqueness** rule scoped to live rows (`ux_accounts_email ... WHERE deleted_at IS NULL`, allowing an email to be reused after a soft delete), that can't be dropped without changing behavior. It's reproduced with a generated column: a `STORED` column that evaluates to the unique value when the row is "live" and `NULL` otherwise, with a `UNIQUE` index on the generated column — InnoDB unique indexes treat each `NULL` as distinct, so soft-deleted rows never collide. See `accounts` in §3.1 for the concrete pattern.
  - `ux_payments_provider_ref_per_provider`'s Postgres partial index (`WHERE provider_reference IS NOT NULL`) needs no such trick — a plain MySQL `UNIQUE (provider_id, provider_reference)` already allows unlimited rows with a `NULL` `provider_reference`, which is the same behavior.

---

## 2. Reference / lookup tables

```sql
CREATE TABLE subjects (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40) NOT NULL UNIQUE,      -- 'mathematics', 'further_maths'
  display_name  VARCHAR(80) NOT NULL,
  is_active     BOOLEAN NOT NULL DEFAULT true
) ENGINE=InnoDB;

CREATE TABLE curricula (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40) NOT NULL UNIQUE,      -- 'ng_waec_neco', 'uk_igcse', 'us_common_core', 'ib'
  display_name  VARCHAR(80) NOT NULL,
  is_active     BOOLEAN NOT NULL DEFAULT true
) ENGINE=InnoDB;

CREATE TABLE payment_methods (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  code          VARCHAR(40) NOT NULL UNIQUE,      -- 'card', 'bank_transfer', 'ussd', 'mobile_money'
  display_name  VARCHAR(80) NOT NULL,
  is_active     BOOLEAN NOT NULL DEFAULT true
) ENGINE=InnoDB;
```

---

## 3. Core schema

### 3.1 Identity & access

`email` has no MySQL equivalent of Postgres's `CITEXT` type, but MySQL 8's default collation (`utf8mb4_0900_ai_ci`) is already case-insensitive (`_ai_` = accent-insensitive, `_ci_` = case-insensitive) for comparison and uniqueness — a plain `VARCHAR` gets the same case-insensitive-login behavior `CITEXT` gave under Postgres, with no extra type. The `WHERE deleted_at IS NULL` partial unique index becomes the generated-column pattern from §1.8.

`account_type` carries five roles, not the original three — `child` (a minor's own view-only login, via `learner_profiles.linked_login_account_id`) and `admin` (internal staff: verification review, safeguarding, support) were added in v2.2 once `docs/api-contract.md` made both real login paths rather than theoretical ones. This reuses `accounts` rather than a separate staff table — see `docs/api-gap-analysis.md` §0 for the tradeoff.

```sql
CREATE TABLE accounts (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id       CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
  email           VARCHAR(255) NOT NULL,           -- case-insensitive via utf8mb4_0900_ai_ci
  email_live      VARCHAR(255) AS (IF(deleted_at IS NULL, email, NULL)) STORED,
  phone           VARCHAR(20),
  password_hash   VARCHAR(255) NOT NULL,           -- argon2id
  account_type    VARCHAR(20) NOT NULL
                    CHECK (account_type IN ('parent','independent_student','child','teacher','admin')),
  email_verified_at DATETIME(6),
  created_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at      DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  deleted_at      DATETIME(6),
  UNIQUE KEY ux_accounts_email_live (email_live),  -- partial-unique-index equivalent; see §1.8
  KEY ix_accounts_type (account_type)
) ENGINE=InnoDB;
```

### 3.2 Learner & assessment

```sql
CREATE TABLE learner_profiles (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id              CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
  owner_account_id       BIGINT UNSIGNED NOT NULL,
  linked_login_account_id BIGINT UNSIGNED,
  profile_type           VARCHAR(10) NOT NULL CHECK (profile_type IN ('child','own')),
  full_name              VARCHAR(120) NOT NULL,
  date_of_birth          DATE,
  grade_level            VARCHAR(60) NOT NULL,
  curriculum_id           BIGINT UNSIGNED NOT NULL,
  created_at             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  deleted_at             DATETIME(6),
  CONSTRAINT fk_learner_profiles_owner FOREIGN KEY (owner_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_learner_profiles_linked_login FOREIGN KEY (linked_login_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  CONSTRAINT fk_learner_profiles_curriculum FOREIGN KEY (curriculum_id) REFERENCES curricula(id) ON DELETE RESTRICT,
  KEY ix_learner_profiles_owner (owner_account_id),
  KEY ix_learner_profiles_linked_login (linked_login_account_id)
) ENGINE=InnoDB;
```

> **Caught only by actually running this against real MySQL, not by linting
> or model tests:** `chk_child_has_no_self_login` cannot be a `CHECK`
> constraint at all — MySQL rejects it outright (error 3823) because
> `linked_login_account_id` also carries an `ON DELETE SET NULL` foreign
> key action, and MySQL won't let a `CHECK` reference a column a FK can
> mutate out from under it. Rebuilt as a `BEFORE INSERT`/`BEFORE UPDATE`
> trigger (`trg_learner_profiles_no_self_login_*`), the same pattern
> already used for the consent-required-if-minor rule in §3.2 below.

```sql

CREATE TABLE assessments (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  learner_profile_id  BIGINT UNSIGNED NOT NULL,
  status              VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','submitted')),
  academic_challenges JSON,                -- array of strings; MySQL has no native TEXT[] type
  learning_goals      JSON,                -- array of strings; required only once submitted (see CHECK below)
  budget_tier         VARCHAR(10) CHECK (budget_tier IN ('basic','standard','premium')),
  preferred_format    VARCHAR(15)
                        CHECK (preferred_format IN ('one_on_one','group','no_preference')),
  session_frequency   VARCHAR(15)
                        CHECK (session_frequency IN ('weekly','twice_weekly','custom')),
  availability        JSON,              -- [{day, start, end}, ...] — no fixed cardinality, JSON is appropriate
  consent_given        BOOLEAN NOT NULL DEFAULT false,
  submitted_at         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_assessments_learner FOREIGN KEY (learner_profile_id) REFERENCES learner_profiles(id) ON DELETE RESTRICT,
  CONSTRAINT chk_learning_goals_present_if_submitted
    CHECK (status = 'draft' OR (learning_goals IS NOT NULL AND JSON_LENGTH(learning_goals) > 0)),
  KEY ix_assessments_learner (learner_profile_id, submitted_at)
) ENGINE=InnoDB;

CREATE TABLE assessment_subjects (   -- many-to-many: an assessment can name several subjects
  assessment_id  BIGINT UNSIGNED NOT NULL,
  subject_id     BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (assessment_id, subject_id),
  CONSTRAINT fk_assessment_subjects_assessment FOREIGN KEY (assessment_id) REFERENCES assessments(id) ON DELETE CASCADE,
  CONSTRAINT fk_assessment_subjects_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT
) ENGINE=InnoDB;
```

> **Flagged, not silently fixed:** the consent rule (`consent_given` must be `true` for any assessment tied to a `child` profile) is a cross-row rule — it depends on `learner_profiles.profile_type`, not just the row being written — so it cannot be a `CHECK` constraint at all under MySQL (`CHECK` may only reference columns of the same row). Same conclusion as the Postgres version's flagged note, for a different mechanical reason: it belongs in a `BEFORE INSERT`/`BEFORE UPDATE` trigger or the application write path, not a bare `CHECK`. See `docs/backend-engineering-standards.md` §9 for the application-layer version of this rule (`ConsentRequiredIfMinor`); the trigger implementation lives in the migration itself. As of v2.2 the trigger only fires when `NEW.status = 'submitted'` — unscoped, it would reject every draft autosave of a child profile before consent has ever been collected.
>
> **A second flag, caught while writing this section up:** `chk_learning_goals_present_if_submitted` must guard `learning_goals IS NOT NULL` explicitly before calling `JSON_LENGTH(...)` — `JSON_LENGTH(NULL) > 0` evaluates to `NULL`, and MySQL (standard SQL) treats a `CHECK` that evaluates to `NULL` as *passing*, not failing. Without the explicit `IS NOT NULL`, a submitted assessment with `learning_goals` left unset would silently satisfy the constraint instead of being rejected. Worth re-checking any future status-conditioned `CHECK` for the same trap.

### 3.3 Teacher & verification

`id_verified_at`/`credentials_verified_at`/`demo_status`/`verification_status` remain as the *overall* rollup, but as of v2.2 they are no longer where verification review actually happens — see `teacher_verification_checks` below for why. `max_group_size` and `new_matches_suspended_at` are new columns, not new tables: `max_group_size` is a plain cap (`docs/api-contract.md` §3's `Teacher.groupSize` had no home before); `new_matches_suspended_at` is safeguarding's narrower-than-`deleted_at`/`verification_status` suspension (§17) — existing lessons keep running, only new matching stops.

```sql
CREATE TABLE teachers (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id             CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
  account_id            BIGINT UNSIGNED NOT NULL UNIQUE,
  years_experience      SMALLINT UNSIGNED NOT NULL,
  bio                   TEXT,
  preferred_format      VARCHAR(15) NOT NULL
                          CHECK (preferred_format IN ('one_on_one','group','both')),
  max_group_size        SMALLINT UNSIGNED,
  rate_minor            BIGINT NOT NULL CHECK (rate_minor >= 0),
  currency_code         CHAR(3) NOT NULL DEFAULT 'NGN',
  id_verified_at        DATETIME(6),
  credentials_verified_at DATETIME(6),
  demo_status           VARCHAR(15) NOT NULL DEFAULT 'not_submitted'
                          CHECK (demo_status IN ('not_submitted','submitted','scheduled','completed')),
  verification_status   VARCHAR(10) NOT NULL DEFAULT 'pending'
                          CHECK (verification_status IN ('pending','approved','rejected')),
  new_matches_suspended_at DATETIME(6),
  rating_avg            DECIMAL(3,2),          -- denormalized rollup, see §6
  created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  deleted_at            DATETIME(6),
  CONSTRAINT fk_teachers_account FOREIGN KEY (account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  KEY ix_teachers_verification_status (verification_status)
) ENGINE=InnoDB;

-- v2.2: three independently tracked checks per teacher, each with its own
-- pending|in_review|approved|rejected state — the flat columns above can't
-- represent that (demo_status has its own unrelated vocabulary, and
-- id_verified_at/credentials_verified_at are just timestamps, no
-- in-review/rejected state at all). Serves both the teacher-facing
-- verification badge and the admin verification queue
-- (docs/api-contract.md §3/§14) from one source of truth.
CREATE TABLE teacher_verification_checks (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id            BIGINT UNSIGNED NOT NULL,
  kind                  VARCHAR(20) NOT NULL
                          CHECK (kind IN ('government_id','credentials','teaching_demo')),
  state                 VARCHAR(15) NOT NULL DEFAULT 'pending'
                          CHECK (state IN ('pending','in_review','approved','rejected')),
  evidence              TEXT,
  checked_by_account_id BIGINT UNSIGNED,
  checked_at            DATETIME(6),
  created_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at            DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_teacher_verification_checks_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_teacher_verification_checks_checked_by FOREIGN KEY (checked_by_account_id) REFERENCES accounts(id) ON DELETE SET NULL,
  UNIQUE KEY ux_teacher_verification_checks_teacher_kind (teacher_id, kind)
) ENGINE=InnoDB;

CREATE TABLE teacher_subjects (
  teacher_id  BIGINT UNSIGNED NOT NULL,
  subject_id  BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (teacher_id, subject_id),
  CONSTRAINT fk_teacher_subjects_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_teacher_subjects_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE teacher_curricula (
  teacher_id    BIGINT UNSIGNED NOT NULL,
  curriculum_id BIGINT UNSIGNED NOT NULL,
  PRIMARY KEY (teacher_id, curriculum_id),
  CONSTRAINT fk_teacher_curricula_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT fk_teacher_curricula_curriculum FOREIGN KEY (curriculum_id) REFERENCES curricula(id) ON DELETE RESTRICT
) ENGINE=InnoDB;

CREATE TABLE teacher_availability (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id   BIGINT UNSIGNED NOT NULL,
  day_of_week  TINYINT UNSIGNED NOT NULL CHECK (day_of_week BETWEEN 0 AND 6),
  start_time   TIME NOT NULL,
  end_time     TIME NOT NULL,
  CONSTRAINT fk_teacher_availability_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT chk_availability_window CHECK (end_time > start_time),
  KEY ix_teacher_availability_teacher (teacher_id, day_of_week)
) ENGINE=InnoDB;

-- v2.2: §7's open decision resolved — `teacher_availability` above models
-- weekly recurring slots only; date-specific exceptions (holiday
-- blackouts, one-off unavailability, docs/api-contract.md §13's
-- `block-time-off`) are this separate, additive table, not a rework of it.
CREATE TABLE teacher_time_off (
  id           BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id   BIGINT UNSIGNED NOT NULL,
  starts_at    DATETIME(6) NOT NULL,
  ends_at      DATETIME(6) NOT NULL,
  reason       VARCHAR(255),
  created_at   DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_teacher_time_off_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE CASCADE,
  CONSTRAINT chk_teacher_time_off_window CHECK (ends_at > starts_at),
  KEY ix_teacher_time_off_teacher (teacher_id, starts_at)
) ENGINE=InnoDB;
```

### 3.4 Matching & scheduling

```sql
CREATE TABLE matches (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id           CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,  -- v2.2: matches now has a real API surface (docs/api-contract.md §9)
  learner_profile_id  BIGINT UNSIGNED NOT NULL,
  teacher_id          BIGINT UNSIGNED NOT NULL,
  match_reasoning     TEXT,
  status              VARCHAR(10) NOT NULL DEFAULT 'proposed'
                        CHECK (status IN ('proposed','accepted','declined')),
  decline_reason      TEXT,
  created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_matches_learner FOREIGN KEY (learner_profile_id) REFERENCES learner_profiles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_matches_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
  KEY ix_matches_learner_status (learner_profile_id, status),
  KEY ix_matches_teacher_status (teacher_id, status)   -- unfiltered; see §1.8 (was a WHERE status='proposed' partial index)
) ENGINE=InnoDB;

CREATE TABLE sessions (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id           CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
  match_id            BIGINT UNSIGNED,
  plan_id             BIGINT UNSIGNED,
  learner_profile_id  BIGINT UNSIGNED NOT NULL,
  teacher_id          BIGINT UNSIGNED NOT NULL,
  scheduled_at         DATETIME(6) NOT NULL,
  format               VARCHAR(10) NOT NULL CHECK (format IN ('one_on_one','group')),
  is_trial             BOOLEAN NOT NULL DEFAULT false,
  status               VARCHAR(15) NOT NULL DEFAULT 'pending'
                         CHECK (status IN ('pending','confirmed','scheduled','in_progress','completed','no_show','cancelled')),
  created_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_sessions_match FOREIGN KEY (match_id) REFERENCES matches(id) ON DELETE SET NULL,
  CONSTRAINT fk_sessions_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE SET NULL,
  CONSTRAINT fk_sessions_learner FOREIGN KEY (learner_profile_id) REFERENCES learner_profiles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sessions_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
  KEY ix_sessions_plan (plan_id),
  KEY ix_sessions_learner_scheduled (learner_profile_id, scheduled_at),
  KEY ix_sessions_teacher_scheduled (teacher_id, scheduled_at),
  KEY ix_sessions_status_scheduled (status, scheduled_at)  -- unfiltered; see §1.8 (was a WHERE status='scheduled' partial index)
) ENGINE=InnoDB;
```

`status` also carries the `pending`/`confirmed` values the booking saga (`docs/microservices-architecture.md` §4) needs before a session reaches `scheduled` — same reconciliation applied under the Postgres version.

`match_id` is kept as the one-time origin of a session (historical); `plan_id`, added in v2.2, is the operative link for every session a recurring plan generates afterward — see `plans` immediately below, which `sessions.plan_id` forward-references (create `plans` first in an actual migration run; the order here follows the doc's existing "matching, then scheduling" narrative instead).

```sql
-- v2.2: the single biggest gap the original schema had. A learner's
-- recurring lesson arrangement (teacher, subject, days/time, rate,
-- sessions remaining) had no table of its own — Home, Plans, Progress,
-- Rebook, and Move-a-lesson (docs/api-contract.md §4-6) all hang off this.
-- A learner holds many plans concurrently (one per subject/teacher):
-- learner_profile_id is deliberately not unique.
CREATE TABLE plans (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id            CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
  learner_profile_id   BIGINT UNSIGNED NOT NULL,
  teacher_id           BIGINT UNSIGNED NOT NULL,
  subject_id           BIGINT UNSIGNED NOT NULL,
  format               VARCHAR(10) NOT NULL CHECK (format IN ('one_on_one','group')),
  days                 JSON NOT NULL,           -- e.g. ["tue","thu"]
  time_of_day          TIME NOT NULL,
  status               VARCHAR(10) NOT NULL DEFAULT 'active' CHECK (status IN ('active','paused','ended')),  -- 'ended' added later: see the CHECK note below
  rate_minor           BIGINT NOT NULL CHECK (rate_minor >= 0),
  currency_code        CHAR(3) NOT NULL DEFAULT 'NGN',
  sessions_per_month   SMALLINT UNSIGNED NOT NULL,
  sessions_remaining   SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  renews_at            DATETIME(6),
  reference            VARCHAR(30) NOT NULL UNIQUE,   -- e.g. TWT-2026-04817
  paid_to_date_minor   BIGINT NOT NULL DEFAULT 0 CHECK (paid_to_date_minor >= 0),
  created_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  updated_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_plans_learner FOREIGN KEY (learner_profile_id) REFERENCES learner_profiles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_plans_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_plans_subject FOREIGN KEY (subject_id) REFERENCES subjects(id) ON DELETE RESTRICT,
  CONSTRAINT chk_plans_renews_at_if_active CHECK (status != 'active' OR renews_at IS NOT NULL),
  KEY ix_plans_learner (learner_profile_id, status),
  KEY ix_plans_teacher (teacher_id, status)
) ENGINE=InnoDB;
```

> **Added after the fact, caught while implementing `DELETE /plans/:id`:** the
> original v2.2 DDL above only had `active`/`paused` — but "ends the plan"
> (docs/api-contract.md §4) needs a terminal state, and `plans` has no
> `deleted_at` (only `accounts`/`learner_profiles`/`teachers` get soft
> deletes). `status` gained `'ended'`, and `chk_plans_renews_at_if_active`
> was rewritten from `status = 'paused' OR renews_at IS NOT NULL` to
> `status != 'active' OR renews_at IS NOT NULL` — the original would have
> incorrectly still demanded a `renews_at` on an ended plan.

```sql
-- v2.2: not derivable from anything else stored — a table, not a JSON
-- column, for the same reason progress_reports (§3.6) is rows, not a blob.
CREATE TABLE plan_goals (
  id         BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id  CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,  -- added once this got its own API resource
  plan_id    BIGINT UNSIGNED NOT NULL,
  label    VARCHAR(120) NOT NULL,
  pct      DECIMAL(5,2) NOT NULL DEFAULT 0 CHECK (pct BETWEEN 0 AND 100),
  CONSTRAINT fk_plan_goals_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE CASCADE,
  KEY ix_plan_goals_plan (plan_id)
) ENGINE=InnoDB;

-- v2.2: the trial-request negotiation stage (pending -> teacher
-- accepts/declines, 12h expiry) that precedes a real `sessions` row with
-- is_trial = true (docs/api-contract.md §3).
CREATE TABLE trial_requests (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id           CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
  learner_profile_id  BIGINT UNSIGNED NOT NULL,
  teacher_id          BIGINT UNSIGNED NOT NULL,
  session_id          BIGINT UNSIGNED,     -- set once accepted
  slot_starts_at      DATETIME(6) NOT NULL,
  duration_minutes    SMALLINT UNSIGNED NOT NULL,
  status              VARCHAR(10) NOT NULL DEFAULT 'pending'
                        CHECK (status IN ('pending','accepted','declined','cancelled','expired')),
  expires_at          DATETIME(6) NOT NULL,
  created_at          DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_trial_requests_learner FOREIGN KEY (learner_profile_id) REFERENCES learner_profiles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_trial_requests_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_trial_requests_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE SET NULL,
  KEY ix_trial_requests_learner (learner_profile_id, status),
  KEY ix_trial_requests_teacher (teacher_id, status)
) ENGINE=InnoDB;

-- v2.2: rescheduling a lesson is a genuine multi-party workflow, not a
-- simple PATCH — a small-group move needs one approval per affected
-- family plus the teacher, and nothing touches the plan's actual schedule
-- until every party has accepted (docs/api-contract.md §5). `kind` also
-- carries 'renewal' since renewals surface on the same teacher Schedule
-- screen as moves.
CREATE TABLE move_requests (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id              CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
  plan_id                BIGINT UNSIGNED NOT NULL,
  kind                   VARCHAR(10) NOT NULL CHECK (kind IN ('move','renewal')),
  route                  VARCHAR(20) CHECK (route IS NULL OR route IN ('move_learner','move_group','to_one_to_one')),
  reason                 TEXT NOT NULL,
  from_day               VARCHAR(10) NOT NULL,
  from_starts_at         TIME NOT NULL,
  to_day                 VARCHAR(10) NOT NULL,
  to_starts_at           TIME NOT NULL,
  outside_teacher_hours  BOOLEAN NOT NULL DEFAULT false,   -- computed server-side against teacher_availability
  status                 VARCHAR(10) NOT NULL DEFAULT 'pending'
                           CHECK (status IN ('pending','accepted','declined','withdrawn','expired')),
  expires_at             DATETIME(6) NOT NULL,             -- 24h for a single-party move, 5 days for a group move
  gross_minor            BIGINT NOT NULL CHECK (gross_minor >= 0),
  created_at             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_move_requests_plan FOREIGN KEY (plan_id) REFERENCES plans(id) ON DELETE RESTRICT,
  KEY ix_move_requests_plan (plan_id, status)
) ENGINE=InnoDB;

CREATE TABLE move_approvals (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  move_request_id   BIGINT UNSIGNED NOT NULL,
  party_account_id  BIGINT UNSIGNED NOT NULL,
  party_label       VARCHAR(120) NOT NULL,
  role              VARCHAR(10) NOT NULL CHECK (role IN ('teacher','parent')),
  state             VARCHAR(10) NOT NULL DEFAULT 'pending'
                       CHECK (state IN ('pending','accepted','declined')),
  responded_at      DATETIME(6),
  CONSTRAINT fk_move_approvals_move_request FOREIGN KEY (move_request_id) REFERENCES move_requests(id) ON DELETE CASCADE,
  UNIQUE KEY ux_move_approvals_request_party (move_request_id, party_account_id),
  KEY ix_move_approvals_party (party_account_id, state)
) ENGINE=InnoDB;
```

### 3.5 Payments & payouts

```sql
CREATE TABLE payments (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id           CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
  payer_account_id    BIGINT UNSIGNED NOT NULL,
  session_id          BIGINT UNSIGNED,             -- null for plan payments
  payment_method_id   BIGINT UNSIGNED NOT NULL,
  plan_type           VARCHAR(15) NOT NULL
                        CHECK (plan_type IN ('per_session','weekly','monthly')),
  sessions_covered    SMALLINT UNSIGNED NOT NULL CHECK (sessions_covered > 0),
  amount_minor        BIGINT NOT NULL CHECK (amount_minor >= 0),
  currency_code       CHAR(3) NOT NULL DEFAULT 'NGN',
  status               VARCHAR(12) NOT NULL DEFAULT 'processing'
                         CHECK (status IN ('processing','success','failed','pending')),
  provider_reference   VARCHAR(120),         -- gateway transaction id, for reconciliation
  created_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_payments_payer FOREIGN KEY (payer_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE RESTRICT,
  KEY ix_payments_payer (payer_account_id, created_at)
) ENGINE=InnoDB;

CREATE TABLE payouts (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  teacher_id    BIGINT UNSIGNED NOT NULL,
  amount_minor  BIGINT NOT NULL CHECK (amount_minor >= 0),
  currency_code CHAR(3) NOT NULL DEFAULT 'NGN',
  method_id     BIGINT UNSIGNED NOT NULL,
  schedule      VARCHAR(10) NOT NULL CHECK (schedule IN ('weekly','biweekly','monthly')),
  period_start  DATE NOT NULL,
  period_end    DATE NOT NULL,
  status        VARCHAR(12) NOT NULL DEFAULT 'processing'
                  CHECK (status IN ('processing','paid','failed')),
  CONSTRAINT fk_payouts_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payouts_method FOREIGN KEY (method_id) REFERENCES payment_methods(id) ON DELETE RESTRICT,
  CONSTRAINT chk_payout_period CHECK (period_end > period_start),
  KEY ix_payouts_teacher_period (teacher_id, period_start)
) ENGINE=InnoDB;
```

> **Superseded by `docs/microservices-architecture.md` §7.2:** `payments.provider_reference` is refined there into a `payment_providers` lookup table plus a `provider_id` FK, with the uniqueness constraint scoped to `(provider_id, provider_reference)` instead of global. Apply that version — it's the one the Payment service actually implements. Under MySQL this needs no generated-column trick (§1.8): `UNIQUE (provider_id, provider_reference)` already allows unlimited `NULL` `provider_reference` rows per provider.
>
> **Further extended in `docs/microservices-architecture.md` §7.6 (v2.2):** the "hold the full amount, release one session's fee after that session is taught and fed back on" rule (`docs/api-contract.md` §7) can't be represented by a single `payments.status` — a `quote_id` column and two new tables (`payment_quotes`, `payment_line_items`) live there, alongside a `commission_tiers` config table.

### 3.6 Feedback & progress

`attendance`, `status`, and the relaxed `NOT NULL`s on `session_notes`/`progress_rating` are v2.2 additions — `docs/api-contract.md` §11 needs "Save draft" to keep the session's held payment held, and only a `submitted` row (with a note and a rating) should trigger the release. `UNIQUE(session_id)` still holds: one feedback row per session, draft or final.

```sql
CREATE TABLE feedback (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id          BIGINT UNSIGNED NOT NULL UNIQUE,
  teacher_id          BIGINT UNSIGNED NOT NULL,
  learner_profile_id  BIGINT UNSIGNED NOT NULL,
  attendance          VARCHAR(15) CHECK (attendance IS NULL OR attendance IN ('present','absent','late')),
  status              VARCHAR(10) NOT NULL DEFAULT 'draft' CHECK (status IN ('draft','submitted')),
  session_notes       TEXT,
  progress_rating     TINYINT UNSIGNED CHECK (progress_rating IS NULL OR progress_rating BETWEEN 1 AND 5),
  next_steps          TEXT,
  submitted_at         DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_feedback_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_feedback_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_feedback_learner FOREIGN KEY (learner_profile_id) REFERENCES learner_profiles(id) ON DELETE RESTRICT,
  CONSTRAINT chk_feedback_notes_present_if_submitted
    CHECK (status = 'draft' OR (session_notes IS NOT NULL AND session_notes != '')),
  CONSTRAINT chk_feedback_rating_present_if_submitted
    CHECK (status = 'draft' OR progress_rating IS NOT NULL),
  KEY ix_feedback_learner (learner_profile_id, submitted_at)
) ENGINE=InnoDB;

CREATE TABLE progress_reports (      -- materialized rollup, see §6 for refresh strategy
  learner_profile_id  BIGINT UNSIGNED PRIMARY KEY,
  attendance_pct       DECIMAL(5,2) NOT NULL DEFAULT 0,
  goal_progress_pct    DECIMAL(5,2) NOT NULL DEFAULT 0,
  sessions_completed   INT UNSIGNED NOT NULL DEFAULT 0,
  updated_at           DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6) ON UPDATE CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_progress_reports_learner FOREIGN KEY (learner_profile_id) REFERENCES learner_profiles(id) ON DELETE CASCADE
) ENGINE=InnoDB;

CREATE TABLE ratings (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  session_id            BIGINT UNSIGNED NOT NULL,
  rated_by_account_id   BIGINT UNSIGNED NOT NULL,
  teacher_id            BIGINT UNSIGNED NOT NULL,
  stars                 TINYINT UNSIGNED NOT NULL CHECK (stars BETWEEN 1 AND 5),
  comment               TEXT,
  created_at             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_ratings_session FOREIGN KEY (session_id) REFERENCES sessions(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ratings_rater FOREIGN KEY (rated_by_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ratings_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
  UNIQUE KEY ux_ratings_session_rater (session_id, rated_by_account_id),   -- one rating per rater per session
  KEY ix_ratings_teacher (teacher_id, created_at)
) ENGINE=InnoDB;
```

### 3.7 Safeguarding & audit (v2.2)

`safeguarding_incidents` doesn't fit cleanly into any of the four logical services as originally scoped — it reads/writes across Auth (the teacher-suspension side effect), Core (which session triggered it), and needs its own audit trail. It's placed in Auth here because the highest-stakes side effect (`teachers.new_matches_suspended_at`, §3.3) is an Auth-owned column; see `docs/api-gap-analysis.md` §17 for the alternative considered and rejected. `session_id` is deliberately not a FK — cross-domain reference into `sessions`, plain indexed column only, same rule as everywhere else a domain boundary is crossed (see `docs/microservices-architecture.md` §2).

```sql
CREATE TABLE safeguarding_incidents (
  id                      BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  public_id               CHAR(36) NOT NULL DEFAULT (UUID()) UNIQUE,
  learner_profile_id      BIGINT UNSIGNED NOT NULL,
  teacher_id              BIGINT UNSIGNED NOT NULL,
  session_id              BIGINT UNSIGNED,
  reported_by_account_id  BIGINT UNSIGNED NOT NULL,
  severity                VARCHAR(10) NOT NULL CHECK (severity IN ('high','medium')),
  status                  VARCHAR(10) NOT NULL DEFAULT 'open' CHECK (status IN ('open','closed')),
  summary                 TEXT NOT NULL,
  closed_note             TEXT,
  reported_at             DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  closed_at               DATETIME(6),
  CONSTRAINT fk_safeguarding_incidents_learner FOREIGN KEY (learner_profile_id) REFERENCES learner_profiles(id) ON DELETE RESTRICT,
  CONSTRAINT fk_safeguarding_incidents_teacher FOREIGN KEY (teacher_id) REFERENCES teachers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_safeguarding_incidents_reporter FOREIGN KEY (reported_by_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  CONSTRAINT chk_safeguarding_closed_note CHECK (status = 'open' OR closed_note IS NOT NULL),
  KEY ix_safeguarding_incidents_status (status, reported_at)
) ENGINE=InnoDB;
```

`audit_log` was flagged as required before production launch as far back as v2.0 (§5) and never actually built until `docs/api-contract.md` §14-18 made it unambiguously load-bearing — every write to `learner_profiles`/`payments`/`teachers.verification_status`, and now every admin `GET`, needs a row here. It's a cross-cutting concern (written by Auth, Recommendation, and Core alike within the bundled deployment), not owned by one domain's folder — see `backend-engineering-standards.md` §7 for the `Auditable` trait that writes to it. `subject_type`/`subject_id` are a deliberately non-FK polymorphic pointer: the audited subject can be in any schema, or — for a search, per §18's note that searching itself is logged — not a row at all.

```sql
CREATE TABLE audit_log (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  actor_account_id  BIGINT UNSIGNED NOT NULL,
  action            VARCHAR(20) NOT NULL
                      CHECK (action IN ('viewed','acted_as','approved','rejected','refunded','suspended','acted','closed')),
  subject_type      VARCHAR(50) NOT NULL,
  subject_id        VARCHAR(120) NOT NULL,
  reason            TEXT,
  before_state      JSON,
  after_state       JSON,
  occurred_at       DATETIME(6) NOT NULL DEFAULT CURRENT_TIMESTAMP(6),
  CONSTRAINT fk_audit_log_actor FOREIGN KEY (actor_account_id) REFERENCES accounts(id) ON DELETE RESTRICT,
  KEY ix_audit_log_subject (subject_type, subject_id, occurred_at),
  KEY ix_audit_log_actor (actor_account_id, occurred_at)
) ENGINE=InnoDB;
```

Payment is a fully separate service/database (§7 of the microservices doc) and gets its **own** `payment_audit_log` table rather than a cross-service write into this one — documented in `docs/microservices-architecture.md` §7.6 alongside its other ledger additions.

---

## 4. Indexing strategy — consolidated

| Index | Definition | Serves |
|---|---|---|
| `ux_accounts_email_live` | `UNIQUE (email_live)`, a generated column that is `NULL` once `deleted_at` is set | Login lookup; soft-deleted rows don't block re-registration (generated-column emulation of a Postgres partial unique index — see §1.8). |
| `ix_learner_profiles_owner` | `learner_profiles (owner_account_id)` | Parent dashboard: list of managed children + own profile. |
| `ix_assessments_learner` | `assessments (learner_profile_id, submitted_at)` | Fetch latest assessment for matching. |
| `ix_teachers_verification_status` | `teachers (verification_status)` | Admin review queue for pending verifications. |
| `ix_teacher_availability_teacher` | `teacher_availability (teacher_id, day_of_week)` | Render a teacher's weekly calendar. |
| `ix_matches_teacher_status` | `matches (teacher_id, status)` | Teacher's pending match-request inbox — unfiltered index; the app still queries `WHERE status = 'proposed'`. |
| `ix_sessions_learner_scheduled` | `sessions (learner_profile_id, scheduled_at)` | Parent/student session history and "upcoming" view. |
| `ix_sessions_status_scheduled` | `sessions (status, scheduled_at)` | Background reminder/notification job scan — unfiltered index; the app still queries `WHERE status = 'scheduled'`. |
| `ux_payments_provider_ref_per_provider` | `payments (provider_id, provider_reference)` | Idempotency guard against duplicate webhook delivery — see §7.2 in the microservices doc. Needs no generated column: MySQL unique indexes already allow unlimited `NULL`s. |
| `ix_payments_payer` | `payments (payer_account_id, created_at)` | Billing history screen. |
| `ix_feedback_learner` | `feedback (learner_profile_id, submitted_at)` | Progress/feedback timeline on the dashboard. |
| `ix_ratings_teacher` | `ratings (teacher_id, created_at)` | Teacher profile review list. |
| `ux_teacher_verification_checks_teacher_kind` | `teacher_verification_checks (teacher_id, kind)` | v2.2. Enforces one row per check kind per teacher; also the natural lookup for rendering the three-check badge. |
| `ix_plans_learner` / `ix_plans_teacher` | `plans (learner_profile_id, status)` / `(teacher_id, status)` | v2.2. A learner's active plans (Home/Plans screens); a teacher's active roster. |
| `ix_trial_requests_learner` / `ix_trial_requests_teacher` | `trial_requests (learner_profile_id, status)` / `(teacher_id, status)` | v2.2. Pending trial requests on each side of the negotiation. |
| `ix_move_requests_plan` | `move_requests (plan_id, status)` | v2.2. A plan's pending moves/renewals. |
| `ux_move_approvals_request_party` | `move_approvals (move_request_id, party_account_id)` | v2.2. Enforces one approval row per party — the mechanism behind "nothing changes until everyone accepts." |
| `ix_safeguarding_incidents_status` | `safeguarding_incidents (status, reported_at)` | v2.2. The open-incidents queue — first-loaded screen in the admin nav, per the contract's own note to keep it fast. |
| `ix_audit_log_subject` / `ix_audit_log_actor` | `audit_log (subject_type, subject_id, occurred_at)` / `(actor_account_id, occurred_at)` | v2.2. "Show the audit trail for this record" and "show what this admin has done," respectively. |

---

## 5. Security & compliance

- Minor data (`learner_profiles` for `profile_type='child'`, including `date_of_birth`) is personal data of a child under NDPR (Nigeria) and comparable regimes in expansion markets. MySQL has no Postgres-style `ROW LEVEL SECURITY` — there is no database-enforced fallback here, so scoping access to the owning parent account and platform staff with a documented need is **entirely** the application's job (the `LearnerProfile` global scope + policy layer). Treat that as a hard requirement, not a nice-to-have, precisely because the database won't catch a mistake for you the way Postgres RLS would have.
- `password_hash` uses argon2id, never a reversible or fast hash. No password or raw payment credential is ever stored — `payment_methods` holds only method type, never card/account numbers, which stay with the payment processor (PCI scope stays off this database).
- `provider_reference` on payments is the reconciliation key against the payment processor's own ledger — this database is the system of record for bookings and status, not for card data.
- All PII columns (`accounts.email`, `accounts.phone`, `learner_profiles.full_name`, `learner_profiles.date_of_birth`) should be covered by encryption at rest (managed disk encryption at minimum; column-level encryption for `date_of_birth` is worth evaluating given its sensitivity for minors). MySQL's InnoDB tablespace encryption (`innodb_encrypt_tables`) covers the at-rest case if the hosting provider's disk encryption isn't sufficient on its own.
- Audit logging: every write to `learner_profiles`, `payments`, and `verification_status` on `teachers` should emit to an append-only `audit_log` table (actor account, action, before/after, timestamp). Modeled in §3.7 as of v2.2 — flagged here since v2.0 and finally built once `docs/api-contract.md`'s admin section made it unambiguously required, not just recommended. See `docs/backend-engineering-standards.md` §7 for the application-layer implementation (`Auditable` trait) — the trait's writing side is still pending; only the table and its model shell exist so far.

---

## 6. Scaling & operations

- Partition `sessions` and `payments` by `RANGE` on `created_at` (monthly) once volume justifies it — MySQL's native `PARTITION BY RANGE (...)` works the same way conceptually as Postgres declarative partitioning, though the column used for the range must be part of every unique key (including the primary key) on the table, which `id` already is not — plan the partition key choice against that constraint before implementing, don't assume the Postgres design ports over unchanged.
- `progress_reports` and `teachers.rating_avg` are denormalized rollups, not sources of truth. Refresh them via a background job or a MySQL trigger on `feedback`/`ratings` insert — do not let application code write to them ad hoc from multiple code paths, or they will drift.
- Route dashboard/reporting reads (parent progress view, admin analytics) to a read replica; keep the primary reserved for the booking/payment write path, which is the latency-sensitive one. MySQL's native async/semisync replication covers this without extra tooling.
- Use a migrations tool with reversible, versioned migrations — no hand-run DDL against production. Every `CREATE TABLE` in §3 is a first migration; every `ALTER` after launch is a numbered follow-on.
- Connection pooling (ProxySQL, or the application server's own pool) is required before this schema sees production traffic — `BIGINT UNSIGNED AUTO_INCREMENT` + frequent short writes (session status transitions, payment webhooks) will exhaust direct connections quickly under load, same concern as the Postgres version, different tool.

---

## 7. Open engineering decisions

- Single MySQL instance vs. service-per-domain database: this schema assumes one instance behind one application for v1. If Payment is later split into its own service for compliance isolation (see `docs/microservices-architecture.md`), `payments`/`payouts` move out and become an event-consuming read model here instead of a direct FK. (Payment already is its own service/database as of the microservices doc — this note is about the remaining bundled three.)
- `JSON` usage now also covers `plans.days`, `assessments`/`recommendation.learner_profile_view`'s `challenges`/`goals`/`preferred_slots`, and the outbox tables' `payload` — same rule as before: reach for `JSON` only where structure is genuinely variable, not to avoid a migration.
- ~~`teacher_availability` models weekly recurring slots only; if the product needs date-specific exceptions...~~ **Resolved in v2.2:** `teacher_time_off` (§3.3) is that additive table.
- The minor-consent rule (§3.2) is flagged, not silently fixed, as an example of a rule that looks right conceptually but can't be a same-row `CHECK` under MySQL (or Postgres) at all — it needs a trigger or application-layer validation before this ships. As of v2.2, the same "can't be a bare `CHECK`" lesson applies more subtly to *conditional* CHECKs: see the `chk_learning_goals_present_if_submitted` flag in §3.2 for a CHECK that looked right and wasn't, caught only while writing this revision up.
- Table/column names avoid MySQL reserved words (`matches`, `sessions`, `ratings` are all safe), but every reference to them in raw SQL should still be backtick-quoted defensively, since the reserved-word list changes between MySQL versions.
- **New in v2.2:** three items were decided rather than left open, and are worth revisiting if the product's needs shift — `admin` reuses `accounts.account_type` instead of a separate staff table (§3.1); draft assessments relax `NOT NULL`s on the existing table instead of a separate `assessment_drafts` table (§3.2); `safeguarding_incidents` is owned by Auth instead of becoming a fifth logical domain (§3.7). See `docs/api-gap-analysis.md` for the reasoning behind each.
- **Still open:** whether `payment_line_items.session_id` is assigned at quote time or lazily as sessions get scheduled off a plan — an application-layer decision, not a schema one (the column is nullable either way); and whether `plans.days`/`time_of_day` (a single weekly pattern) needs to support more than one time slot per week before this ships, which the current shape doesn't.
