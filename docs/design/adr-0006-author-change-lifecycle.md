# ADR-0006: Author-side change lifecycle - continue and withdraw

## Status

Accepted (2026-08-30). Amends [ADR-0004](adr-0004-visibility-of-open-changes.md).

## Context

ADR-0004 gave the author tools to see and search their own open drafts, but deliberately
left the drafts themselves immutable once queued: "a re-edit is a new pending change"
(`docs/design/spec.md`, `helper/store.php::update()`'s docblock). Combined with
[ADR-0004's stacking rule](adr-0004-visibility-of-open-changes.md) - each further save is
based on the live revision, not on the author's own prior draft - this has a real cost in
practice: an agent that returns to refine a change it just submitted, or that corrects a
change after search turns up something it missed, keeps producing a new queue entry for
the same page rather than one entry that improves over time. The reviewer ends up working
through a pile of near-duplicate, non-additive drafts instead of one.

Phase 10 also introduces range-addressed write tools (`replaceSection`, `insertSection`,
`deleteSection`, `replaceLines`, `replaceText` - see
[ADR-0005](adr-0005-range-addressed-access.md)) that already read the author's own open
draft as their base (the same "effective text" precedence `getPageToEdit` established).
Having them then write that continuation as a *new* queue entry, discarding the
relationship to the draft they just read, would be the exact same stacking trap ADR-0004
warns about - just reintroduced one level up. It would also be a worse default than doing
nothing: a tool explicitly designed to build on the author's existing draft producing a
second, non-additive entry is a trap an agent has no way to see coming.

Separately, an agent has repeatedly created queue entries it then had no way to take
back - e.g. after deciding mid-task that an edit was premature, or that it duplicated
work already covered elsewhere. Today the only way to close such an entry is a reviewer
rejecting it, which is the wrong tool for "the author changed their mind" - a rejection
is a review decision and implies something was wrong with the change on its merits.

## Decision

1. **Range writes continue the author's own open entry for that page, in place.** A new
   store method, `helper_plugin_reviewqueue_store::updateContent($id, $content,
   $metaPatch)`, replaces a still-`pending` entry's proposed text without creating a new
   entry or touching `base`/`baseRev`/`baseHash`/`created`, so the reviewer's diff and the
   three-way merge keep their original anchor throughout. Two new bookkeeping fields,
   `updated` and `updateCount`, record that this happened; the admin queue shows a badge
   when `updateCount > 0` so a reviewer who looked at the change earlier knows the text
   has moved on since.
2. **`core.savePage` keeps ADR-0004's stack-and-warn behaviour, unchanged.** Range writes
   are additive continuations by construction - they read the draft, adjust one part of
   it, write it back - so continuing in place is the correct default with essentially no
   ambiguity about intent. A full `core.savePage` carries no such evidence about the
   caller's intent (it may be a deliberate alternative proposal, per ADR-0004's own
   rationale for allowing stacking), so it is left exactly as ADR-0004 decided.
3. **`updatePendingChange($id, $text, $summary)`** gives the same in-place continuation
   as an explicit remote method, for a rewrite too large or scattered for the range tools
   to express as one call.
4. **`withdrawPendingChange($id, $reason)`** lets the author cancel their own still-`pending`
   change without a reviewer decision. It is not a review outcome: no `reviewer` is
   recorded, and it goes through a new terminal state, `withdrawn` (distinct from
   `rejected`), so a rejection's meaning - "a human reviewed this and said no" - stays
   intact. Like every terminal state it moves to `archive/` rather than being deleted, so
   the reviewer can still see that it happened and why.
5. **Only the author, never a reviewer, can continue or withdraw a change** -
   `checkOwnChangeAccess()` in `remote.php` deliberately does not fall back to
   `mayReviewTarget()` the way `checkChangeAccess()` does for read access. Deciding what
   an author's own draft says is the author's call.
6. **Only a `pending` entry can be continued or withdrawn.** A `conflicted` entry needs a
   human's manual resolution (unchanged from `docs/design/spec.md`); an `approved`,
   `rejected`, or already-`withdrawn` entry is a closed decision. Both `updateContent()`
   and `withdraw()` refuse outside of `pending`, and `remote.php` gives a specific error
   naming the actual state rather than a generic failure.

## Rationale

- **ADR-0004's refusal to auto-supersede stands - this is a different actor making the
  choice.** ADR-0004 decided that the *reviewer* should not have an older draft silently
  superseded out from under them, because "the reviewer might prefer the older draft on
  its merits." That concern does not apply to the *author* replacing their own
  not-yet-reviewed text: nobody has looked at it yet, there is nothing to override.
- **Scoping the automatic continuation to range writes, rather than making every
  `core.savePage` behave this way, keeps one clear rule instead of two competing
  heuristics.** Guessing intent from a full-text save (is this a continuation or a
  deliberate second proposal?) would need exactly the kind of implicit inference ADR-0004
  rejected in favour of explicit tools. A range write has no such ambiguity: it is,
  definitionally, "change part of what I already have open here."
- **`withdrawn` as its own state, not a variant of `rejected`,** keeps `getStatus()`'s
  contract honest for every existing caller: `rejected` has always meant "read `comment`,
  it's the reviewer's reason" (see `skills/dokuwiki-reviewqueue/SKILL.md`). Overloading it
  for a self-cancellation would make that field's meaning depend on who acted, which is
  exactly the kind of state an agent is liable to misreport to the user.

## Consequences

- `helper_plugin_reviewqueue_store::update()`'s docblock claim that "content is immutable
  once queued" is no longer true in general - `updateContent()` is now the documented
  exception, gated on `state === 'pending'` and never touching the merge-relevant fields.
- The state machine in `docs/design/spec.md` gains `withdrawn` as a fourth terminal state
  alongside `approved`, `rejected`, `superseded`.
- `skills/dokuwiki-reviewqueue/SKILL.md`'s "you cannot withdraw it yourself" paragraph
  (written against ADR-0004's original scope) is now wrong and is replaced with guidance
  to prefer the range write tools for continuing a draft, and `withdrawPendingChange` for
  abandoning one.
- **A pending change's content no longer being immutable is a real hole in the review
  guarantee unless approval itself is guarded against it, so it is:**
  `admin.php::renderForm()` embeds the record's `contentHash` as a hidden `rqhash` field
  at render time; `handle()` refuses an `approve` whose submitted `rqhash` no longer
  matches the record's current `contentHash`, with a message asking the reviewer to look
  at the current text. Concretely: reviewer opens the diff for change #42, the author
  continues #42 via a range write before the reviewer clicks Approve, the submission is
  refused rather than silently publishing text the reviewer never saw - the badge from
  the point above is what a reviewer sees *before* deciding; this check is what stops a
  decision already in flight from applying to different text than it was made against.
  `reject` doesn't publish anything and needs no such check; `resolve` (the conflict path)
  already publishes exactly the text submitted in its own form rather than re-reading by
  id, so it was never exposed to this in the first place. Verified end to end in
  `test/e2e/tests/range-write.api.spec.ts`.
