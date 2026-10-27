#!/usr/bin/env bash
set +x
set -Eeuo pipefail

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    UPDATE_ROOT=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
    exec bash "$UPDATE_ROOT/_release-kit/install/update-ubuntu24.sh" "$@"
fi
