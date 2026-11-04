#!/usr/bin/env bash
# Ubuntu 24.04 amd64, same-host WordPress. No downloaded shell scripts are run.
# Sourceable for the offline installation harness; execution always enters main.

INSTALL_ROOT=/opt/oj-microbit
NODE_VERSION=24.22.1
NODE_ROOT=/opt/oj-microbit-node-24.22.1
# Official release: https://nodejs.org/en/blog/release/v24.22.1
NODE_SHA256=6c172a9ab6718f7b801fb5a7bd486af2ee5e32758086a04b84fcce2aa77095e6
ENV_DIR=/etc/oj-microbit
UNIT_FILE=/etc/systemd/system/oj-microbit-runner.service
LOCK_FILE=/run/lock/oj-microbit/install.lock
SERVICE_NAME=oj-microbit-runner.service
SERVICE_USER=oj-microbit
PHP_BIN=/usr/bin/php8.3
EXPECTED_RUNTIME='pxt-microbit/9.1.1;pxt-core/13.0.1;oj-gpio/3'

usage() {
  cat <<'USAGE'
Usage: sudo bash /path/to/oj-microbit/runner/install.sh [options]

Install/update the isolated micro:bit runner and connect the local WordPress site.
Supported host: Ubuntu 24.04 amd64, systemd, PHP 8.3, active OJ Microbit plugin.

  --wp-root PATH  WordPress directory (default: /home/judge/src/web)
  --wp-user USER  Non-root WordPress OS account (default: wp-config.php owner;
                  root-owned files use www-data if available)
  --port NUMBER   Loopback runner port (default: existing environment or 3037)
  --dry-run       Show the plan; no mutation, lock creation, or network requests
  --check         Read-only local installation/health/WordPress connection checks
  --help          Show this help

No token is accepted on the command line. Existing credentials are reused.
The installer does not edit wp-config.php, replace global Node, upgrade an
existing Docker installation, remove conflicting packages, or expose a port.
USAGE
}

log() { printf '[microbit] %s\n' "$*"; }
fail() { printf '[microbit] ERROR: %s\n' "$*" >&2; return 1; }
stage() { CURRENT_STAGE=$1; log "$1"; }

