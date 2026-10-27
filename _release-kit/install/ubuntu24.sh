#!/usr/bin/env bash
# Ubuntu 24.04: official HUSTOJ, WordPress, and OJ Platform.
set +x
set -Eeuo pipefail
umask 077

WEB_ROOT=/home/judge/src/web
STATE=/var/lib/oj-platform-installer
HANDOFF=/run/oj-platform-installer
PHP=/usr/bin/php8.3
WP=/usr/local/bin/wp
WP_DATABASE=oj_wordpress
WP_DB_USER= WP_DB_PASSWORD= WP_DB_HOST=
SCRIPT_DIR=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd -P)
REPOSITORY=$(cd -- "$SCRIPT_DIR/../.." && pwd -P)
SITE_URL= ADMIN_EMAIL= PLUGIN_ZIP=
ADMIN_USER=ojadmin
SITE_TITLE='OJ Platform'
RESUME=0

fail() { printf 'ERROR: %s\n' "$*" >&2; exit 1; }
step() { printf '\n[%s] %s\n' "$1" "$2"; }
usage() {
    cat <<'TEXT'
sudo bash _release-kit/install/ubuntu24.sh [options]
  --site-url URL       WordPress address; default: http://server-IPv4
  --admin-email EMAIL  Admin/certificate contact; HTTP default: admin@example.test
                      HTTPS asks for a real email when omitted.
  --admin-user NAME    WordPress administrator login; default: ojadmin
  --title TEXT         Site name; default: OJ Platform
  --plugin-zip PATH    Plugin ZIP; default: newest bundled ZIP + .zip.sha256
  --resume            Continue saved stages after resolving an error.
  --help, -h          Show help without installing anything.

Stack: official HUSTOJ, nginx, MariaDB, PHP 8.3, WordPress (ko_KR),
WP-CLI, OJ Platform, compilers/runtimes and SPJ tools.
Host tools: GCC/G++, make, JDK, Python 3 (venv/pip, NumPy/pandas/Matplotlib),
Node.js/npm, bubblewrap, prlimit, AppArmor and ACL.
HTTPS: certificate issuance, nginx redirect and certbot.timer renewal.
HUSTOJ: live redacted output plus 10-second elapsed/PID/status updates.
TEXT
}

show_plan() {
    printf '\nInstallation plan\n  Site: %s\n  Admin login: %s\n  Admin email: %s\n  Site title: %s\n' "$SITE_URL" "$ADMIN_USER" "$ADMIN_EMAIL" "$SITE_TITLE"
    printf '  Stack: HUSTOJ, nginx, MariaDB, PHP 8.3, WordPress (ko_KR), WP-CLI, OJ Platform\n'
    printf '  Tools: GCC/G++, JDK, Python 3 and host libraries, Node.js/npm, SPJ sandbox\n'
    printf '  Databases: jol + oj_wordpress, using the account in judge.conf\n'
    if [[ $SITE_URL == https://* ]]; then
        printf '  HTTPS: issue certificate, configure nginx redirect and automatic renewal\n'
    else
        printf '  Web access: HTTP\n'
    fi
}

parse_args() {
    while (($#)); do
        case "$1" in
            --site-url|--admin-email|--admin-user|--title|--plugin-zip)
                (($# >= 2)) && [[ -n $2 && $2 != --* ]] || fail "Missing value for $1"
                case "$1" in
                    --site-url) SITE_URL=$2 ;; --admin-email) ADMIN_EMAIL=$2 ;;
                    --admin-user) ADMIN_USER=$2 ;; --title) SITE_TITLE=$2 ;;
                    --plugin-zip) PLUGIN_ZIP=$2 ;;
                esac
                shift 2 ;;
            --resume) RESUME=1; shift ;;
            --help|-h) usage; exit 0 ;;
            *) fail "Unknown option: $1" ;;
        esac
    done
}

