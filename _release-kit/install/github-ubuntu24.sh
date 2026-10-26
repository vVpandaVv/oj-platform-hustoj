#!/usr/bin/env bash
# Download the public OJ repository anonymously, then run its Ubuntu installer.
set +x
set -Eeuo pipefail

usage() {
    cat <<'TEXT'
bash github-ubuntu24.sh --site-url https://oj.example.com --admin-email admin@example.com
Optional: --admin-user NAME, --title TITLE, --plugin-zip PATH
TEXT
}

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

main() {
    local -a forwarded=("$@") elevate=()
    local site_url= admin_email= checkout
    while (($#)); do
        case "$1" in
            --help|-h) usage; return 0 ;;
            --site-url|--admin-email|--admin-user|--title|--plugin-zip)
                (($# >= 2)) && [[ -n $2 && $2 != --* ]] || fail "Missing value for $1"
                case "$1" in
                    --site-url) site_url=$2 ;;
                    --admin-email) admin_email=$2 ;;
                esac
                shift 2 ;;
            *) fail "Unknown option: $1" ;;
        esac
    done
    [[ -n $site_url && -n $admin_email ]] || { usage; return 1; }
    [[ $(uname -s) == Linux && $(uname -m) == x86_64 ]] || fail 'Use an Ubuntu 24.04 amd64 server.'
    # shellcheck disable=SC1091
    source /etc/os-release
    [[ $ID == ubuntu && $VERSION_ID == 24.04 ]] || fail 'Ubuntu 24.04 is required.'
    [[ ! -e /home/judge && ! -L /home/judge ]] || fail 'Use a fresh server.'
    checkout=$HOME/oj-platform-hustoj
    [[ ! -e $checkout && ! -L $checkout ]] || fail "Download destination already exists: $checkout"
    if ((EUID != 0)); then
        command -v sudo >/dev/null || fail 'sudo is required.'
        elevate=(sudo)
    fi

    "${elevate[@]}" apt-get update
    "${elevate[@]}" apt-get install -y ca-certificates git
    GIT_CONFIG_NOSYSTEM=1 GIT_CONFIG_GLOBAL=/dev/null \
        GIT_TERMINAL_PROMPT=0 GIT_ASKPASS=/bin/false SSH_ASKPASS=/bin/false \
        git -c credential.helper= -c core.askPass= -c http.extraHeader= \
        clone --depth 1 --branch main --single-branch \
        https://github.com/vVpandaVv/oj-platform-hustoj.git "$checkout"
    [[ -f $checkout/_release-kit/install/ubuntu24.sh && ! -L $checkout/_release-kit/install/ubuntu24.sh ]] || fail 'The OJ installer is missing.'
    "${elevate[@]}" bash "$checkout/_release-kit/install/ubuntu24.sh" "${forwarded[@]}"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    main "$@"
fi
