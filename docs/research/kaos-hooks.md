# Verified DokuWiki Kaos hook points (release 2024-02-06b)

All findings verified against the git tag `release-2024-02-06b` in the official repo
<https://github.com/splitbrain/dokuwiki> (commit `aad0b49e48318eb343208b2c865291716c1819b3`).
A local clone is kept for reference purposes (not part of the repo) under `scratchpad/kaos/`.

## Saving pages: `COMMON_WIKIPAGE_SAVE`

The central function `saveWikiText()` in `inc/common.php:1296` delegates to
`PageFile::saveWikiText()` in `inc/File/PageFile.php`. There (lines ~79–139):

```php
public function saveWikiText($text, $summary, $minor = false)
{
    // ... assemble $data: newContent, changeType, summary, minor, ...
    $data['page'] = $this; // event handlers get access to the PageFile instance
    $event = new Event('COMMON_WIKIPAGE_SAVE', $data);
    if (!$event->advise_before()) return;   // <-- PREVENTABLE
    if (!$data['contentChanged']) return;
    // ... writes io_writeWikiPage(), creates attic copy, changelog entry
    $event->advise_after();
}
```

**This is the central interception point.** `$event->preventDefault()` in the BEFORE
handler reliably prevents anything from being written — regardless of whether the save
was triggered via the browser UI, XML-RPC, JSON-RPC, the MCP plugin, or a CLI script,
because all of these paths ultimately call `saveWikiText()`.

Important for section-edit correctness: `$data['newContent']` already contains the
**complete** new page text (section merging has already happened), not just the
changed section. The queue therefore needs no special handling for section edits.

`$data['changeType']` distinguishes `DOKU_CHANGE_TYPE_CREATE` / `_EDIT` / `_MINOR_EDIT` /
`_DELETE` / `_REVERT` — deletions go through the same hook (empty `newContent`).

## Remote API path: `ApiCore::savePage()`

`inc/Remote/ApiCore.php:660` (`savePage`) and `:723` (`appendPage`, which internally
calls `savePage`) simply call the global function `saveWikiText($page, $TEXT, $summary,
$isminor)` at the end (line 696) and then hard-`return true;` afterward (line 702).

**Consequence:** in Kaos there is no event that can be used to alter the
return value of `core.savePage` after the fact — `Api::call()` in `inc/Remote/Api.php`
calls `$methods[$method]($args)` directly and passes the result straight through, without
an intervening, preventable event. The only way to inform the caller (the AI) that
the save is *not* live is an **exception** thrown from the
`COMMON_WIKIPAGE_SAVE` BEFORE handler — it propagates through `Api::call()` up to the
JSON-RPC server and is delivered to the client as an error (see ADR-0003).

## Media uploads: `MEDIA_UPLOAD_FINISH`

`inc/media.php`, function `media_upload_finish()` (~lines 419–501):

```php
// Event data:
// $data[0] fn_tmp, $data[1] fn, $data[2] id, $data[3] imime,
// $data[4] overwrite, $data[5] move (callback name for move/copy)
return Event::createAndTrigger('MEDIA_UPLOAD_FINISH', $data, '_media_upload_action', true);
```

`Event::createAndTrigger()` with `$canPreventDefault = true` (last argument) — also
preventable. For the queue, the temporary file (`$data[0]`) needs to be copied before
the request is discarded, since DokuWiki cleans it up afterward.

## Diff / 3-way merge: `Diff3`

`inc/DifferenceEngine.php:1319` — `class Diff3 extends Diff`. Takes three text arrays
(base, "mine", "yours") and returns conflict blocks or the merged text.
Rendering via `TableDiffFormatter` (line 1120) and `InlineDiffFormatter` (line 1234)
in the same file — the same classes DokuWiki's own revision/diff view
(`inc/Ui/Diff.php`, `inc/Ui/PageDiff.php`) uses.

## API token / authentication

