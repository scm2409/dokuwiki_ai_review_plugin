#!/usr/bin/env bash
# Stop and remove the test container. The image is left cached so the next
# up.sh only rebuilds changed layers.
set -euo pipefail

CONTAINER="reviewqueue-test-dokuwiki"

if podman container exists "$CONTAINER"; then
    podman rm -f "$CONTAINER" >/dev/null
    echo "==> Removed $CONTAINER"
else
    echo "==> $CONTAINER not running"
fi
