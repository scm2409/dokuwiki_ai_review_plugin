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
| 10 | Range-addressed access + author change lifecycle | ✅ done | `phase-10-page-ranges` | ADR-0005/ADR-0006; 12 new remote methods, 81/81 tests green |
| 11 | Agent confinement (capability allowlist) | ✅ done | `phase-11-agent-confinement` | ADR-0007; own MCP endpoint, 3 gates, 112/112 tests green |

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

**Phase 11 (2026-09-01):** [`design/adr-0007-agent-confinement.md`](design/adr-0007-agent-confinement.md).
Motivated by two operator requirements the plugin did not meet: the MCP tool list was
everything the wiki has (53 tools on a production install, pure context cost), and a
review-scoped account could read anything on any transport — page history included —
because only the *write* path was ever governed. Confinement now lives in
`helper/capability.php` and is enforced at three gates that all ask it:
`action/entrypoint.php` (`DOKUWIKI_INIT_DONE` — entry-script allowlist plus one
`rev`/`at` check that closes page *and* media history on every script at once),
`action/save.php` (the trimmed `do=` allowlist, its list moved into the helper), and our
own MCP endpoint (`plugin/mcp.php`, `meta/McpServer.php`, `meta/ToolSchema.php`, adapted
from `splitbrain/dokuwiki-plugin-mcp`, GPL-2) which refuses a non-allowlisted
`tools/call` rather than merely hiding it. **The splitbrain `mcp` plugin must be
uninstalled** — while it is installed it serves the unrestricted surface next to ours.

Dropping `core.savePage` left two gaps that the range tools structurally cannot fill,
both closed with dedicated tools rather than by restoring it: `createPage` (a range tool
has no range to address on a page that does not exist; auto-creating would turn a typo in
a page id into a silent orphan page) and `deletePage` (every write tool refuses to empty a
page on purpose, so a deletion should be explicit). Both refuse to stack on an open draft,
enforcing ADR-0004's rule instead of only warning about it — which means stacking is now
reachable *only* through the browser edit form, where its warnings are still tested.

`/code-review` before the merge found two live bypasses, both reproduced and then
closed: core's `media_metasave()` (`mediado=save` in the media manager) wrote IPTC
fields into a **live** media file while firing neither media event the plugin hooks —
an unreviewed publish that had been reachable since phase 7, and that this phase had
newly documented as safe; and `tab_details=history` disclosed media revisions with no
`rev`/`at` parameter for the revision rule to catch. Both are closed by refusing
`lib/exe/mediamanager.php` and the `media` act outright, keeping the gate a list of
doors rather than a growing set of parameter special-cases. Media read/write over the
API is unaffected. See `write-path-audit.md`, which had missed this path.

Also fixed here, found while testing: `searchWithContext`'s `SEARCH_MAX_PAGES` cap counted
pending records *examined* rather than *matches*, and `myPending()` is oldest-first, so an
author with more than 20 open drafts silently stopped finding their newest ones.
`ToolSchema` additionally guarantees an array schema always carries `items` — core's
generator omits it for a bare `array` docblock, and Google's Gemini API rejects the entire
request over one such schema. Deliberately **not** done: a `restrict_ui` switch (browser
editing stays, those edits are queued) and web-server-level blocking (outside a plugin's
reach; noted in `usage.md` as optional hardening).


**Post-completion fix (2026-09-02):** re-checked whether `core.deleteMedia` still
bypasses the queue (and should therefore be dropped from the ADR-0007 allowlist). It
does not - the deletion is queued and only carried out on approval. The check did find
two defects on either side of that queue, both reproduced live and fixed on
`fix-media-delete-feedback`: the caller was told `Failed to delete media file` for a
deletion that had in fact been queued (no result channel on `MEDIA_DELETE_FILE`; now
the same throw-the-confirmation convention as a queued page save, ADR-0003), and
approving the deletion of the last file in a namespace fatally errored on
`DOKU_MEDIA_NOT_EXIST`, a constant Kaos does not define, leaving the file deleted but
the change stuck `pending`. `core.deleteMedia` had been asserted as "queued" in
[`design/write-path-audit.md`](design/write-path-audit.md) since phase 9 without any
test ever calling it; testing strategy scenarios 31-32 close that. 114/114 tests green.

## Decided questions

- Plugin base name: **`reviewqueue`** (decided with the user on 2026-08-08 — more
  precise than `aireview`, since the plugin is user-based and not AI-specific).
- `martin` gets his own `reviewer` group instead of DokuWiki admin rights (cleaner
  separation between "may perform reviews" and "is wiki administrator"); will be
  seeded this way in phase 3.
