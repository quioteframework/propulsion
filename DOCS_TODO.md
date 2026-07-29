# End-user documentation TODO: per-driver differences

This is a checklist of things end-user-facing documentation (README/docs site/
wherever "how do I use Propulsion with X" content lives) needs to cover, so
someone picking a non-default database doesn't have to reconstruct this from
`KNOWN_ISSUES.md`'s commit-log-style entries or the source itself. Nothing
here is a bug — everything listed is either a real SQL-dialect limitation, a
setup step, or a deliberate design choice, but all of it is currently
undocumented for users.

Source material: `KNOWN_ISSUES.md` (esp. the MySQL/MSSQL/Oracle parity audit
sections), `PLATFORM_FEATURES.md`, and this repo's own adapter/platform code
under `runtime/Lib/Adapter/` and `generator/Lib/Platform/`.

## 1. Setup: extensions each driver actually needs

Should be a table or subsection per driver, since "just set
`propulsion.database`" undersells how much of a project this is for two of
the five.

- **PostgreSQL** (default): `pdo_pgsql`. Prebuilt everywhere, no special setup.
- **MySQL/MariaDB**: `pdo_mysql`. Prebuilt everywhere, no special setup.
- **SQLite**: `pdo_sqlite`. Bundled with PHP itself on most distros.
- **MSSQL/SQL Server**: `pdo_dblib` (FreeTDS) — Debian/Ubuntu: `php-sybase`
  package. No further setup beyond installing it; `DBMSSQL`/`MssqlPropulsionPDO`
  already assume dblib.
- **Oracle**: `pdo_oci` — **not distributed as a prebuilt package for any
  current PHP version.** Users need to build it themselves from PECL against
  a real Oracle Instant Client:
  - Document the full build recipe (currently only in
    `test/tools/helpers/IntegrationDatabase.php`'s docblock): download
    Instant Client Basic + SDK from Oracle, `pecl download pdo_oci`, `phpize`,
    `./configure --with-pdo-oci=instantclient,/path/to/instantclient,<version>`,
    `make`, then either install the built `.so` system-wide or load it
    per-invocation with `-d extension=/path/to/pdo_oci.so`.
  - Document the Ubuntu 24.04+ `libaio1t64` package rename and the
    `libaio.so.1 -> libaio.so.1t64` compat symlink it requires.
  - Document that the Instant Client directory must be on `LD_LIBRARY_PATH`
    at runtime (not just at build time).
  - Note this is a real barrier to entry — worth being upfront that Oracle
    support requires more from the user than the other four drivers, not
    just "set one config key."

## 2. Identifier quoting differs per platform

Users writing raw SQL fragments (`Criteria::addAsColumn()` with a literal
string, `ColumnExpression::raw()`, etc.) or naming columns/tables need to
know how each platform treats identifiers, since this determines whether a
column name that happens to collide with a SQL keyword works or breaks:

- **MySQL**: the only driver that quotes *every* identifier unconditionally
  (backtick-quoted). Never breaks on a reserved word, but means raw SQL
  fragments a user writes themselves won't get this treatment automatically.
- **Oracle**: quotes only identifiers that are actual Oracle reserved words
  (case-insensitive match against a fixed list — see
  `DBOracle::RESERVED_WORDS` / `OraclePlatform::RESERVED_WORDS`), leaving
  everything else unquoted/uppercase-folded. Document the actual reserved
  word list, or at least point at where it lives, since a column named e.g.
  `uid`, `size`, `level`, `date`, `comment`, or `user` will silently get
  quoted differently than the user might expect if they're also inspecting
  raw generated SQL or writing their own DDL by hand.
- **Postgres/MSSQL/SQLite**: no automatic quoting at all. A column name that
  collides with a reserved word will break outright on these platforms —
  worth an explicit warning to avoid reserved words in schema.xml regardless
  of target platform, since portability across drivers is a stated goal.

## 3. Locking: `FOR SHARE`/`FOR UPDATE` support differs

- Postgres/MySQL/Oracle: real `FOR UPDATE` (Postgres/MySQL also `FOR SHARE`,
  with `NOWAIT`/`SKIP LOCKED` — see `PLATFORM_FEATURES.md` for exact
  per-platform variant support).
- **Oracle has no `FOR SHARE` equivalent at all** — only row-exclusive
  `FOR UPDATE`. Calling the `FOR SHARE` API against an Oracle connection
  throws (`DBOracle::supportsForShare()` returns `false`); document this as
  an intentional limitation, not something to work around.
- **MSSQL has no trailing lock clause at all** — locking is expressed via
  inline table hints (`WITH (UPDLOCK, ROWLOCK)`) instead of a `FOR UPDATE`/
  `FOR SHARE` suffix. If a user's code branches on "did my query get a lock
  clause," document that MSSQL's approach is structurally different, not
  just syntactically.
- SQLite: no row-level locking at all (whole-database locking) — `FOR
  UPDATE`/`FOR SHARE` are no-ops there.

## 4. Nested transactions / savepoints: real vs. emulated

This affects correctness of nested `beginTransaction()`/`rollBack()` usage
and deserves a clear callout, not just a footnote:

- **Postgres, MySQL, SQLite, Oracle**: real `SAVEPOINT`/`ROLLBACK TO
  SAVEPOINT` — a nested rollback undoes only its own work; the outer
  transaction can still commit normally afterward.
- **MSSQL: nested transactions are emulated, not real.** `MssqlPropulsionPDO`
  uses a depth-counter + coarse "poisoned" flag instead of actual T-SQL
  `SAVE TRANSACTION`/`ROLLBACK TRANSACTION` — a nested `rollBack()` doesn't
  undo just its own work, it poisons the *entire* outer transaction, so the
  eventual outer `commit()` throws instead of silently discarding the rolled-
  back work. This is a real, user-visible behavior difference from every
  other supported platform: code that assumes "a nested rollback only
  affects its own scope" (a common pattern for e.g. optional/best-effort
  sub-operations inside a larger transaction) will behave differently on
  MSSQL and needs restructuring (catch the eventual poisoned-commit
  exception, or avoid nested rollback-and-continue patterns against MSSQL
  entirely). See `KNOWN_ISSUES.md`'s "MSSQL nested transactions are
  emulated, not real" entry for the full technical reason (a reverted
  real-savepoint attempt and the still-open root cause investigation behind
  it).

