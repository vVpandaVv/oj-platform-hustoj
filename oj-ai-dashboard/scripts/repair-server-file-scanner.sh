#!/usr/bin/env bash
set -euo pipefail
export DEBIAN_FRONTEND=noninteractive
export PATH=/usr/sbin:/usr/bin:/sbin:/bin

test "$(id -u)" = 0
. /etc/os-release
test "$ID" = ubuntu
test "$VERSION_ID" = 24.04
printf 'UTC: '; date -u '+%Y-%m-%d %H:%M:%S'
available_kib=$(awk '/^MemAvailable:/ {print $2}' /proc/meminfo)
disk_kib=$(df -Pk /var/lib | awk 'NR == 2 {print $4}')
printf 'Available memory KiB: %s; disk KiB: %s\n' "$available_kib" "$disk_kib"
if (( available_kib < 3145728 || disk_kib < 5242880 )); then
  echo 'STOP: ClamAV needs additional resource review (3 GiB available RAM, 5 GiB disk). No package/configuration changes made.'
  exit 20
fi
if test -e /etc/clamav/clamd.conf || test -n "$(ss -H -ltn 'sport = :3310')"; then
  echo 'STOP: Existing scanner configuration/listener requires review. Nothing overwritten.'
  exit 21
fi

echo 'Refreshing signed package metadata.'
apt-get update -qq
# Inspect the resolver's actual changes, not only the named package origins.
python3 - <<'PY'
import apt
cache = apt.Cache()
names = ('clamav', 'clamav-daemon', 'clamav-freshclam', 'clamdscan')
for name in names:
    cache[name].mark_install(auto_fix=True, auto_inst=True, from_user=True)
for package in cache.get_changes():
    candidate = package.candidate
    if package.marked_delete or candidate is None:
        raise SystemExit('STOP: Package resolver would delete a package or has no candidate.')
    if package.is_installed and package.installed.version != candidate.version:
        raise SystemExit('STOP: An existing package upgrade requires separate review: ' + package.name)
    if not any(origin.origin == 'Ubuntu' and origin.trusted for origin in candidate.origins):
        raise SystemExit('STOP: Package candidate is not from trusted Ubuntu metadata: ' + package.name)
    print('Trusted candidate:', package.name, candidate.version)
PY
apt-get install -y --no-install-recommends --no-remove --no-upgrade clamav clamav-daemon clamav-freshclam clamdscan

# Only the newly installed scanner services are stopped/restarted. The web
# server, PHP workers, WordPress configuration and application data stay intact.
systemctl stop clamav-daemon.service clamav-daemon.socket clamav-freshclam.service
test -f /lib/systemd/system/clamav-daemon.socket || test -f /usr/lib/systemd/system/clamav-daemon.socket
dropin=/etc/systemd/system/clamav-daemon.socket.d/oj-ai-local.conf
test ! -e "$dropin"
install -d -m 755 /etc/systemd/system/clamav-daemon.socket.d
printf '[Socket]\nListenStream=127.0.0.1:3310\n' > "$dropin"
chmod 644 "$dropin"
systemctl daemon-reload

echo 'Updating and verifying ClamAV signature databases.'
if ! timeout 600 freshclam --quiet; then
  systemctl enable --now clamav-freshclam.service
  echo 'STOP: Initial signature update failed. No scan bypass or successful acceptance recorded.'
  exit 22
fi
systemctl enable --now clamav-freshclam.service clamav-daemon.socket
systemctl start clamav-daemon.service

echo 'Testing loopback connection and benign/EICAR INSTREAM from the PHP account.'
runuser -u www-data -- php <<'PHP'
<?php
$socket = false;
for ($attempt = 0; $attempt < 90; ++$attempt) {
    $socket = @stream_socket_client('tcp://127.0.0.1:3310', $number, $message, 2);
    if ($socket !== false) {
        stream_set_timeout($socket, 2);
        fwrite($socket, "zPING\0");
        $reply = stream_get_contents($socket, 5);
        fclose($socket);
        if ($reply === "PONG\0") { break; }
    }
    sleep(1);
}
if (($reply ?? '') !== "PONG\0") { fwrite(STDERR, "FAIL: PHP account cannot reach scanner.\n"); exit(30); }
echo "PASS: www-data PHP received PONG.\n";
$cases = [
    'benign' => 'OJ AI acceptance: harmless scanner fixture.',
    'eicar' => 'X5O!P%@AP[4\\PZX54(P^)7CC)7}$EICAR-STANDARD-ANTIVIRUS-TEST-FILE!$H+H*',
];
foreach ($cases as $kind => $bytes) {
    $socket = stream_socket_client('tcp://127.0.0.1:3310', $number, $message, 5);
    stream_set_timeout($socket, 30);
    $request = "zINSTREAM\0" . pack('N', strlen($bytes)) . $bytes . pack('N', 0);
    $offset = 0;
    while ($offset < strlen($request)) {
        $written = fwrite($socket, substr($request, $offset));
        if ($written === false || $written === 0) { exit(31); }
        $offset += $written;
    }
    $response = stream_get_contents($socket, 4096);
    fclose($socket);
    $passed = $kind === 'benign' ? $response === "stream: OK\0"
        : preg_match('/^stream: [^\x00\r\n]+ FOUND\x00$/D', $response) === 1;
    echo ($passed ? 'PASS' : 'FAIL') . ': ' . $kind . " scan.\n";
    if (!$passed) { exit(32); }
}
PHP
echo 'Final service state:'
systemctl is-active clamav-daemon.service clamav-freshclam.service
ss -H -ltn 'sport = :3310'
if ss -H -ltn 'sport = :3310' | awk '{print $4}' | grep -vx '127.0.0.1:3310'; then
  systemctl stop clamav-daemon.service clamav-daemon.socket
  echo 'STOP: Unexpected non-loopback listener.'
  exit 33
fi
clamdscan --version
echo 'Scanner setup and synthetic protocol checks completed. Application submission acceptance is still required.'
