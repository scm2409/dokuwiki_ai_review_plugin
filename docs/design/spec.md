# Specification: reviewqueue plugin

Consolidates the decisions from [ADR-0001](adr-0001-holdback-vs-hide.md),
[ADR-0002](adr-0002-file-store.md), and [ADR-0003](adr-0003-ai-feedback-remoteexception.md)
into a binding reference for the implementation. For verified facts about DokuWiki Kaos,
see [`docs/research/kaos-hooks.md`](../research/kaos-hooks.md).

## State machine

```
                 ┌──────────────────────────────────────────┐
  Save by a      │                                          │
  review-        v                                          │
  required   ──> [pending] ──approve──> merge ok ──> [approved] ──> live revision
  user               │                     │                          (author: original user)
                      │                     └─ conflict ──> [conflicted] ──manual──> [approved]
                      ├──reject(reason)──> [rejected]
                      ├──author withdraws──> [withdrawn]
                      └──newer base makes change moot──> [superseded]
```

Terminal states: `approved`, `rejected`, `withdrawn`, `superseded`. All four move from
`queue/` to `archive/` once reached (see data model).

`withdrawn` (added Phase 10, see [ADR-0006](adr-0006-author-change-lifecycle.md)) is the
author's own counterpart to `reject`: the author cancels their own still-`pending` change
via `plugin.reviewqueue.withdrawPendingChange`, without a reviewer's decision. Unlike
`rejected`, no `reviewer` is ever recorded on a `withdrawn` entry - `getStatus()`'s
`reviewer` field staying empty is precisely what distinguishes "the author changed their
mind" from "a human reviewed this and said no". Only a `pending` entry can be withdrawn;
`conflicted` still requires the reviewer's manual resolution regardless of who authored it.

While a change is still `pending`, its content is no longer strictly immutable as it was
through Phase 9: `plugin.reviewqueue.updatePendingChange` and the range write tools
(`replaceSection`, `insertSection`, `deleteSection`, `replaceLines`, `replaceText` - see
[ADR-0005](adr-0005-range-addressed-access.md)) replace the proposed text of the author's
own open change in place instead of creating a new one, provided it is still `pending`.
`base`/`baseRev`/`baseHash`/`created` are never touched by this, so the reviewer's diff and
the three-way merge keep their original anchor no matter how many times the author has
continued the draft.

`superseded` is set when a *newer* pending change by the same user for the same page is
created while an older one is still open, **and** the reviewer decides to handle only
the newest one (see test scenario 6 — by default both stay open and are processed
sequentially; `superseded` is a manual reviewer step, not an automatism).

## Data model

```
data/reviewqueue/
├── seq                          # text file with the last assigned ID, protected by io_lock()
├── queue/
│   ├── <id>.json                 # metadata, see below
│   ├── <id>.content              # new full text of the page (empty = deletion)
│   ├── <id>.base                 # text state the change is based on
│   └── <id>.media                # only for type=media: binary data of the upload
└── archive/                      # same files, for decided changes
```

`<id>.base` is stored alongside instead of reconstructing the base text from the attic
on demand: DokuWiki revisions have second-level granularity, and if a human saves in the
same second the change is based on, their attic entry overwrites the original one —
exactly in the busy-wiki situation where automerge would be most valuable.

Media files do **not** go through `io_saveFile()`/`io_readFile()`, because
`io_readFile()` applies `cleanText()`, which would corrupt binary data; they are copied
byte-for-byte instead (`helper/store.php::putMedia()`).

### `<id>.json`

