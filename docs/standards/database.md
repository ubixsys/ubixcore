# Database Standards

**Status:** Approved
**Last Updated:** 2026-05-08 (added §6.5 event-table archetype + §8 checklist row, codifying the BI ClickHouse export-friendly contract; first surface canonicalising this is FT spec v1.7 REQ-FT-EVENT-008)

This document defines the database standards for all new schema work across uBix Core and related the host organisation systems. It codifies the conventions already present in the existing schemas and introduces forward-looking rules that prepare us for referential integrity, predictable data shapes, and safer temporal data.

---

## 1. Scope

These standards apply to:

- All **new** databases, tables, and columns.
- Any **altered** table where the migration is non-trivial (e.g. renaming, restructuring, or introducing new columns).
- Schema changes reviewed in pull requests touching the `sql/` directory.

Legacy tables are grandfathered; they are **not** required to be retrofitted unless touched by a substantial migration.

---

## 2. Naming Conventions

The conventions below reflect the dominant patterns observed across the 14 existing schema files (`SHOP`, `LEDGER`, `ADSERVER`, `MAILINGS`, `REWARDS`, `CHAT_SYSTEM`, `CHAT_SYSTEM_LOG`, `MESSAGING`, `TENANTS`, `TENANTS_STATS`, `SHOP_STATS`, `SYSTEMS`, `legacy_db`, `content_db`).

### 2.1 Database Names

- **Convention:** `UPPER_CASE` or `UPPER_CASE_WITH_UNDERSCORES`
- **Rationale:** Matches the existing naming for 12 of 14 databases in the monorepo.
- **Examples:**
  - `SHOP`, `LEDGER`, `ADSERVER`, `MESSAGING`, `TENANTS`
  - `CHAT_SYSTEM`, `CHAT_SYSTEM_LOG`, `REWARDS`, `SHOP_STATS`, `TENANTS_STATS`
- **Legacy exceptions** (do not use as a template): `legacy_db`, `content_db`.

### 2.2 Table Names

- **Convention:** `PascalCase_With_Underscores` — each word capitalized, underscores between logical word groups. This is the dominant convention across the existing schema and is the **only** accepted style for new tables.
- **Examples:**
  - `Activity_Stream`, `Account_Login`, `Blocked_Models`
  - `Chat_Filter_IP_Blacklist`, `Edge_Server_Assignment`, `Delivery_Stats_Daily`
- **Pluralization:** All new tables must be **plural**. A table holds a collection of rows, so the name reflects the collection (`Users`, `Affiliates`, `Blocked_Models`, `Error_Codes`). This matches the modern web-development standard (Rails, Laravel, Django) and the dominant lean of our existing entity tables. Legacy singular tables (`Configuration`, `Event_Log`, `Account_Login`, `Activity_Stream`) are grandfathered and must not be used as a template for new work.
- **Do not:**
  - Use pure PascalCase without underscores (e.g. `AdminUsers`, `AdminLinks`). These exist in legacy schemas but must not be used for new tables — always separate words with underscores (`Admin_Users`, `Admin_Links`).
  - Start a new table name with a digit (e.g. `2011_Annual_Survey`). Legacy only.
  - Use reserved SQL words as names.

### 2.3 Column Names

- **Convention:** `snake_case` — all lowercase, underscores between words.
- **Examples:**
  - Identifiers: `id`, `user_id`, `model_id`, `affiliate_id`
  - Timestamps: `date_created`, `date_last_updated`, `date_expires`, `ts_sent`
  - Booleans: `is_active`, `is_private`, `is_test`
  - Descriptors: `display_name`, `short_name`, `ip_address`, `mp_code`
- **Do not:**
  - Use camelCase, PascalCase, or mixed case.
  - Use opaque single-letter columns (`F1`, `F2`, `F3`) in new tables — legacy only.

### 2.4 Additional Naming Rules

- **Primary keys:** `id` when the table owns a single surrogate key.
- **Foreign keys (soft):** `<referenced_table_singular>_id` — e.g. `user_id`, `affiliate_id`, `model_id`.
- **Booleans:** Prefix with `is_`, `has_`, or `can_`.
- **Timestamps:** Prefix with `date_` for `DATE`/`DATETIME`, or `ts_` for `TIMESTAMP`.
- **UTC timestamps:** Suffix with `_utc` when the column stores UTC and a sibling column stores local time (e.g. `date_start`, `date_start_utc`).

