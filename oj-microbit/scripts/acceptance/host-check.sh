#!/usr/bin/env bash
# Read-only host inspection. Only explicitly requested private reports are written.
set +x
set +a
unset TOKEN MICROBIT_RUNNER_TOKEN NODE_OPTIONS NODE_PATH DOCKER_HOST DOCKER_CONTEXT DOCKER_CONFIG
set -Eeuo pipefail
umask 077
export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin
export DOCKER_HOST=unix:///var/run/docker.sock
command -v python3 >/dev/null || { printf '%s\n' 'Python 3 is required; no packages were installed.' >&2; exit 1; }
script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
exec python3 -B "$script_dir/host_check.py" "$@"
