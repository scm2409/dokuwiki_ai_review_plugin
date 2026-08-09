# Code review of the implementation (2026-08-08)

Review of the plugin's source code, as requested by the user. Split into fixed / knowingly
left open, so the current state stays honestly traceable.

## Fixed

### 1. Orphaned page lock after an intercepted remote save (severe)

`ApiCore::savePage()` is structured as `lock()` → `saveWikiText()` → `unlock()`. The
`RemoteException` from [ADR-0003](adr-0003-ai-feedback-remoteexception.md) is thrown from
right in the middle of `saveWikiText()`, so `unlock()` was **never** reached.

Effect: after every submitted change, the page stayed locked for the full lock
duration. `martin` got "The page is currently locked" and couldn't edit exactly the
pages the agent was working on — precisely the disruption to normal wiki operation
that this plugin is meant to avoid. An agent that kept writing continuously could have
kept pages effectively locked permanently.

Fixed in `action/save.php`: an explicit `unlock()` before the throw. Regression test in
`visibility.api.spec.ts`. The browser path was never affected, because it returns
normally and `dokuwiki\Action\Save` reaches its own `unlock()`.

### 2. Multi-line docblock tags break the MCP tool descriptions

DokuWiki's parser (`inc/Remote/OpenApiDoc/DocBlock.php:49`) strips only the *first*
line for `@param`/`@return`/`@throws`. Continuation lines ended up in the generated
tool description, so agents saw fragments like
"'approved', 'rejected' or 'superseded'), comment (reviewer's" as tool documentation.
Fixed: tags kept single-line, structural description moved into the prose section.
`hardening.api.spec.ts` now checks the descriptions as well.

### 3. `$conf['savedir']` as a path anchor (already fixed in Phase 4, documented here)

See `CLAUDE.md`. A relative config value that resolves differently depending on the
entry script — now `dirname($conf['datadir'])`.

### 4. ID collision when enqueuing

`io_lock()` gives up after 3 seconds and treats the lock as stale; under pathological
load, two callers could get the same ID, with the second overwriting the first. A load
test with 8 parallel saves produced clean, unique IDs — so the risk is low, but silent
loss of a submitted change contradicts the fail-closed principle. `enqueue()` now aborts
instead of overwriting.

## Knowingly left open

### A. Reviewer access doesn't check the target page's ACL

`remote.php::checkChangeAccess()` and the admin queue let any user from
`reviewer_groups` view **any** open change — even if they wouldn't be allowed to read
the target page itself. In the test environment this isn't noticeable (everyone can do
everything).

The correct fix would be an additional `auth_quickaclcheck($target) >= AUTH_READ`. Not
changed immediately, because it could silently break the review process in
installations with tight ACLs (changes would sit invisibly in the queue for the
responsible reviewer) — that needs a deliberate decision about what should happen then,
plus a restrictive test ACL. Belongs in Phase 9 (security review).

### B. `archive()` can abort partway through

Moves `.json` and `.content` one after another. If the second `rename()` fails, a
half-archived state is left behind. Practically unlikely (same filesystem, immediately
in sequence); the effect would be a change whose metadata is archived but whose content
still sits in `queue/`.

### C. `replaySave()` only sets `REMOTE_USER`, not `$USERINFO`

For changelog attribution and notifications, that's sufficient (verified: approvals
show up correctly as `kail`). A third-party plugin that looks at `$USERINFO['grps']`
in `COMMON_WIKIPAGE_SAVE`, however, would see the reviewer's groups during the
approval. No known case so far.

### D. `getContent()` reads via `io_readFile()` with `cleanText()`

Correct for wikitext (DokuWiki treats pages the same way). **For Phase 7 (media), it's
a trap**: binary data must not be read that way. When implementing the media queue, the
binary path must use `file_get_contents()`/`file_put_contents()`.

## Not flagged

- No path traversal risk with change IDs: all inputs are cast to `int`
  (`$INPUT->int('rqid')`, `(int) $id`).
- Output escaping in `admin.php`/`action/banner.php` consistently via `hsc()`; the diff
  is escaped by the core formatter.
- CSRF via `checkSecurityToken()` in `admin.php::handle()`, with a test.
- Self-approval ban also holds against a direct POST with a valid token, with a test.
- The re-entrancy flag is reset in `finally`, so it survives exceptions.

## Addendum (search over own drafts)

### 5. Approved pages don't land in the search index (severe)

Noticed while testing draft search: `helper/apply.php` called `saveWikiText()`
directly, which doesn't touch the search index. `ApiCore::savePage()` specifically
calls `idx_addPage()` for that purpose, and the browser path relies on the task runner
on the next page view — an approval has neither.

Effect: approved content was live, but **not findable via search**, until some
unrelated request happened to trigger the indexer. A serious shortcoming for a wiki,
and right at the point where findability matters most. Fixed by calling `idx_addPage()`
after approval, with a test (`gaps.martin.spec.ts`, "once approved, a draft leaves the
pending search and enters the wiki search").
