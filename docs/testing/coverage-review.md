# Test coverage review (2026-08-08)

Comparison of the existing Playwright tests against the scenario matrix in
[`strategy.md`](strategy.md). Result of the review the user requested.

## Findings

| # | Scenario | Status before review | Action |
|---|---|---|---|
| 1 | New page → approval → live | partial — approval tested, **author attribution to `kail` not tested** | Test added |
| 2 | Edit page → live content unchanged | ✅ | — |
| 3 | Deletion → approval → deleted | ❌ **not tested at all** | Test added |
| 4 | Media upload | ❌ not implemented (phase 7) | open, deliberately |
| 5 | Rejection with reason, retrievable | ✅ | — |
| 6 | Two changes, sequential approval | partial — warning tested, **second approval not** | Test added |
| 7 | `martin` edits → immediately live | ✅ | — |
| 8 | `martin` deletes / section edit | ❌ not tested | Test added (deletion) |
| 9 | `review_users` empty → `kail` also writes directly | ❌ **not tested at all** | Test added |
| 10 | Automerge of disjoint changes | ❌ not implemented (phase 6) | open, deliberately |
| 11 | Conflict → `conflicted` | ❌ **implemented but untested** | Test added |
| 12 | MCP `tools/list` contains `plugin_reviewqueue_*` | ❌ only checked manually | Test added |
| 13 | `martin` via MCP → goes through | ❌ not tested | Test added |
| 14 | Self-approval forbidden | ❌ **not tested at all** (security!) | Test added |
| 15 | Non-reviewer has no access | ✅ | — |
| 16 | CSRF | ✅ | — |
| 17 | Fail-closed | ❌ **not tested at all**, even though `CLAUDE.md` declares it "mandatory, not optional" | Test added |

## Assessment

The gaps systematically occurred exactly where I had verified **manually** while
building and seen the result (author attribution, MCP tool list, deletion) — the
confirmation on the terminal masked the missing test. Three of them were
security-relevant and particularly uncomfortable:

- **Self-approval (14)** was untested, even though it's the core safeguard against
  an agent waving through its own changes.
- **Fail-closed (17)** was untested, even though it's the project's explicitly
  stated guiding principle.
- **`conflicted` (11)** is already executed code (`helper/apply.php`
  compares `baseHash`) — untested but active code is worse than
  code not yet written.

`review_users` empty (9) is the test that substantiates the actual project
requirement — that the review obligation depends purely on configuration and
there is no hidden special-casing of the name `kail`.

## Addendum after completing phases 6, 7, and 9

The scenarios still open at the time of the first review have since been
implemented **and** tested:

| # | Scenario | Test |
|---|---|---|
| 4 | Media upload → approval | `media.martin.spec.ts` — including a sha256 comparison of the delivered file against the original, so that binary data corruption doesn't silently slip through |
| 8 | `martin` uploads media | `media.martin.spec.ts` |
| 10 | Automerge of disjoint changes | `merge.martin.spec.ts` |
| 11 | Conflict → manual resolution | `merge.martin.spec.ts` — including rejection of text that still contains conflict markers |
| — | ACL isolation (finding A from the code review) | `acl.martin.spec.ts` — with a real, restrictive namespace instead of the permissive test ACL |

Two additional tests were sharpened during the final review because they
could pass falsely:

- "kail doesn't see the admin queue" would also have passed with an **empty**
  queue. The test now creates a change with distinct marker text first.
- The CSRF test only checked that the page was unchanged — that would also
  hold for an approve that failed for entirely different reasons. It now
  additionally checks that the change is still `pending`.

## Not fixed (deliberately)
- Scenario 8 "section edit" is only indirectly covered: per
  [`kaos-hooks.md`](../research/kaos-hooks.md), `COMMON_WIKIPAGE_SAVE`
  always receives the complete page text, so section edits are not a
  special case in the plugin code. A dedicated test would be pure ceremony.
