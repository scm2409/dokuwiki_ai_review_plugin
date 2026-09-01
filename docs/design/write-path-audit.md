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

Additional operational safeguards, see [`../usage.md`](../usage.md):

- keep the ACL for the review-required account as tight as possible (no `AUTH_DELETE`
  if deletions aren't needed),
- restrict `$conf['remoteuser']` to the accounts that actually need the API,
- be cautious about installing writing plugins that bring their own remote API.
