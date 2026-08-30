# Roadmap

Status anchor across sessions. Read this before starting a new work session.

| # | Phase | Status | Branch | Notes |
|---|---|---|---|---|
| 0 | Repo bootstrap | ✅ done | `main` | git init, base scaffold, CLAUDE.md |
| 1 | Document research | ✅ done | `main` | existing-plugins.md, kaos-hooks.md |
| 2 | ADRs + spec | ✅ done | `main` | ADR-0001..0003, spec.md, testing/strategy.md |
| 3 | Test environment (Podman + Playwright) | ✅ done | `main` | Container running, 8/8 smoke tests green, MCP handshake actually verified |
| 4 | Plugin core (policy, store, save interception) | ✅ done | `main` | 13/13 tests green, all 3 paths (browser/JSON-RPC/MCP) verified live |
| 5 | Review UI (admin queue, diff, banner) | ✅ done | `main` | 17/17 tests green, full approve/reject loop verified live |
| 6 | 3-way merge / conflicts | ✅ done | `main` | Diff3 automerge + manual resolution; Kaos bug in Diff3 worked around |
| 7 | Media uploads | ✅ done | `main` | Queue + approval, byte integrity tested |
| 8 | remote.php + MCP verification | ✅ done | `main` | moved up due to ADR-0004; 4 MCP tools verified live |
| 9 | Hardening, security review, docs | ✅ done | `main` | ACL gap closed, CLI, usage.md, 46 tests |
| 10 | Range-addressed access + author change lifecycle | ✅ done | `phase-10-page-ranges` | ADR-0005/ADR-0006; 12 new remote methods, 77/77 tests green |

The original plan (phases 0-9) is located at
`/home/martin/.claude/plans/ber-neues-projekt-starten-atomic-feigenbaum.md`. Phase 10's
plan is at `/home/martin/.claude/plans/ich-brauche-eine-nderung-erweiterung-hidden-nova.md`.

**Post-completion addition (2026-08-23):** admin queue gained a rendered
preview per pending page change (`admin.php::renderPreview()`), reviewer-only,
switchable against the source diff via CSS-only Diff/Preview tabs
(`renderDiffAndPreview()`, `plugin/style.css`); the diff table also got its
own horizontal scrollbar for lines wider than the page. See testing strategy
scenarios 18-19.

Reviews: [`design/code-review.md`](design/code-review.md) (interim state),
[`design/final-review.md`](design/final-review.md) (final), 
[`testing/coverage-review.md`](testing/coverage-review.md) (test coverage).
What was deliberately left open is documented in the final review.

**Phase 10 (2026-08-30):** [`design/adr-0005-range-addressed-access.md`](design/adr-0005-range-addressed-access.md)
and [`design/adr-0006-author-change-lifecycle.md`](design/adr-0006-author-change-lifecycle.md).
Motivated by two recurring problems: whole-page MCP transfers being an implicit size limit
on large pages, and the AI agent repeatedly stacking new queue entries for the same page
instead of continuing its own draft. Adds 12 remote methods (5 range reads, 5 range
writes, 2 lifecycle: `updatePendingChange`/`withdrawPendingChange`), a new `withdrawn`
terminal state, and `helper/range.php` (section/line addressing derived from DokuWiki's
own parser, reusing core's `con()`/`rawWikiSlices()` byte-range convention verbatim -
verified byte-for-byte against the browser's own section-edit links). `skills/dokuwiki-reviewqueue/SKILL.md`
was substantially rewritten for the new tools. Deliberately deferred, not part of this
phase: moving a section *across* pages (needs a "linked changes" concept so both halves
are approved together) and a regex grep over `data/pages/` directly.

## Decided questions

- Plugin base name: **`reviewqueue`** (decided with the user on 2026-08-08 — more
  precise than `aireview`, since the plugin is user-based and not AI-specific).
- `martin` gets his own `reviewer` group instead of DokuWiki admin rights (cleaner
  separation between "may perform reviews" and "is wiki administrator"); will be
  seeded this way in phase 3.
