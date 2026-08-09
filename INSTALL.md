# Installation

Step by step for an existing DokuWiki installation, target version
**2024-02-06b "Kaos"**. For ongoing operation afterward see
[`docs/usage.md`](docs/usage.md).

Below, `<dokuwiki>` refers to the root directory of your installation
(where `doku.php` lives).

## 1. Install the plugin

The directory name must be exactly `reviewqueue` — DokuWiki derives its
class names from it, and any other name results in "Plugin installed incorrectly".

```bash
cp -r plugin <dokuwiki>/lib/plugins/reviewqueue
chown -R www-data:www-data <dokuwiki>/lib/plugins/reviewqueue
```

The Extension Manager is **not** used — the plugin is not hosted on
dokuwiki.org.

## 2. Create a group and accounts

*Administration → User Manager*:

| Account | Groups | Purpose |
|---|---|---|
| your account | `reviewer`, `user` | reviews and approves |
| agent account (e.g. `kail`) | `user` | writes, but only into the queue |

The `reviewer` group doesn't exist in DokuWiki out of the box; it simply
comes into being by entering it on the user. **Do not** use the
`admin` group: review and wiki administration are deliberately separate, so that
reviewing doesn't mean having all rights.

## 3. Configure

*Administration → Configuration*, `reviewqueue` section — or directly in
`<dokuwiki>/conf/local.php`:

```php
$conf['plugin']['reviewqueue']['review_users']    = 'kail';     // logins subject to review
$conf['plugin']['reviewqueue']['review_groups']   = '';         // alternatively whole groups
$conf['plugin']['reviewqueue']['reviewer_groups'] = 'reviewer'; // who may approve
```

As long as `review_users` and `review_groups` are empty, the plugin does nothing —
that's the safe starting state.

## 4. Set ACLs

The agent account needs **completely normal write permissions**. The change is
held back by the plugin, not by the ACL — without write permission it would
never even reach the queue.

Recommendation in *Administration → Access Control List*: `Upload` (8), not
`Delete` (16). Deletions also go through the queue, but tight
permissions are the second line of defense.

## 5. Verify that it works

Log in as the agent account, edit a page, and save. Expected:

> Your change to '…' has been submitted for review as change #1. It is
> NOT YET live.

Log in as reviewer → *Site Tools → Review Queue*. The change must appear there
with a diff. If the menu item doesn't appear, the group in
`reviewer_groups` doesn't match your account's group.

Test with a third, normal account that everything there still works
unchanged.

---

# Connecting an AI agent via MCP

Only needed if the agent is to operate the wiki itself.

## 6. Install the MCP plugin

```bash
cd <dokuwiki>/lib/plugins
git clone https://github.com/splitbrain/dokuwiki-plugin-mcp.git mcp
chown -R www-data:www-data mcp
```

For reproducible installations, pin to a commit (this project's test
environment uses `c44faefa170c63435ccd19c3a25e84e2e2a24c53`):

```bash
git -C mcp checkout c44faefa170c63435ccd19c3a25e84e2e2a24c53
```

## 7. Enable the Remote API

*Administration → Configuration*:

- `remote` → **enable**
- `remoteuser` → limit to the accounts that actually need the API, e.g.
  `kail,your-account`. Leaving it empty means **all** users — then any
  third-party plugin with its own writing API method can be
  called from any account.

## 8. Get an API token

Log in as the respective account → *User Profile* (`?do=profile`). At the
bottom is an API token as a long `eyJ…` string. Copy it.

The token is a password equivalent for this account. It can be regenerated
via the button in the profile, which immediately invalidates the old one.

## 9. Configure the MCP client

The endpoint is `https://<your-wiki>/lib/plugins/mcp/mcp.php` over HTTP
transport, authenticated via header:

```
Authorization: Bearer <token>
```

For Claude Code, for example:

```bash
claude mcp add --transport http dokuwiki https://<your-wiki>/lib/plugins/mcp/mcp.php --header "Authorization: Bearer <token>"
```

The exact syntax depends on the client — what matters is only the URL, HTTP
transport, and the Bearer header.

Quick test without a client:

```bash
curl -sS -X POST https://<your-wiki>/lib/plugins/mcp/mcp.php \
  -H "Authorization: Bearer <token>" -H "Content-Type: application/json" \
  -d '{"jsonrpc":"2.0","method":"initialize","params":{},"id":1}'
```

The response must contain `"You are authenticated as '<account>'"`. If it
instead says no token was accepted, either the header or
`remoteuser` is wrong.

## 10. Install the skill for the agent

Without this context, an agent reliably misunderstands the flow: it takes
the error message on save for a failure, or reads the page back with
`core.getPage`, doesn't see its draft, and overwrites its own unreviewed work
on the next save.

```bash
cp -r skills/dokuwiki-reviewqueue ~/.claude/skills/
```

Project-scoped instead of global: to `.claude/skills/` in the respective
project. Details in [`skills/README.md`](skills/README.md).

## 11. Verify end to end

Have the agent change a page. Expected: it reports that the change has been
submitted for review — **not** that the page has been updated. The change sits
in the review queue, the page is unchanged.

---

## Updating

```bash
rsync -a --delete plugin/ <dokuwiki>/lib/plugins/reviewqueue/
```

`--delete` is important so that removed files don't linger. The queue
lives under `<dokuwiki>/data/reviewqueue/` and is not touched by this.

## Uninstalling

**Empty the queue first** — otherwise open changes are never published:

```bash
php <dokuwiki>/bin/plugin.php reviewqueue list
```

Then:

```bash
rm -rf <dokuwiki>/lib/plugins/reviewqueue
```

The `reviewqueue` entries in `conf/local.php` can stay; they are
ignored without the plugin. Keep `data/reviewqueue/` if anything is still
there — these are plain text files that can be migrated by hand.

## If something doesn't work

| Symptom | Cause |
|---|---|
| "Plugin installed incorrectly. Rename plugin directory" | Directory is not named `reviewqueue` |
| Saving goes live directly | Account is not in `review_users`/`review_groups`; check spelling and case |
| No *Review Queue* menu item | Account is not in a group from `reviewer_groups` |
| "The review queue could not be written to" | `data/reviewqueue/` is not writable by the web server — this is intentional: when in doubt, it's rejected rather than published unreviewed |
| MCP calls fail with "not authorized" | `remote` is off, or account is not in `remoteuser` |
| Agent reports "page saved" even though it isn't | Skill from step 10 is missing |
