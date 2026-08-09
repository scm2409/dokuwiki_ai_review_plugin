# Project context: dokuwiki-plugin-reviewqueue

## What's being built here

A DokuWiki plugin (target version: **Release 2024-02-06b "Kaos"**, fixed, no
compatibility target for newer releases) that holds back saves from certain configured
users/groups instead of publishing them live, putting them into a review queue instead.
A reviewer approves/rejects/edits before release. The motivating use case is an AI agent
with its own DokuWiki user (`kail`) via MCP, but the plugin itself is user-based and not
AI-specific.

The full, approved plan is at
`/home/martin/.claude/plans/ber-neues-projekt-starten-atomic-feigenbaum.md`.
**Check there first when anything is unclear**, before re-opening architecture questions.

## Fixed decisions (don't re-discuss unless the user brings it up)

- **Hold-back queue**, not "save-then-hide". No intervention in rendering/cache/search.
- **File-based store** under `data/reviewqueue/`, no sqlite plugin dependency.
- Scope: pages (create/change/delete) + media uploads. No namespace filter.
- AI feedback via `RemoteException` + dedicated `plugin.reviewqueue.*` remote methods
  (automatically exposed as tools by the `mcp` plugin).
- Review UI: admin page (queue+diff) + banner on the affected page. No emails,
  no syntax block (deliberately deferred).
- Conflicts: 3-way merge via `Diff3` (`inc/DifferenceEngine.php`); on a real conflict,
  status `conflicted` + manual resolution.
- **Guiding principle: fail-closed.** If the queue can't be written cleanly, the save is
  rejected — never let it through. A test for this is mandatory, not optional.
- Test users (already fixed by the user): `martin`/`martin` (regular reviewer),
  `kail`/`kail` (AI/MCP user, subject to review).
- E2E stack: Playwright against a Podman container running exactly DokuWiki 2024-02-06b.

## Verified Kaos facts (don't re-research)

- `COMMON_WIKIPAGE_SAVE` BEFORE is preventable — `inc/File/PageFile.php:139`. Covers
  browser UI, XML-RPC, JSON-RPC, MCP, CLI. Event data contains the full new page text.
- `MEDIA_UPLOAD_FINISH` is preventable — `inc/media.php:501`.
- `ApiCore::savePage()` always returns `true`; no event exists in Kaos to redirect the
  return value → feedback to the caller only via `RemoteException`.
- API tokens: `dokuwiki\JWT::fromUser($user)->getToken()`, auth via
  `Authorization: Bearer <token>` — `inc/auth.php:199`.
- MCP plugin: [`splitbrain/dokuwiki-plugin-mcp`](https://github.com/splitbrain/dokuwiki-plugin-mcp),
  exposes the entire remote API automatically as MCP tools via `SchemaGenerator`
  (an `OpenAPIGenerator` subclass). Must actually be verified against Kaos in Phase 8.
- Reference clone of the `approve` plugin (a pattern for plugin structure, NOT for the
  review model) lived under `scratchpad/approve` — created during research, not part of
  the repo.
- **Trap:** DokuWiki deliberately leaves `$conf['savedir']` as the raw, often relative
  config value (`'./data'`). Core only resolves the *derived* paths (`$conf['datadir']`,
  `$conf['lockdir']`, …) against `DOKU_INC` via `init_path()`/`fullpath()` in
  `inc/init.php`. Custom code that uses `$conf['savedir']` directly for paths writes to
  different, sometimes wrong, locations depending on the calling entry script (different
  `cwd`: `doku.php` vs. `lib/exe/jsonrpc.php` vs. `lib/plugins/mcp/mcp.php`). Fix/convention
  in this plugin: use `dirname($conf['datadir'])` instead of `$conf['savedir']`
  (see `plugin/helper/store.php::dataDir()`).

## How we work on this project

- Markdown in the repo is the single source of truth: research in `docs/research/`,
  decisions as ADRs in `docs/design/`, spec in `docs/design/spec.md`, test strategy in
  `docs/testing/strategy.md`, phase status in `docs/roadmap.md`.
- **Read `docs/roadmap.md` before starting a new session** — that's where actual
  progress lives, not just the plan.
- One branch per phase, commit at the end of each phase, `/code-review` before every
  merge.
- Use DokuWiki idioms instead of custom infrastructure: `io_saveFile()`, `io_lock()`,
  `io_unlock()`, `Event`/`Doku_Event`, `AdminPlugin`, `RemotePlugin`, `Diff3`,
  `TableDiffFormatter`. Don't reinvent what core already provides.
- Language: **everything written into this repository — code, comments, commit
  messages, and all documentation under `docs/`, `README.md`, etc. — is English,
  no exceptions.** This applies regardless of what language we're chatting in;
  chat with the user may switch to German at times, but written artifacts in the repo
  never do. The one deliberate exception is `plugin/lang/de/`, DokuWiki's own German
  UI translation for end users — keep `lang/de/` and `lang/en/` both fully maintained.

## Test environment

DokuWiki 2024-02-06b runs in a Podman container (`test/env/`), built from the official
tarball rather than a Docker Hub image, for deterministic seeding. `test/env/up.sh`
produces a fresh `data/` state on every run. Playwright tests in `test/e2e/` use separate
storage states for `martin` and `kail` and cover both the browser path and the
JSON-RPC/MCP path. The full scenario list (17 scenarios) is in `docs/testing/strategy.md`.