---

## 3. Soft Foreign Keys

We do not currently enforce foreign key constraints at the database level. To make a future migration to real `FOREIGN KEY` constraints possible, all new references between tables must obey the following rules.

### 3.1 Use `NULL` to represent "no reference"

- **Required:** Foreign-key-style columns must be `NULL`-able when a missing reference is a legitimate state.
- **Forbidden:** Using `0`, `-1`, or any sentinel integer to mean "no record."
  - **Why:** A real `FOREIGN KEY` constraint will reject `0` unless a row with `id = 0` exists. Using `NULL` is the only portable way to express absence.

```sql
-- ✅ Correct
affiliate_id INT UNSIGNED NULL DEFAULT NULL,

-- ❌ Forbidden
affiliate_id INT UNSIGNED NOT NULL DEFAULT 0,
```

### 3.2 Column type must match the referenced primary key

- `INT UNSIGNED` on one side and `BIGINT` on the other will break when constraints are added later.
- When creating a new FK-style column, copy the exact type, size, and signedness of the target `id`.

### 3.3 Name FK columns after the target table (singular) + `_id`

- `user_id` references `Users.id` (or `Admin_Users.id`, context permitting).
- If a table holds **two** references to the same target, prefix with role: `created_by_user_id`, `assigned_to_user_id`.

### 3.4 Index every FK column

- Every soft-FK column must have its own index. A real `FOREIGN KEY` constraint will create one implicitly; we do it explicitly now so that queries and future migrations are fast.

```sql
KEY idx_affiliate_id (affiliate_id),
```

---

## 4. Prohibited Data Shapes in New Tables

### 4.1 No JSON columns

- **Forbidden:** `JSON`, `LONGTEXT`/`TEXT` used as a serialized JSON blob, or any column whose documented purpose is to hold JSON.
- **Why:**
  - JSON is opaque to reporting, migrations, and indexing.
  - Schema drift inside the blob is invisible to code review.
  - Our validation layer (Payloads + DataTypes) exists precisely so that every field has a typed home.
- **Do instead:**
  - Model the fields as real columns.
  - If the data is genuinely unbounded and variable, create a child table with `(parent_id, key, value)` rows.
  - If an external system forces JSON on us, isolate it in a dedicated staging/import table documented as such, not in a domain table.

### 4.2 No zero dates or zero datetimes

- **Forbidden:** `'0000-00-00'`, `'0000-00-00 00:00:00'`, or any default that MySQL accepts only with `NO_ZERO_DATE` disabled.
- **Why:**
  - Zero dates are invalid under modern SQL modes and break most date libraries (PHP `DateTimeImmutable`, Symfony validators, `date()` math).
  - They silently mask missing data that should be explicitly `NULL`.
- **Do instead:**
  - Declare the column `NULL DEFAULT NULL` when the event may not have happened yet.
  - Declare `NOT NULL` with a meaningful application-set default (e.g. `date_created` set to `CURRENT_TIMESTAMP`).

```sql
-- ✅ Correct
date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
date_deleted DATETIME NULL DEFAULT NULL,

-- ❌ Forbidden
date_deleted DATETIME NOT NULL DEFAULT '0000-00-00 00:00:00',
```

### 4.3 No enum misuse

- Prefer a lookup table over a native `ENUM(...)` column when the set of values is expected to change. `ENUM` changes are schema changes and lock tables.
- Small, stable sets (e.g. `('male','female','other')`) may use `ENUM`, but document the rationale.

### 4.4 No reserved words as identifiers

- Avoid `type`, `order`, `key`, `group`, `status` as bare column names where a more specific term exists (`user_type`, `sort_order`, `access_key`, `user_group`, `payment_status`).

---

## 5. Column Standards

### 5.1 Nullability

- Every column must have an **explicit** `NULL` or `NOT NULL` declaration.
- Columns that represent optional data must be `NULL`; do not use empty strings, `0`, or sentinel dates to mean "absent."

### 5.2 Defaults

- `NOT NULL` columns must have a default **or** be guaranteed to be set by the application on every insert.
- Prefer `CURRENT_TIMESTAMP` for creation timestamps.