| Field | Type | Meaning |
|---|---|---|
| `id` | int | Sequential ID, from `seq` |
| `type` | `page` \| `media` | Kind of change |
| `target` | string | Page ID or media ID |
| `author` | string | Original username (e.g. `kail`) |
| `summary` | string | Summary provided by the author |
| `minor` | bool | Minor-edit flag |
| `baseRev` | int\|null | Timestamp of the revision the change is based on (null for new pages) |
| `baseHash` | string | Hash of the base text, for merge detection |
| `created` | int | Unix timestamp of submission |
| `state` | `pending`\|`approved`\|`rejected`\|`conflicted`\|`withdrawn`\|`superseded` | Current status |
| `reviewer` | string\|null | Who made the decision (never set for `withdrawn`) |
| `reviewedAt` | int\|null | When the decision was made |
| `comment` | string\|null | Rejection/approval/withdrawal comment |
| `mergeResult` | `clean`\|`auto-merged`\|`conflict`\|null | Result of the 3-way merge on approval |
| `origin` | `ui`\|`remote`\|`cli` | Path through which it was submitted |
| `updated` | int\|null | Unix timestamp of the last in-place content update (Phase 10, null if never updated) |
| `updateCount` | int | Number of times the content was updated in place (Phase 10, default 0) |
| `contentHash` | string | Short hash of the current proposed text (Phase 10; same format as `helper_plugin_reviewqueue_range::hash()`). Also what `admin.php`'s approve form checks against to refuse a stale approval - see ADR-0006 |

All writes exclusively via DokuWiki's `io_saveFile()` / `io_lock()` / `io_unlock()`. No
custom locking scheme.

## Policy: who is subject to review?

Single point of decision: `helper/policy.php::needsReview($user, array $groups)`.

```php
$conf['review_users']    = 'kail';   // comma-separated, exact usernames
$conf['review_groups']   = '';       // comma-separated, DokuWiki groups
$conf['reviewer_groups'] = 'reviewer'; // who may approve/reject
$conf['review_media']    = 1;        // include media uploads?
$conf['review_delete']   = 1;        // include deletions? (technically: empty text)
$conf['auto_merge']      = 1;        // attempt Diff3 automerge on approval?
$conf['show_banner']     = 1;        // banner on the affected page for reviewers?
$conf['max_pending_age'] = 0;        // days until automatic archiving; 0 = no expiry
```

`needsReview()` is called as the **first line** in every hook — for users not subject to
review (the normal case, e.g. `martin`), the handler exits the function immediately,
without touching the store, lock, or I/O. That is the technical implementation of "no
side effects for other users."

## Hook matrix

| File | Hook | BEFORE/AFTER | Task |
|---|---|---|---|
| `action/save.php` | `ACTION_ACT_PREPROCESS` | BEFORE | Pure marker (no `preventDefault()`, no data change): records that this request is DokuWiki's own interactive `save` act, so `handleWikipageSave()` below can distinguish "human at the editor" (friendly notice) from every other caller (hard `RemoteException`) — without any fragile script-name detection |
| `action/save.php` | `COMMON_WIKIPAGE_SAVE` | BEFORE | **The actual interception point for all paths** (browser, remote API, MCP, CLI, third-party plugins) — enqueue + `preventDefault()`. Browser → `msg()`; everything else → `RemoteException` (ADR-0003) |
| `action/media.php` | `MEDIA_UPLOAD_FINISH` | BEFORE | Copy upload bytes into the queue, `preventDefault()` (Phase 7) |
| `action/banner.php` | `TPL_CONTENT_DISPLAY` | BEFORE | Prepend banner (`$event->data` is the rendered HTML string, by reference — see `docs/research/kaos-hooks.md`), only if `show_banner` is on, the current user `isReviewer()`, **and** open pending changes exist for this page |
| `admin.php` | `AdminPlugin` interface | — | `handle()`/`html()` are called by `dokuwiki\Action\Admin::preProcess()` for every request to the page (`handle()` processes an approve/reject POST including `checkSecurityToken()` and the self-approval ban, then `html()` for the queue list + diff). `isAccessibleByCurrentUser()` is overridden to `isReviewer()` instead of DokuWiki admin/manager rights — no separate `action/review.php` needed, this is the native `AdminPlugin` mechanism for that |
| `remote.php` | `RemotePlugin` interface | — | Phase 8: `getPageToEdit`, `listMyPending`, `searchMyPending`, `getStatus`, `getPendingText`. Phase 10 (ADR-0005/ADR-0006) adds range-addressed reads (`getPageOutline`, `getSection`, `getLines`, `findInPage`, `searchWithContext`), range-addressed writes that continue the author's own open change in place (`replaceSection`, `insertSection`, `deleteSection`, `replaceLines`, `replaceText`), and full-text lifecycle tools (`updatePendingChange`, `withdrawPendingChange`) |

