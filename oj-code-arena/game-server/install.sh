#!/usr/bin/env bash
set -Eeuo pipefail
PLUGIN="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
CORE="$PLUGIN/common-server"
if [ ! -f "$CORE/install.sh" ]; then CORE="$PLUGIN/../_shared/oj-realtime-hub"; fi
if [ ! -f "$CORE/install.sh" ]; then echo "Common server runtime is missing; install the complete Code Arena ZIP." >&2; exit 1; fi
exec bash "$CORE/install.sh" "$PLUGIN" "$@"
