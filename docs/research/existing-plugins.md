# Survey: existing DokuWiki review/approval plugins

Research as of: 2026-08-08.

## Summary

DokuWiki deliberately has **no** built-in moderation workflow — that contradicts the
wiki principle ("quick" editing). All existing solutions are third-party plugins,
and all follow the same basic pattern: **the save goes through immediately and creates a
real revision; the plugin only controls which revision is shown to which reader.**
None of them holds back the save itself, and none scales to individual users instead of
namespaces/permissions. That's the gap this project closes.

## `approve` (Szymon Olewniczak, formerly Michael Große)

- Repo: <https://github.com/gkrid/dokuwiki-plugin-approve>, doku page
  <https://www.dokuwiki.org/plugin:approve>. Actively maintained (`plugin.info.txt` dated
  2026-02-19, 133 commits, 23 open issues).
- **Model:** Save immediately creates a revision. Via `hide_drafts_for_viewers`, users
  without approval rights only see the last *approved* revision; editors always see
  the latest. Approval = click on a banner link, permission via
  `strict_approver` + its own ACL concept (`helper/acl.php`).
- **Scope:** `apr_namespaces` / `no_apr_namespaces` — namespace-based, not
  user-based. All authors in an affected namespace are treated the same.
- **Architecture:** classic plugin layout — `action/` (including `approve.php`, `move.php`,
  `notification.php`, `viewmode.php`), `admin.php`, `helper/db.php`
  (`dokuwiki\plugin\sqlite\SQLiteDB` — **dependency on the `sqlite` plugin**), `remote.php`,
  `syntax/table.php` for an overview page.
- **Hooks** (verified in source code):
  `ACTION_ACT_PREPROCESS` BEFORE (multiple — display/approve/ready handling),
  `COMMON_WIKIPAGE_SAVE` AFTER, `FORM_REVISIONS_OUTPUT` BEFORE,
  `HTML_SHOWREV_OUTPUT` BEFORE, `MENU_ITEMS_ASSEMBLY` AFTER (×2),
  `PARSER_CACHE_USE` BEFORE, `PLUGIN_MOVE_PAGE_RENAME` AFTER,
  `PLUGIN_NOTIFICATION_*` (notification integration),
  `PLUGIN_SQLITE_DATABASE_UPGRADE` AFTER, `TPL_ACT_RENDER` AFTER + BEFORE.
- **Why it doesn't fit our case:** the unreviewed text is already a real
  revision in the live directory, visible to anyone with editor rights or a targeted
  revision request. That's too weak for "reviewed text vs. AI draft" — we want
  the draft to exist *nowhere* as page content before it's approved. The
  scope is also namespace- rather than user-bound, and it adds a `sqlite`
  dependency that we want to avoid (decision see ADR-0002).
- **What we take from it:** basic plugin structure, banner pattern via `TPL_ACT_RENDER`,
  menu item integration via `MENU_ITEMS_ASSEMBLY`, `remote.php`/`admin.php` structure.
  A reference clone lives (not part of the repo) under `scratchpad/approve/`.

## `publish` (originally Jarrod Lowe, now CosmoCode)

- Repo: <https://github.com/cosmocode/dokuwiki-plugin-publish>, doku page
  <https://www.dokuwiki.org/plugin:publish>. 294 commits, 59 open issues, 21 open PRs.
- **Model:** identical to `approve` at its core — every page has a "published revision"
  shown to readers, while editors can keep writing freely. Approval
  by a user with `AUTH_DELETE` or `AUTH_ADMIN` via a banner link
  (green = currently approved, red = unreviewed version).
- **Scope:** permission-based (whoever has `AUTH_DELETE`/`AUTH_ADMIN` on the page may
  approve) — also not tailored to "this one author needs review".
- **Why it doesn't fit:** same structural limitation as `approve` — save immediately
  becomes a revision, no holding back of content.

## `structpublish` (CosmoCode)

- Repo: <https://github.com/cosmocode/dokuwiki-plugin-structpublish>.
- **Model:** publish workflow built on top of the `struct` plugin, with a
  `publish_needs_approve` option (see PR #27) that only unlocks the "Publish"
  button after approval.
- **Why it doesn't fit:** additional heavy dependency (`struct`), disproportionately
  complex for our use case (plain page content, no structured data part), and,
  as above, not user-based.

## Conclusion

None of the three plugins holds back the save, and none scales to "review required for
certain users, everyone else unaffected". The decision for a new build with a
hold-back queue is documented in [`docs/design/adr-0001-holdback-vs-hide.md`](../design/adr-0001-holdback-vs-hide.md).
