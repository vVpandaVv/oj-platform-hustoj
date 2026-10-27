#!/usr/bin/env bash
# Apply a released OJ Platform ZIP to an existing WordPress site.
set +x
set -Eeuo pipefail
umask 077

UPDATE_SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
# The installer exposes validation and WP-CLI helpers without running main().
# shellcheck source=ubuntu24.sh
source "$UPDATE_SCRIPT_DIR/ubuntu24.sh"

U_STATE=/var/lib/oj-platform-updater
U_BACKUP_PARENT=/var/backups/oj-platform/updates
U_PLUGIN_DIR=$WEB_ROOT/wp-content/plugins/oj-platform
U_TIMER=oj-wordpress-cron.timer
U_SERVICE=oj-wordpress-cron.service
U_REQUESTED_ZIP= U_CACHED_ZIP= U_SHA= U_BACKUP= U_OLD_VERSION= U_NEW_VERSION=
U_MIN_PHP= U_MIN_WP= U_SITE_URL= U_HANDOFF=
U_TIMER_WAS_ACTIVE=0 U_PENDING_VALID=0 U_PHASE= U_SUCCEEDED=0

update_usage() {
    cat <<'TEXT'
sudo bash update.sh [--plugin-zip PATH]
  --plugin-zip PATH  Use a released OJ Platform ZIP and its .zip.sha256 file.
  --help, -h        Show this help without changing the site.

Backs up the WordPress database, wp-config.php and OJ Platform files,
then applies the release and its schema updates. Rerun the same command
to continue a pending update using its saved release.
TEXT
}

update_check_versions() {
    "$PHP" -r '
[$old,$new,$php,$minPhp,$wp,$minWp]=array_slice($argv,1);
foreach ([$old,$new,$php,$minPhp,$wp,$minWp] as $version) {
    if (!preg_match("/^[0-9]+(?:\\.[0-9]+){1,3}(?:[-+][A-Za-z0-9.-]+)?$/",$version)) {
        fwrite(STDERR,"Invalid release or runtime version.\n"); exit(1);
    }
}
if (version_compare($php,$minPhp,"<") || version_compare($wp,$minWp,"<")) {
    fwrite(STDERR,"This release requires a newer PHP or WordPress version.\n"); exit(1);
}
if (version_compare($new,$old,"<")) { fwrite(STDERR,"Refusing to downgrade OJ Platform.\n"); exit(1); }
echo version_compare($new,$old,"=") ? "same\n" : "upgrade\n";
' "$@"
}

update_phase_is_applying() {
    [[ $U_PHASE == applying || $U_PHASE == applied || $U_PHASE == verified ]]
}

update_maintenance_text() {
    printf '<?php\n// OJ_PLATFORM_UPDATER_MAINTENANCE_V1\n$upgrading = time();\n'
}

update_owns_maintenance() {
    [[ -f $WEB_ROOT/.maintenance && ! -L $WEB_ROOT/.maintenance ]] &&
        cmp -s -- "$WEB_ROOT/.maintenance" <(update_maintenance_text)
}

update_set_maintenance() {
    if [[ -e $WEB_ROOT/.maintenance || -L $WEB_ROOT/.maintenance ]]; then
        if ! update_owns_maintenance; then
            printf 'Another maintenance operation is active.\n' >&2
            return 1
        fi
    else
        (umask 022; set -o noclobber; update_maintenance_text > "$WEB_ROOT/.maintenance")
    fi
}

update_remove_maintenance() {
    if [[ -e $WEB_ROOT/.maintenance || -L $WEB_ROOT/.maintenance ]]; then
        update_owns_maintenance || return 1
        rm -f -- "$WEB_ROOT/.maintenance"
    fi
}

update_assert_private_directory() {
    local path=$1
    [[ -d $path && ! -L $path && $(realpath -e -- "$path") == "$path" && $(stat -c '%u:%a' "$path") == 0:700 ]] ||
        fail "Expected a root-owned private directory: $path"
}

