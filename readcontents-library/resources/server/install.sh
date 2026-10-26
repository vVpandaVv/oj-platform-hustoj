#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
trap 'printf "Installation stopped at line %s. Resolve the error and rerun the same command.\n" "$LINENO" >&2' ERR

fail() { printf '%s\n' "$*" >&2; exit 1; }
[[ $# == 4 && $4 == --apply ]] || fail 'Usage: sudo bash install.sh readcontents /absolute/wordpress PHP_VERSION --apply'
task=$1
root_arg=$2
version=$3
case "$task" in readcontents) ;; *) fail 'Unknown installation task.' ;; esac
[[ $version =~ ^8\.[0-9]+$ ]] || fail 'PHP version must be 8.x (the web PHP version).'
[[ $(uname -s) == Linux && $EUID == 0 ]] || fail 'Run with sudo on the WordPress Linux server.'
[[ -f /etc/debian_version ]] || fail 'This installer supports Ubuntu/Debian.'
[[ $root_arg == /* && $root_arg != *$'\n'* ]] || fail 'An absolute WordPress path is required.'
root=$(realpath -e -- "$root_arg")
[[ -f $root/wp-settings.php && -f $root/wp-config.php && ! -L $root/wp-config.php ]] || fail 'WordPress root or regular wp-config.php not found.'
id www-data >/dev/null 2>&1 || fail 'www-data was not found.'
command -v flock >/dev/null || fail 'flock is required (util-linux).'
exec 9>/run/oj-server-prepare.lock
flock -n 9 || fail 'Another server preparation is running. Try again later.'

script_dir=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
base=$(dirname -- "$root")
[[ $root != /home/judge/src/web ]] || base=/home/judge
directory=$(dirname -- "$root")/readcontents-imports

if [[ -n $directory ]]; then
  [[ -d $(dirname -- "$directory") && ! -L $directory ]] || fail "Storage parent missing or path is a symlink: $directory"
  [[ ! -e $directory || -d $directory ]] || fail 'Storage path is not a directory.'
  resolved=$(realpath -m -- "$directory")
  [[ $resolved != "$root" && $resolved != "$root/"* ]] || fail 'Private storage must be outside the web root.'
  mkdir -p -- "$directory"
  chown www-data:www-data -- "$directory"
  chmod 0700 -- "$directory"
fi

printf 'Preparation complete. Refresh the installation screen and check the plugin status.\n'
