#!/usr/bin/env bash
# Non-privileged fixture only: production paths are all redirected before main.
set -Eeuo pipefail
source "$1"
CASE_ROOT=$(cd "$2" && pwd -P)
REAL_NODE=$3
ACTION=$4
REAL_PREREQUISITES=false
if [[ $ACTION == coexist-* ]]; then REAL_PREREQUISITES=true; ACTION=${ACTION#coexist-}; fi
export CASE_ROOT REAL_NODE
INSTALL_ROOT=$CASE_ROOT/opt/oj-microbit
NODE_ROOT=$CASE_ROOT/opt/node
ENV_DIR=$CASE_ROOT/etc/oj-microbit
UNIT_FILE=$CASE_ROOT/etc/systemd/system/oj-microbit-runner.service
LOCK_FILE=$CASE_ROOT/run/lock/install.lock
PHP_BIN=$CASE_ROOT/php
for name in INSTALL_ROOT NODE_ROOT ENV_DIR UNIT_FILE LOCK_FILE PHP_BIN; do
  [[ ${!name} == "$CASE_ROOT"/* ]] || exit 98
done
TRACE=$CASE_ROOT/commands.log
export TRACE

# User and system services are boundary doubles; all staging/backup/activation/
# rollback/argument parsing/key reuse functions execute from the real installer.
check_platform() { printf 'platform\n' >> "$TRACE"; }
check_wordpress_files() { WP_ROOT=$CASE_ROOT/wp; WP_USER=wordpress; }
check_source() {
  SOURCE_DIR=$CASE_ROOT/source
  VERSION=$(cat "$CASE_ROOT/version")
  RELEASE_DIR=$INSTALL_ROOT/$VERSION
  RUNNER_DIR=$RELEASE_DIR/runner
  IMAGE=oj-microbit-worker:$VERSION
  NODE_BIN=$NODE_ROOT/bin/node
  ENV_FILE=$ENV_DIR/runner.env
}
if ! $REAL_PREREQUISITES; then
  ensure_tools() { printf 'tools\n' >> "$TRACE"; }
  ensure_docker() { printf 'docker-prerequisite\n' >> "$TRACE"; }
fi
ensure_node() { printf 'node\n' >> "$TRACE"; }
ensure_service_user() { printf 'service-user\n' >> "$TRACE"; }
check_local_docker_socket() { printf 'socket:local-docker\n' >> "$TRACE"; [[ ${FAIL_STAGE:-} != socket ]] || fail 'A local Docker Engine socket is required.'; }
check_ca_bundle() { printf 'ca-bundle:read-only\n' >> "$TRACE"; [[ ${FAIL_STAGE:-} != ca-bundle ]] || fail 'Existing Docker is preserved; the system CA bundle is missing.'; }
command() {
  if [[ ${1:-} == -v && ${2:-} == docker && ${MISSING_DOCKER_CLI:-} == 1 ]]; then return 1; fi
  if [[ ${1:-} == -v && ${2:-} == "${MISSING_TOOL:-}" && -n ${MISSING_TOOL:-} ]]; then return 1; fi
  builtin command "$@"
}
dpkg-query() {
  printf 'dpkg-query:%s\n' "$*" >> "$TRACE"
  [[ ${MISSING_TOOL:-} != "${!#}" ]] || return 1
  printf 'install ok installed\n'
}
apt-get() { printf 'FORBIDDEN:apt-get:%s\n' "$*" >> "$TRACE"; return 95; }
curl() { printf 'FORBIDDEN:curl:%s\n' "$*" >> "$TRACE"; return 95; }
xz() { printf 'FORBIDDEN:xz:%s\n' "$*" >> "$TRACE"; return 95; }
https_download() { printf 'FORBIDDEN:download:%s\n' "$1" >> "$TRACE"; return 95; }
ss() {
  printf 'ss:%s\n' "$*" >> "$TRACE"
  [[ ${FAIL_STAGE:-} != ss ]] || return 1
  local owner=${PORT_OWNER:-free}
  if [[ $owner == race ]]; then
    local checks=0
    [[ ! -f $CASE_ROOT/port-checks ]] || checks=$(cat "$CASE_ROOT/port-checks")
    checks=$((checks+1)); printf '%s' "$checks" > "$CASE_ROOT/port-checks"
    if ((checks > 1)); then owner=foreign; else owner=free; fi
  fi
  case $owner in
    free) :;;
    foreign) printf 'LISTEN 0 4096 0.0.0.0:3037 0.0.0.0:* users:(("hustoj-web",pid=8888,fd=5))\n';;
    self) printf 'LISTEN 0 511 127.0.0.1:3037 0.0.0.0:* users:(("node",pid=12345,fd=18))\n';;
    mixed) printf 'LISTEN 0 511 127.0.0.1:3037 0.0.0.0:* users:(("node",pid=12345,fd=18))\nLISTEN 0 511 [::]:3037 [::]:* users:(("hustoj-web",pid=8888,fd=5))\n';;
    unknown) printf 'LISTEN 0 4096 0.0.0.0:3037 0.0.0.0:*\n';;
    *) return 94;;
  esac
}
df() {
  printf 'df:%s\n' "$*" >> "$TRACE"
  local available=9000000
  [[ ${FAIL_STAGE:-} != disk ]] || available=1000
  if [[ ${FAIL_STAGE:-} == docker-disk && $* == *hustoj/docker-root* ]]; then available=1000; fi
  printf 'Filesystem 1024-blocks Used Available Capacity Mounted on\nfixture 10000000 1000000 %s 10%% /\n' "$available"
}
prepare_credentials() {
  [[ -n $TOKEN ]] || TOKEN=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa
  printf '{"settings":"previous"}\n' > "$BACKUP_DIR/wordpress-option.json"
}
run_wp_bridge() {
  printf 'bridge:%s\n' "$1" >> "$TRACE"
  if [[ $1 == configure && ${FAIL_STAGE:-} == wordpress ]]; then return 42; fi
  [[ $1 != configure ]] || printf '%s\n' "$URL" > "$CASE_ROOT/wordpress-setting"
}
probe_runner() {
  printf 'probe:%s\n' "$2" >> "$TRACE"
  if [[ $2 == canary && ${FAIL_STAGE:-} == legacy-docker-unavailable ]]; then : > "$CASE_ROOT/docker-unavailable"; return 1; fi
  [[ $2 != canary || ${FAIL_STAGE:-} != canary ]]
}
wait_for_health() { probe_runner "$RUNNER_DIR" health; }

stat() {
  # Windows filesystems do not implement root POSIX ownership. Actual mode and
  # owner enforcement must also pass on the target Ubuntu host.
  if [[ $1 == -c && $2 == %u ]]; then printf '0\n';
  elif [[ $1 == -c && $2 == %a ]]; then printf '600\n';
  else command stat "$@"; fi
}
find() {
  if [[ $* == *'! -user root'* ]]; then return 0; fi
  if [[ $* == *'-exec chmod '* ]]; then return 0; fi
  command find "$@"
}
chown() { printf 'chown\n' >> "$TRACE"; }
chmod() { printf 'chmod:%s\n' "$*" >> "$TRACE"; }
install() {
  local args=() directory=false
  while (($#)); do
    case "$1" in -o|-g|-m) shift 2;; -d) directory=true; shift;; --) shift;; *) args+=("$1"); shift;; esac
  done
  local destination
  if $directory; then
    for destination in "${args[@]}"; do [[ $destination == "$CASE_ROOT"/* ]] || { printf 'FORBIDDEN:install-outside-fixture\n' >> "$TRACE"; return 96; }; done
  else
    destination=${args[${#args[@]}-1]}
    [[ $destination == "$CASE_ROOT"/* ]] || { printf 'FORBIDDEN:install-outside-fixture\n' >> "$TRACE"; return 96; }
  fi
  if $directory; then command mkdir -p -- "${args[@]}"; else command cp -- "${args[@]}"; fi
}
flock() { printf 'flock:%s\n' "$*" >> "$TRACE"; [[ ${FAIL_STAGE:-} != lock ]]; }
docker() {
  printf 'docker:%s\n' "$*" >> "$TRACE"
  case "$1" in
    info)
      if [[ ${DORMANT_DOCKER:-} == 1 ]]; then printf 'FORBIDDEN:dormant-daemon-query\n' >> "$TRACE"; return 95; fi
      [[ ${FAIL_STAGE:-} != docker-info ]] || return 1
      [[ ! -f $CASE_ROOT/docker-unavailable ]] || return 1
      [[ $* != *DockerRootDir* ]] || printf '%s\n' "$CASE_ROOT/hustoj/docker-root";;
    image) [[ -f $CASE_ROOT/image-$VERSION ]] || return 1; [[ $* != *--format* ]] || cat "$CASE_ROOT/image-$VERSION";;
    build) [[ ${FAIL_STAGE:-} != build ]] || return 44; printf '%s' "$SOURCE_DIGEST" > "$CASE_ROOT/image-$VERSION";;
    *) printf 'FORBIDDEN:docker:%s\n' "$*" >> "$TRACE"; return 90;;
  esac
}
systemctl() {
  printf 'systemctl:%s\n' "$*" >> "$TRACE"
  if [[ $* == *docker.service* || $* == *docker.socket* || $* == *snap.docker.dockerd.service* || $* == *hustoj* ]]; then
    if [[ $1 == is-active ]]; then
      if [[ $* == *docker.socket* ]]; then
        [[ ${FAIL_STAGE:-} != socket-status ]] || return 127
        [[ ${DORMANT_DOCKER:-} == 1 ]] && return 0
      elif [[ $* != *snap.docker.dockerd.service* ]]; then
        [[ ${FAIL_STAGE:-} != docker-status ]] || return 127
        [[ ${DORMANT_DOCKER:-} != 1 && ${DOCKER_GENERIC_INACTIVE:-} != 1 && ! -f $CASE_ROOT/docker-unavailable ]] && return 0
      fi
      return 3
    fi
    printf 'FORBIDDEN:foreign-service:%s\n' "$*" >> "$TRACE"; return 95
  fi
  case "$1" in
    is-active) [[ -f $CASE_ROOT/active ]] && return 0; return 3;;
    is-enabled) [[ -f $CASE_ROOT/enabled ]];;
    show)
      if [[ $* == *MainPID* ]]; then
        if [[ -f $CASE_ROOT/active ]]; then printf '12345\n'; else printf '0\n'; fi
      else printf 'WorkingDirectory=previous-release\n'; fi;;
    enable) : > "$CASE_ROOT/enabled";;
    disable) rm -f "$CASE_ROOT/enabled";;
    restart)
      if [[ ${FAIL_STAGE:-} == restart && ! -f $CASE_ROOT/restart-failed ]]; then : > "$CASE_ROOT/restart-failed"; return 43; fi
      : > "$CASE_ROOT/active";;
    stop) rm -f "$CASE_ROOT/active";;
    daemon-reload) :;;
    *) return 90;;
  esac
}

case "$ACTION" in
  install) main --wp-root "$CASE_ROOT/wp";;
  port) main --wp-root "$CASE_ROOT/wp" --port 4040;;
  dry-run) main --wp-root "$CASE_ROOT/wp" --dry-run;;
  check) main --wp-root "$CASE_ROOT/wp" --check;;
  *) exit 91;;
esac