### 5.3 Character Set & Collation

- New tables: `utf8mb4` with `utf8mb4_unicode_ci` collation unless there is a documented reason to differ.
- Do not mix collations within a database.

### 5.4 Integer Sizing

- Use `UNSIGNED` for any column that cannot be negative (ids, counts, durations).
- Right-size: `TINYINT` for booleans and small enums, `INT` for most ids, `BIGINT` only when the row count justifies it (log/event tables).

### 5.5 Money

- Never use `FLOAT` or `DOUBLE` for currency.
- Use `DECIMAL(10,2)` (or wider) for USD; match the precision already established in `UsdCurrency`.

### 5.6 Booleans

- Use `TINYINT(1) UNSIGNED NOT NULL DEFAULT 0` and name with `is_` / `has_` / `can_`.

---

## 6. Table Standards

### 6.1 Required columns on domain tables

Unless there is a documented reason to omit them, every new domain table should have:

- `id` — primary key, `INT UNSIGNED AUTO_INCREMENT` (or `BIGINT UNSIGNED` for high-volume tables).
- `date_created DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP`.
- `date_last_updated DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`.

### 6.2 Primary keys

- Every new table must have a primary key.
- Prefer a surrogate `id` over a natural composite key, unless the table is a pure join table.

### 6.3 Indexes

- Index every soft-FK column.
- Index any column appearing in a frequent `WHERE`, `ORDER BY`, or `JOIN` clause.
- Name indexes descriptively: `idx_<column>` or `idx_<col1>_<col2>` for composites.

### 6.4 Engine

- `InnoDB` for all new tables. No `MyISAM` in new work.

### 6.5 Event-table archetype (analytics-export-friendly)

Some uBix Core tables are **event tables** — append-only, time-series, immutable post-insert — rather than domain tables. Examples: `Feature_Test_Events` (FT spec REQ-FT-EVENT-008), audit-log tables (`*_Audits`), click-tracking tables, conversion-attribution event sinks.

These tables flow into the **BI ClickHouse export pipeline** for analysis + dashboarding. Cross-event analytics, long-term retention, dashboards, and ad-hoc queries all live at the ClickHouse end; the uBix Core-side table is the source-of-truth write path. The pipeline mechanism (Kafka Engine, Debezium CDC, batch ETL) is BI-owned; uBix Core's responsibility is keeping the schema export-friendly so the pipeline can consume rows reliably.

Event-table contract (in addition to §6.1–§6.4):

- **Append-only writes.** Rows are INSERTed, never UPDATEd. Correct an event with a compensating row, not an overwrite. Code that mutates event-row state is a bug.
- **Immutable post-insert.** No DELETE except for retention purges (by-cutoff-timestamp, scheduled). Mid-lifecycle row deletion breaks the export pipeline's monotonicity assumption.
- **Stable column types.** Schema evolution is **additive only** — new NULLable columns with sensible defaults are fine; retypes / renames / drops are forbidden while the export pipeline depends on the column. Genuinely-breaking changes go through "new table version, dual-write, cut over, retire the old."
- **`event_timestamp` (or equivalent) is `NOT NULL DATETIME`.** Time-series partitioning at ClickHouse requires a non-null event time. `date_created` works if it represents the event's occurrence time; otherwise add a dedicated `event_timestamp` / `occurred_at` column.
- **Polymorphic data as discrete columns, not JSON** (per §4.1; the rule applies universally but event tables face the strongest temptation to use JSON for "extra context"). Express subject-id polymorphism, event-type variation, etc., as separate columns so ClickHouse can index per-type.
- **Stable monotonic PK.** `BIGINT UNSIGNED AUTO_INCREMENT` is the default for high-volume event tables (per §5.4). Avoid composite primary keys — single-column PKs ease ClickHouse `ORDER BY` partitioning.
- **Retention documented per surface.** This standard provides the checklist; the surface's spec owns the specific window + purge cadence + override mechanism. Default: 1-year rolling, monthly purge command, env-overridable retention flag for ops control (FT spec §5.5 + REQ-FT-DATA-001..005 is the canonical example).

