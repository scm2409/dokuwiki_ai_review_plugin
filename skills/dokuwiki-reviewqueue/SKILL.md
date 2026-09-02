---
name: dokuwiki-reviewqueue
description: Read and edit pages in a DokuWiki whose saves go through a human review queue (the reviewqueue plugin). Use whenever writing to, editing, deleting, or checking the status of DokuWiki pages over MCP - your saves do NOT go live immediately there, and reading a page back will not show your own unreviewed work unless you use the right tool.
---

# Editing a DokuWiki that has a review queue

This wiki runs the `reviewqueue` plugin. Your account's saves are **held back
for human review** instead of being published. Other accounts are unaffected.

The single most important consequence: **a successful-looking save is not a
published page, and reading the page back will not show your own draft.** Get
this wrong and you will silently destroy your own earlier work.

## Default workflow: work on parts of a page, not the whole thing

A whole page can easily be too large to read or write in one call, and every
small edit otherwise costs two full-page transfers. Unless a page is small,
don't pull it whole:

1. **`getPageOutline`** — the table of contents: every heading, its size, and
   a "hash" you can pass to a write tool to catch a stale write. Call this
   first, before deciding what to read next.
2. **`getSection`** (by heading, index, or `#hid`), **`getLines`**, or
   **`findInPage`** (a search within one page, with line numbers and
   context) — read only the part you actually need.
3. **`replaceSection`**, **`insertSection`**, **`deleteSection`**,
   **`replaceLines`**, or **`replaceText`** (an exact-string edit, the
   cheapest and safest when you know the exact wording to change) — write
   only that part back.

All of these accept a `source` parameter (`auto`/`live`/`pending`) and work
identically whether the effective text is the live page or your own open
draft - see "The one rule" below for what "effective text" means. All of the
read tools accept an optional `expect`/context handling that lets a write
tool refuse a stale write; read a section right before writing it if you are
not sure it is still current.

Two writes cannot address a range of anything, so they are their own tools:

- **`createPage(page, text, summary)`** — bring a new page into being. Refused
  if the page already exists, or if you already have an open draft creating it.
- **`deletePage(page, summary)`** — propose removing a page. Every other write
  tool refuses to leave a page empty, so a deletion is always deliberate.

`core.savePage`, `core.appendPage` and `core.getPage` are **not available** to
a review-scoped account (ADR-0007): `getPageToEdit` and the range tools replace
them, and the two above cover creating and deleting.

## What `status` means on every write tool

Every write tool (the range tools, `updatePendingChange`) returns a `status`
field - use it, don't guess from side effects:

- `"live"` — you are not subject to review, or nothing needed to change; the
  wiki reflects your write immediately.
- `"queued"` — this created a new pending change. Report it as pending, not
  as done.
- `"updated"` — this continued your own existing open change **in place**;
  the pending id is unchanged. Still pending, still not published - but
  there is only ever one entry for your ongoing work on this page, not a
  growing pile of them.

## Never stack changes on one page - the range tools already handle this

Two drafts for one page, each based on the published revision rather than on
each other, mean whichever the reviewer approves last silently wins. Over the
tools you have, this can no longer happen by accident:

- the range write tools read your own open draft as their starting point and
  continue it in place (`status: "updated"`), so refining a page you are
  already working on never creates a second entry;
- `createPage` refuses outright when a draft for that page is already open, and
  tells you which change to continue.

If you get that refusal, you used the wrong tool: read the draft with
`getPageToEdit` and continue it with a range write (or `updatePendingChange`
for a full rewrite).

## The one rule for full-page work

Before reading or editing a **whole** page, call **`getPageToEdit`**.

| Transport | Tool name |
|---|---|
| MCP | `plugin_reviewqueue_getPageToEdit` |
| JSON-RPC | `plugin.reviewqueue.getPageToEdit` |

It returns the text you should actually edit:

```json
{ "text": "...", "source": "pending", "pendingId": 42, "warning": "" }
```

- `source: "live"` — nothing of yours is pending; this is the published text.
- `source: "pending"` — you have an unreviewed change on this page and this is
  *your* draft. Edit this, not the live text.
- `warning` — non-empty means read it and act on it.

If you need the published text specifically - to compare against your draft,
say - the range read tools take `source: "live"`. Left at their default
(`source: "auto"`) they give you your own draft when you have one, which is
almost always what you want.

## Changed your mind about an open change? Withdraw it.

