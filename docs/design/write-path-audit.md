# Audit: can a review-required account change anything directly?

Reason: the question of whether `kail` can make changes to the wiki that don't go
through review — with `move` (renaming pages) named as an example of an operation that
can't meaningfully be reviewed, and that the account therefore shouldn't be able to
perform at all.

Approach: went through **every** remote API method (not just the ones visible as MCP
tools), plus the browser actions. Results verified against the running container, not
inferred from the source code.

## Result per write path

| Path | Before | Now |
|---|---|---|
| `core.savePage`, `core.appendPage` | Queue | Queue |
| `core.saveMedia` | Queue | Queue |
| **`core.deleteMedia`** | ⚠️ **went through directly** | Queue |
| `core.lockPages` / `unlockPages` | locks only, no content | unchanged |
| `core.login` / `logoff` | own session only | unchanged |
| `plugin.acl.addAcl` / `delAcl` / `listAcls` | restricted to admins by the ACL plugin | unchanged, now tested |
| `plugin.usermanager.createUser` / `deleteUser` | restricted to admins | unchanged, now tested |
| `dokuwiki.createUser` / `deleteUsers` (legacy) | `auth_isadmin()` | unchanged, now tested |
| Browser actions of third-party plugins (e.g. `move`) | ⚠️ **ran unhindered** | denied via allowlist |

## The two closed gaps

### `core.deleteMedia`

Media deletions go through `media_delete()`, not through `MEDIA_UPLOAD_FINISH` — so the
upload hook didn't apply here. A review-required account could thus **delete** files,
even though it couldn't add any. This only requires `AUTH_DELETE`, which is quickly
granted in a typical ACL.

Now caught via `MEDIA_DELETE_FILE` (preventable, `inc/media.php:276`) and enqueued as a
change of type `media` with `operation = delete`. The approval performs the deletion as
the original requester. The review UI clearly indicates that an approval removes the
file.

Deliberately queued instead of blocked: deletion is a reviewable intent, just like
deleting a page (empty text), which has always gone through the queue.

**Re-checked on 2026-09-02** (the question being whether this path still bypasses the
queue and should therefore be dropped from the allowlist): it does not - reproduced
live, the file stays and a `type=media`/`operation=delete` entry appears. The row above
holds. But the check found two defects in the path either side of the queue, both since
fixed and both now covered by `media.martin.spec.ts` (strategy scenarios 31-32); this
row had been asserted since phase 9 without any test ever calling the method, which is
how they survived:

- *No queue feedback.* Unlike `MEDIA_UPLOAD_FINISH`, `MEDIA_DELETE_FILE` has no result
  channel: after `preventDefault()` `media_delete()` returns `0`, which
  `ApiCore::deleteMedia()` turns into `RemoteException('Failed to delete media file')`.
  The caller was told its deletion had failed when it had in fact been queued - and an
  agent that cannot tell the two apart retries, stacking duplicate entries. Fixed the
  way ADR-0003 handles a queued page save: `action/media.php` throws the queue
  confirmation itself (`msg()` on the browser path, which the media manager would
  otherwise turn into an error page).
- *Approval could not complete.* `helper/apply.php` compared `media_delete()`'s return
  value against `DOKU_MEDIA_DELETED` and a `DOKU_MEDIA_NOT_EXIST` that Kaos does not
  define (`inc/defines.php:63-66` has exactly four). The return value is a bitmask:
  deleting the last file in a namespace yields `DOKU_MEDIA_DELETED | DOKU_MEDIA_EMPTY_NS`,
  so a successful deletion took the failure branch and fatally errored on the missing
  constant - file deleted, change stuck `pending`, reviewer told the decision could not
  be applied, and every retry hit the same fatal. Now a bit test plus a `media_exists()`
  check for "already gone".

### Actions from third-party plugins

Plugins bring their own actions (`do=…`) that change the wiki without touching
`COMMON_WIKIPAGE_SAVE` — for the `move` plugin, for instance, renaming pages. There is
nothing for the queue to intercept there.

Instead of blocking individual known plugins, review-required accounts are now subject
to an **allowlist of permitted actions** (`action/save.php`): reading, navigating,
editing, and saving are allowed, everything else is rejected with a notice. That means a
plugin installed later is locked out by default instead of silently ungoverned — that's
the safe failure direction. If an additional action is needed, it must be deliberately
added to the list.