set_default_inputs() {
    if [[ -z $SITE_URL ]]; then
        local route_info address_info address
        route_info=$(ip -4 route get 1.1.1.1 2>/dev/null || :)
        address_info=$(hostname -I 2>/dev/null || :)
        address=$(python3 - "$route_info" "$address_info" <<'PY'
import ipaddress,sys
route=sys.argv[1].split(); candidates=[]
for index,token in enumerate(route[:-1]):
    if token=='src': candidates.append(route[index+1])
candidates.extend(sys.argv[2].split())
for candidate in candidates:
    try: address=ipaddress.ip_address(candidate)
    except ValueError: continue
    if isinstance(address,ipaddress.IPv4Address) and not (address.is_loopback or address.is_unspecified or address.is_multicast or address.is_link_local or address.is_reserved):
        print(address)
        break
else: sys.exit(1)
PY
        ) || fail 'No server IPv4 address found. Set --site-url explicitly.'
        SITE_URL=http://$address
    fi
    if [[ -z $ADMIN_EMAIL ]]; then
        if [[ $SITE_URL == https://* ]]; then
            [[ -t 0 ]] || fail 'HTTPS installation requires --admin-email for the certificate contact.'
            read -r -p 'Administrator email (HTTPS certificate contact): ' ADMIN_EMAIL
            [[ -n $ADMIN_EMAIL ]] || fail 'An HTTPS contact email is required.'
        else
            ADMIN_EMAIL=admin@example.test
        fi
    fi
}

assert_web_root() {
    [[ $WEB_ROOT == /home/judge/src/web ]] || fail 'Unexpected web path.'
    local directory mount
    for directory in /home /home/judge /home/judge/src "$WEB_ROOT"; do
        [[ -d $directory && ! -L $directory ]] || fail "Unsafe directory: $directory"
        [[ $(realpath -e -- "$directory") == "$directory" ]] || fail "Unexpected real path: $directory"
    done
    while IFS= read -r mount; do
        [[ $mount != "$WEB_ROOT" && $mount != "$WEB_ROOT/"* ]] || fail 'Unmount web subdirectories before installing.'
    done < <(findmnt -rn --raw -o TARGET)
}

validate_inputs() {
    python3 - "$SITE_URL" "$ADMIN_EMAIL" "$ADMIN_USER" "$SITE_TITLE" <<'PY'
import ipaddress,re,sys,urllib.parse
url,email,admin,title=sys.argv[1:]
if not url.startswith(('http://','https://')) or any(ord(c)<=32 or ord(c)==127 for c in url):
    sys.exit('Use a lowercase http(s) scheme and a URL without whitespace.')
p=urllib.parse.urlsplit(url)
if p.scheme not in ('http','https') or not p.hostname or p.username or p.password or p.query or p.fragment or p.path not in ('','/'):
    sys.exit('Use a site URL containing only http(s)://hostname.')
try:
    if p.port is not None: raise ValueError()
except ValueError:
    sys.exit('Use the standard HTTP/HTTPS port.')
host=p.hostname
if len(host)>253 or not all(re.fullmatch(r'[a-zA-Z0-9](?:[a-zA-Z0-9-]{0,61}[a-zA-Z0-9])?',part) for part in host.split('.')):
    sys.exit('Invalid hostname.')
if p.scheme=='https':
    try: ipaddress.ip_address(host)
    except ValueError: pass
    else: sys.exit('HTTPS requires a DNS hostname.')
if not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+',email): sys.exit('Invalid administrator email.')
if not re.fullmatch(r'[a-zA-Z0-9_][a-zA-Z0-9_.-]{2,59}',admin): sys.exit('Invalid administrator login.')
if not title.strip() or any(ord(c)<32 for c in title): sys.exit('Invalid site title.')
PY
    SITE_URL=${SITE_URL%/}
    DOMAIN=${SITE_URL#*://}
}

validate_package() {
    python3 - "$PLUGIN_ZIP" <<'PY'
import hashlib,pathlib,re,stat,sys,zipfile
p=pathlib.Path(sys.argv[1])
if not p.is_file() or p.is_symlink(): sys.exit('A regular OJ Platform release ZIP is required.')
checksum=pathlib.Path(str(p)+'.sha256')
if not checksum.is_file() or checksum.is_symlink() or checksum.stat().st_size>1024:
    sys.exit('A regular matching .zip.sha256 file is required.')
fields=checksum.read_text(encoding='utf-8').split()
if not fields: sys.exit('The checksum file is empty.')
expected=fields[0].lower()
actual=hashlib.file_digest(p.open('rb'),'sha256').hexdigest()
if not re.fullmatch('[0-9a-f]{64}',expected) or actual!=expected: sys.exit('Plugin ZIP checksum mismatch.')
with zipfile.ZipFile(p) as z:
    names=set()
    total=0
    for info in z.infolist():
        parts=pathlib.PurePosixPath(info.filename).parts
        if not parts or parts[0]!='oj-platform' or '..' in parts or '\\' in info.orig_filename or '\x00' in info.orig_filename or '//' in info.filename or info.filename.rstrip('/')!='/'.join(parts) or info.filename in names or stat.S_ISLNK(info.external_attr>>16):
            sys.exit('Unsafe plugin ZIP entry.')
        names.add(info.filename)
        total+=info.file_size
        if total>512*1024*1024: sys.exit('Plugin ZIP is too large.')
    main=z.read('oj-platform/oj-platform.php').decode('utf-8')
    if not re.search(r'^\s*\*\s*Plugin Name:\s*OJ Platform\s*$',main,re.M) or not re.search(r'^\s*\*\s*Version:\s*\d+\.\d+',main,re.M):
        sys.exit('Not an OJ Platform release ZIP.')
PY
    PLUGIN_SHA=$(sha256sum -- "$PLUGIN_ZIP" | cut -d ' ' -f1)
}

save_inputs() {
    local key
    : > "$STATE/config.env"
    for key in SITE_URL DOMAIN ADMIN_EMAIL ADMIN_USER SITE_TITLE PLUGIN_ZIP PLUGIN_SHA ADMIN_PASSWORD WP_DB_USER WP_DB_PASSWORD WP_DB_HOST BACKUP; do
        printf '%s=%q\n' "$key" "${!key}" >> "$STATE/config.env"
    done
    chmod 600 "$STATE/config.env"
}

download() { curl --fail --location --proto '=https' --tlsv1.2 --retry 3 --connect-timeout 20 --output "$2" "$1"; }
wp_root() { "$PHP" "$WP" --allow-root --path="$1" "${@:2}"; }
wp_site() {
    # WP-CLI can spawn another process; its working directory must be
    # accessible to www-data even when the installer was started in a private home.
    (
        cd -- "$WEB_ROOT" &&
        runuser -u www-data -- env HOME=/var/cache/oj-wp-cli "$PHP" "$WP" --path="$WEB_ROOT" "$@"
    )
}

load_judge_credentials() {
    local -a credentials=()
    mapfile -d '' -t credentials < <(python3 - <<'PY'
import pathlib,re,sys
values={}
for line in pathlib.Path('/home/judge/etc/judge.conf').read_text(encoding='utf-8').splitlines():
    line=line.strip()
    if line and not line.startswith('#') and '=' in line:
        key,value=line.split('=',1); values[key.strip()]=value.strip()
if values.get('OJ_DB_NAME')!='jol' or values.get('OJ_HOST_NAME') not in ('127.0.0.1','localhost'):
    sys.exit('Expected a local HUSTOJ jol database.')
for key in ('OJ_SIM_ENABLE','OJ_TURBO_MODE','OJ_HTTP_JUDGE'):
    if values.get(key,'0')!='0': sys.exit('Incompatible HUSTOJ runtime setting: '+key)
user=values.get('OJ_USER_NAME',''); password=values.get('OJ_PASSWORD','')
port=values.get('OJ_PORT_NUMBER','3306')
if not re.fullmatch(r'[A-Za-z0-9_]{1,32}',user) or not re.fullmatch(r'[A-Za-z0-9]{1,256}',password):
    sys.exit('Expected the credentials generated by the official fresh HUSTOJ installer.')
if not port.isdigit() or not 1<=int(port)<=65535: sys.exit('Invalid HUSTOJ database port.')
sys.stdout.write(user+'\0'+password+'\0'+'127.0.0.1:'+port+'\0')
PY
    )
    ((${#credentials[@]} == 3)) || fail 'Could not read HUSTOJ database credentials.'
    if [[ -n $WP_DB_USER ]]; then
        [[ $WP_DB_USER == "${credentials[0]}" && $WP_DB_PASSWORD == "${credentials[1]}" && $WP_DB_HOST == "${credentials[2]}" ]] || fail 'HUSTOJ credentials changed after this installation started.'
    fi
    WP_DB_USER=${credentials[0]}
    WP_DB_PASSWORD=${credentials[1]}
    WP_DB_HOST=${credentials[2]}
    unset credentials
    save_inputs
    printf 'Site: %s\nAdministrator: %s\nPassword: %s\nWordPress database: %s\nDatabase user: %s\nDatabase password (HUSTOJ): %s\n' "$SITE_URL" "$ADMIN_USER" "$ADMIN_PASSWORD" "$WP_DATABASE" "$WP_DB_USER" "$WP_DB_PASSWORD" > "$STATE/credentials.txt"
    chmod 600 "$STATE/credentials.txt"
}

install_hustoj() {
    step 1 'Install official HUSTOJ'
    if [[ ! -e $STATE/hustoj-started ]]; then
        local commit
        download https://api.github.com/repos/zhblue/hustoj/commits/master "$STATE/hustoj-commit.json"
        commit=$(python3 -c 'import json,sys; print(json.load(open(sys.argv[1]))["sha"])' "$STATE/hustoj-commit.json")
        [[ $commit =~ ^[0-9a-f]{40}$ ]] || fail 'Invalid official HUSTOJ revision.'
        printf '%s\n' "$commit" > "$STATE/hustoj-commit"
        download "https://raw.githubusercontent.com/zhblue/hustoj/$commit/trunk/install/install-ubuntu24.04.sh" "$STATE/hustoj-upstream.sh"
        download "https://codeload.github.com/zhblue/hustoj/tar.gz/$commit" "$STATE/hustoj-source.tar.gz"
        bash -n "$STATE/hustoj-upstream.sh"
        # Use the same official revision over HTTPS instead of the HTTP mirror tarball.
        python3 - "$STATE" "$commit" <<'PY'
import pathlib,sys
root=pathlib.Path(sys.argv[1]); commit=sys.argv[2]
source=(root/'hustoj-upstream.sh').read_text(encoding='utf-8')
old='wget -O hustoj.tar.gz http://dl.hustoj.com/hustoj.tar.gz\ntar xzf hustoj.tar.gz'
new=f'mkdir -p /home/judge/src\ntar xzf /var/lib/oj-platform-installer/hustoj-source.tar.gz --strip-components=2 -C /home/judge/src hustoj-{commit}/trunk'
if source.count(old)!=1: sys.exit('Official installer download block changed; update this wrapper before continuing.')
(root/'hustoj-install.sh').write_text(source.replace(old,new),encoding='utf-8')
PY
        sha256sum "$STATE/hustoj-upstream.sh" "$STATE/hustoj-install.sh" "$STATE/hustoj-source.tar.gz" > "$STATE/upstream.sha256"
        touch "$STATE/hustoj-started"
        printf 'Live log: %s/hustoj-progress.log\nPrivate raw log: %s/hustoj-install.log\n' "$STATE" "$STATE"
        (umask 022; cd "$STATE"; TERM=dumb python3 "$SCRIPT_DIR/run-with-progress.py" \
            --raw-log "$STATE/hustoj-install.log" --progress-log "$STATE/hustoj-progress.log" \
            --heartbeat 10 -- timeout --kill-after=60s 3h bash "$STATE/hustoj-install.sh")
    fi
    [[ -f /home/judge/etc/judge.conf && -x /usr/bin/judged && -x /usr/bin/judge_client ]] || fail "HUSTOJ is incomplete. Check $STATE/hustoj-install.log before --resume."
    [[ -d /home/judge/data && -f /etc/init.d/hustoj ]] || fail 'HUSTOJ data or service is missing.'
    mariadb --protocol=socket --batch --skip-column-names -e 'SELECT 1 FROM jol.problem LIMIT 1' >/dev/null
    chmod 755 /home/judge/src
    runuser -u www-data -- test -x /home/judge/src
    systemctl enable --now hustoj
    pgrep -x judged >/dev/null || fail 'The HUSTOJ judge daemon is not running.'
    touch "$STATE/hustoj.done"
}

install_programs() {
    step 2 'Install WordPress and platform programs'
    apt-get update
    apt-get install -y nginx mariadb-server php8.3-cli php8.3-fpm php8.3-mysql php8.3-xml \
        php8.3-mbstring php8.3-intl php8.3-zip php8.3-curl php8.3-gd php8.3-bcmath \
        php8.3-sqlite3 php8.3-opcache bubblewrap gcc g++ make default-jdk \
        python3 python3-venv python3-pip python3-numpy python3-pandas python3-matplotlib \
        nodejs npm acl apparmor apparmor-utils cron util-linux rsync unzip \
        certbot python3-certbot-nginx libfcgi-bin
    timedatectl set-timezone Etc/UTC
    printf "[mysqld]\ndefault_time_zone = '+00:00'\n" > /etc/mysql/mariadb.conf.d/99-oj-utc.cnf
    chmod 644 /etc/mysql/mariadb.conf.d/99-oj-utc.cnf
    systemctl restart mariadb
    systemctl restart hustoj
    mariadb --protocol=socket --batch --skip-column-names -e 'SELECT @@global.time_zone' | grep -Fx '+00:00' >/dev/null
    # Remove only the upstream web maintenance jobs; WP manages private executions.
    crontab -l > "$STATE/root.cron.before" 2>/dev/null || :
    python3 - "$STATE" <<'PY'
import pathlib,sys
root=pathlib.Path(sys.argv[1])
lines=(root/'root.cron.before').read_text(encoding='utf-8').splitlines()
jobs=('/home/judge/src/install/bak.sh','/home/judge/src/install/oomsaver.sh')
(root/'root.cron.after').write_text(''.join(line+'\n' for line in lines if not any(job in line for job in jobs)),encoding='utf-8')
PY
    crontab "$STATE/root.cron.after"
    cat > /etc/apparmor.d/bwrap <<'PROFILE'
abi <abi/4.0>,
include <tunables/global>
profile bwrap /usr/bin/bwrap flags=(unconfined) {
  userns,
  include if exists <local/bwrap>
}
PROFILE
    chmod 644 /etc/apparmor.d/bwrap
    apparmor_parser -r /etc/apparmor.d/bwrap
    runuser -u www-data -- bwrap --ro-bind / / --dev /dev --proc /proc --unshare-all /bin/true
    install -d -o www-data -g judge -m 2770 /home/judge/data/_capture
    usermod -a -G judge www-data
    chown www-data:judge /home/judge/data
    chmod 2770 /home/judge/data
    setfacl -R -m u:www-data:rwX,g:judge:r-X /home/judge/data
    find /home/judge/data -type d -exec setfacl -m d:u:www-data:rwx,d:g:judge:r-x {} +
    local probe
    probe=$(runuser -u www-data -- mktemp -d /home/judge/data/.oj-install-check-XXXXXXXX)
    [[ $probe == /home/judge/data/.oj-install-check-* && ! -L $probe ]] || fail 'Unexpected data probe path.'
    runuser -u www-data -- sh -c 'chmod 750 "$1"; printf test > "$1/input"; chmod 640 "$1/input"; printf "#!/bin/sh\nexit 0\n" > "$1/spj"; chmod 750 "$1/spj"' sh "$probe"
    runuser -u judge -- test -r "$probe/input"
    runuser -u judge -- "$probe/spj"
    rm -rf -- "$probe"
    install -d -o www-data -g www-data -m 750 /home/judge/oj-platform-private
    install -d -o www-data -g www-data -m 750 /var/cache/oj-wp-cli
    download https://raw.githubusercontent.com/wp-cli/builds/gh-pages/phar/wp-cli.phar "$STATE/wp-cli.phar"
    "$PHP" "$STATE/wp-cli.phar" --info >/dev/null
    install -o root -g root -m 755 "$STATE/wp-cli.phar" "$WP"
    touch "$STATE/programs.done"
}

verify_judge_runtime() {
    local docker_mode
    docker_mode=$(python3 - <<'PY'
import pathlib
values={line.split('=',1)[0].strip():line.split('=',1)[1].strip() for line in pathlib.Path('/home/judge/etc/judge.conf').read_text().splitlines() if '=' in line and not line.lstrip().startswith('#')}
print(values.get('OJ_USE_DOCKER','0'))
PY
    )
    local -a runner=(bash -se)
    if [[ $docker_mode == 1 ]]; then
        docker image inspect hustoj:latest >/dev/null
        runner=(timeout --kill-after=10s 120s docker run --rm -i --network none --entrypoint /bin/sh hustoj:latest -se)
    elif [[ $docker_mode != 0 ]]; then
        fail 'Unknown HUSTOJ judging mode.'
    fi
    "${runner[@]}" <<'SMOKE'
work=$(mktemp -d)
trap 'rm -f -- "$work/main.c" "$work/main.cpp" "$work/test-c" "$work/test-cpp" "$work/Main.java" "$work/Main.class"; rmdir -- "$work"' EXIT
cd "$work"
printf '#include <stdio.h>\nint main(void){puts("OJ_OK");}\n' > main.c
gcc main.c -o test-c
test "$(./test-c)" = OJ_OK
printf '#include <iostream>\nint main(){std::cout<<"OJ_OK";}\n' > main.cpp
g++ -std=c++17 main.cpp -o test-cpp
test "$(./test-cpp)" = OJ_OK
test "$(python3 -c 'print("OJ_OK")')" = OJ_OK
printf 'class Main { public static void main(String[] a) { System.out.println("OJ_OK"); } }\n' > Main.java
javac Main.java
test "$(java -cp . Main)" = OJ_OK
SMOKE
}

prepare_wordpress() {
    step 3 'Download WordPress and back up the HUSTOJ web files'
    if [[ ! -f $STATE/wordpress-download.done ]]; then
        install -d -m 700 "$STATE/wordpress"
        wp_root "$STATE/wordpress" core download --locale=ko_KR --version=latest --force
        wp_root "$STATE/wordpress" core verify-checksums --locale=ko_KR
        touch "$STATE/wordpress-download.done"
    fi
    assert_web_root
    if [[ ! -f $STATE/backup.done ]]; then
        install -d -m 700 "$BACKUP"
        tar --one-file-system -czf "$BACKUP/hustoj-web.tar.gz" -C "$WEB_ROOT" .
        cp -a /etc/nginx "$BACKUP/nginx"
        cp -a /etc/php/8.3/fpm "$BACKUP/php-fpm"
        touch "$STATE/backup.done"
    fi
    systemctl stop nginx
    if [[ ! -f $STATE/web-cleared.done ]]; then
        assert_web_root
        [[ -s $BACKUP/hustoj-web.tar.gz ]] || fail 'Web backup is missing.'
        find "$WEB_ROOT" -mindepth 1 -maxdepth 1 -exec rm -rf -- {} +
        touch "$STATE/web-cleared.done"
    fi
    if [[ ! -f $WEB_ROOT/wp-config.php ]]; then
        rsync -a "$STATE/wordpress/" "$WEB_ROOT/"
        if [[ ! -f $STATE/database-started ]]; then
            [[ $(mariadb --protocol=socket -NBe "SELECT COUNT(*) FROM information_schema.SCHEMATA WHERE SCHEMA_NAME='$WP_DATABASE'") == 0 ]] || fail 'The WordPress database already exists.'
            touch "$STATE/database-started"
        fi
        local account_host account_count
        account_count=$(mariadb --protocol=socket -NBe "SELECT COUNT(*) FROM mysql.user WHERE User='$WP_DB_USER'")
        [[ $account_count == 1 ]] || fail 'Expected one existing HUSTOJ database account.'
        account_host=$(mariadb --protocol=socket -NBe "SELECT Host FROM mysql.user WHERE User='$WP_DB_USER'")
        [[ $account_host == '%' || $account_host == localhost || $account_host == 127.0.0.1 || $account_host == ::1 ]] || fail 'Unexpected HUSTOJ database account host.'
        mariadb --protocol=socket <<SQL
CREATE DATABASE IF NOT EXISTS $WP_DATABASE CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
GRANT ALL PRIVILEGES ON $WP_DATABASE.* TO '$WP_DB_USER'@'$account_host';
SQL
        printf '%s\n' "$WP_DB_PASSWORD" | wp_root "$WEB_ROOT" config create --dbname="$WP_DATABASE" --dbuser="$WP_DB_USER" --dbhost="$WP_DB_HOST" --dbprefix=wp_ --prompt=dbpass
    fi
    wp_root "$WEB_ROOT" config set DISABLE_WP_CRON true --raw
    wp_root "$WEB_ROOT" config set DISALLOW_FILE_EDIT true --raw
    wp_root "$WEB_ROOT" config set OJ_PLATFORM_PRIVATE_DATA_DIR /home/judge/oj-platform-private
    chown -R www-data:www-data "$WEB_ROOT"
    find "$WEB_ROOT" -type d -exec chmod 755 {} +
    find "$WEB_ROOT" -type f -exec chmod 644 {} +
    chmod 640 "$WEB_ROOT/wp-config.php"
    if ! wp_site core is-installed >/dev/null 2>&1; then
        printf '%s\n' "$ADMIN_PASSWORD" | wp_site core install --url="$SITE_URL" --title="$SITE_TITLE" --admin_user="$ADMIN_USER" --admin_email="$ADMIN_EMAIL" --prompt=admin_password --skip-email
    fi
    wp_site option update timezone_string Asia/Seoul
    wp_site rewrite structure '/%postname%/'
    touch "$STATE/wordpress.done"
}

install_platform() {
    step 4 'Install and configure OJ Platform'
    install -d -o root -g www-data -m 750 "$HANDOFF"
    install -o root -g www-data -m 640 "$SCRIPT_DIR/configure-platform.php" "$HANDOFF/configure-platform.php"
    python3 - "$HANDOFF/settings.json" <<'PY'
import json,pathlib,sys
values={}
for line in pathlib.Path('/home/judge/etc/judge.conf').read_text(encoding='utf-8').splitlines():
    text=line.strip()
    if not text or text.startswith('#') or '=' not in text: continue
    key,value=text.split('=',1); values[key.strip()]=value.strip()
if values.get('OJ_HTTP_JUDGE','0')!='0': sys.exit('HUSTOJ must use direct database judging (OJ_HTTP_JUDGE=0).')
if values.get('OJ_DB_NAME')!='jol' or values.get('OJ_HOST_NAME') not in ('localhost','127.0.0.1'): sys.exit('Expected a local HUSTOJ jol database.')
for key in ('OJ_USER_NAME','OJ_PASSWORD'):
    if not values.get(key): sys.exit('HUSTOJ database credentials are incomplete.')
data={'schema_version':1,'fresh_install':True,'judge':{'host':'127.0.0.1','port':int(values.get('OJ_PORT_NUMBER','3306')),'database':'jol','username':values['OJ_USER_NAME'],'password':values['OJ_PASSWORD'],'timestamp_contract':'utc-v1'},'test_data':{'root':'/home/judge/data'}}
pathlib.Path(sys.argv[1]).write_text(json.dumps(data),encoding='utf-8')
PY
    chown www-data:www-data "$HANDOFF/settings.json"
    chmod 600 "$HANDOFF/settings.json"
    # Copy the already validated package where the unprivileged WP process can read it.
    install -o root -g www-data -m 640 "$PLUGIN_ZIP" "$HANDOFF/oj-platform.zip"
    wp_site plugin install "$HANDOFF/oj-platform.zip" --activate --force
    wp_site --user="$ADMIN_USER" eval-file "$HANDOFF/configure-platform.php" "$HANDOFF/settings.json"
    rm -f -- "$HANDOFF/settings.json" "$HANDOFF/oj-platform.zip" "$HANDOFF/configure-platform.php"
    wp_site rewrite flush
    wp_site plugin is-active oj-platform
    touch "$STATE/platform.done"
}

configure_web_server() {
    step 5 'Configure PHP, nginx, HTTPS, and scheduled tasks'
    local children memory
    memory=$(awk '/MemTotal:/{print int($2/1024)}' /proc/meminfo)
    children=$((memory / 256))
    ((children >= 2)) || children=2
    ((children <= 16)) || children=16
    cat > /etc/php/8.3/fpm/pool.d/www.conf <<POOL
[www]
user = www-data
group = www-data
listen = /run/php/php8.3-fpm.sock
listen.owner = www-data
listen.group = www-data
listen.mode = 0660
pm = ondemand
pm.max_children = $children
pm.process_idle_timeout = 10s
pm.max_requests = 500
POOL
    cat > /etc/php/8.3/fpm/conf.d/99-oj-platform.ini <<'INI'
upload_max_filesize = 128M
post_max_size = 136M
memory_limit = 256M
max_execution_time = 120
max_input_time = 120
date.timezone = UTC
session.save_handler = files
session.save_path = /var/lib/php/sessions
INI
    chmod 644 /etc/php/8.3/fpm/pool.d/www.conf /etc/php/8.3/fpm/conf.d/99-oj-platform.ini
    cat > /etc/nginx/sites-available/default <<NGINX
server {
    listen 80 default_server;
    server_name $DOMAIN;
    root /home/judge/src/web;
    index index.php;
    client_max_body_size 136m;
    location / { try_files \$uri \$uri/ /index.php?\$args; }
    location = /wp-config.php { deny all; }
    location ~ /\.(?!well-known/) { deny all; }
    location ~* ^/wp-content/uploads/.*\.(php[0-9]?|phtml|phar)(/|$) { deny all; }
    location ~ \.php$ {
        include snippets/fastcgi-php.conf;
        fastcgi_pass unix:/run/php/php8.3-fpm.sock;
    }
}
NGINX
    chmod 644 /etc/nginx/sites-available/default
    if [[ -e /etc/nginx/sites-enabled/default || -L /etc/nginx/sites-enabled/default ]]; then
        rm -f -- /etc/nginx/sites-enabled/default
    fi
    ln -s /etc/nginx/sites-available/default /etc/nginx/sites-enabled/default
    php-fpm8.3 -t
    nginx -t
    systemctl enable --now mariadb php8.3-fpm nginx cron hustoj
    systemctl restart php8.3-fpm
    cat > "$HANDOFF/check-fpm.php" <<'PHP'
<?php
$extensions = ['openssl', 'mysqli', 'dom', 'xmlwriter', 'zip', 'fileinfo', 'curl', 'mbstring', 'intl', 'gd'];
$functions = ['proc_open', 'proc_close', 'proc_get_status', 'proc_terminate'];
foreach ($extensions as $extension) { if (!extension_loaded($extension)) { http_response_code(500); exit('OJ_RUNTIME_FAILED'); } }
foreach ($functions as $function) { if (!is_callable($function)) { http_response_code(500); exit('OJ_RUNTIME_FAILED'); } }
$process = proc_open(['/bin/true'], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
if (!is_resource($process)) { http_response_code(500); exit('OJ_RUNTIME_FAILED'); }
foreach ($pipes as $pipe) { fclose($pipe); }
if (proc_close($process) !== 0) { http_response_code(500); exit('OJ_RUNTIME_FAILED'); }
echo 'OJ_RUNTIME_OK';
PHP
    chmod 640 "$HANDOFF/check-fpm.php"
    chown root:www-data "$HANDOFF/check-fpm.php"
    SCRIPT_FILENAME="$HANDOFF/check-fpm.php" REQUEST_METHOD=GET cgi-fcgi -bind -connect /run/php/php8.3-fpm.sock > "$STATE/fpm-check.txt"
    grep -q 'OJ_RUNTIME_OK' "$STATE/fpm-check.txt" || fail 'PHP-FPM runtime verification failed.'
    rm -f -- "$HANDOFF/check-fpm.php"
    systemctl restart nginx
    if [[ $SITE_URL == https://* ]]; then
        certbot --nginx --non-interactive --agree-tos --keep-until-expiring --email "$ADMIN_EMAIL" --domains "$DOMAIN" --redirect
        systemctl enable --now certbot.timer
    fi
    cat > /etc/systemd/system/oj-wordpress-cron.service <<'SERVICE'
[Unit]
Description=OJ WordPress scheduled tasks
After=mariadb.service php8.3-fpm.service
[Service]
Type=oneshot
User=www-data
Group=www-data
Environment=HOME=/var/cache/oj-wp-cli
ExecStart=/usr/bin/php8.3 /usr/local/bin/wp --path=/home/judge/src/web cron event run --due-now --quiet
SERVICE
    cat > /etc/systemd/system/oj-wordpress-cron.timer <<'TIMER'
[Unit]
Description=Run OJ WordPress tasks every minute
[Timer]
OnCalendar=*-*-* *:*:00
Persistent=true
[Install]
WantedBy=timers.target
TIMER
    chmod 644 /etc/systemd/system/oj-wordpress-cron.service /etc/systemd/system/oj-wordpress-cron.timer
    systemctl daemon-reload
    systemctl enable --now oj-wordpress-cron.timer
    systemctl start oj-wordpress-cron.service
    nginx -t
    systemctl is-active --quiet mariadb php8.3-fpm nginx
    pgrep -x judged >/dev/null
    curl --fail --silent --show-error --resolve "$DOMAIN:80:127.0.0.1" "http://$DOMAIN/" >/dev/null
    if [[ $SITE_URL == https://* ]]; then
        curl --fail --silent --show-error --resolve "$DOMAIN:443:127.0.0.1" "$SITE_URL/" >/dev/null
    fi
    touch "$STATE/complete"
}

main() {
    parse_args "$@"
    [[ $(uname -s) == Linux && $EUID == 0 ]] || fail 'Run with sudo on the Ubuntu server.'
    [[ $(uname -m) == x86_64 ]] || fail 'Use an Ubuntu 24.04 amd64 server.'
    # shellcheck disable=SC1091
    source /etc/os-release
    [[ $ID == ubuntu && $VERSION_ID == 24.04 ]] || fail 'Ubuntu 24.04 is required.'
    [[ -d /run/systemd/system && ! -f /.dockerenv ]] || fail 'Use an Ubuntu server with systemd.'
    command -v flock >/dev/null || fail 'util-linux is required.'
    exec 9>/run/oj-platform-installer.lock
    flock -n 9 || fail 'Installer lock is held. Check: sudo fuser -v /run/oj-platform-installer.lock'
    # Bash retains the lock in a close-on-exec save descriptor while the
    # stages run. Services started by any stage must not inherit the lock.
    install_stages 9>&-
}

install_stages() {
    [[ ! -L $STATE && ! -L $HANDOFF ]] || fail 'Installer directories must not be symlinks.'
    export DEBIAN_FRONTEND=noninteractive NEEDRESTART_MODE=a
    if ((RESUME)); then
        [[ -f $STATE/config.env && ! -L $STATE/config.env && $(stat -c '%u:%a' "$STATE/config.env") == 0:600 && $(stat -c '%u:%a' "$STATE") == 0:700 ]] || fail 'A trusted previous installer state was not found.'
        # This root-only file is written exclusively by save_inputs().
        # shellcheck disable=SC1090
        source "$STATE/config.env"
    else
        [[ ! -e $STATE && ! -e /home/judge && ! -L /home/judge ]] || fail 'Use a fresh server, or --resume for this installer.'
        [[ -f $SCRIPT_DIR/configure-platform.php && -f $SCRIPT_DIR/run-with-progress.py ]] || fail 'Installer helper is missing.'
        if [[ -z $PLUGIN_ZIP ]]; then
            PLUGIN_ZIP=$(find "$REPOSITORY/_releases/oj-platform" -mindepth 2 -maxdepth 2 -type f -name 'oj-platform-*.zip' | sort -V | tail -n 1)
        fi
        [[ -n $PLUGIN_ZIP && -f $PLUGIN_ZIP ]] || fail 'Set --plugin-zip to the release ZIP with its .sha256 file.'
        [[ ! -L $PLUGIN_ZIP ]] || fail 'The installation package must not be a symlink.'
        PLUGIN_ZIP=$(realpath -e -- "$PLUGIN_ZIP")
        [[ $PLUGIN_ZIP != /home/judge/* ]] || fail 'Keep the installation package outside /home/judge.'
        apt-get update
        apt-get install -y ca-certificates curl wget python3 openssl tar util-linux iproute2 hostname
        set_default_inputs
        validate_inputs
        validate_package
        ADMIN_PASSWORD=$(openssl rand -hex 18)
        BACKUP=/var/backups/oj-platform/$(date -u +%Y%m%dT%H%M%SZ)
        install -d -o root -g root -m 700 "$STATE"
        install -o root -g root -m 600 "$PLUGIN_ZIP" "$STATE/oj-platform.zip"
        install -o root -g root -m 600 "$PLUGIN_ZIP.sha256" "$STATE/oj-platform.zip.sha256"
        PLUGIN_ZIP=$STATE/oj-platform.zip
        [[ $(sha256sum -- "$PLUGIN_ZIP" | cut -d ' ' -f1) == "$PLUGIN_SHA" ]] || fail 'Package changed while preparing the installer cache.'
        save_inputs
    fi
    cat > "$STATE/apt.conf" <<'APT'
APT::Get::Assume-Yes "true";
DPkg::Options { "--force-confdef"; "--force-confold"; };
APT
    export APT_CONFIG="$STATE/apt.conf"
    validate_inputs
    local expected_package_sha=$PLUGIN_SHA
    validate_package
    [[ $PLUGIN_SHA == "$expected_package_sha" ]] || fail 'Installation package changed since the first run.'
    show_plan
    [[ -f $STATE/hustoj.done ]] || install_hustoj
    load_judge_credentials
    [[ -f $STATE/programs.done ]] || install_programs
    verify_judge_runtime
    [[ -f $STATE/wordpress.done ]] || prepare_wordpress
    [[ -f $STATE/platform.done ]] || install_platform
    [[ -f $STATE/complete ]] || configure_web_server
    printf '\nInstalled: %s\nAdmin: %s/wp-admin/\nCredentials: sudo cat %s/credentials.txt\nWeb backup: %s\n' "$SITE_URL" "$SITE_URL" "$STATE" "$BACKUP"
}

if [[ ${BASH_SOURCE[0]} == "$0" ]]; then
    trap 'printf "Installation stopped at line %s. Resolve the error, then rerun with --resume.\n" "$LINENO" >&2' ERR
    trap 'if [[ -f $HANDOFF/settings.json ]]; then rm -f -- "$HANDOFF/settings.json"; fi' EXIT
    main "$@"
fi