update_save_pending() {
    local candidate key
    candidate=$(mktemp -- "$U_STATE/.pending.XXXXXXXX") || return
    for key in U_CACHED_ZIP U_SHA U_BACKUP U_OLD_VERSION U_NEW_VERSION U_TIMER_WAS_ACTIVE U_PHASE U_SITE_URL; do
        if ! printf '%s=%q\n' "$key" "${!key}" >> "$candidate"; then rm -f -- "$candidate"; return 1; fi
    done
    if ! chmod 600 "$candidate" || ! mv -f -- "$candidate" "$U_STATE/pending.env"; then
        rm -f -- "$candidate"
        return 1
    fi
    U_PENDING_VALID=1
}

update_load_pending() {
    local pending=$U_STATE/pending.env
    [[ -f $pending && ! -L $pending && $(stat -c '%u:%a' "$pending") == 0:600 && $(stat -c '%s' "$pending") -le 8192 ]] ||
        fail 'The pending update state is not trusted.'
    # This private file is written exclusively by update_save_pending().
    # shellcheck disable=SC1090
    source "$pending"
    [[ $U_CACHED_ZIP == "$U_STATE/oj-platform.zip" && $U_SHA =~ ^[0-9a-f]{64}$ && $U_TIMER_WAS_ACTIVE =~ ^[01]$ ]] ||
        fail 'The pending update state is invalid.'
    [[ $U_PHASE == preparing ]] || update_phase_is_applying || fail 'The pending update phase is invalid.'
    [[ $U_BACKUP == "$U_BACKUP_PARENT/"* && ${U_BACKUP#"$U_BACKUP_PARENT/"} != */* ]] || fail 'The saved backup path is invalid.'
    update_assert_private_directory "$U_BACKUP"
    U_PENDING_VALID=1
}

update_read_package_metadata() {
    local -a fields=()
    mapfile -t fields < <(python3 - "$PLUGIN_ZIP" <<'PY'
import re,sys,zipfile
with zipfile.ZipFile(sys.argv[1]) as bundle:
    header=bundle.read('oj-platform/oj-platform.php').decode('utf-8')
values=[]
for field in ('Version','Requires PHP','Requires at least'):
    match=re.search(r'^\s*\*\s*'+re.escape(field)+r':\s*([^\s*]+)\s*$',header,re.M)
    if not match: sys.exit('The release is missing required version metadata.')
    values.append(match.group(1))
print('\n'.join(values))
PY
    )
    ((${#fields[@]} == 3)) || fail 'Could not read release requirements.'
    U_NEW_VERSION=${fields[0]} U_MIN_PHP=${fields[1]} U_MIN_WP=${fields[2]}
}

update_prepare_handoff() {
    U_HANDOFF=$(mktemp -d /run/oj-platform-update.XXXXXXXX)
    [[ $U_HANDOFF == /run/oj-platform-update.* && ! -L $U_HANDOFF ]] || fail 'Unexpected update handoff path.'
    chown root:www-data "$U_HANDOFF"
    chmod 750 "$U_HANDOFF"
    install -o root -g www-data -m 640 "$UPDATE_SCRIPT_DIR/update-platform.php" "$U_HANDOFF/update-platform.php"
    if [[ -n $U_CACHED_ZIP ]]; then
        install -o root -g www-data -m 640 "$U_CACHED_ZIP" "$U_HANDOFF/oj-platform.zip"
    fi
}

update_stop_timer() {
    local attempt load state
    load=$(systemctl show --property=LoadState --value "$U_TIMER") || [[ $load == not-found ]] || return
    [[ -n $load ]] || fail 'Could not inspect the WordPress task timer.'
    if [[ $load != not-found ]]; then systemctl stop "$U_TIMER" || return; fi
    load=$(systemctl show --property=LoadState --value "$U_SERVICE") || [[ $load == not-found ]] || return
    [[ -n $load ]] || fail 'Could not inspect the WordPress task service.'
    [[ $load != not-found ]] || return 0
    for ((attempt=0; attempt<60; attempt++)); do
        state=$(systemctl show --property=ActiveState --value "$U_SERVICE") || return
        case "$state" in
            activating|active|reloading|deactivating) sleep 1 ;;
            inactive|failed) return 0 ;;
            *) fail 'Could not inspect the scheduled WordPress task state.' ;;
        esac
    done
    fail 'A scheduled WordPress task is still running. Retry after it finishes.'
}

update_restore_timer() {
    if [[ $U_TIMER_WAS_ACTIVE == 1 ]]; then systemctl start "$U_TIMER"; fi
}

update_back_up() {
    printf 'Backing up to %s\n' "$U_BACKUP"
    wp_site --skip-plugins --skip-themes db export - --single-transaction --quick --skip-lock-tables --no-tablespaces --quiet |
        gzip > "$U_BACKUP/wordpress.sql.gz"
    [[ -s $U_BACKUP/wordpress.sql.gz ]]
    gzip -t "$U_BACKUP/wordpress.sql.gz"
    tar --acls --xattrs -czf "$U_BACKUP/oj-platform.tar.gz" -C "$WEB_ROOT/wp-content/plugins" oj-platform
    gzip -t "$U_BACKUP/oj-platform.tar.gz"
    install -o root -g root -m 600 "$WEB_ROOT/wp-config.php" "$U_BACKUP/wp-config.php"
    printf 'Previous version: %s\nTarget version: %s\nPackage SHA-256: %s\n' "$U_OLD_VERSION" "$U_NEW_VERSION" "$U_SHA" > "$U_BACKUP/update.txt"
}

update_check_http() {
    local -a parts=()
    local status
    mapfile -t parts < <(python3 - "$U_SITE_URL" <<'PY'
import sys,urllib.parse
p=urllib.parse.urlsplit(sys.argv[1])
if p.scheme not in ('http','https') or not p.hostname or p.username or p.password or p.query or p.fragment:
    sys.exit('The existing site URL is not supported by the local HTTP check.')
try: port=p.port or (443 if p.scheme=='https' else 80)
except ValueError: sys.exit(1)
if not 1<=port<=65535 or ':' in p.hostname: sys.exit(1)
print(p.hostname+':'+str(port)+':127.0.0.1')
print(sys.argv[1].rstrip('/')+'/login')
PY
    )
    ((${#parts[@]} == 2)) || fail 'Could not prepare the existing site URL for verification.'
    status=$(curl --fail --silent --show-error --connect-timeout 10 --max-time 30 \
        --resolve "${parts[0]}" --output /dev/null --write-out '%{http_code}' "${parts[1]}")
    [[ $status == 200 ]] || fail 'The updated login page did not return HTTP 200.'
}

update_cleanup() {
    local status=$1
    set +e
    if ((status != 0 && U_PENDING_VALID)); then
        if update_phase_is_applying; then
            systemctl stop "$U_TIMER" 2>/dev/null
            update_set_maintenance
            printf '\nUpdate interrupted. Backup: %s\nRerun the update command to continue the saved release.\n' "$U_BACKUP" >&2
        else
            if update_remove_maintenance && update_restore_timer; then
                rm -f -- "$U_STATE/pending.env"
            else
                printf 'Previous maintenance or task state could not be restored; pending update retained.\n' >&2
            fi
            printf '\nUpdate stopped before replacing platform files. Backup: %s\n' "$U_BACKUP" >&2
        fi
    fi
    if [[ -n $U_HANDOFF && $U_HANDOFF == /run/oj-platform-update.* && -d $U_HANDOFF && ! -L $U_HANDOFF ]]; then
        rm -f -- "$U_HANDOFF/oj-platform.zip" "$U_HANDOFF/update-platform.php"
        rmdir -- "$U_HANDOFF"
    fi
    return "$status"
}

update_stages() {
    assert_web_root
    [[ -f $WEB_ROOT/wp-config.php && ! -L $WEB_ROOT/wp-config.php ]] || fail 'An existing WordPress site is required.'
    [[ -d $WEB_ROOT/wp-content/plugins && ! -L $WEB_ROOT/wp-content/plugins && $(realpath -e -- "$WEB_ROOT/wp-content/plugins") == "$WEB_ROOT/wp-content/plugins" ]] || fail 'Unexpected WordPress plugin directory.'
    [[ $U_PLUGIN_DIR == "$WEB_ROOT/wp-content/plugins/oj-platform" && ! -L $U_PLUGIN_DIR ]] || fail 'Unexpected OJ Platform directory.'
    [[ -x $PHP && -f $WP && -f $UPDATE_SCRIPT_DIR/update-platform.php ]] || fail 'The installed PHP, WP-CLI or update helper is missing.'
    wp_site --skip-plugins --skip-themes core is-installed
    [[ ! -L /var/lib && $(realpath -e -- /var/lib) == /var/lib ]] || fail 'Unexpected updater state parent.'
    if [[ -e $U_STATE || -L $U_STATE ]]; then update_assert_private_directory "$U_STATE";
    else install -d -o root -g root -m 700 "$U_STATE"; fi
    local resuming=0 current_version current_php current_wp decision saved_version timer_load timer_state
    if [[ -e $U_STATE/pending.env || -L $U_STATE/pending.env ]]; then
        [[ -z $U_REQUESTED_ZIP ]] || fail 'A pending update exists. Rerun without --plugin-zip.'
        update_load_pending
        resuming=1
    fi
    if [[ -e $U_PLUGIN_DIR ]]; then
        [[ -d $U_PLUGIN_DIR && $(realpath -e -- "$U_PLUGIN_DIR") == "$U_PLUGIN_DIR" && -z $(find "$U_PLUGIN_DIR" -type l -print -quit) ]] || fail 'Platform files must stay inside the WordPress plugin directory.'
    fi
    if ((resuming)) && update_phase_is_applying; then
        current_version=$(wp_site --skip-plugins --skip-themes plugin get oj-platform --field=version 2>/dev/null) || current_version=$U_OLD_VERSION
        [[ $current_version =~ ^[0-9]+([.][0-9]+){1,3}([-+][a-zA-Z0-9.-]+)?$ ]] || current_version=$U_OLD_VERSION
    else
        [[ -d $U_PLUGIN_DIR ]] || fail 'An installed OJ Platform plugin is required.'
        wp_site --skip-plugins --skip-themes plugin is-active oj-platform
        current_version=$(wp_site --skip-plugins --skip-themes plugin get oj-platform --field=version)
    fi
    current_php=$("$PHP" -r 'echo PHP_VERSION;')
    current_wp=$(wp_site --skip-plugins --skip-themes core version)
    if ((resuming)); then
        saved_version=$U_NEW_VERSION
        PLUGIN_ZIP=$U_CACHED_ZIP
        validate_package
        [[ $PLUGIN_SHA == "$U_SHA" ]] || fail 'The pending release checksum changed.'
        update_read_package_metadata
        [[ $U_NEW_VERSION == "$saved_version" ]] || fail 'The pending release version changed.'
    else
        [[ ! -e $WEB_ROOT/.maintenance && ! -L $WEB_ROOT/.maintenance ]] || fail 'Another maintenance operation is active.'
        PLUGIN_ZIP=$U_REQUESTED_ZIP
        if [[ -z $PLUGIN_ZIP ]]; then
            PLUGIN_ZIP=$(find "$REPOSITORY/_releases/oj-platform" -mindepth 2 -maxdepth 2 -type f -name 'oj-platform-*.zip' | sort -V | tail -n 1)
        fi
        [[ -n $PLUGIN_ZIP ]] || fail 'An OJ Platform release ZIP is required.'
        validate_package
        update_read_package_metadata
        U_OLD_VERSION=$current_version
        U_SITE_URL=$(wp_site --skip-plugins --skip-themes option get siteurl)
    fi
    decision=$(update_check_versions "$current_version" "$U_NEW_VERSION" "$current_php" "$U_MIN_PHP" "$current_wp" "$U_MIN_WP")
    update_prepare_handoff
    if ((!resuming)) || ! update_phase_is_applying; then
        wp_site eval-file "$U_HANDOFF/update-platform.php" check --use-include
    fi
    if ((!resuming)) && [[ $decision == same ]]; then
        printf 'OJ Platform %s is already up to date.\n' "$current_version"
        return 0
    fi
    if ((!resuming)); then
        U_SHA=$PLUGIN_SHA U_CACHED_ZIP=$U_STATE/oj-platform.zip
        [[ ! -L $U_CACHED_ZIP && ! -L $U_CACHED_ZIP.sha256 ]] || fail 'Unexpected release cache link.'
        if [[ $(realpath -e -- "$PLUGIN_ZIP") != "$U_CACHED_ZIP" ]]; then
            install -o root -g root -m 600 "$PLUGIN_ZIP" "$U_CACHED_ZIP"
            install -o root -g root -m 600 "$PLUGIN_ZIP.sha256" "$U_CACHED_ZIP.sha256"
        fi
        PLUGIN_ZIP=$U_CACHED_ZIP
        validate_package
        [[ $PLUGIN_SHA == "$U_SHA" ]] || fail 'The release changed while being cached.'
        local directory
        for directory in /var /var/backups /var/backups/oj-platform; do
            [[ ! -L $directory ]] || fail 'Unexpected backup parent link.'
            [[ -d $directory ]] || install -d -o root -g root -m 700 "$directory"
            [[ $(realpath -e -- "$directory") == "$directory" && $(stat -c '%u' "$directory") == 0 ]] || fail 'Unexpected backup directory owner.'
        done
        [[ ! -L $U_BACKUP_PARENT ]] || fail 'Unexpected backup directory link.'
        install -d -o root -g root -m 700 "$U_BACKUP_PARENT"
        update_assert_private_directory "$U_BACKUP_PARENT"
        U_BACKUP=$(mktemp -d "$U_BACKUP_PARENT/$(date -u +%Y%m%dT%H%M%SZ)-XXXXXXXX")
        timer_load=$(systemctl show --property=LoadState --value "$U_TIMER") || [[ $timer_load == not-found ]] || return
        [[ -n $timer_load ]] || fail 'Could not inspect the WordPress task timer.'
        if [[ $timer_load != not-found ]]; then
            timer_state=$(systemctl show --property=ActiveState --value "$U_TIMER")
            case "$timer_state" in
                active|activating|reloading) U_TIMER_WAS_ACTIVE=1 ;;
                inactive|failed|deactivating) U_TIMER_WAS_ACTIVE=0 ;;
                *) fail 'Could not inspect the WordPress task timer state.' ;;
            esac
        fi
        U_PHASE=preparing
        update_save_pending
        install -o root -g www-data -m 640 "$U_CACHED_ZIP" "$U_HANDOFF/oj-platform.zip"
    fi
    update_set_maintenance
    update_stop_timer
    if ! update_phase_is_applying; then
        [[ $current_version == "$U_OLD_VERSION" ]] || fail 'Platform files changed since the pending update was prepared.'
        update_back_up
        U_PHASE=applying
        if ! update_save_pending; then U_PHASE=preparing; return 1; fi
    fi
    printf 'Updating OJ Platform %s -> %s\n' "$U_OLD_VERSION" "$U_NEW_VERSION"
    wp_site --skip-plugins --skip-themes plugin install "$U_HANDOFF/oj-platform.zip" --force --activate --quiet
    update_set_maintenance
    U_PHASE=applied
    update_save_pending
    wp_site eval-file "$U_HANDOFF/update-platform.php" apply --use-include
    [[ $(wp_site --skip-plugins --skip-themes plugin get oj-platform --field=version) == "$U_NEW_VERSION" ]] || fail 'The installed release version does not match.'
    wp_site --skip-plugins --skip-themes plugin is-active oj-platform
    U_PHASE=verified
    update_save_pending
    systemctl reload php8.3-fpm
    update_remove_maintenance
    update_check_http
    update_restore_timer
    rm -f -- "$U_STATE/pending.env"
    U_PENDING_VALID=0 U_SUCCEEDED=1
    printf '\nUpdated: OJ Platform %s\nBackup: %s\n' "$U_NEW_VERSION" "$U_BACKUP"
}

update_main() {
    while (($#)); do
        case "$1" in
            --help|-h) update_usage; return 0 ;;
            --plugin-zip) (($# >= 2)) && [[ -n $2 && $2 != --* ]] || fail 'Missing value for --plugin-zip'; U_REQUESTED_ZIP=$2; shift 2 ;;
            *) fail "Unknown update option: $1" ;;
        esac
    done
    [[ $(uname -s) == Linux && $EUID == 0 && $(uname -m) == x86_64 ]] || fail 'Run with sudo on an Ubuntu 24.04 amd64 server.'
    # shellcheck disable=SC1091
    source /etc/os-release
    [[ $ID == ubuntu && $VERSION_ID == 24.04 && -d /run/systemd/system && ! -f /.dockerenv ]] || fail 'Ubuntu 24.04 with systemd is required.'
    command -v flock >/dev/null || fail 'util-linux is required.'
    exec 9>/run/oj-platform-installer.lock
    flock -n 9 || fail 'Another installation or update is running.'
    trap 'update_cleanup "$?"' EXIT
    update_stages 9>&-
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then update_main "$@"; fi
