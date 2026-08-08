#!/usr/bin/env bash
# Build (if needed) and start the DokuWiki 2024-02-06b test container with
# a fresh data/ state every run, then generate fresh API tokens for the
# seeded test users. Not for production use.
set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
REPO_ROOT="$(cd "$SCRIPT_DIR/../.." && pwd)"
IMAGE="reviewqueue-test-dokuwiki"
CONTAINER="reviewqueue-test-dokuwiki"
PORT="${REVIEWQUEUE_TEST_PORT:-8080}"
AUTH_DIR="$REPO_ROOT/test/e2e/.auth"

echo "==> Building test image (only rebuilds layers that changed)"
podman build -t "$IMAGE" -f "$SCRIPT_DIR/Containerfile" "$REPO_ROOT"

if podman container exists "$CONTAINER"; then
    echo "==> Removing existing container for a fresh data/ state"
    podman rm -f "$CONTAINER" >/dev/null
fi

echo "==> Starting container (fresh copy-on-write data/, plugin/ bind-mounted for live dev)"
podman run -d --name "$CONTAINER" \
    -p "${PORT}:80" \
    -v "$REPO_ROOT/plugin:/var/www/html/lib/plugins/reviewqueue:Z" \
    "$IMAGE"

echo "==> Waiting for DokuWiki to respond"
for _ in $(seq 1 30); do
    if curl -fsS "http://localhost:${PORT}/doku.php" -o /dev/null; then
        break
    fi
    sleep 1
done
if ! curl -fsS "http://localhost:${PORT}/doku.php" -o /dev/null; then
    echo "DokuWiki did not become ready in time" >&2
    podman logs "$CONTAINER" >&2
    exit 1
fi

echo "==> Generating fresh API tokens for martin and kail"
mkdir -p "$AUTH_DIR"
podman exec "$CONTAINER" php /usr/local/bin/dokuwiki-gen-tokens.php martin kail \
    > "$AUTH_DIR/tokens.json"

echo "==> Ready: http://localhost:${PORT}/ (login martin/martin, kail/kail, admin/admin)"
echo "    Tokens written to $AUTH_DIR/tokens.json"
