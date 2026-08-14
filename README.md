# dokuwiki-plugin-reviewqueue

**This project is vibe coded.**

A DokuWiki plugin that doesn't put changes from certain users (typically an AI agent
with its own wiki account and MCP access) live directly, but instead places them in a
review queue. A human with reviewer rights sees the change as a diff and approves,
rejects, or edits it before release. All other users are completely unaffected by this
process — they save directly as usual.

Target version: DokuWiki **Release 2024-02-06b "Kaos"**.

## Why a new plugin?

Existing solutions ([`approve`](https://github.com/gkrid/dokuwiki-plugin-approve),
[`publish`](https://github.com/cosmocode/dokuwiki-plugin-publish)) let the save go
through and only hide unapproved revisions from readers — and are
**namespace-** or permission-based rather than **user-based**. See
[`docs/research/existing-plugins.md`](docs/research/existing-plugins.md) for details.

## Status

Functional and fully tested against DokuWiki 2024-02-06b: page creation, editing,
and deletion, as well as media uploads, all go through the queue, with 3-way merge,
conflict resolution, an admin interface, and MCP tools for the agent. 46 end-to-end
tests run against a real installation in a container.

Installation: [`INSTALL.md`](INSTALL.md). Operation: [`docs/usage.md`](docs/usage.md).
Phase status: [`docs/roadmap.md`](docs/roadmap.md).

## Documentation

- [`docs/research/`](docs/research/) — Survey of existing plugins and
  verified DokuWiki Kaos hook points
- [`docs/design/spec.md`](docs/design/spec.md) — State machine, data formats,
  configuration
- [`docs/design/`](docs/design/) — Architecture decisions (ADRs)
- [`docs/testing/strategy.md`](docs/testing/strategy.md) — Test concept and
  scenario matrix
- [`INSTALL.md`](INSTALL.md) — Step-by-step installation, including MCP integration
- [`docs/usage.md`](docs/usage.md) — Operation, conflicts, maintenance, backup, security
- [`docs/roadmap.md`](docs/roadmap.md) — Phase plan and status
- [`skills/`](skills/) — Agent skill that explains the review workflow to an AI

## Directory structure

```
plugin/     DokuWiki plugin source code (installed to lib/plugins/reviewqueue/)
skills/     Agent skill for handling the review queue via MCP
test/env/   Podman test environment with DokuWiki 2024-02-06b
test/e2e/   Playwright end-to-end tests
docs/       Research, decisions, specification, test concept, operation
```

## Development

```bash
test/env/up.sh          # bring up DokuWiki 2024-02-06b in the Podman container
npx playwright test     # run the test suite
test/env/down.sh
```

See [`docs/testing/strategy.md`](docs/testing/strategy.md) for details.