- `dokuwiki\JWT` (`inc/JWT.php`): `JWT::fromUser($user)` creates a token object,
  `->getToken()` returns the string. Reachable in the user profile
  (`inc/Ui/UserProfile.php:172`) via `do=authtoken` — meaning it **can also be generated
  via a PHP CLI script**, which is important for automated seeding in the test container.
- `inc/auth.php:199` (`auth_tokenlogin()`): accepts `Authorization: Bearer <token>`
  (and, per the MCP plugin code, also an `X-DOKUWIKI-TOKEN` header).
- `conf/dokuwiki.php:68-70`: `$conf['remote']` must be `1`, `$conf['remoteuser']` controls
  who is allowed to use the remote API at all (empty = everyone).

## `mcp` plugin (Andreas Gohr, `splitbrain/dokuwiki-plugin-mcp`)

- `mcp.php` (entry point) instantiates `McpServer` (extends `JsonRpcServer`) and calls
  `->serve()`. Errors are translated into an MCP-conformant error response via
  `returnError()`.
- `McpServer::mcpToolsList()` returns `SchemaGenerator::getTools()` — automatically
  generates an MCP tool **from every registered remote API method** (core plus all
  `RemotePlugin` implementations), including a JSON schema from
  `OpenAPIGenerator::getMethodArguments()`. This means that as soon as our `remote.php`
  (phase 8) implements `RemotePlugin`, the `plugin.reviewqueue.*` methods
  automatically appear as MCP tools — **without** any change to the `mcp` plugin.
- Dependencies of `McpServer`/`SchemaGenerator` checked against Kaos: `ApiCall`,
  `ApiCall::getCategory()`, `::getSummary()`, `::getArgs()`, `JsonRpcServer`,
  `AccessDeniedException`, `RemoteException`, `OpenAPIGenerator::getMethodArguments()`
  — all present in Kaos's `inc/Remote/` resp. `inc/Remote/OpenApiDoc/`. The plugin code
  itself doesn't appear to require any Kaos-incompatible language features. **Still needs
  to be actually verified in the container in phase 8** — static checking is no substitute
  for a live test run.
- The mcp plugin's `plugin.info.txt` is dated `2026-08-04` (newer than Kaos itself)
  — one more reason to actually test it against Kaos in phase 8 instead of just trusting it.

## Other findings relevant for later

- `inc/Extension/RemotePlugin.php`, `AdminPlugin.php` — base interfaces for our plugin's
  `remote.php` resp. `admin.php`.
- `inc/Ui/PageConflict.php`, `inc/Ui/PageDraft.php` — DokuWiki's own UI building blocks
  for conflict resp. draft display, possibly usable as a template for our review editor
  view for `conflicted` status.
- `_test/phpunit.xml` automatically picks up `../lib/plugins/*/_test/` — should PHPUnit
  be added later (see non-goal in the roadmap), our plugin only needs an
  `_test/` directory for that, no further configuration.

## Bug in Kaos: `Diff3::mergedOutput()` is unusable

Found while implementing the 3-way merge (phase 6). `Diff3::mergedOutput()`
(`inc/DifferenceEngine.php:1357`) accesses `$edit->final1` / `$edit->final2`
directly in the conflict branch, but these properties are declared `protected`
on `_Diff3_Op` (line 1458 ff.). As soon as an actual conflict
occurs, the call ends in a fatal error:

```
Error: Cannot access protected property _Diff3_Op::$final1
```

This doesn't happen for conflict-free merges, because only the public
`merged()` is used there — the automerge path does work.

The core never calls `mergedOutput()` anywhere; `Diff3` isn't even listed in the
autoload map (`inc/load.php:45-47` only lists `Diff`, `UnifiedDiffFormatter`,
and `TableDiffFormatter`), which is why the class doesn't get loaded at all
without an explicit `require_once(DOKU_INC . 'inc/DifferenceEngine.php')`.
That's why the defect was never noticed upstream.

Workaround in the plugin (`helper/merge.php`): iterate the edit list itself
(`$diff3->_edits`, `isConflict()` and `merged()` are publicly accessible)
and read both conflict sides via reflection. This keeps working even if a
later DokuWiki version fixes the visibility.
