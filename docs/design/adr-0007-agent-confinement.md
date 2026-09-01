# ADR-0007: Confining a review-scoped account to a capability allowlist

## Status

Accepted (2026-09-01).

## Context

Up to and including phase 10 the plugin governed exactly one thing: the **write
path**. Every route by which DokuWiki can change content is intercepted and
either queued or refused, verified method by method in
[`write-path-audit.md`](write-path-audit.md) and guarded by
`lockdown.api.spec.ts`. That part holds and is not in question here.

The **read path was deliberately left untouched** ([ADR-0001](adr-0001-holdback-vs-hide.md),
[ADR-0004](adr-0004-visibility-of-open-changes.md)): no intervention in
rendering, cache, search index, or revision list. The reasoning was that the
write barrier is what protects the wiki, and that reopening the read path would
mean rebuilding exactly the machinery the hold-back model exists to avoid.

Operating the plugin with a real agent surfaced two requirements that the
current shape does not meet:

1. **Context size.** The `mcp` plugin exposes *every* registered remote method
   as a tool - core plus every installed plugin. On a production wiki that is
   53 tools, of which the agent needs a handful. The rest is noise that costs
   context on every single request and gives the model 47 ways to do the wrong
   thing.
2. **Confinement.** The operator's requirement is that the agent may perform
   harmless reads and harmless (i.e. queued) edits, and nothing else, on
   **every** path - MCP, remote API, and browser alike. Named explicitly: the
   agent must never be able to see a page's history.

Requirement 2 is not met today. A review-scoped account can reach:

| Route | Reachable today | Why |
|---|---|---|
| `core.getPageHistory`, `getRecentPageChanges`, `getMediaHistory` | yes | `lib/exe/jsonrpc.php`, no per-method gate |
| `do=revisions`, `do=diff`, `do=recent` | yes | listed in `ALLOWED_ACTS` |
| `doku.php?id=x&rev=<ts>` / `?at=<date>` | yes | the act is still `show`, so an act allowlist never sees it |
| `lib/exe/fetch.php?rev=`, `lib/exe/detail.php?rev=` | yes | old media revisions, same blind spot |
| `feed.php` | yes | RSS of recent changes |
| `lib/exe/openapi.php` | yes | discloses the full remote API surface |
| ajax `call=mediadiff`, `call=mediadetails` | yes | media revision comparison |

None of this is a bug against the plan - the plan said reads are open. It is a
mismatch between the plan and what the plugin is now required to do.

### Why this cannot be a per-method check

Kaos has **no hook that can intercept a remote API call**: there is no
`RPC_CALL` event, and `Api::call()` invokes the method directly
(`write-path-audit.md`, "What is explicitly not covered"). Nor is the core
access check per-method - `Api::ensureAccessIsAllowed()` asks only whether
*this user* may use the remote API at all:

```php
if (trim($conf['remoteuser']) === '') return;        // everyone may
if (auth_isMember($conf['remoteuser'], ...)) return; // this user may -> every method
```

So the enforcement point cannot be "the method". It has to be "the door".

### The one event that sees every door

