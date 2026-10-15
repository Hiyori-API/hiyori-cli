#!/usr/bin/env bash
# Thin wrapper around `php hiyori release` so CI and shell users share one path.
# Usage: scripts/publish-release.sh [--tag data-2026.42] [--dry-run] ...
set -euo pipefail
cd "$(dirname "$0")/.."
exec php hiyori release "$@"
