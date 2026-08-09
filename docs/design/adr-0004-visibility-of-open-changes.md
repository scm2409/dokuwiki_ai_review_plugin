# ADR-0004: Visibility of open changes for the author

## Status

Accepted (2026-08-08).

## Context

From the hold-back model ([ADR-0001](adr-0001-holdback-vs-hide.md)) it follows that a
submitted change exists **nowhere** as page content before it is approved. This was
verified against the running container — after a change submitted by `kail`:

| What `kail` does | What he gets |
|---|---|
| `core.getPage` on the page | the **live text**, not his draft, with no indication whatsoever |
| `core.searchPages` for a word from his draft | **no hit** (not even for himself) |
| `core.searchPages` for a word from the live text | normal hit |
| Opening the page in the browser | live version, no banner (that's for reviewers only) |

For isolation, that is exactly right — unreviewed text must not appear in rendering or
in the search index. For the **author**, however, it is a real trap, and one that causes
silent data loss:

1. `kail` submits change #1 (page completely rewritten).
2. `kail` reads the page again → sees the old live text, assumes his work was lost or
   never happened.
3. `kail` writes again, this time based on the live text → change #2.
4. Both entries carry the **same `baseHash`** (verified), i.e. both are based on the
   live version and not on each other.
5. If the reviewer approves both, #2 silently overwrites the work from #1. If he
   approves only #1, #2 becomes `conflicted`.

This affects not only AI agents — a human subject to review runs into the same trap.

## Decision

The **read path stays untouched** (ADR-0001 still applies unchanged): no intervention in
rendering, cache, search index, or revision list, not even for the author themself.
Instead, the author gets **explicit tools and explicit warnings**:

1. **`plugin.reviewqueue.getPageToEdit($page)`** — the central building block. Returns
   the text to continue working on: the author's own latest open draft, if one exists,
   otherwise the live version. Along with it, `source` (`pending`/`live`), `pendingId`,
   and a `warning` field. A single call that always does the right thing.
2. **`listMyPending()` / `getStatus($id)` / `getPendingText($id)`** — list your own open
   changes, query status including a rejection reason, read back the submitted text.
2a. **`searchMyPending($query)`** — full-text search over your own open drafts. Closes
   the remaining gap that `getPageToEdit` does **not** cover: that call only kicks in
   once the target page is already determined. Anyone searching by content ("have I
   already written something about topic X?") gets only published text back from
   `core.searchPages`, assumes the topic is untouched, and creates a second version on
   a **different** page — a case no page-scoped tool can catch. Simple substring search
   over text, page ID, and summary, with hit context; at the expected volume
   ([ADR-0002](adr-0002-file-store.md)) a linear scan is sufficient, a dedicated search
   index would be inappropriate.
3. **Warning on stacking.** If someone submits a change for a page for which they
   already have open changes, the response explicitly names them by number ("you
   already have unreviewed change(s) #1, #2 on this page … the earlier work will be
   overwritten"). In the browser as an additional message, over the remote API attached
   to the `RemoteException` that is thrown anyway ([ADR-0003](adr-0003-ai-feedback-remoteexception.md)).
4. **Warning for the reviewer.** If multiple open changes exist for the same page, the
   admin queue points this out on every affected entry, noting that they don't build on
   each other and that approving more than one overwrites the earlier ones.
5. **The agent skill** (`skills/dokuwiki-reviewqueue/`) mandates the workflow: call
   `getPageToEdit` before every edit instead of `core.getPage`.

Deliberately **not** implemented: automatically setting the older entry to `superseded`.
The reviewer might prefer the older draft on its merits; that decision stays with the
human (consistent with `docs/design/spec.md`, where `superseded` is a manual step).

## Rationale

- **The read path is the expensive spot.** Opening it up for the author would mean
  rebuilding exactly the interventions ADR-0001 wanted to avoid (cache, search, feeds,
  revisions) — only now user-dependent, which makes things harder to test and more prone
  to leaks, rather than simpler.
- **Tools are the more natural channel for an agent.** The MCP client sees the tool
  descriptions anyway; a tool named "get the page text to base an edit on" with an
  unambiguous description steers behavior more reliably than implicit state the agent
  would have to guess.
- **Warnings catch the case where the tool wasn't used.** Even an agent that ignores
  `getPageToEdit` gets an unmissable message when submitting, and the reviewer gets one
  too. No path leads silently into data loss.

## Consequences

- Every client that wants to write should use `getPageToEdit`. `core.getPage` remains
  functional and continues to return the live version — which is also correct for
  purely read-only applications.
- The warnings are text, not a block: an author *is* allowed to stack multiple changes
  (for instance, deliberately as alternative proposals). Responsibility lies with the
  reviewer, who also sees the warning.
- **Docblock trap:** DokuWiki's parser (`inc/Remote/OpenApiDoc/DocBlock.php:49`) strips
  only the *first* line for `@param`/`@return`/`@throws`. Multi-line tags leak into the
  generated MCP tool description and produce unusable fragments (happened and was fixed
  on the first pass). Tags must stay single-line; structural descriptions belong in the
  prose section before them.
- The read tools are annotated by the `mcp` plugin as `readOnlyHint: false`, because its
  `READ_ONLY` list (`SchemaGenerator`) only knows core methods. Cosmetic — a client might
  unnecessarily ask for confirmation. Not changeable without patching the `mcp` plugin,
  hence accepted.
