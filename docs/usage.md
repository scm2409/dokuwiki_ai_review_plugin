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

## Keeping the agent's account as narrow as possible

The plugin intercepts every write path that DokuWiki itself offers (see
[`design/write-path-audit.md`](design/write-path-audit.md)). Two things, however,
lie outside its reach and should be secured via configuration:

- **Keep ACLs tight.** If the account doesn't need delete rights, give it `AUTH_UPLOAD`
  (8) instead of `AUTH_DELETE` (16). Changes go through the queue regardless; tight
  permissions are the second line of defense.
- **`$conf['remoteuser']`** should be limited to the accounts that actually
  need the remote API. Third-party plugins can bring their own writing API methods,
  and DokuWiki has no interception point for that.

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
