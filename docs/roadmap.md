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

All planned phases are complete. The original plan is located at
`/home/martin/.claude/plans/ber-neues-projekt-starten-atomic-feigenbaum.md`.

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

## Decided questions

- Plugin base name: **`reviewqueue`** (decided with the user on 2026-08-08 — more
  precise than `aireview`, since the plugin is user-based and not AI-specific).
- `martin` gets his own `reviewer` group instead of DokuWiki admin rights (cleaner
  separation between "may perform reviews" and "is wiki administrator"); will be
  seeded this way in phase 3.
