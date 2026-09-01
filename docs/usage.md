# Operations

Target version: DokuWiki **2024-02-06b "Kaos"**. Newer releases are not tested.

Setup — plugin, accounts, configuration, MCP connection — is covered in
[`../INSTALL.md`](../INSTALL.md). This document describes ongoing
operation afterward.

## Day to day

**As an author subject to review** (typically the AI agent): saving
works as usual, but results in a message "submitted for review
as change #N". The page remains unchanged until someone approves it.

**As a reviewer:** under *Site Tools → Review Queue* you'll find all pending
changes with a Diff/Preview tab pair, plus approve and reject buttons. Diff is
the default tab; Preview renders the proposed page as it would actually look
once published — useful when the diff alone doesn't tell you enough about
layout or formatting — without approving anything; switching tabs has no
effect on the change's state. A long diff line gets its own horizontal
scrollbar rather than running off the page. On affected pages a notice banner
also appears. A rejection should include a reason — the text is retrievable
by the author via the API and, for an agent, is the only chance to do better
on the next attempt.

**All other users** notice nothing of the plugin.

An author subject to review can also continue their own still-pending change instead of
submitting a new one every time (via the range write tools or `updatePendingChange`), and
can withdraw it themselves if they change their mind (`withdrawPendingChange`) — see
`docs/design/adr-0006-author-change-lifecycle.md`. A change that was continued shows an
"updated N× " notice in the admin queue so a reviewer knows the text has moved on since
they last looked; a withdrawn change disappears from the queue but stays visible in its
archived state via the API, distinct from a rejection (no reviewer is ever recorded on it).

## Conflicts

If the page has changed since a change was submitted, approval automatically
attempts a 3-way merge. If the changes affect different parts, both go in.
If they overlap, the change is marked as
*conflicted* and offered in the review form as text with conflict markers;
the markers must be removed before publishing.

## Maintenance

```bash
php bin/plugin.php reviewqueue list      # pending changes
php bin/plugin.php reviewqueue show 42   # a single change including text
php bin/plugin.php reviewqueue expire    # archive old entries
```

Approving and rejecting are deliberately not available on the command line: a
decision must be attributable to a person.

`max_pending_age` (days) controls when `expire` takes effect; `0` disables
expiry. Useful as a cron job if changes regularly pile up.

## Data storage and backup

Everything is located under `<savedir>/reviewqueue/`:

```
queue/<id>.json      metadata
queue/<id>.content   proposed page text
queue/<id>.base      text state the change is based on (for the merge)
queue/<id>.media     uploaded file for media changes
archive/…            same for decided changes
```

**This directory belongs in the backup.** If only `pages/` is backed up, pending
changes are lost — they are, after all, deliberately not yet a revision.

## Updating and uninstalling

See [`../INSTALL.md`](../INSTALL.md). Key point: empty the queue before
removing the plugin, otherwise pending changes will never be published.

## What a review-scoped account can and cannot do

Since [ADR-0007](design/adr-0007-agent-confinement.md) such an account is confined
to a capability allowlist on **every** path — MCP, remote API and browser alike —
not just held back on writes.

**It can:** read current pages, search, browse the index, read and upload media,
edit and save (into the queue), and use the `plugin.reviewqueue.*` tools including
`createPage` and `deletePage`.

**It cannot:** see page or media **history** by any route (`do=revisions`, `do=diff`,
`do=recent`, `?rev=`, `?at=`, `feed.php`, `core.getPageHistory`, the media-diff ajax
calls — all refused); reach `lib/exe/jsonrpc.php` or `lib/exe/xmlrpc.php` at all;
change its own profile or password; or call any remote method outside the allowlist,
including ones a plugin installed later might add.

A **human** placed under review is confined identically. That is the price of a
single policy — which accounts pay it is a configuration decision (`review_users`).

## Keeping the agent's account as narrow as possible

The plugin intercepts every write path DokuWiki offers (see
[`design/write-path-audit.md`](design/write-path-audit.md)) and confines every read
path it can reach. Beyond that:

- **Keep ACLs tight.** If the account doesn't need delete rights, give it `AUTH_UPLOAD`
  (8) instead of `AUTH_DELETE` (16). Changes go through the queue regardless; tight
  permissions are the second line of defense.
- **`$conf['remoteuser']`** should still be limited to the accounts that actually
  need the remote API.
- **Give the agent no password it knows** — only an API token. Its browser routes are
  confined anyway, but an account that cannot log in interactively cannot be driven
  through the browser at all.
- **Optional, outside the plugin's reach:** blocking `lib/exe/jsonrpc.php` and
  `lib/exe/xmlrpc.php` at the web server for the agent's source address. The plugin
  already refuses them in PHP, after `init.php`; a web-server rule refuses them before
  PHP starts. Belt and braces, not a requirement.

Confinement applies only where DokuWiki authenticated someone. The five entry scripts
that skip authentication (`css.php`, `js.php`, `jquery.php`, `manifest.php`,
`opensearch.php`) are exactly the ones carrying no wiki content, so nothing is lost —
but an unauthenticated request is governed by your ACLs like any anonymous visitor's.

## Security properties

- **Fail-closed:** if the queue cannot be written, the save is
  rejected instead of let through.
- **No self-approval**, not even by directly submitting the form.
- **No ACL bypass:** whoever isn't allowed to read a page also doesn't see the
  changes submitted for it — even as a reviewer.
- **CSRF protection** via DokuWiki's `checkSecurityToken()`.
- Unreviewed content ends up in neither the search index, feeds, nor the
  version history.
- **No unreviewed changes:** creating/editing/deleting pages as well as
  media uploads *and* deletions all go through the queue. Actions that
  can't be reviewed (such as renaming via the `move` plugin) are rejected
  for review-required accounts instead of being executed unreviewed.