## 5. LOB (BLOB/CLOB) columns: setter/getter contract and platform quirks

- The documented contract (setter accepts a plain string *or* a stream
  resource; getter returns a stream resource for BLOB, a string for CLOB) is
  the same across all five platforms — this part doesn't need new docs.
- **Oracle-specific internal note, probably not user-facing but worth a
  short mention if anyone inspects generated SQL or hits a size limit**: BLOB
  writes go through a hex-encode + `HEXTORAW(...)` rewrite rather than a
  native LOB bind, working around a real `pdo_oci` extension bug (confirmed
  independent of this project — its `PDO::PARAM_LOB` bind silently writes an
  empty LOB). This is transparent to normal usage but has a practical upper
  bound on value size (each byte becomes two hex characters bound as a
  string) — worth documenting if there's a known ceiling reasonable for
  typical use, so a user storing e.g. large file attachments via Oracle
  knows to check rather than being surprised by a bind-size error.
- No other platform needs a LOB-related user-facing caveat from this
  session's work.

## 6. Migration table naming (Oracle-specific)

If a user names their own migration ledger table (the `--migration-table`
option / `PropulsionMigrationManager::setMigrationTable()`) with a **long**
name under Oracle, note the interaction with Oracle's identifier length
limit: `OraclePlatform`'s sequence-naming logic truncates and uniquely
suffixes the sequence it creates for that table once `"{table}_SEQ"` would
exceed 30 characters. This is handled correctly by the library itself now,
but if a user's own tooling assumes a sequence is literally named
`"{table}_SEQ"` (e.g. custom cleanup scripts, direct catalog queries), a long
custom migration table name will surprise them. Worth a short note in the
migration command docs: keep custom `--migration-table` names reasonably
short under Oracle, or don't assume the literal sequence name.

## 7. Platform-specific `ALTER TABLE`/DDL syntax quirks (if migrations docs exist)

If there's (or will be) a "writing your own migration SQL" doc, it should
call out platform-specific ALTER syntax differences a user writing raw
migration SQL will hit directly (these aren't abstracted away — migration
SQL is written by the user per-datasource):

- **MSSQL and Oracle**: `ALTER TABLE t ADD column ...` — no `COLUMN` keyword
  (`ADD COLUMN` is a syntax error on both). Postgres/MySQL/SQLite accept
  `ADD COLUMN`.
- **Oracle**: no `DROP TABLE IF EXISTS` at all — a user's own migration/setup
  scripts need the `BEGIN EXECUTE IMMEDIATE '...'; EXCEPTION WHEN OTHERS THEN
  NULL; END;` guard idiom (or check existence first) if they want an
  idempotent drop.
- **Oracle**: no `DELETE FROM t AS alias` — aliased deletes are
  `DELETE FROM t alias WHERE alias.col = ...` (no `AS`, and the alias can't
  appear directly after the `DELETE` keyword the way MySQL's
  `DELETE alias FROM t AS alias` does either).
- **MySQL**: aliased deletes need `DELETE alias FROM t AS alias WHERE ...`
  (naming the alias twice) unlike Postgres's `DELETE FROM t AS alias`.

## 8. `allowPkInsert` + auto-increment interaction (MSSQL-specific)

Document that inserting/updating an explicit PK value against an
auto-increment column requires `SET IDENTITY_INSERT tbl ON`/`OFF` bracketing
on MSSQL specifically (handled automatically by
`DBAdapter::supportsInsertNullPk()`/`getIdentityInsertOnSql()` machinery —
nothing the user needs to do differently in their own code), but worth a
one-line "this works transparently, here's why MSSQL needed special-casing"
note if the docs ever explain `allowPkInsert` in depth, since a curious
reader inspecting generated SQL will see the `IDENTITY_INSERT` statements
appear only for MSSQL and might wonder why.

## 9. Recommended-platform framing

`README.md` already says Postgres is the default/recommended platform and
gets the most feature-parity attention. Once this document's items are
folded into real docs, consider whether the same page should rank the other
four by "how much extra you need to know/do": MySQL and SQLite are close to
drop-in; MSSQL requires understanding the nested-transaction/locking
differences above; Oracle requires the most setup work (extension build) and
has the most dialect differences to be aware of (identifier quoting,
`HEXTORAW` LOB handling, DDL syntax). This isn't a bug list — it's honest
scoping so a user picking a platform for a new project knows what they're
signing up for.
