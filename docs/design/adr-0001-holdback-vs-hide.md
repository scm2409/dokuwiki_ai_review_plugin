# ADR-0001: Hold-back queue instead of "save-then-hide"

## Status

Accepted (2026-08-08).

## Context

Two fundamental models are available for keeping changes by review-required users from
taking effect immediately:

1. **Save-then-hide** (like `approve`/`publish`, see
   [`docs/research/existing-plugins.md`](../research/existing-plugins.md)): the save
   immediately creates a real revision in the live directory. An additional mechanism
   decides which revision is shown to which reader (cache hook, render hook, revision
   list, search, feeds all have to cooperate).
2. **Hold-back queue:** the save is intercepted *before* a revision is written
   (`COMMON_WIKIPAGE_SAVE` BEFORE, see
   [`docs/research/kaos-hooks.md`](../research/kaos-hooks.md)). The new text lands in a
   separate queue store. Only on approval is `saveWikiText()` actually called again and
   a real revision created.

## Decision

We choose the **hold-back queue**.

## Rationale

- **Smaller attack surface.** Save-then-hide has to intervene in every read path:
  render cache (`PARSER_CACHE_USE`), revision list, full-text search/indexer, RSS/Atom
  feeds, backlinks. Every forgotten path is a leak through which unreviewed text becomes
  visible. The hold-back queue has exactly one interception point: the save itself.
  Everything after that is unmodified DokuWiki behavior.
- **Zero side effects for users who are not subject to review.** This is an explicit
  requirement of the project (see `CLAUDE.md`). With the hold-back queue, the plugin
  does not touch the read path at all — there is nothing that could look different for
  `martin` or other users, because their pages never pass through the queue.
- **Consistency with user expectations.** "Draft, no review, no effect yet" is more
  intuitive than "revision already exists, but is invisible" — backups, exports, and
  direct filesystem access to `data/pages/` never show unreviewed text with the
  hold-back queue.
- **Better diagnosability for the AI.** A failed/held-back save can be returned to the
  caller as a clear error (see ADR-0003) — with save-then-hide, the remote API reports a
  *successful* save even though the content is not live, which would be misleading for
  an AI agent.

## Consequences

- No access to DokuWiki's built-in revision management for pending changes — a separate,
  simple store is needed (see [ADR-0002](adr-0002-file-store.md)).
- Approval has to retroactively replay the original save with the correct
  author/timestamp context (see `docs/design/spec.md`, section "Approval").
- Conflicts (the live page has evolved further since the pending change) must be
  handled explicitly, because the queue is not part of the normal revision chain —
  see the 3-way merge in `docs/design/spec.md`.
