#!/usr/bin/env bash
set -Eeuo pipefail
umask 077
if [ "$(id -u)" -ne 0 ] || [ "$#" -lt 1 ] || [ "$#" -gt 2 ]; then
    echo "Run with sudo. Optional argument: nginx site file, or --update to preserve installed settings." >&2; exit 1
fi
UPDATE=0
if [ "${2:-}" = --update ]; then UPDATE=1; fi
if [ "$UPDATE" -eq 0 ] && [ -t 0 ]; then
    printf 'Paste the setup code from the plugin settings: ' >/dev/tty
    IFS= read -r -s SETUP_CODE </dev/tty
    printf '\n' >/dev/tty
elif [ "$UPDATE" -eq 0 ]; then
    IFS= read -r SETUP_CODE
fi
if [ "$UPDATE" -eq 0 ]; then [[ "$SETUP_CODE" == ojhub1.* ]] || { echo "Invalid setup code." >&2; exit 1; }; fi
if [ ! -f /etc/debian_version ] || ! command -v systemctl >/dev/null; then
    echo "Ubuntu/Debian with systemd and an existing nginx site is required." >&2; exit 1
fi
SOURCE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
PLUGIN="$(cd "$1" && pwd)"
TARGET=/opt/oj-realtime-hub
LEGACY=/opt/oj-code-arena-server
SITE=''
if [ "$UPDATE" -eq 0 ]; then SITE="$(readlink -f "${2:-/etc/nginx/sites-enabled/default}")"; fi
SNIPPETS=/etc/nginx/snippets
UNIT=/etc/systemd/system/oj-realtime-hub.service
if { [ "$UPDATE" -eq 0 ] && [ ! -f "$SITE" ]; } || [ ! -f "$PLUGIN/realtime-addon.json" ] || [ -L "$TARGET" ]; then echo "Invalid site/module installation path." >&2; exit 1; fi
exec 9>/run/lock/oj-realtime-hub-install.lock
flock -n 9 || { echo "Another realtime installation is running." >&2; exit 1; }
PORT=3210
if [ "$UPDATE" -eq 1 ]; then
    command -v node >/dev/null && [ -f "$UNIT" ] || { echo "Run the initial installer first." >&2; exit 1; }
    PORT="$(node "$SOURCE/src/update.js" port "$TARGET" "$PLUGIN")"
fi
node_major=0
if command -v node >/dev/null; then node_major="$(node -p "Number(process.versions.node.split('.')[0])")"; fi
if [ "$node_major" -lt 24 ]; then
    apt-get update
    apt-get install -y ca-certificates curl gnupg
    script="$(mktemp)"
    curl -fsSL https://deb.nodesource.com/setup_24.x -o "$script"
    bash "$script"
    rm -f -- "$script"
    apt-get install -y nodejs
fi
commands=(npm ss)
if [ "$UPDATE" -eq 0 ]; then commands+=(nginx curl); fi
for command in "${commands[@]}"; do
    command -v "$command" >/dev/null || { echo "Install nginx, curl, iproute2 and Node.js with npm first." >&2; exit 1; }
done
# Never downgrade a newer shared runtime when another plugin is installed later.
CORE="$SOURCE"
if [ -f "$TARGET/config.json" ]; then
    newer="$(node -e 'const a=require(process.argv[1]).version.split(".").map(Number),b=require(process.argv[2]).version.split(".").map(Number);const i=a.findIndex((v,i)=>v!==b[i]);console.log(i>=0&&a[i]>b[i]);' "$TARGET/package.json" "$SOURCE/package.json")"
    if [ "$newer" = true ]; then CORE="$TARGET"; fi
fi
if [ "$UPDATE" -eq 0 ]; then
    printf '%s' "$SETUP_CODE" | node -e 'const fs=require("node:fs");require(process.argv[1]).decode(fs.readFileSync(0,"utf8").trim());' "$CORE/src/registration.js"
    nginx -t
fi
active=0; enabled=0; legacy_active=0; legacy_enabled=0; changed=0; stopped=0; switched=0
systemctl is-active --quiet oj-realtime-hub && active=1
systemctl is-enabled --quiet oj-realtime-hub && enabled=1
app="$(node -e 'console.log(require(process.argv[1]).app)' "$PLUGIN/realtime-addon.json")"
if [ "$app" = oj-code-arena ]; then
    systemctl is-active --quiet oj-code-arena && legacy_active=1
    systemctl is-enabled --quiet oj-code-arena && legacy_enabled=1
