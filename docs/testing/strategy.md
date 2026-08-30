# Test strategy

## Goal

Automated tests up to end-to-end level against a real DokuWiki 2024-02-06b
installation — not just PHPUnit tests against isolated classes. The core of
the requirement ("AI changes need review, `martin`'s changes don't") is
integration behavior spanning multiple DokuWiki subsystems (save pipeline,
remote API, ACL, rendering) and can only really be verified end-to-end.

## Environment

- **Container:** `test/env/Containerfile`, base `php:8.2-apache`, DokuWiki from the
  official tarball `dokuwiki-2024-02-06b.tgz` (not from a prebuilt
  Docker Hub image — more deterministic, allows clean pre-seeding of `data/`).
  Podman runs rootless in this environment.
- **Plugins in the image:** our `reviewqueue` (mounted/copied from `plugin/`) and
  `splitbrain/dokuwiki-plugin-mcp`, pinned to a fixed commit (no `master` tracking
  in the test setup, so test runs stay reproducible).
- **Seeding (`test/env/seed/`):**
  - `users.auth.php`: `admin` (DokuWiki admin), `martin`/`martin` (group `reviewer`),
    `kail`/`kail` (group — none special, review-required via `review_users=kail`).
  - `acl.auth.php`: `martin` and `kail` have write access to the test namespace.
  - `local.php`: `$conf['remote'] = 1`, `reviewqueue` configuration from `docs/design/spec.md`.
  - a few pre-seeded test pages for change/conflict scenarios.
  - API tokens for `martin` and `kail` are freshly generated via PHP CLI on every test
    run (`JWT::fromUser()`, see `docs/research/kaos-hooks.md`), not hardcoded.
- **Reproducibility:** `test/env/up.sh` copies a pristine `data/` snapshot from
  the image into a fresh volume before the container starts — every test run begins
  in the same state. `test/env/down.sh` cleans up.

## Tools

- **Playwright** (Node already present) for browser interaction — separate
  `storageState` files for `martin`, `kail` (where browser-based is relevant), and
  `admin`.