parse_args() {
  MODE=install WP_ROOT=/home/judge/src/web WP_USER='' PORT='' EXPLICIT_PORT=false
  while (($#)); do
    case "$1" in
      --help|-h) MODE=help; shift ;;
      --wp-root|--wp-user|--port)
        (($# >= 2)) && [[ -n $2 && $2 != --* ]] || { fail "Missing value for $1"; return 1; }
        case "$1" in
          --wp-root) WP_ROOT=$2 ;;
          --wp-user) WP_USER=$2 ;;
          --port) PORT=$2; EXPLICIT_PORT=true ;;
        esac
        shift 2 ;;
      --dry-run|--check)
        [[ $MODE == install ]] || { fail 'Choose only one of --dry-run and --check.'; return 1; }
        MODE=${1#--}; shift ;;
      *) fail "Unknown option: $1"; return 1 ;;
    esac
  done
  if [[ -n $PORT ]]; then
    [[ $PORT =~ ^[0-9]{4,5}$ ]] && ((10#$PORT >= 1024 && 10#$PORT <= 65535)) || { fail 'Port must be between 1024 and 65535.'; return 1; }
    PORT=$((10#$PORT))
  fi
  [[ $WP_ROOT == /* && $WP_ROOT != *$'\n'* && $WP_ROOT != *$'\r'* ]] || { fail 'WordPress root must be an absolute path without line breaks.'; return 1; }
  [[ -z $WP_USER || $WP_USER =~ ^[a-z_][a-z0-9_-]*\$?$ ]] || { fail 'Invalid WordPress OS account.'; return 1; }
}

supported_platform() { [[ $1 == ubuntu && $2 == 24.04 && $3 == amd64 ]]; }
check_platform() {
  local ID='' VERSION_ID=''
  [[ -r /etc/os-release ]] || { fail 'Cannot identify this operating system.'; return 1; }
  # /etc/os-release is an operating-system-owned configuration file.
  . /etc/os-release
  supported_platform "$ID" "$VERSION_ID" "$(dpkg --print-architecture)" || { fail 'Only Ubuntu 24.04 amd64 is supported.'; return 1; }
  [[ -d /run/systemd/system ]] && command -v systemctl >/dev/null || { fail 'A running systemd host is required.'; return 1; }
  [[ $(id -u) == 0 ]] || { fail 'Run this command with sudo bash.'; return 1; }
}

check_wordpress_files() {
  [[ -f $WP_ROOT/wp-load.php && -f $WP_ROOT/wp-config.php ]] || { fail 'WordPress wp-load.php and wp-config.php must exist in --wp-root.'; return 1; }
  WP_ROOT=$(realpath -e -- "$WP_ROOT")
  if [[ -z $WP_USER ]]; then
    WP_USER=$(stat -c '%U' -- "$WP_ROOT/wp-config.php")
    if [[ $WP_USER == root ]] && id www-data >/dev/null 2>&1; then WP_USER=www-data; fi
  fi
  id "$WP_USER" >/dev/null 2>&1 && [[ $(id -u "$WP_USER") != 0 ]] || { fail 'Specify the non-root WordPress OS account with --wp-user.'; return 1; }
  [[ -x $PHP_BIN ]] || { fail 'PHP 8.3 CLI is required at /usr/bin/php8.3.'; return 1; }
  runuser -u "$WP_USER" -- test -r "$WP_ROOT/wp-config.php" || { fail 'The WordPress OS account cannot read wp-config.php.'; return 1; }
}

valid_release_version() { [[ $1 =~ ^(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)\.(0|[1-9][0-9]*)(-rc\.(0|[1-9][0-9]*))?$ ]]; }

check_source() {
  SOURCE_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
  local file
  for file in package.json package-lock.json Dockerfile .dockerignore src/server.mjs install-tools/configure-wordpress.php install-tools/WordPressConfigurator.php install-tools/runner-check.mjs; do
    [[ -f $SOURCE_DIR/$file && ! -L $SOURCE_DIR/$file ]] || { fail "Missing installer source file: $file"; return 1; }
  done
  VERSION=$(sed -n 's/^[[:space:]]*"version"[[:space:]]*:[[:space:]]*"\([^"[:cntrl:]]*\)".*/\1/p' "$SOURCE_DIR/package.json" | head -n 1)
  valid_release_version "$VERSION" || { fail 'Invalid runner package version (expected stable or -rc.N).' ; return 1; }
  [[ -z $(find "$SOURCE_DIR/src" "$SOURCE_DIR/install-tools" -type l -print -quit) ]] || { fail 'Runner source must not contain symbolic links.'; return 1; }
  RELEASE_DIR=$INSTALL_ROOT/$VERSION
  RUNNER_DIR=$RELEASE_DIR/runner
  IMAGE=oj-microbit-worker:$VERSION
  NODE_BIN=$NODE_ROOT/bin/node
  ENV_FILE=$ENV_DIR/runner.env
}

read_environment() {
  TOKEN=''
  local old_port='' key value line
  if [[ -e $ENV_FILE || -L $ENV_FILE ]]; then
    [[ -f $ENV_FILE && ! -L $ENV_FILE && $(stat -c '%u' "$ENV_FILE") == 0 ]] || { fail 'Existing runner.env must be a root-owned regular file.'; return 1; }
    local environment_mode
    environment_mode=$(stat -c '%a' "$ENV_FILE")
    (( (8#$environment_mode & 0027) == 0 )) || { fail 'Existing runner.env must not be group writable or accessible to other users.'; return 1; }
    while IFS= read -r line || [[ -n $line ]]; do
      line=${line%$'\r'}
      [[ $line == \#* || -z $line ]] && continue
      [[ $line == *=* ]] || continue
      key=${line%%=*}; value=${line#*=}
      value=$(unquote_environment_value "$value")
      case "$key" in
        MICROBIT_RUNNER_TOKEN) TOKEN=$value ;;
        MICROBIT_RUNNER_PORT) old_port=$value ;;
        MICROBIT_RUNNER_HOST) [[ $value == 127.0.0.1 ]] || { fail 'Existing runner must use loopback 127.0.0.1 for this installer.'; return 1; } ;;
      esac
    done < "$ENV_FILE"
    valid_token "$TOKEN" || { fail 'Existing runner token is missing or uses an unsupported format; runner.env was not changed.'; return 1; }
  fi
  if ! $EXPLICIT_PORT; then PORT=${old_port:-3037}; fi
  [[ $PORT =~ ^[0-9]{4,5}$ ]] && ((10#$PORT >= 1024 && 10#$PORT <= 65535)) || { fail 'Invalid runner port in existing environment.'; return 1; }
  PORT=$((10#$PORT))
  URL=http://127.0.0.1:$PORT
}

valid_token() { local LC_ALL=C; [[ ${#1} -ge 32 && ${#1} -le 256 && $1 =~ ^[!-~]+$ ]]; }

unquote_environment_value() {
  local value=$1 output='' character next index
  if [[ $value == \"*\" ]]; then
    value=${value:1:${#value}-2}
    for ((index=0; index<${#value}; index++)); do
      character=${value:index:1}
      if [[ $character == \\ ]]; then
        ((index+=1)); next=${value:index:1}
        [[ $next == \\ || $next == \" ]] || { fail 'Unsupported escape in runner.env.'; return 1; }
        character=$next
      fi
      output+=$character
    done
    printf '%s' "$output"
  elif [[ $value == \'*\' ]]; then printf '%s' "${value:1:${#value}-2}"
  else printf '%s' "$value"
  fi
}

secure_directory() {
  [[ ! -L $1 ]] || { fail "Refusing symbolic-link installation directory: $1"; return 1; }
  if [[ -e $1 ]]; then
    [[ -d $1 && $(stat -c '%u' "$1") == 0 ]] || { fail "Installation directory must be owned by root: $1"; return 1; }
  fi
  install -d -o root -g root -m "$2" -- "$1"
}

acquire_lock() {
  secure_directory "$(dirname "$LOCK_FILE")" 0700
  [[ ! -L $LOCK_FILE ]] || { fail 'Refusing symbolic-link installer lock.'; return 1; }
  exec 9>"$LOCK_FILE"
  flock -n 9 || { fail 'Another micro:bit installation is already running.'; return 1; }
}

ensure_tools() {
  if [[ ${DOCKER_POLICY:-} == reuse ]]; then
    local required
    for required in curl tar xz sha256sum ss; do
      command -v "$required" >/dev/null || { fail "Existing Docker is preserved; required tool '$required' is missing. Install prerequisites during a separate maintenance window."; return 1; }
    done
    check_ca_bundle
    return
  fi
  [[ ${DOCKER_POLICY:-} == install ]] || { fail 'Docker installation policy must be checked before prerequisites.'; return 1; }
  local missing=() package
  for package in ca-certificates curl xz-utils gnupg iproute2; do
    if ! dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -qx 'install ok installed'; then missing+=("$package"); fi
  done
  if ((${#missing[@]})); then
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends "${missing[@]}"
  fi
}

check_ca_bundle() {
  [[ -r /etc/ssl/certs/ca-certificates.crt && -s /etc/ssl/certs/ca-certificates.crt ]] || { fail 'Existing Docker is preserved; the system CA bundle is missing. Prepare certificates separately.'; return 1; }
}

check_local_docker_socket() {
  [[ -S /var/run/docker.sock ]] || { fail 'A local Docker Engine socket is required at /var/run/docker.sock.'; return 1; }
}

check_docker_query_safe() {
  local status
  if systemctl is-active --quiet docker.service; then return 0; else status=$?; fi
  [[ $status == 3 || $status == 4 ]] || { fail 'Cannot safely inspect Docker service activation; no Docker request was sent.'; return 1; }
  if systemctl is-active --quiet docker.socket; then
    fail 'Docker socket activation is enabled while its daemon is inactive; no Docker request was sent. Start or repair Docker separately.'
    return 1
  else status=$?; fi
  [[ $status == 3 || $status == 4 ]] || { fail 'Cannot safely inspect Docker socket activation; no Docker request was sent.'; return 1; }
  return 0
}

detect_docker_policy() {
  if command -v docker >/dev/null; then
    DOCKER_POLICY=reuse
    check_docker_query_safe || return 1
    docker info >/dev/null 2>&1 || { fail 'Existing Docker is not available. Start or repair it separately; no packages or daemon settings were changed.'; return 1; }
    check_local_docker_socket
    return
  fi
  local package conflicts=()
  for package in docker-ce docker-ce-cli docker-ce-rootless-extras docker-buildx-plugin docker-compose-plugin docker.io docker-compose docker-compose-v2 docker-doc docker-buildx podman-docker containerd.io containerd runc; do
    if dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -qx 'install ok installed'; then conflicts+=("$package"); fi
  done
  ((${#conflicts[@]} == 0)) || { fail "Existing or conflicting container packages require administrator review; no packages were changed: ${conflicts[*]}"; return 1; }
  [[ ! -e /var/run/docker.sock && ! -e /var/lib/docker && ! -e /var/snap/docker && ! -e /etc/docker/daemon.json ]] || { fail 'Existing Docker data or configuration was found without a usable CLI; repair it separately.'; return 1; }
  if systemctl is-active --quiet docker.service || systemctl is-active --quiet snap.docker.dockerd.service; then
    fail 'An existing Docker daemon is active without a usable CLI; no packages were changed.'; return 1;
  fi
  DOCKER_POLICY=install
}

check_port_available() {
  command -v ss >/dev/null || { fail 'The read-only port check requires ss (iproute2). Prepare this tool separately before installation.'; return 1; }
  local listeners main_pid line remaining pid
  listeners=$(ss -H -ltnp "sport = :$PORT") || { fail 'Cannot inspect the runner port; no existing listener was changed.'; return 1; }
  [[ -n $listeners ]] || return 0
  main_pid=$(systemctl show "$SERVICE_NAME" --property=MainPID --value) || { fail 'Cannot identify the runner port owner.'; return 1; }
  [[ $main_pid =~ ^[1-9][0-9]*$ ]] && systemctl is-active --quiet "$SERVICE_NAME" || { fail "Port $PORT is already in use; no process was stopped. Choose another --port."; return 1; }
  while IFS= read -r line; do
    [[ $line =~ pid=([0-9]+) ]] || { fail "Port $PORT has an unidentified listener; no process was stopped."; return 1; }
    remaining=$line
    while [[ $remaining =~ pid=([0-9]+) ]]; do
      pid=${BASH_REMATCH[1]}
      [[ $pid == "$main_pid" ]] || { fail "Port $PORT belongs to another process; no process was stopped. Choose another --port."; return 1; }
      remaining=${remaining#*pid=$pid}
    done
  done <<< "$listeners"
}

check_filesystem_space() {
  local available
  available=$(df -Pk -- "$1" | awk 'NR==2 {print $4}') || { fail "Cannot inspect free disk space for $2."; return 1; }
  [[ $available =~ ^[0-9]+$ ]] && ((available >= 4194304)) || { fail "At least 4 GiB of free space is required for $2 before npm/image build. Existing Docker objects were not changed."; return 1; }
}

check_disk_space() {
  local docker_root
  check_docker_query_safe || return 1
  docker_root=$(docker info --format '{{.DockerRootDir}}') || { fail 'Cannot inspect the local Docker data directory.'; return 1; }
  [[ $docker_root == /* && $docker_root != *$'\n'* && $docker_root != *$'\r'* ]] || { fail 'Docker returned an unsupported data-directory path.'; return 1; }
  check_filesystem_space "$INSTALL_ROOT" 'the runner installation filesystem' || return 1
  check_filesystem_space "$docker_root" 'the Docker data filesystem'
}

https_download() {
  curl --proto '=https' --proto-redir '=https' --tlsv1.2 --fail --silent --show-error --location --retry 3 --connect-timeout 10 --max-time 300 "$1" -o "$2"
}

ensure_node() {
  if [[ -e $NODE_ROOT ]]; then
    check_node_runtime
    return
  fi
  local archive=node-v$NODE_VERSION-linux-x64.tar.xz
  https_download "https://nodejs.org/dist/v$NODE_VERSION/$archive" "$WORK_DIR/$archive"
  printf '%s  %s\n' "$NODE_SHA256" "$WORK_DIR/$archive" | sha256sum --check --status || { fail 'Node archive checksum verification failed.'; return 1; }
  install -d -m 0755 "$WORK_DIR/node"
  tar -xJf "$WORK_DIR/$archive" --strip-components=1 --no-same-owner -C "$WORK_DIR/node"
  chown -R root:root "$WORK_DIR/node"
  chmod -R go-w "$WORK_DIR/node"
  mv -- "$WORK_DIR/node" "$NODE_ROOT"
  [[ $($NODE_BIN --version) == v$NODE_VERSION ]] || { fail 'Installed Node did not start correctly.'; return 1; }
}

check_node_runtime() {
  [[ -d $NODE_ROOT && ! -L $NODE_ROOT && -x $NODE_BIN && ! -L $NODE_BIN ]] || { fail 'Dedicated Node is missing or uses an unsafe path.'; return 1; }
  [[ -z $(find "$NODE_ROOT" -xdev \( ! -user root -o \( ! -type l -a -perm /022 \) \) -print -quit) ]] || { fail 'Dedicated Node files must be root-owned and not group/world writable.'; return 1; }
  [[ $($NODE_BIN --version) == v$NODE_VERSION ]] || { fail 'Dedicated Node version does not match the required version.'; return 1; }
}

ensure_docker() {
  [[ ${DOCKER_POLICY:-} == reuse || ${DOCKER_POLICY:-} == install ]] || { fail 'Docker installation policy was not checked.'; return 1; }
  if [[ $DOCKER_POLICY == reuse ]]; then
    check_docker_query_safe || return 1
    docker info >/dev/null 2>&1 || { fail 'Existing Docker is not available. Start or repair it; the installer will not change its configuration.'; return 1; }
  else
    # A Docker CLI appearing after preflight must never be upgraded/reconfigured.
    if command -v docker >/dev/null; then fail 'Docker appeared during installation; rerun to reuse it without package changes.'; return 1; fi
    local package conflicts=() fingerprint
    for package in docker-ce docker-ce-cli docker-ce-rootless-extras docker-buildx-plugin docker-compose-plugin docker.io docker-compose docker-compose-v2 docker-doc docker-buildx podman-docker containerd.io containerd runc; do
      if dpkg-query -W -f='${Status}' "$package" 2>/dev/null | grep -qx 'install ok installed'; then conflicts+=("$package"); fi
    done
    ((${#conflicts[@]} == 0)) || { fail "Conflicting packages require administrator review; nothing was removed: ${conflicts[*]}"; return 1; }
    cat > "$WORK_DIR/docker.sources" <<'SOURCES'
Types: deb
URIs: https://download.docker.com/linux/ubuntu
Suites: noble
Components: stable
Architectures: amd64
Signed-By: /etc/apt/keyrings/oj-microbit-docker.asc
SOURCES
    [[ ! -e /etc/apt/sources.list.d/docker.list && ! -L /etc/apt/sources.list.d/docker.sources ]] || { fail 'A different Docker repository is already configured. Repair that installation first.'; return 1; }
    if [[ -e /etc/apt/sources.list.d/docker.sources ]]; then
      cmp -s "$WORK_DIR/docker.sources" /etc/apt/sources.list.d/docker.sources || { fail 'A different Docker repository is already configured. It was not overwritten.'; return 1; }
    fi
    https_download https://download.docker.com/linux/ubuntu/gpg "$WORK_DIR/docker.asc"
    fingerprint=$(gpg --batch --no-options --homedir "$WORK_DIR" --show-keys --with-colons "$WORK_DIR/docker.asc" 2>/dev/null | awk -F: '$1=="fpr" {print $10; exit}')
    [[ $fingerprint == 9DC858229FC7DD38854AE2D88D81803C0EBFCD88 ]] || { fail 'Docker repository signing-key fingerprint verification failed.'; return 1; }
    install -d -m 0755 /etc/apt/keyrings
    [[ ! -L /etc/apt/keyrings/oj-microbit-docker.asc ]] || { fail 'Refusing a symbolic-link Docker key file.'; return 1; }
    install -m 0644 "$WORK_DIR/docker.asc" /etc/apt/keyrings/oj-microbit-docker.asc
    install -m 0644 "$WORK_DIR/docker.sources" /etc/apt/sources.list.d/docker.sources
    apt-get update
    DEBIAN_FRONTEND=noninteractive apt-get install -y --no-install-recommends docker-ce docker-ce-cli containerd.io docker-buildx-plugin
    systemctl enable --now docker.service
    check_docker_query_safe || return 1
    docker info >/dev/null 2>&1 || { fail 'New Docker installation is not ready.'; return 1; }
  fi
  # Never inherit a remote Docker daemon or credentials from the invoking shell.
  check_local_docker_socket
}

ensure_service_user() {
  if id "$SERVICE_USER" >/dev/null 2>&1; then
    [[ $(id -u "$SERVICE_USER") != 0 ]] || { fail 'Runner service account must not be root.'; return 1; }
    local service_entry service_shell
    service_entry=$(getent passwd "$SERVICE_USER")
    service_shell=${service_entry##*:}
    [[ $service_shell == /usr/sbin/nologin || $service_shell == /sbin/nologin || $service_shell == /bin/false ]] || { fail 'The existing runner account must be a dedicated non-login service account; no Docker permissions were granted.'; return 1; }
  else
    useradd --system --user-group --home-dir "$INSTALL_ROOT" --shell /usr/sbin/nologin "$SERVICE_USER"
  fi
  getent group docker >/dev/null || { fail 'The local Docker group is missing.'; return 1; }
  usermod -aG docker "$SERVICE_USER"
}

json_string() {
  local value=$1
  value=${value//\\/\\\\}; value=${value//\"/\\\"}
  value=${value//$'\n'/\\n}; value=${value//$'\r'/\\r}; value=${value//$'\t'/\\t}
  printf '"%s"' "$value"
}

emit_bridge_input() {
  printf '{"mode":'; json_string "$1"
  printf ',"wpRoot":'; json_string "$WP_ROOT"
  printf ',"url":'; json_string "$URL"
  if [[ -n $TOKEN ]]; then printf ',"token":'; json_string "$TOKEN"; fi
  if [[ -n ${2:-} ]]; then printf ',"secretFile":'; json_string "$2"; fi
  if [[ -n ${3:-} ]]; then printf ',"backupFile":'; json_string "$3"; fi
  if [[ $1 == configure ]]; then printf ',"expectedHash":'; json_string "$EXPECTED_SETTINGS_HASH"; fi
  printf '}\n'
}

run_wp_bridge() {
  # No token in command arguments, environment, files readable by other users,
  # or installer output. The bridge never boots WordPress as root.
  BRIDGE_RESULT=$(emit_bridge_input "$1" "${2:-}" "${3:-}" | runuser -u "$WP_USER" -- "$PHP_BIN" "$BRIDGE_PATH") || {
    local bridge_code
    bridge_code=$(printf '%s' "$BRIDGE_RESULT" | "$NODE_BIN" -e 'let s="";process.stdin.on("data",c=>s+=c);process.stdin.on("end",()=>{let e="unknown_bridge_error";try{const v=JSON.parse(s).error;if(typeof v==="string"&&/^[a-z_]{1,80}$/.test(v))e=v}catch{}process.stdout.write(e)})')
    fail "WordPress connection step '$1' failed ($bridge_code). No credentials were printed."; return 1;
  }
  # A successful configure process has committed the database change. No later
  # output/validation/cleanup error may roll back only the runner's credentials.
  if [[ $1 == configure ]]; then COMMITTED=true; ACTIVATING=false; fi
  printf '%s' "$BRIDGE_RESULT" | "$NODE_BIN" -e 'let s="";process.stdin.on("data",c=>s+=c);process.stdin.on("end",()=>{try{if(JSON.parse(s).ok!==true)process.exit(1)}catch{process.exit(1)}})' || { fail 'WordPress bridge did not return a successful response.'; return 1; }
}

prepare_credentials() {
  install -d -o "$WP_USER" -g "$(id -gn "$WP_USER")" -m 0700 "$WORK_DIR/handoff"
  install -o "$WP_USER" -g "$(id -gn "$WP_USER")" -m 0600 /dev/null "$WORK_DIR/handoff/token"
  install -o "$WP_USER" -g "$(id -gn "$WP_USER")" -m 0600 /dev/null "$WORK_DIR/handoff/wordpress-option.json"
  run_wp_bridge prepare "$WORK_DIR/handoff/token" "$WORK_DIR/handoff/wordpress-option.json"
  EXPECTED_SETTINGS_HASH=$(printf '%s' "$BRIDGE_RESULT" | "$NODE_BIN" -e 'let s="";process.stdin.on("data",c=>s+=c);process.stdin.on("end",()=>{try{const h=JSON.parse(s).expectedHash;if(!/^[a-f0-9]{64}$/.test(h))process.exit(1);process.stdout.write(h)}catch{process.exit(1)}})')
  local secret_fd
  exec {secret_fd}<"$WORK_DIR/handoff/token"
  [[ $(stat -Lc '%u:%a:%h' "/proc/$$/fd/$secret_fd") == "$(id -u "$WP_USER"):600:1" && -f /proc/$$/fd/$secret_fd ]] || { exec {secret_fd}<&-; fail 'Unsafe credential handoff file.'; return 1; }
  TOKEN=$(cat <&"$secret_fd")
  exec {secret_fd}<&-
  rm -f -- "$WORK_DIR/handoff/token"
  valid_token "$TOKEN" || { fail 'WordPress returned an unsupported runner credential format.'; return 1; }
  exec {secret_fd}<"$WORK_DIR/handoff/wordpress-option.json"
  [[ $(stat -Lc '%u:%a:%h' "/proc/$$/fd/$secret_fd") == "$(id -u "$WP_USER"):600:1" && -f /proc/$$/fd/$secret_fd ]] || { exec {secret_fd}<&-; fail 'Unsafe WordPress backup handoff file.'; return 1; }
  cat <&"$secret_fd" > "$BACKUP_DIR/wordpress-option.json"
  exec {secret_fd}<&-
  rm -f -- "$WORK_DIR/handoff/wordpress-option.json"
  # The bridge hashes compact JSON without its trailing newline.
  local archived_hash
  archived_hash=$("$NODE_BIN" -e 'const fs=require("node:fs"),c=require("node:crypto");const s=fs.readFileSync(process.argv[1],"utf8").replace(/\n$/,"");process.stdout.write(c.createHash("sha256").update(s).digest("hex"))' "$BACKUP_DIR/wordpress-option.json")
  [[ $archived_hash == "$EXPECTED_SETTINGS_HASH" ]] || { fail 'WordPress backup does not match the prepared settings snapshot.'; return 1; }
}

source_digest() {
  (cd "$1" && find package.json package-lock.json Dockerfile .dockerignore src install-tools -type f -print0 | LC_ALL=C sort -z | xargs -0 sha256sum | sha256sum | cut -d' ' -f1)
}

stage_release() {
  local digest
  digest=$(source_digest "$SOURCE_DIR")
  SOURCE_DIGEST=$digest
  if [[ -e $RELEASE_DIR || -L $RELEASE_DIR ]]; then
    [[ -d $RUNNER_DIR && ! -L $RELEASE_DIR && -f $RELEASE_DIR/source.sha256 ]] || { fail 'Existing release directory is not installer-managed; it was not overwritten.'; return 1; }
    [[ -z $(find "$RELEASE_DIR" -xdev \( ! -user root -o \( ! -type l -a -perm /022 \) \) -print -quit) ]] || { fail 'Existing runner release is not protected by root ownership and permissions.'; return 1; }
    [[ $(cat "$RELEASE_DIR/source.sha256") == "$digest" && $(source_digest "$RUNNER_DIR") == "$digest" ]] || { fail 'This version already exists with different source. Install a new plugin version; no release was overwritten.'; return 1; }
    [[ -f $RUNNER_DIR/node_modules/pxt-microbit/built/target.json ]] || { fail 'Existing release dependencies are incomplete; restore that release before retrying.'; return 1; }
    log "Reusing protected release $VERSION."
    return
  fi
  install -d -m 0755 "$WORK_DIR/release/runner"
  local file
  for file in package.json package-lock.json Dockerfile .dockerignore; do install -m 0644 "$SOURCE_DIR/$file" "$WORK_DIR/release/runner/$file"; done
  cp -a -- "$SOURCE_DIR/src" "$SOURCE_DIR/install-tools" "$WORK_DIR/release/runner/"
  (
    cd "$WORK_DIR/release/runner"
    env PATH="$NODE_ROOT/bin:/usr/sbin:/usr/bin:/sbin:/bin" npm_config_cache="$WORK_DIR/npm-cache" "$NODE_ROOT/bin/npm" ci --omit=dev --ignore-scripts --no-audit --no-fund
  )
  printf '%s\n' "$digest" > "$WORK_DIR/release/source.sha256"
  chown -R root:root "$WORK_DIR/release"
  chmod -R go-w "$WORK_DIR/release"
  find "$WORK_DIR/release" -type d -exec chmod a+rx {} +
  find "$WORK_DIR/release" -type f -exec chmod a+r {} +
  mv -- "$WORK_DIR/release" "$RELEASE_DIR"
}

build_worker() {
  check_docker_query_safe || return 1
  if docker image inspect "$IMAGE" >/dev/null 2>&1; then
    local existing_digest
    existing_digest=$(docker image inspect --format '{{index .Config.Labels "org.oj-microbit.source-sha256"}}' "$IMAGE")
    [[ $existing_digest == "$SOURCE_DIGEST" ]] || { fail 'This image tag already exists with different or unknown source; it was not overwritten.'; return 1; }
    log "Reusing local worker image $IMAGE."
  else
    docker build --label "org.oj-microbit.source-sha256=$SOURCE_DIGEST" --tag "$IMAGE" "$RUNNER_DIR"
  fi
}

backup_configuration() {
  BACKUP_DIR=$INSTALL_ROOT/backups/$(date -u +%Y%m%dT%H%M%SZ)-$$
  secure_directory "$INSTALL_ROOT/backups" 0700
  install -d -m 0700 "$BACKUP_DIR"
  HAD_ENV=false HAD_UNIT=false WAS_ACTIVE=false WAS_ENABLED=false
  if [[ -f $ENV_FILE ]]; then cp -p -- "$ENV_FILE" "$BACKUP_DIR/runner.env"; HAD_ENV=true; fi
  if [[ -e $UNIT_FILE || -L $UNIT_FILE ]]; then
    [[ -f $UNIT_FILE && ! -L $UNIT_FILE && $(stat -c '%u' "$UNIT_FILE") == 0 ]] || { fail 'Existing service unit must be a root-owned regular file.'; return 1; }
    cp -p -- "$UNIT_FILE" "$BACKUP_DIR/oj-microbit-runner.service"; HAD_UNIT=true
  fi
  if systemctl is-active --quiet "$SERVICE_NAME"; then WAS_ACTIVE=true; fi
  if systemctl is-enabled --quiet "$SERVICE_NAME"; then WAS_ENABLED=true; fi
  systemctl show "$SERVICE_NAME" --property=WorkingDirectory --property=ExecStart --property=FragmentPath > "$BACKUP_DIR/previous-service.txt" 2>/dev/null || true
  printf 'Previous release directories are retained in %s.\n' "$INSTALL_ROOT" >> "$BACKUP_DIR/previous-service.txt"
}

activate_service() {
  check_port_available
  secure_directory "$ENV_DIR" 0700
  ACTIVATING=true
  {
    printf 'MICROBIT_RUNNER_HOST=127.0.0.1\nMICROBIT_RUNNER_PORT=%s\n' "$PORT"
    printf 'MICROBIT_RUNNER_TOKEN='; json_string "$TOKEN"; printf '\n'
    printf 'MICROBIT_RUNNER_MODE=docker\nMICROBIT_WORKER_IMAGE=%s\n' "$IMAGE"
  } > "$WORK_DIR/runner.env"
  install -o root -g root -m 0600 "$WORK_DIR/runner.env" "$ENV_FILE"
  cat > "$WORK_DIR/oj-microbit-runner.service" <<UNIT
[Unit]
Description=OJ micro:bit isolated grading service
After=network.target docker.service

[Service]
Type=simple
User=$SERVICE_USER
SupplementaryGroups=docker
WorkingDirectory=$RUNNER_DIR
EnvironmentFile=$ENV_FILE
Environment=PATH=$NODE_ROOT/bin:/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin
Environment=DOCKER_HOST=unix:///var/run/docker.sock
ExecStart=$NODE_BIN src/server.mjs
Restart=on-failure
RestartSec=3
TimeoutStopSec=10
NoNewPrivileges=true
PrivateTmp=true
ProtectSystem=strict
ProtectHome=true
UMask=0077

[Install]
WantedBy=multi-user.target
UNIT
  install -o root -g root -m 0644 "$WORK_DIR/oj-microbit-runner.service" "$UNIT_FILE"
  systemctl daemon-reload
  systemctl enable "$SERVICE_NAME" >/dev/null
  systemctl restart "$SERVICE_NAME"
}

probe_runner() {
  check_docker_query_safe || return 1
  printf '%s' "$TOKEN" | "$NODE_BIN" "$1/install-tools/runner-check.mjs" "$2" "$URL" "$EXPECTED_RUNTIME"
}

wait_for_health() {
  local attempt
  for attempt in {1..20}; do
    if systemctl is-active --quiet "$SERVICE_NAME" && probe_runner "$RUNNER_DIR" health >/dev/null 2>&1; then return; fi
    sleep 1
  done
  fail 'Runner did not become ready. Review journalctl -u oj-microbit-runner (do not publish credentials).'
}

rollback_configuration() {
  local failed=0
  log 'Restoring the previous runner environment and service configuration.'
  if $HAD_ENV; then cp -p -- "$BACKUP_DIR/runner.env" "$ENV_FILE" || failed=1; else rm -f -- "$ENV_FILE" || failed=1; fi
  if $HAD_UNIT; then cp -p -- "$BACKUP_DIR/oj-microbit-runner.service" "$UNIT_FILE" || failed=1; else rm -f -- "$UNIT_FILE" || failed=1; fi
  systemctl daemon-reload || failed=1
  if $WAS_ENABLED; then systemctl enable "$SERVICE_NAME" >/dev/null 2>&1 || failed=1; else systemctl disable "$SERVICE_NAME" >/dev/null 2>&1 || true; fi
  if $WAS_ACTIVE; then
    if docker_available_for_rollback; then systemctl restart "$SERVICE_NAME" || failed=1
    else
      systemctl stop "$SERVICE_NAME" || failed=1
      log 'Previous environment and unit were restored; the runner was stopped and restart was skipped because Docker is unavailable or its running service cannot be confirmed. Manual runner recovery is required.'
      failed=1
    fi
  else systemctl stop "$SERVICE_NAME" || failed=1
  fi
  return "$failed"
}

docker_available_for_rollback() {
  # Avoid querying a dormant socket-activated daemon, and never let a restored
  # legacy requirement activate the generic daemon beside a Snap Docker daemon.
  if grep -Eq '^[[:space:]]*(Requires|Wants|BindsTo|Upholds)=[^#]*docker[.]service([[:space:]]|$)' "$UNIT_FILE"; then
    systemctl is-active --quiet docker.service || return 1
  elif ! systemctl is-active --quiet docker.service && ! systemctl is-active --quiet snap.docker.dockerd.service; then
    return 1
  fi
  check_docker_query_safe || return 1
  docker info >/dev/null 2>&1
}

cleanup() {
  if [[ -n ${WORK_DIR:-} && $WORK_DIR == "$INSTALL_ROOT"/.install.* && -d $WORK_DIR && ! -L $WORK_DIR ]]; then rm -rf -- "$WORK_DIR"; fi
}

handle_failure() {
  local code=$1
  trap - ERR INT TERM
  set +e
  if ${ACTIVATING:-false} && ! ${COMMITTED:-false}; then
    rollback_configuration || log 'Automatic service rollback needs administrator attention; the backup is retained.'
  fi
  printf '[microbit] Failed during: %s. Exit %s.\n' "${CURRENT_STAGE:-preflight}" "$code" >&2
  [[ -z ${BACKUP_DIR:-} ]] || log "Configuration backup: $BACKUP_DIR (root only)."
  log 'Existing releases, installed OS prerequisites, and built Docker images were retained.'
  exit "$code"
}

check_installation() {
  check_node_runtime
  [[ -f $ENV_FILE && -n $TOKEN && -f $UNIT_FILE && -d $RUNNER_DIR ]] || { fail 'Runner installation is incomplete.'; return 1; }
  [[ -z $(find "$RELEASE_DIR" -xdev \( ! -user root -o \( ! -type l -a -perm /022 \) \) -print -quit) ]] || { fail 'Runner release ownership or permissions are unsafe.'; return 1; }
  systemctl is-active --quiet "$SERVICE_NAME" || { fail 'Runner systemd service is not active.'; return 1; }
  check_port_available
  check_disk_space
  check_docker_query_safe
  docker image inspect "$IMAGE" >/dev/null 2>&1 || { fail 'Local worker image is missing.'; return 1; }
  probe_runner "$RUNNER_DIR" health
  BRIDGE_PATH=$RUNNER_DIR/install-tools/configure-wordpress.php
  run_wp_bridge check
  log 'Read-only checks passed. No install/configuration changes or GPIO job were performed.'
}

main() {
  set +x
  set +a
  unset TOKEN MICROBIT_RUNNER_TOKEN
  set -Eeuo pipefail
  umask 077
  export PATH=/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin:/snap/bin
  unset NODE_OPTIONS NODE_PATH DOCKER_HOST DOCKER_CONTEXT DOCKER_CONFIG
  export DOCKER_HOST=unix:///var/run/docker.sock
  CURRENT_STAGE=preflight WORK_DIR='' BACKUP_DIR='' ACTIVATING=false COMMITTED=false
  parse_args "$@"
  if [[ $MODE == help ]]; then usage; return; fi
  check_source
  if [[ $MODE == dry-run ]]; then
    log "Plan only: Ubuntu 24.04 amd64/systemd; WordPress $WP_ROOT; port ${PORT:-existing-or-3037}."
    log "Install isolated Node $NODE_VERSION into $NODE_ROOT; Docker official repository only when Docker is absent."
    log "Stage root-owned runner $VERSION in $RELEASE_DIR; npm ci --ignore-scripts; build $IMAGE."
    log 'Reuse/generate credentials; backup environment/unit; start service; authenticated health and real GPIO canary; configure WordPress last.'
    log 'No files, packages, locks, services, WordPress settings, or network requests were changed.'
    return
  fi
  check_platform
  check_wordpress_files
  if [[ $MODE == check ]]; then read_environment; check_installation; return; fi
  acquire_lock
  read_environment
  detect_docker_policy
  check_port_available
  trap 'handle_failure $?' ERR
  trap 'handle_failure 130' INT
  trap 'handle_failure 143' TERM
  trap cleanup EXIT
  secure_directory "$INSTALL_ROOT" 0755
  WORK_DIR=$(mktemp -d "$INSTALL_ROOT/.install.XXXXXXXX")
  chmod 0711 "$WORK_DIR"
  install -m 0644 "$SOURCE_DIR/install-tools/configure-wordpress.php" "$WORK_DIR/configure-wordpress.php"
  install -m 0644 "$SOURCE_DIR/install-tools/WordPressConfigurator.php" "$WORK_DIR/WordPressConfigurator.php"
  BRIDGE_PATH=$WORK_DIR/configure-wordpress.php
  stage '1/8 Preparing verified local prerequisites and dedicated Node.'
  ensure_tools
  ensure_node
  stage '2/8 Checking the active single-site WordPress installation.'
  run_wp_bridge inspect
  backup_configuration
  prepare_credentials
  stage '3/8 Checking Docker and the isolated service account.'
  ensure_docker
  check_disk_space
  ensure_service_user
  stage '4/8 Staging a protected immutable runner release.'
  stage_release
  stage '5/8 Building the pinned worker image.'
  build_worker
  stage '6/8 Backing up and activating the systemd service.'
  activate_service
  stage '7/8 Verifying authenticated health and real GPIO grading.'
  wait_for_health
  probe_runner "$RUNNER_DIR" canary
  stage '8/8 Connecting WordPress (final configuration step).'
  run_wp_bridge configure
  ACTIVATING=false
  log "Installed runner $VERSION at $URL; WordPress connection and GPIO canary passed."
  log "Previous configuration: $BACKUP_DIR. Previous releases were preserved."
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then main "$@"; fi
