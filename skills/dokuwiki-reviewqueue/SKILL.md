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

## The one rule

Before editing any page, call **`getPageToEdit`**, never `core.getPage`.

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

`core.getPage` always returns the **live** text. If you use it while you have a
pending change, your next save reverts your own unreviewed work back to the
published version.

## What happens when you save

`core.savePage` (and `core.appendPage`) will **return an error** when your change
is queued. That error is the success path — it is not a failure to retry:

> Your change to 'start' was submitted for review as change #42. It is NOT live yet.

Take the change id from it. Do not retry the save, do not try to work around it,
and **do not tell the user the page was updated.** Say it was submitted for
review and is awaiting approval.

A genuine failure looks different — it says the queue could not be written and
your change was *not* saved. That one is worth retrying or escalating.

## Never stack changes on one page

If you save twice to the same page before the first is reviewed, both drafts are
based on the published revision, not on each other. Whichever the reviewer
approves last wins, and the other's content is lost. The error message warns you
when this happens:

> Warning: you already have unreviewed change(s) #41 on this page.

If you see that, you skipped `getPageToEdit`. Recover by calling
`getPageToEdit`, folding both intents into one text, and asking the reviewer (via
the user) to reject the superfluous change — you cannot withdraw it yourself.

## Searching: the wiki search cannot see your drafts

`core.searchPages` only matches **published** text. Anything you have written
that is still awaiting review is invisible to it — including to you.

So whenever you search in order to decide *what to write*, search both:

1. `core.searchPages` — what is actually on the wiki.
2. `searchMyPending` — what you have already written but that is not approved yet.

Skip the second and you will conclude a topic is uncovered, write it again on
another page, and end up with two competing drafts. `getPageToEdit` cannot save
you here: it only helps once you have picked the page.

## Checking on your work

| Purpose | Tool |
|---|---|
| List everything of yours still awaiting review | `listMyPending` |
| Full-text search across your unreviewed drafts | `searchMyPending` |
| State of one change, plus the reviewer's reason if rejected | `getStatus` |
| Re-read the exact text you submitted | `getPendingText` |

`getStatus` returns `state` as one of:

- `pending` — still waiting for a human.
- `approved` — now live on the wiki.
- `rejected` — **read `comment`**, it is the reviewer's reason. Address it and
  submit a new change; the old one is closed and cannot be revived.
- `conflicted` — the page changed underneath your draft, so it could not be
  applied automatically. A human must resolve it. Do not resubmit blindly:
  call `getPageToEdit` for the current state first.
- `superseded` — replaced by a later change.

## Things that will mislead you if you forget them

- **Search does not see queued changes.** Your draft being absent from
  `core.searchPages` results does not mean it was lost — use `searchMyPending`
  or `listMyPending`.
- **Deleting a page** (saving empty text) is queued like any other change. The
  page stays visible until a human approves the deletion.
- **You cannot approve anything**, including your own changes. Self-approval is
  refused by design. Only a reviewer can publish.
- **The page history won't show your pending change.** Once approved, it appears
  attributed to you, with a note naming the reviewer.

## Reporting to the user

Be accurate about state. Good:

> Submitted the rewrite of `projects:roadmap` for review (change #42). It's
> queued and won't be visible on the wiki until someone approves it.

Not acceptable: "I updated the page" / "Done, the wiki now says X" when the
change is merely queued.