`inc/init.php` fires `DOKUWIKI_INIT_DONE` at line 245, and `auth_setup()` runs
at line 238 - seven lines earlier. Every entry script (`doku.php`, `feed.php`,
each `lib/exe/*.php`, and any plugin's own endpoint) begins with
`require_once(DOKU_INC.'inc/init.php')`, so at that event:

- the acting user is already known, and
- **nothing script-specific has run yet** - in particular `doku.php:45`
  (`$REV = $INPUT->int('rev')`), `fetch.php:32` and `detail.php:15` are all
  still ahead.

That makes it the single place where both "which door" and "which revision"
can be decided once, for every path at once.

## Decision

A review-scoped account is confined by a **capability allowlist** enforced at
three gates that all consult one policy object.

```
helper/capability.php            <- single source of truth
  |- action/entrypoint.php       DOKUWIKI_INIT_DONE     -> entry script + rev/at + ajax call
  |- action/save.php             ACTION_ACT_PREPROCESS  -> do= actions (exists, trimmed)
  |- meta/ToolSchema.php         own MCP endpoint       -> tools/list and tools/call
```

### 1. Entry script allowlist (`DOKUWIKI_INIT_DONE`)

Deny by default. A review-scoped account may reach only:

| Script | Why |
|---|---|
| `doku.php` | the wiki UI; further narrowed by the act allowlist |
| `lib/exe/ajax.php` | edit locking, drafts, search suggestions, media list; narrowed by a call allowlist |
| `lib/exe/fetch.php` | media delivery |
| `lib/exe/detail.php` | media detail page |
| `lib/exe/mediamanager.php` | media upload UI |
| `lib/exe/css.php`, `js.php`, `jquery.php`, `manifest.php` | static assets |
| `lib/plugins/reviewqueue/mcp.php` | our own MCP endpoint |

Refused: `lib/exe/jsonrpc.php`, `lib/exe/xmlrpc.php` (the whole remote API),
`feed.php` (recent changes), `lib/exe/openapi.php` (API surface disclosure),
`lib/exe/opensearch.php`, `lib/exe/indexer.php`, `lib/exe/taskrunner.php`,
`install.php`.

A script added by a future DokuWiki release is refused rather than silently
reachable - the same fail-closed direction as the existing act allowlist.

### 2. No historical revisions, anywhere

On any allowed script, a review-scoped account requesting `rev` or `at` is
refused. One rule, checked before any script reads those parameters, covering
`doku.php`, `fetch.php`, `detail.php` and `mediamanager.php` together. This -
not the act allowlist - is what actually makes "the agent must never see the
history" true.

### 3. Act allowlist (`ACTION_ACT_PREPROCESS`), trimmed

Kept: `show`, `search`, `index`, `backlink`, `sitemap`, `media`, `login`,
`logout`, `denied`, `draftdel`, `locked`, `redirect`, `edit`, `preview`,
`save`, `cancel`, `conflict`, `draft`, `admin`.

Removed: `revisions`, `diff`, `recent` (history), `mediadetail` (media history
table), `subscribe` (change notifications by mail are history by another
route), `profile` (the agent must not change its own credentials), `check`,
`resendpwd`.

`search` and `index` stay by explicit operator decision: without them the agent
cannot find pages at all, and neither exposes revisions.

### 4. Ajax call allowlist

Allowed: `qsearch`, `suggestions`, `lock`, `draftdel`, `index`, `linkwiz`,
`medians`, `medialist`, `mediaupload`.
Refused: `mediadetails`, `mediadiff` (media revision history).

### 5. Own MCP endpoint, and the `mcp` plugin goes

`reviewqueue` ships its own MCP endpoint (`plugin/mcp.php` plus
`meta/McpServer.php`, `meta/ToolSchema.php`), adapted from
`splitbrain/dokuwiki-plugin-mcp` (GPL-2, same licence, attribution retained).
It advertises only allowlisted tools **and refuses a non-allowlisted
`tools/call`** - hiding a tool is not the same as blocking it.

`core.savePage` and `core.appendPage` are not on the list: the phase 10 range
write tools replace them, and they call `ApiCore::savePage()` *internally*
(`remote.php::writeEffectiveText()`), not through `Api::call()`, so removing
them from the remote surface does not affect them.

The `splitbrain/dokuwiki-plugin-mcp` plugin must be **uninstalled**. While it is
installed it serves the full 53-tool surface at its own URL, and gate 1 would
have to allowlist that URL for the agent to reach any MCP at all.

### 6. Schema emission fix, carried along

The generator must never emit `{"type":"array"}` without `items`. DokuWiki
core's `OpenAPIGenerator::typeToSchema()` adds `items` only when
`Type::getSubType()` is non-null, which it is only for a `foo[]` docblock; a
bare `array` yields an array schema with no `items`. Google's Gemini API rejects
the **entire** `GenerateContentRequest` over this, so a single such tool takes
the whole tool list down; Anthropic and OpenAI do not validate it. Since we now
own the generator, `ToolSchema` enforces the invariant and falls back to
`items: {"type":"string"}` where no element type can be derived.

## Rationale

- **One policy, three gates.** The rules are a property of the *account*, not
  of a transport. Defining them once and asking the same object at each door is
  what keeps the three from drifting apart - the same reason
  `helper/policy.php` is already the only place that answers "needs review?".
- **The door, not the method.** Kaos gives no per-method interception point, so
  a per-method allowlist would have to be re-implemented per transport and
  would silently miss any transport added later. Gating the door is both
  enforceable and complete.
- **`rev`/`at` before anything reads them.** Blocking `do=revisions` while
  leaving `?rev=` open would look like confinement and not be it. Checking at
  `DOKUWIKI_INIT_DONE` is the only point that is both after authentication and
  before every consumer of those parameters.
- **Deny by default at every gate**, consistent with the existing act
  allowlist: a future DokuWiki release or a newly installed plugin is refused,
  not silently admitted.
- **Owning the MCP endpoint** removes an upstream fork that would need
  re-applying on every update, puts the allowlist in this repo where this
  repo's tests can assert on it, and makes tool exposure default-deny - a
  `struct` plugin installed next year never appears.

## Consequences

- The plugin stops being purely additive to a DokuWiki install: it now
  **removes** capability from the accounts it governs. Accounts not subject to
  review are entirely unaffected, as before.
- `restrict_ui` is **not** introduced. Browser editing stays available to a
  review-scoped account because those edits are queued like any other, and the
  operator's requirement was "harmless edits on every path", not "no browser".
- A human placed under review loses history, recent changes and their profile
  page. That is a real narrowing compared to phase 10 and is the price of a
  single policy; it is a configuration decision (`review_users`) which accounts
  pay it.
- The tool list shrinks from 53 to roughly a dozen, which is the point of
  requirement 1 - substantially less context per request.
- Uninstalling the `mcp` plugin is a **required** deployment step, not a
  suggestion. `INSTALL.md` changes accordingly.
- JWT tokens do not expire (`inc/JWT.php` sets no `exp` claim and validates it
  only when present); they are revoked by regenerating the per-user token file.
  Confinement therefore cannot rely on credential lifetime, which is precisely
  why it is enforced per request at the gates.
- Enforcement is PHP-level: it runs inside DokuWiki, after `init.php`. A
  web-server rule would block earlier still, but is outside a plugin's reach
  and is left as optional operator hardening, documented in `usage.md`.
