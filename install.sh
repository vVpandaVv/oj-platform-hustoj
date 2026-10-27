#!/usr/bin/env bash
# Download the public OJ repository anonymously, then run its Ubuntu installer.
set +x
set -Eeuo pipefail

usage() {
    cat <<'TEXT'
bash install.sh [options]

  --site-url URL       WordPress address; default: http://server-IPv4
  --admin-email EMAIL  Admin/certificate contact; HTTP default: admin@example.test
                      HTTPS asks for a real email when omitted.
  --admin-user NAME    New site administrator username; asks when omitted.
  --title TEXT         Site name; default: OJ Platform
  --plugin-zip PATH    OJ Platform ZIP; default: newest bundled ZIP + .zip.sha256
  --help, -h          Show this help without installing anything.

Installs: official HUSTOJ and Docker judging, nginx, MariaDB, PHP 8.3,
WordPress (ko_KR), WP-CLI, OJ Platform, compilers/runtimes and SPJ tools.
Host tools: GCC/G++, make, JDK, Python 3 (venv/pip, NumPy/pandas/Matplotlib),
Node.js/npm, bubblewrap, prlimit, AppArmor and ACL.
An HTTPS URL also enables certificate issuance, redirect and automatic renewal.
Create the site administrator username and password during installation.
HUSTOJ output is shown live with elapsed/PID/status updates every 10 seconds.

Resume an existing run with the downloaded internal installer:
  sudo bash ~/oj-platform-hustoj/_release-kit/install/ubuntu24.sh --resume
TEXT
}

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }

main() {
    local -a forwarded=("$@") elevate=()
    local checkout
    while (($#)); do
        case "$1" in
            --help|-h) usage; return 0 ;;
            --site-url|--admin-email|--admin-user|--title|--plugin-zip)
                (($# >= 2)) && [[ -n $2 && $2 != --* ]] || fail "Missing value for $1"
                shift 2 ;;
            *) fail "Unknown option: $1" ;;
        esac
    done
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
