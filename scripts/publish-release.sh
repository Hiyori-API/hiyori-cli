#!/usr/bin/env bash
# Thin wrapper around the release orchestrator, so CI and shell users share one path.
#
# `--from=release` runs the orchestrator's last stage -- build, verify and
# publish the release -- behind the same gate the full chain applies: the
# orchestrator refuses to publish while a combined collection repeats an
# identity key or is empty.
#
# Usage: scripts/publish-release.sh [--tag data-2026.42] [--repo owner/repo] [--branch master] [--draft]
#
# The release stage carries only the flags the orchestrator forwards to
# `php hiyori release`. The release-only flags are refused here instead of
# being silently dropped: `release --dry-run` builds and verifies the assets
# while the orchestrator's `--dry-run` runs no stage at all, and `--force`,
# `--profile`, `--no-manga`, `--no-zstd`, `--clean-target` and `--message` are
# not carried by the stage. Use `php hiyori release` for those.
set -euo pipefail
cd "$(dirname "$0")/.."

expect_value=0
for arg in "$@"; do
  if [ "$expect_value" = "1" ]; then
    expect_value=0
    continue
  fi
  case "$arg" in
    --tag|--repo|--branch) expect_value=1 ;;
    --tag=*|--repo=*|--branch=*|--draft) ;;
    *)
      echo "publish-release.sh: unsupported option '$arg'" >&2
      echo "  the release stage accepts: --tag --repo --branch --draft" >&2
      echo "  for --dry-run/--force/--profile/--no-manga/--no-zstd/--clean-target/--message run: php hiyori release" >&2
      exit 2
      ;;
  esac
done

exec php hiyori pipeline:release --from=release "$@"
