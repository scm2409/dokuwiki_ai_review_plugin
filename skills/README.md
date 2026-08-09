# Agent skill for the review workflow

[`dokuwiki-reviewqueue/SKILL.md`](dokuwiki-reviewqueue/SKILL.md) explains to an
AI agent how to work with a DokuWiki whose saves go through the
review queue. Without this context, an agent reliably falls into two
traps (see [`docs/design/adr-0004-visibility-of-open-changes.md`](../docs/design/adr-0004-visibility-of-open-changes.md)):

1. It takes the `RemoteException` on save for a failure and reports
   either an error to the human or — worse — a success that
   didn't happen.
2. It reads the page back with `core.getPage`, sees the live version without
   its draft, considers its work lost, and overwrites it itself on the
   next save.

## Installation

The skill is a normal Claude skill (a directory with `SKILL.md` and
YAML frontmatter). Copy or link the directory to wherever the agent
looks for its skills — for Claude Code, for example:

```bash
cp -r skills/dokuwiki-reviewqueue ~/.claude/skills/
```

Project-wide instead of global: to `.claude/skills/` in the respective project.

## Prerequisites

The agent needs access to the DokuWiki MCP plugin
([`splitbrain/dokuwiki-plugin-mcp`](https://github.com/splitbrain/dokuwiki-plugin-mcp))
with an API token of the account subject to review. Through this plugin, the
`plugin_reviewqueue_*` tools appear automatically as MCP tools — nothing
needs to be configured on the agent side for this.

Generating a token: in the DokuWiki user profile of the account, or via CLI in
the container (`JWT::fromUser()`, see [`test/env/`](../test/env/README.md)).

## Customizing

The skill is deliberately worded account-neutral — it describes "your account
is subject to review", not specifically `kail`. If your agent needs additional
context (wiki URL, namespace conventions, content tone), add that in
a separate skill or in the project's `CLAUDE.md`, rather than bloating this
one — it is meant to explain only the review protocol.