### Re-entrancy

`helper/apply.php` sets a static flag (`Policy::$applying = true`) when approving,
before calling `saveWikiText()`/media save itself — the `COMMON_WIKIPAGE_SAVE` handler
checks this flag first and lets the call through, otherwise the approval would put
itself back into the queue.

## Approval flow (`helper/apply.php`)

1. Lock the pending-change entry.
2. `helper/merge.php`: load the current live text, compare it against `baseHash`.
   - Unchanged since `baseRev` → `mergeResult = clean`, pending text is taken over 1:1.
   - Changed, `auto_merge=1` → attempt `Diff3`. Clean → `auto-merged`, merged text is
     taken over. Conflict → `state = conflicted`, the flow stops here, the reviewer must
     resolve manually in the review editor (which then directly supplies the final
     text, no repeated automerge attempt).
3. Temporarily set `REMOTE_USER` (or whichever author context `saveWikiText()` uses) to
   `author` from the pending change.
4. Set the re-entrancy flag, call `saveWikiText($target, $finalText, $summary, $minor)`
   (or `media_save()` for `type=media`). The summary gets an approval note appended
   (e.g. `" (reviewed by martin, #42)"`); the review ID additionally lands in the
   changelog's `extra` field for machine traceability.
5. Reset the re-entrancy flag, restore `REMOTE_USER`.
6. Move the pending-change entry to `archive/`, set `state = approved`, set
   `reviewer`/`reviewedAt`.

**Self-approval ban:** `admin.php::handle()` checks `$record['author'] !==
$_SERVER['REMOTE_USER']` before every approve/reject action — even if the author happens
to be in `reviewer_groups`.

### Implementation status

Fully implemented and verified against the running container. The 3-way merge
(`helper/merge.php`) attempts a `Diff3` merge on a diverging `baseHash`; if it succeeds
without conflict, `mergeResult = auto-merged` is set, otherwise `state = conflicted`
with manual resolution in the admin form (`mergeResult = manual`).

Note: **`Diff3::mergedOutput()` is broken in Kaos** (accesses `protected` properties in
the conflict branch, see [`docs/research/kaos-hooks.md`](../research/kaos-hooks.md)).
`helper/merge.php` works around this by iterating the edit list itself.

## Fail-closed (guiding principle, see `CLAUDE.md`)

Every attempt to write to the queue (`helper/store.php::enqueue()`, and since Phase 10
also `updateContent()` and `withdraw()`) is wrapped in a try/catch. If the write fails
(lock timeout, directory not writable, JSON encoding error):

- in the browser path: the save is aborted with an error message (no fallback to a
  normal save!),
- in the remote path: a `RemoteException` is thrown,

— in both cases the previous state (the live page, or the pending change's previous
content) remains unchanged. Tested in test scenarios 17 and 23.

## MCP visibility

`remote.php` implements `dokuwiki\Extension\RemotePlugin`, so every public method with a
docblock is a candidate tool. Since [ADR-0007](adr-0007-agent-confinement.md) the plugin
serves them itself, from `plugin/mcp.php` via `meta/McpServer.php` and
`meta/ToolSchema.php` (adapted from `splitbrain/dokuwiki-plugin-mcp`, GPL-2), rather than
relying on that plugin being installed — which is now a deployment error, because it
would serve the entire unrestricted API surface alongside ours.

A method appears as a tool only if it is on `helper/capability.php`'s `TOOLS` list, and a
`tools/call` naming anything else is refused rather than merely hidden. Adding a remote
method therefore takes two steps now: write it with a clean docblock, and add it to that
list — deliberately, since the list is also what `lockdown.api.spec.ts` audits.

`ToolSchema` additionally guarantees that no emitted schema has type `array` without
`items`. Core's `OpenAPIGenerator::typeToSchema()` omits `items` whenever the docblock
says a bare `array` rather than `foo[]`, and Google's Gemini API rejects the *entire*
request over one such schema — so a single method with a loose docblock would otherwise
take the whole tool list down for anyone routing through Gemini.
