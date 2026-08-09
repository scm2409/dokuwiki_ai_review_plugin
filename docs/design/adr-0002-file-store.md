# ADR-0002: File-based store instead of the sqlite plugin

## Status

Accepted (2026-08-08).

## Context

The `approve` plugin uses `dokuwiki\plugin\sqlite\SQLiteDB` (see
[`docs/research/existing-plugins.md`](../research/existing-plugins.md)) for its state.
For our pending queue, two options are open: adopt the same sqlite plugin dependency, or
build our own file-based store analogous to DokuWiki's own handling of pages/attic
(`io_saveFile()`, `io_lock()`).

## Decision

We build our own **file-based store** under `data/reviewqueue/`.

## Rationale

- **No external plugin dependency.** `sqlite` is itself a separately installed,
  separately maintained DokuWiki plugin. For a plugin whose explicit starting point is
  "no existing plugin fits exactly," we don't want to introduce an additional foreign
  dependency that would itself raise new compatibility questions against Kaos.
- **Expected data volume is small.** One to a handful of AI users, with pending changes
  in the range of dozens to a few hundred at a time — a directory scan is entirely
  performant enough for that; a database would be over-engineered.
- **Consistency with DokuWiki's own model.** Pages, attic revisions, and changelogs are
  themselves file-based in DokuWiki. A file-based queue store fits into the same
  backup/restore/inspection model (backing up `data/` is enough).
- **Simpler tests.** Pending changes can be created/checked directly as files in tests,
  without opening a SQLite file or maintaining schema migrations.

## Consequences

- No SQL, no migrations. Schema changes to the `<id>.json` format must be read
  backward-compatibly by the plugin itself (treat missing fields with a default).
- Queries like "all pending changes of a user" or "all open changes for namespace X" are
  linear directory scans over `queue/*.json`. Uncritical at the expected scale; should
  that change, a switch to sqlite would later be possible in isolation within
  `helper/store.php`, without touching the rest of the plugin architecture.
- Locking via DokuWiki's `io_lock()`/`io_unlock()` and a simple `seq` file for ID
  assignment — no custom concurrency concept needed.