**Why:** uBix Core's BI strategy consolidates analytics on ClickHouse (purpose-built columnar OLAP, ideal for high-volume append-only event data). Building dashboards or query layers on the uBix Core side would duplicate BI tooling without matching ClickHouse's analytical UX. Keeping event tables export-friendly lets BI choose their own pipeline mechanism without forcing schema changes on uBix Core.

**Why the export-pipeline mechanism choice lives outside uBix Core's standards:** Kafka Engine vs Debezium CDC vs batch ETL depends on operational concerns (CDC tooling availability, MariaDB binlog config, batch-window tolerance) owned by ops + BI, not by surface authors. uBix Core's contract is "give the pipeline a clean append-only / immutable / stable-schema source"; the rest is BI's call.

When authoring a surface spec that emits events: the BI / DW approver in the spec's Document Control row §10 confirms the table is on the ClickHouse export schedule.

---

## 7. Migration & Change Management

- All schema changes are delivered as reviewable SQL files in `sql/`.
- Destructive changes (drops, renames, type narrowings) require explicit sign-off.
- New tables and columns should be accompanied by:
  - A corresponding DataType (see `php/Ubix/DataType/`) if the column represents a domain concept.
  - A Payload mapping (see `php/Ubix/Payload/`) if the column is user-supplied.
  - A repository method if the column is queryable.

The mechanics of schema delivery — filename format, the master `SYSTEMS.Schema_Migrations` tracker, the `bin/ubix migrate:*` runner, the schema-vs-seed split, strict checksum enforcement, manual-apply recovery, and the tiered CI drift policy — live in [`migrations.md`](migrations.md). Read both docs together when authoring schema changes: this doc covers _what_ a well-formed schema looks like; that one covers _how_ a change actually lands.

---

## 8. Checklist for New Tables

Before merging a new table, confirm:

- [ ] Database name is `UPPER_CASE`.
- [ ] Table name is `PascalCase_With_Underscores` and **plural**.
- [ ] All column names are `snake_case`.
- [ ] Primary key named `id`.
- [ ] `date_created` and `date_last_updated` present (or justified absence).
- [ ] No `JSON` / JSON-blob columns.
- [ ] No `'0000-00-00'` defaults.
- [ ] Soft-FK columns are `NULL`-able, not defaulted to `0`.
- [ ] Soft-FK columns are indexed.
- [ ] Soft-FK column type matches the referenced `id` exactly.
- [ ] Engine is `InnoDB`, charset is `utf8mb4`.
- [ ] The connection speaks `utf8mb4` too (the default since v0.37; `MYSQL_CHARSET` overrides it). A `utf8mb4` table behind a `latin1` connection stores every non-ASCII character double-encoded.
- [ ] If the app needs a fixed session time zone, set `MYSQL_TIME_ZONE` (since v0.48.0). Every new MySQL connection, read, write and migration, then runs `SET time_zone = ?` with the value bound. Unset, nothing is issued and the server's zone applies. It accepts `SYSTEM`, an offset such as `+00:00`, or a named zone such as `America/New_York`. **Named zones need the server's time-zone tables loaded** (`mysql_tzinfo_to_sql`); where they are not, every connection fails with an error naming `MYSQL_TIME_ZONE`, and there is no silent fallback. Use `SYSTEM` or an offset on such servers (an offset does not follow daylight saving time).
- [ ] Every column has explicit `NULL` / `NOT NULL`.
- [ ] Money columns are `DECIMAL`, not float.
- [ ] If event-table archetype (§6.5): append-only writes; immutable post-insert (no UPDATE; DELETE only via retention purge); `event_timestamp NOT NULL DATETIME`; no JSON columns; stable monotonic `BIGINT UNSIGNED AUTO_INCREMENT` PK; retention policy documented in the surface's spec.

---

## 9. Open Questions / To Be Decided

These items are left open for the review cycle:

- Whether to begin enforcing real `FOREIGN KEY` constraints on **new** tables immediately, vs. writing to the soft-FK rules and flipping later.
- Whether `date_last_updated` should be mandatory across the board or opt-in per table.
- Standard naming for audit-log companion tables (`*_Log` vs `*_History` vs `*_Audit`).
- Whether we adopt a standard soft-delete column (`date_deleted` vs `is_deleted`) or continue with per-table ad-hoc approaches.