**`withdrawPendingChange(id, reason)`** lets you cancel your own still-pending
change yourself - you no longer have to leave it for a reviewer to reject.
Use it when you decide a change was premature, wrong, or superseded by other
work, instead of leaving a stale entry for a human to clean up. It moves to
`withdrawn`, distinct from `rejected` (no reviewer is involved or recorded).
You cannot withdraw a change that is not yours, is already decided, or is
`conflicted` (that still needs a reviewer's manual resolution).

## What happens when a full-page save is queued

`createPage`, `deletePage` and the range write tools all report the outcome as
a value, in the `status` field described above - a `"queued"` or `"updated"`
status is the success path, not a failure to retry. Do not retry the write, do
not try to work around it, and **do not tell the user the page was updated.**
Say it was submitted for review and is awaiting approval.

The two media writes (`core.saveMedia` and `core.deleteMedia`) are the ones
that still report a queued change by **returning an error**, because core's
methods have no other channel:

> Your change to 'start' was submitted for review as change #42. It is NOT live yet.

> Deletion of 'logo.png' was submitted for review as change #43. The file is NOT deleted yet.

Take the change id from it. Anything else the two report *is* a real failure -
in particular `Failed to delete media file`, which means the deletion neither
happened nor was queued. The range write tools report the same
outcome via `status: "queued"`/`"updated"` instead of an error - see above.

## Searching: use `searchWithContext`, not `core.searchPages`

`core.searchPages` only matches **published** text and returns whole-page
snippets. `searchWithContext` is strictly better on a wiki with a review
queue: it returns line numbers and context (so you can jump straight to
`getLines`/`getSection` instead of pulling the whole page), and by default
(`scope: "all"`) it also searches your own unreviewed drafts - something
`core.searchPages` can never do (see "Searching: the wiki search cannot see
your drafts" below for why that matters). Use `searchWithContext` as your
default search on this wiki; fall back to `core.searchPages` only if you
specifically want published-only results with core's own ranking.

### Searching: the wiki search cannot see your drafts

Anything you have written that is still awaiting review is invisible to a
search that only covers published text - including to you. Skip searching
your own drafts and you will conclude a topic is uncovered, write it again on
another page, and end up with two competing drafts. `getPageToEdit`/the range
tools cannot save you here: they only help once you have picked the page.
`searchWithContext`'s default scope already covers this; if you use
`core.searchPages` instead, pair it with `searchMyPending`.

## Checking on your work

| Purpose | Tool |
|---|---|
| List everything of yours still awaiting review | `listMyPending` |
| Full-text search across your unreviewed drafts (whole-page results) | `searchMyPending` |
| State of one change, plus the reviewer's reason if rejected | `getStatus` |
| Re-read the exact full text you submitted | `getPendingText` |

`getStatus` returns `state` as one of:

- `pending` — still waiting for a human.
- `approved` — now live on the wiki.
- `rejected` — **read `comment`**, it is the reviewer's reason. Address it and
  submit a new change; the old one is closed and cannot be revived.
- `conflicted` — the page changed underneath your draft, so it could not be
  applied automatically. A human must resolve it. Do not resubmit blindly:
  call `getPageToEdit` for the current state first.
- `withdrawn` — you cancelled it yourself via `withdrawPendingChange`; also
  closed, but distinct from a rejection (`reviewer` is empty).
- `superseded` — replaced by a later change.

## Things that will mislead you if you forget them

- **Search does not see queued changes** unless you use `searchWithContext`
  or `searchMyPending`.
- **Deleting a page** is `deletePage`, and it is queued like any other change.
  The page stays visible until a human approves the deletion. The range write
  tools refuse a write that would leave the page empty, on purpose: a deletion
  should be something you asked for, not the by-product of replacing a range
  with nothing.
- **You cannot read page history.** No tool exposes revisions, and the browser
  routes to them are closed for your account too. If you need to know what a
  page said before, ask the user.
- **You cannot approve anything**, including your own changes. Self-approval is
  refused by design. Only a reviewer can publish.
- **The page history won't show your pending change.** Once approved, it appears
  attributed to you, with a note naming the reviewer.

## Reporting to the user

Be accurate about state. Good:

> Submitted the rewrite of `projects:roadmap` for review (change #42). It's
> queued and won't be visible on the wiki until someone approves it.

> Updated section "Deployment" of `ops:runbook` within my existing pending
> change (#17, still awaiting review) rather than creating a new one.

Not acceptable: "I updated the page" / "Done, the wiki now says X" when the
change is merely queued or updated-but-still-pending.
