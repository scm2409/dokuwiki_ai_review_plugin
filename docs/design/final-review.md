# Final review (2026-08-09)

Complete review after completion of all phases, as requested by the user. Complements
[`code-review.md`](code-review.md) (interim status) and
[`../testing/coverage-review.md`](../testing/coverage-review.md).

## Systematic checks

| Check | Result |
|---|---|
| Language keys: used vs. defined, `en` vs. `de` | fully congruent, no orphans |
| Config keys: `default.php` vs. `metadata.php` vs. `settings.php` vs. code | fully congruent |
| PHP syntax of all files against PHP 8.2 | error-free |
| Output escaping | consistently `hsc()`; diff and forms via core classes |
| Inputs from requests | IDs consistently cast to `int`, no path traversal possible |
| Test suite | 46 tests, deterministic, against a real installation |

## Fixed during the final review

### 1. Banner ignored changes in conflict

`action/banner.php` filtered on `state = pending`. A change in the `conflicted` state —
i.e. exactly the one that needs attention — no longer produced a notice on the affected
page. Both states are now counted.

### 2. Banner only checked the reviewer role, not the page permissions

After closing the ACL gap in Phase 9, the banner was the last place still using
`isReviewer()` instead of `mayReviewTarget()`. Practically uncritical (the banner only
appears on a page the viewer is reading anyway), but inconsistent — brought in line.

### 3. `searchMyPending` checked media entries with page permissions

For entries of type `media`, `AUTH_READ` was checked on the media ID instead of
`AUTH_UPLOAD`. Brought in line with `mayReviewTarget()`.

### 4. Orphaned queue entries on a failed upload

If the file copy in `action/media.php` failed *after* the record was created, an entry
without payload data was left behind, which would inevitably fail on approval. It is
now removed via `store::discard()` — deliberately deleted rather than archived, because
there is no human decision that needs to be recorded.

### 5. Two tests could pass as false positives

- "kail doesn't see the admin queue" would also have passed against an empty queue and
  thus proven nothing. The test now first creates a change with a unique marker text and
  additionally checks for its absence.
- The CSRF test only checked that the target page stayed unchanged — that would also
  have been true for an approve that failed for some other reason. It now additionally
  checks that the change is still `pending`.

## Real bugs found over the course of the whole project

A reminder, because they show where the pitfalls in this environment lie — all fixed
and covered by tests:

1. **Orphaned page lock** after an intercepted remote save: `martin` was locked out of
   exactly the pages the agent was working on.
2. **Approved pages didn't land in the search index** — live, but unfindable.
3. **`Diff3::mergedOutput()` is broken in Kaos** (accesses `protected` properties);
   additionally, `Diff3` is missing from DokuWiki's autoload map.
4. **Base text from the attic is unreliable** (second-level granularity of revisions) —
   now stored alongside instead.
5. **`$conf['savedir']` is a relative path** that resolves differently depending on the
   entry script.
6. **Multi-line docblock tags** break the generated MCP tool descriptions.
7. **ACL bypass via the queue** by reviewers without read access.

## Knowingly left open

- **`replaySave()` sets `REMOTE_USER`, not `$USERINFO`.** Verified correct for
  attribution, changelog, and notifications. A third-party plugin that looks at
  `$USERINFO['grps']` in `COMMON_WIKIPAGE_SAVE` would see the reviewer's groups during
  the approval. No known case; a fix would mean swapping the entire user context, which
  creates new risks.
- **No PHPUnit.** The logic is almost entirely integration behavior against DokuWiki
  internals; the end-to-end tests cover it more realistically. For pure units
  (`helper/merge.php`) unit tests would make sense should the merge logic grow —
  `_test/` is auto-discovered by DokuWiki's harness.
- **No handling of page renames** by the `move` plugin. If a page is renamed while a
  change is open for it, the change still targets the old ID and is treated as a new
  creation on approval. The clean fix would be a hook on `PLUGIN_MOVE_PAGE_RENAME`; but
  that assumes the `move` plugin is even installed, and was never part of the scope.
- **Compatibility verified only with 2024-02-06b.** The hooks used exist unchanged in
  2025-05-14 and 2026-07-14, but the `Diff3` defect might be fixed there — the
  workaround is prepared for that, but a test run against a newer version is still
  outstanding.