- **HTTP/JSON-RPC requests** (via Playwright's `request` fixture, no browser needed)
  for the remote API and MCP scenarios — real `mcp.php` calls with a bearer token,
  not mocked.
- PHPUnit against DokuWiki's `_test/` harness is **deliberately not part of the
  first pass** (see non-goals in `docs/roadmap.md`), but remains addable later.

## Scenario matrix

### Review-required path (`kail`)

1. Create a new page → page invisible/nonexistent to everyone, queue entry with
   `state=pending` exists → `martin` approves → page live, author in the
   version history is `kail`.
2. Edit an existing page → live content remains exactly the old one until approval.
3. Delete a page (empty text) → page remains until approval → after approval
   deleted.
4. Media upload → file not retrievable before approval → present after approval, with
   `kail` as uploader in the media history.
5. Rejection with reason → page unchanged, `kail` can query the rejection along
   with the reason via `plugin.reviewqueue.getStatus`.
6. Two pending changes on the same page (submitted by `kail` in short succession)
   → both appear individually in the queue, sequential approval works, the
   second merge already takes into account the state approved by the first.

### Non-review-required path (`martin`) — core project requirement

7. `martin` edits a page → immediately live, **no** entry in
   `data/reviewqueue/queue/` is created, standard DokuWiki behavior identical in
   every measurable respect to an installation without the plugin at all.
8. `martin` deletes a page, uploads media, uses a section edit → everything as
   usual, no redirect, no banner for himself.
9. Regression test: `review_users` is configured empty → `kail` also writes
   directly (proves that the policy check is really the sole decision point and
   there is no hidden special-casing for the name `kail`).

### Conflicts

10. `kail` submits a change based on revision X; in the meantime `martin` changes a
    *different* section of the same page → automatic Diff3 merge on approval,
    result contains both changes, `mergeResult=auto-merged`.
11. `kail` and `martin` change the same section with overlap → `state=conflicted`
    after the approval attempt, the review editor shows conflict markers, manual
    resolution by `martin` leads to `state=approved` with the manually cleaned-up
    text.

### MCP end-to-end

12. Real MCP handshake (`initialize` → `tools/list` → `tools/call`) against
    `lib/plugins/mcp/mcp.php` with a bearer token for `kail`. `tools/list` contains
    `plugin_reviewqueue_listMyPending`, among others; `core_savePage` returns an
    `isError` result with the review ID in the text.
13. Same flow with a token for `martin` → `core_savePage` returns success, page is
    immediately live.

### Reviewer UX

18. The admin queue shows Diff and Preview as tabs (CSS radio-button hack, no
    JS) per pending page change; only one panel is visible at a time, Diff is
    the default. Preview is rendered read-only via `p_render()` with `$ID`
    temporarily set to the change's own target (not the admin page) -
    verified via a relative link that only resolves correctly under that
    context. Switching tabs does not change the change's state.
19. A diff line too wide for the page (a single unbroken long word) gets its
    own horizontal scrollbar (`.reviewqueue-scroll`, `overflow-x: auto`)
    instead of overflowing the page with no way to reach the rest of it.

### Range-addressed access (Phase 10, ADR-0005)

20. `getPageOutline` lists every heading in document order (including ones
    beyond `$conf['maxseclevel']`), with byte ranges in core's own
    `rawWikiSlices()` format; `getSection` resolves by index, range, `#hid`,
    or title (with/without nested children) and refuses an ambiguous title
    by name; `getLines`/`findInPage` work the same way for pages without
    useful headings. A `====== fake ======` line inside a `<code>` block
    must not be treated as a section boundary. A range from `getPageOutline`
    fed into the browser's own `do=edit&range=...` link loads byte-identical
    text to what `getSection` returned for it.
21. `searchWithContext` finds a match in a live page *and* in the caller's
    own unreviewed draft in one call (unlike `core.searchPages`, which never
    sees the draft — ADR-0004), reporting which is which; `scope=live`
    narrows to only the former.

### Author-side change lifecycle (Phase 10, ADR-0006)

22. The central regression this phase exists to fix: `kail` uses a range
    write tool (e.g. `replaceSection`) on a page for the first time →
    `status=queued`, a new pending change id. A second range write on the
    *same* page → `status=updated`, the *same* change id — `listMyPending`
    still shows exactly one entry for that page. Approving it publishes both
    edits together. A stale `$expect` (section/line hash) is refused;
    `replaceLines` refuses a missing `$expect` outright; `replaceText`
    refuses an ambiguous match unless `$all` is set; a range write that
    would empty the page is refused (that goes through `core.savePage`
    instead); a caller not subject to review (`martin`) gets `status=live`
    immediately, no queue entry.
23. `updatePendingChange` replaces the full text of the author's own open
    change in place (same id, `updateCount` incremented, admin queue shows
    an "updated N× " notice); a fail-closed write failure here leaves the
    previous content intact and reported accurately, same guarantee as
    scenario 17.
24. `withdrawPendingChange`: the author cancels their own still-`pending`
    change → `state=withdrawn`, removed from `listMyPending` and the admin
    queue, but still visible via `getStatus` (distinct from `rejected`: no
    `reviewer` is recorded). Only the author may withdraw it — not even a
    reviewer, via `checkOwnChangeAccess`'s stricter rule than
    `checkChangeAccess`. A `conflicted` or already-decided change cannot be
    withdrawn.
25. The approve-time race Phase 10 makes possible (a pending change's
    content is no longer immutable, ADR-0006): a reviewer opens the admin
    page for change #N, the author continues #N (a range write or
    `updatePendingChange`) before Approve is clicked, and the reviewer's
    stale submission is refused rather than publishing text they never saw
    - approving again with the current page's hash then succeeds normally.
    `getPageOutline`'s `hashWithChildren` (distinct from the section's own
    `hash`) is what makes `replaceSection`/`deleteSection`'s `$expect`
    actually usable for a heading that has nested subsections in the first
    place - both are exercised in `range-write.api.spec.ts`.

### Security

14. `kail` tries to approve their own pending change via
    `do=reviewqueue_approve` → rejected (self-approval ban).
15. A user without `reviewer_groups` membership gets neither access to the
    admin queue page nor can they trigger `do=reviewqueue_approve`/`_reject`.
16. An approve/reject request without a valid `checkSecurityToken()` value is
    rejected (CSRF protection).
17. Fail-closed: the store directory is made non-writable for the duration of a
    test → `kail`'s save is rejected with an error, **not** silently switched
    live.

## Execution

```bash
test/env/up.sh                 # build/start container, fresh data/ state, generate tokens
cd test/e2e && npm install     # once
npx playwright install chromium
npx playwright test            # entire matrix
../env/down.sh
```

`up.sh` generates `test/e2e/.auth/tokens.json` (bearer tokens for `martin`/`kail`) and is
a prerequisite for the API/MCP tests. The login storage states for the browser tests
are generated by Playwright itself via the `setup` project (`tests/auth.setup.ts`,
standard Playwright auth pattern via `dependencies` in `playwright.config.ts`) —
no manual step needed.

Port configurable via `REVIEWQUEUE_TEST_PORT` (default `8080`), must be set
consistently for `up.sh` and `playwright.config.ts`.

**Known limitation in this sandbox:** `playwright install --with-deps` fails
without `sudo`; `playwright install chromium` (without `--with-deps`) is sufficient here,
because the required OS libraries are already present. In a fresh CI environment, use
`--with-deps` (with root privileges) if needed.

CI readiness (a later step, not part of the first phases): the same commands in
a pipeline, `test/env/up.sh` already provides a clear exit code for
startup problems (30s poll timeout on `doku.php`).