## What is explicitly **not** covered

**Remote API methods from third-party plugins.** Kaos offers no hook that could
intercept a remote call (no `RPC_CALL` event; `Api::call()` invokes the method
directly). An installed plugin that brings its own writing `RemotePlugin` method and
does *not* itself check for admin rights would be reachable by a review-required
account.

The test `lockdown.api.spec.ts` catches exactly this: it compares the list of all
remote methods not marked read-only against a list where each method states *why* it is
harmless. If an unknown writing method shows up — through a DokuWiki update or a newly
installed plugin — the test fails instead of the gap going unnoticed.

## Phase 10 addendum: the range write tools are not a new path

`replaceSection`/`insertSection`/`deleteSection`/`replaceLines`/`replaceText`
(`plugin/remote.php`, see [`adr-0005-range-addressed-access.md`](adr-0005-range-addressed-access.md))
each compute a full new page text and hand it to `dokuwiki\Remote\ApiCore::savePage()`
directly (`remote.php::writeEffectiveText()`) - the same call `core.savePage` itself
makes, with the same ACL/lock/spam checks and the same `COMMON_WIKIPAGE_SAVE`
interception. They add no new route into `saveWikiText()`, so this audit's `core.savePage`
row already covers them; `lockdown.api.spec.ts`'s `MUTATING` map lists them as `queued`
for exactly this reason, and a dedicated test (`range write tools are queued, never
applied, exactly like savePage`) exercises it directly.

`updatePendingChange`/`withdrawPendingChange` are a different kind of write: they only
ever touch a queue entry the caller already authored and that has not been reviewed yet
(`checkOwnChangeAccess()`), so they can never publish anything - `lockdown.api.spec.ts`
lists them separately as `own-queue`.

## Phase 11 addendum: the read path is now governed too

This audit covers writes. [ADR-0007](adr-0007-agent-confinement.md) added the
matching treatment for reads and for the transports themselves: a review-scoped
account is confined by an allowlist at three gates (entry script, `do=` action,
MCP tool), so the "not covered" note above is narrower than it was.

In particular, **"remote API methods from third-party plugins" is now closed** for
such an account: `lib/exe/jsonrpc.php` and `lib/exe/xmlrpc.php` are refused
outright, and our own endpoint serves only allowlisted methods, so a writing
`RemotePlugin` method installed later is unreachable rather than merely
undetected. `lockdown.api.spec.ts` still enumerates the whole surface — now from
core's own OpenAPI spec rather than a tool list — and asserts each method is
either audited or refused by name.

`core.savePage`/`appendPage` are no longer reachable either; `createPage`,
`deletePage` and the range writes replace them and all end in the same
`ApiCore::savePage()` call, so every row of the table above still holds.

**One write path this audit had missed**, found by `/code-review` during phase 11
and reproduced live: core's `media_metasave()` — the media manager's *Edit* tab,
`mediado=save` on `lib/exe/mediamanager.php` or `doku.php?do=media` — writes IPTC
fields into the live media file, pushes an attic revision and appends a changelog
entry while firing **neither** `MEDIA_UPLOAD_FINISH` **nor** `MEDIA_DELETE_FILE`.
`action/media.php` hooks only those two, so a review-scoped account could publish
an unreviewed change to a live file. It was reachable for the whole of phases
7-10. Closed in phase 11 by refusing the media manager outright
([ADR-0007](adr-0007-agent-confinement.md) §4a) rather than by adding a third
media hook: the manager also leaks media history, and an entry-script refusal
covers both without chasing individual `mediado=` values.

The lesson for the table above is that "every write path" was scoped to page and
media *content* events; a core function that edits a file's embedded metadata
fires neither. If a future release adds another such function, the entry-script
allowlist is what contains it.

Additional operational safeguards, see [`../usage.md`](../usage.md):

- keep the ACL for the review-required account as tight as possible (no `AUTH_DELETE`
  if deletions aren't needed),
- restrict `$conf['remoteuser']` to the accounts that actually need the API,
- be cautious about installing writing plugins that bring their own remote API.
