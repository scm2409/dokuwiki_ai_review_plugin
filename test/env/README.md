# Test environment

DokuWiki **2024-02-06b "Kaos"** in a Podman container, built from the official
tarball (not from a Docker Hub image). Not for production use — fixed,
weak test credentials.

```bash
test/env/up.sh      # builds/starts the container, generates fresh API tokens
test/env/down.sh     # stops and removes the container
```

After `up.sh`, the wiki runs at `http://localhost:8080/` (port changeable via
`REVIEWQUEUE_TEST_PORT`).

## Credentials

| Login | Password | Groups | Role |
|---|---|---|---|
| `admin` | `admin` | `admin,user` | DokuWiki superuser (maintenance, not part of the test scenarios) |
| `martin` | `martin` | `reviewer,user` | Reviewer, writes directly |
| `kail` | `kail` | `user` | subject to review via `$conf['plugin']['reviewqueue']['review_users']` (default in `plugin/conf/default.php`) |

API tokens for `martin` and `kail` are freshly generated on every `up.sh` run and
written to `test/e2e/.auth/tokens.json` (not versioned).

## Fresh state per run

`up.sh` removes any currently running container and starts a new one — since
`data/` is **not** mounted as an external volume but is part of the image layer,
every new container automatically starts with the data state seeded in the image
(copy-on-write), without needing a manual snapshot/restore.

`plugin/`, on the other hand, is placed as a bind mount over `lib/plugins/reviewqueue/` —
changes to the plugin code are immediately visible in the container, without a rebuild.

## What lives here

- `Containerfile` — base image, DokuWiki tarball, `mcp` plugin (commit-pinned), seeding
- `seed/conf/` — `local.php`, `acl.auth.php` (templates, copied into the image)
- `seed/gen-users.php` — generates `conf/users.auth.php` with real DokuWiki smd5 hashes
  (`crypt($clear, '$1$'.$salt.'$')`, see `inc/PassHash.php::hash_smd5()`)
- `seed/gen-tokens.php` — generates JWT API tokens via `dokuwiki\JWT::fromUser()`,
  invoked via `podman exec` after startup
- `seed/pages/` — pre-populated test pages (`start`, `playground:test`)

Details on the test concept and the full scenario matrix:
[`docs/testing/strategy.md`](../../docs/testing/strategy.md).
