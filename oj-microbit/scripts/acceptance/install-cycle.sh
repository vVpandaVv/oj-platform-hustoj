#!/usr/bin/env bash
set -euo pipefail
command -v python3 >/dev/null || { printf 'Python 3 is required; no packages were installed.\n' >&2; exit 2; }
exec python3 -B "$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)/install-cycle.py" "$@"