fi
if [ "$active" -eq 0 ] && [ -n "$(ss -H -ltn "sport = :$PORT")" ]; then echo "The realtime port is occupied by another service." >&2; exit 1; fi
STAGE="$(mktemp -d /opt/.oj-realtime-stage.XXXXXX)"
BACKUP="$(mktemp -d /opt/.oj-realtime-backup.XXXXXX)"
if [ "$UPDATE" -eq 0 ]; then cp -p -- "$SITE" "$BACKUP/site"; fi
[ ! -f "$UNIT" ] || cp -p -- "$UNIT" "$BACKUP/unit"
mkdir -p "$BACKUP/snippets" "$BACKUP/new-snippets"
rollback() {
    status=$?
    trap - EXIT INT TERM
    if [ "$status" -ne 0 ]; then
        set +e
        if [ "$switched" -eq 1 ]; then systemctl stop oj-realtime-hub; mv -- "$TARGET" "$BACKUP/failed-server"; fi
        if [ -d "$BACKUP/server" ]; then mv -- "$BACKUP/server" "$TARGET"; fi
        if [ "$changed" -eq 1 ]; then
            cp -p -- "$BACKUP/site" "$SITE"
            for file in "$BACKUP/new-snippets/"*; do
                [ -f "$file" ] || continue
                name="$(basename "$file")"
                if [ -f "$BACKUP/snippets/$name" ]; then cp -p -- "$BACKUP/snippets/$name" "$SNIPPETS/$name"; else rm -f -- "$SNIPPETS/$name"; fi
            done
            [ "$enabled" -eq 1 ] || systemctl disable oj-realtime-hub >/dev/null 2>&1
            if [ -f "$BACKUP/unit" ]; then cp -p -- "$BACKUP/unit" "$UNIT"; else rm -f -- "$UNIT"; fi
            systemctl daemon-reload
        fi
        if [ "$stopped" -eq 1 ]; then
            [ "$active" -eq 0 ] || systemctl restart oj-realtime-hub
            [ "$legacy_active" -eq 0 ] || systemctl restart oj-code-arena
            [ "$legacy_enabled" -eq 0 ] || systemctl enable oj-code-arena
        fi
        if [ "$changed" -eq 1 ]; then nginx -t && systemctl reload nginx; fi
        echo "Installation failed; previous configuration restored where available. Backup: $BACKUP" >&2
    fi
    case "$STAGE" in /opt/.oj-realtime-stage.*) rm -rf -- "$STAGE" ;; esac
    exit "$status"
}
trap rollback EXIT
trap 'exit 130' INT
trap 'exit 143' TERM
cp -a -- "$CORE/src" "$CORE/deploy" "$STAGE/"
cp -- "$CORE/package.json" "$CORE/package-lock.json" "$CORE/install.sh" "$STAGE/"
cp -- "$CORE/LICENSE.txt" "$STAGE/"
if [ "$UPDATE" -eq 1 ]; then
    node "$SOURCE/src/update.js" prepare "$STAGE" "$TARGET" "$PLUGIN"
else
    printf '%s' "$SETUP_CODE" | node "$STAGE/src/prepare.js" register "$STAGE" "$TARGET" "$PLUGIN"
    unset SETUP_CODE
fi
(cd "$STAGE" && npm ci --omit=dev --ignore-scripts --no-audit --no-fund)
node -e 'const fs=require("node:fs"),p=require("node:path"),root=process.argv[1];require(p.join(root,"src/host")).createHost(JSON.parse(fs.readFileSync(p.join(root,"config.json"))),{root}).close();' "$STAGE"
if [ "$UPDATE" -eq 0 ]; then
    node "$STAGE/src/prepare.js" nginx "$STAGE" "$SITE" "$SNIPPETS"
    mkdir -p "$SNIPPETS"
    for file in "$STAGE/.nginx/"*.conf; do
        name="$(basename "$file")"
        [ ! -f "$SNIPPETS/$name" ] || cp -p -- "$SNIPPETS/$name" "$BACKUP/snippets/$name"
        cp -- "$file" "$BACKUP/new-snippets/$name"
    done
    changed=1
    cp -- "$STAGE/.nginx/site" "$SITE"
    cp -- "$BACKUP/new-snippets/"*.conf "$SNIPPETS/"
    for file in "$BACKUP/new-snippets/"*.conf; do chmod 644 "$SNIPPETS/$(basename "$file")"; done
    nginx -t
fi
stopped=1
[ "$active" -eq 0 ] || systemctl stop oj-realtime-hub
[ "$legacy_active" -eq 0 ] || systemctl stop oj-code-arena
node "$STAGE/src/prepare.js" data "$STAGE" "$TARGET" "$LEGACY"
if [ -e "$TARGET" ]; then mv -- "$TARGET" "$BACKUP/server"; fi
mv -- "$STAGE" "$TARGET"
switched=1
chown -R root:root "$TARGET"
chmod -R u=rwX,go=rX "$TARGET"
chown root:www-data "$TARGET/config.json"
chmod 640 "$TARGET/config.json"
while IFS= read -r data; do
    case "$data" in "$TARGET/modules/"*) chown -R www-data:www-data "$data"; chmod -R u=rwX,g=rX,o= "$data" ;; *) exit 1 ;; esac
done < <(node -e 'const p=require("node:path"),c=require(process.argv[1]);for(const a of c.apps)if(a.dataPath)console.log(p.join(process.argv[2],"modules",a.id,a.dataPath))' "$TARGET/config.json" "$TARGET")
if [ "$UPDATE" -eq 0 ]; then
    cp -- "$TARGET/deploy/oj-realtime-hub.service" "$UNIT"
    node -e 'const fs=require("node:fs"),f=process.argv[1];fs.writeFileSync(f,fs.readFileSync(f,"utf8").replace("/usr/bin/node","\""+process.execPath+"\""))' "$UNIT"
    chmod 644 "$UNIT"
    systemctl daemon-reload
    systemctl enable oj-realtime-hub
fi
systemctl restart oj-realtime-hub
healthy=0
for attempt in 1 2 3 4 5; do
    if node "$TARGET/src/check.js" "$TARGET/config.json"; then healthy=1; break; fi
    sleep 1
done
if [ "$healthy" -ne 1 ]; then echo "One or more addon connections failed authentication." >&2; exit 1; fi
if [ "$UPDATE" -eq 0 ]; then systemctl reload nginx; fi
if [ "$legacy_enabled" -eq 1 ]; then systemctl disable oj-code-arena; fi
echo "OJ Realtime Hub updated: $app. Other registered addons preserved. Backup: $BACKUP"
