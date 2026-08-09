# ADR-0003: AI feedback via RemoteException instead of silent success

## Status

Accepted (2026-08-08).

## Context

`ApiCore::savePage()` unconditionally returns `true` in Kaos
(`inc/Remote/ApiCore.php:702`, see [`docs/research/kaos-hooks.md`](../research/kaos-hooks.md)).
There is no event that lets this return value be changed afterward. If our
`COMMON_WIKIPAGE_SAVE` BEFORE handler redirects the save into the queue and then calls
`preventDefault()` without comment, `savePage()` still returns `true` — the AI (and any
other remote API caller) would believe the change is live.

## Decision

The BEFORE handler throws a `\dokuwiki\Remote\RemoteException` in the remote/CLI context
(recognizable by the call not going through the regular browser action), with a
descriptive message and the review ID, e.g.:

> "Change queued for review as #42 (approval required for user 'kail'). The page was
> NOT modified. Use plugin.reviewqueue.getStatus to check its state."

In addition, `remote.php` provides its own methods (`listMyPending`, `getStatus`,
`getPendingChange`), which automatically appear as MCP tools (see
[`docs/research/kaos-hooks.md`](../research/kaos-hooks.md), section on the `mcp` plugin),
so that the AI can actively query the status and, if applicable, a rejection reason.

In the **browser UI path** (`ACTION_ACT_PREPROCESS`), no error is thrown; instead the
user is redirected to a dedicated confirmation page ("Your change has been submitted for
review") — a human at the browser interface should not see a raw RPC exception.

## Rationale

- **Fail-loud instead of fail-silent toward the AI.** An agent that assumes a successful
  save could falsely report "done" to the user. A hard exception is the most reliable
  signal form for an agent — it cannot be accidentally ignored the way a return value
  that happens to also be `true` on success could be.
- **No breaking of remote API contracts for all other methods.** We change nothing in
  `ApiCore` itself (not patchable, not our code) — the exception comes cleanly from our
  own event handler.
- **Two separate UX paths, because the target audiences differ.** Humans on the web UI
  expect a comprehensible page, not a JSON-RPC error message; remote clients (the AI)
  expect a structured error, not an HTML page.

## Consequences

- The handler must reliably distinguish whether the current request goes through the
  browser action or through the remote API (e.g. `$INPUT->server->str('REQUEST_METHOD')`
  combined with the DokuWiki action context, or a flag that `mcp.php`/`XmlRpcServer`/
  `JsonRpcServer` set at bootstrap — a detail decision for Phase 4 based on the actual
  request context that Kaos provides).
- `plugin.reviewqueue.*` methods must be designed so that they get meaningful
  descriptions from `SchemaGenerator::getTools()` (short `@param`/`@return` docblocks, as
  is customary with `ApiCore` methods), so they are understandable as MCP tools.
- Tested in scenario 12/13 of the test matrix (`docs/testing/strategy.md`): a real MCP
  handshake, `core_savePage` returns an error with a review ID for `kail`, while for
  `martin` the call goes through normally.
