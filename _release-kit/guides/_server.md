# 서버 준비 (순서대로)

Ubuntu 24.04 LTS에 HUSTOJ와 OJ 플러그인을 설치하기 위한 서버 작업입니다. 위에서부터 **명령을 한 줄씩** 입력하고 `Enter`를 누릅니다.

- 웹 루트: `/home/judge/src/web` (WordPress가 설치된 곳)
- PHP 버전: 8.3
- 명령 안의 `oj.school.kr`은 예시입니다. **반드시 내 도메인으로 바꿔서** 입력합니다. 보안 업데이트·헤더 확인 예시는 운영 대상 `judge.codingpanda.kr`을 사용하며, 다른 사이트에서는 해당 주소도 내 도메인으로 바꿉니다.
- `[sudo] password`가 나오면 서버 비밀번호를 입력합니다(화면에 보이지 않는 것이 정상).
- 한 번 입력한 명령은 방향키(↑)로 다시 불러와 고쳐 쓸 수 있습니다.

## vi 편집기 사용법

| 하고 싶은 일 | 키 |
|---|---|
| 글자 찾기 | `/찾을글자` 입력 후 `Enter` (다음 찾기 `n`) |
| 커서 위치에서 입력 시작 | `i` |
| 커서 아래에 새 줄을 만들고 입력 | `o` |
| 커서 위에 새 줄을 만들고 입력 | `O` (대문자) |
| 한 글자 지우기 | 입력 중이 아닐 때 `x` |
| 입력 끝내기 | `Esc` |
| 저장하고 나가기 | `Esc` → `:wq` → `Enter` |
| 저장하지 않고 나가기 | `Esc` → `:q!` → `Enter` |

입력 중에 마우스 오른쪽 버튼을 누르면 복사한 내용이 붙여 넣어집니다. 줄 맨 앞에 `#`을 넣으면 그 줄은 주석(사용 안 함)이 됩니다.

---

# A. 공통 필수

## 1. Ubuntu 24.04 LTS에 HUSTOJ 설치 (이미 설치된 서버는 건너뜀)

```
wget -O install-hustoj-ubuntu24.04.sh https://raw.githubusercontent.com/zhblue/hustoj/master/trunk/install/install-ubuntu24.04.sh
```

```
sudo bash install-hustoj-ubuntu24.04.sh
```

[공식 HUSTOJ 저장소](https://github.com/zhblue/hustoj)의 현재 Ubuntu 24.04 설치 스크립트를 내려받아 실행합니다. 설치가 끝나면 다음 단계로 진행합니다.

## 2. 시스템·PHP 보안 업데이트

모든 플러그인의 공통 작업입니다. 운영 중인 서버는 수업·채점·실시간 활동이 없는 시간에 **20단계의 DB·웹 파일·서버 설정 백업부터 완료**합니다. 새 서버에서 아직 보관할 자료가 없을 때만 백업을 건너뜁니다. 이 단계는 설치된 OS와 PHP 8.3 계열의 패키지를 갱신하며, OS 배포판이나 PHP 주 버전을 바꾸는 절차는 아닙니다.

먼저 OS, CLI·FPM 버전과 설치 패키지 전체 버전을 확인합니다.

```bash
cat /etc/os-release
```

```bash
php8.3 -v
```

```bash
sudo php-fpm8.3 -v
```

```bash
dpkg-query -W -f='${binary:Package} ${Version}\n' php8.3-common php8.3-cli php8.3-fpm nginx zlib1g
```

```bash
sudo systemctl status php8.3-fpm --no-pager
```

PHP 8.3-FPM이 실제 웹 요청을 처리하는지 WordPress **도구 → 사이트 건강 → 정보 → 서버**의 PHP 버전과 대조합니다. CLI 결과만으로 웹 PHP의 패치 적용을 판정하지 않습니다. FPM 버전이나 서비스가 다르면 이 문서의 8.3 명령을 그대로 실행하지 말고 실제 서비스와 설치 경로에 맞춥니다.

```
sudo apt update
```

패키지 목록 갱신이 성공한 뒤 설치 버전(`Installed`), 제공 버전(`Candidate`), 저장소 주소를 확인합니다.

```bash
apt-cache policy php8.3-common php8.3-cli php8.3-fpm nginx zlib1g
```

Ubuntu 공식 패키지는 [Ubuntu 보안 공지](https://ubuntu.com/security/notices)에서 **실제 OS 릴리스와 해당 PHP 패키지의 수정 버전**을 확인합니다. 배포판은 보안 수정을 기존 버전에 반영할 수 있으므로 `PHP 8.3.x` 숫자만 비교하지 않고 `dpkg-query`의 배포판 수정 번호까지 대조합니다. 별도 PHP 저장소를 사용한다면 그 공급자의 공지와 패키지 상태를 확인합니다. [PHP 지원 기간](https://www.php.net/supported-versions.php)도 함께 확인하며, 저장소 오류·지원 종료·보류 패키지가 있으면 이를 먼저 해결합니다.

예를 들어 [2026-10-21 PHP 보안 공지](https://www.php.net/archive/2026.php#2026-10-21-5)의 CVE-2026-109618·CVE-2026-109619는 업스트림 PHP 8.3.36에서 수정됐습니다. 같은 결함의 [Ubuntu USN-8948-1](https://ubuntu.com/security/notices/USN-8948-1)은 **Ubuntu 24.04 공식 PHP 8.3 CLI·FPM 패키지의 수정 버전을 `8.3.6-0ubuntu0.24.04.13`**으로 안내합니다. 숫자 표기가 달라도 배포판이 수정을 반영한 것입니다. 이 값은 해당 공지의 수정 기준이며, 앞으로도 최신 버전이라는 뜻은 아닙니다. 실제 OS·저장소에서 이 수정이 포함된 버전 또는 이후 보안 업데이트가 설치됐는지 확인합니다.

[2026-10-22 Ubuntu USN-8954-1](https://ubuntu.com/security/notices/USN-8954-1)의 zlib CVE-2026-85091은 특정 비차단 쓰기 처리의 메모리 오류로 서비스 중단이나 임의 코드 실행을 일으킬 수 있는 결함입니다. **Ubuntu 24.04의 수정 버전은 `zlib1g`와 `zlib1g-dev`의 `1:1.3.dfsg-3.1ubuntu2.3`**입니다. 개발 패키지가 이미 설치돼 있으면 함께 갱신하고, 이후 업데이트가 제공되면 그 버전을 사용합니다. 라이브러리 파일의 교체와 실행 중인 서비스에 대한 반영을 모두 확인해야 합니다.

```bash
sudo apt upgrade --simulate
```

변경 예정 패키지와 재시작 영향을 확인한 뒤 적용합니다.

```
sudo env NEEDRESTART_MODE=l apt upgrade
```

계속할지 물으면 `y`를 누릅니다. 기존 설정 파일을 바꿀지 묻는 경우에는 현재 설정을 보존하고 차이를 검토합니다. PHP 확장도 같은 8.3 저장소의 업데이트가 적용되는지 확인합니다. `NEEDRESTART_MODE=l`은 needrestart가 재시작 대상을 목록으로만 제시하게 합니다. 패키지 자체 설치 스크립트의 재시작까지 막는 설정은 아니므로, 시뮬레이션에서 containerd 등 사용 중인 서비스의 갱신이 보이면 해당 작업의 중단 영향을 먼저 확인합니다.

업데이트 후 재시작이 필요한 프로세스와 커널 상태를 읽기 전용으로 확인합니다. needrestart가 없으면 `sudo apt install needrestart`로 설치한 뒤 실행합니다.

```bash
sudo needrestart -r l
```

```bash
sudo php-fpm8.3 -t
```

검사가 성공하면 실행 중인 FPM을 새 패키지로 다시 시작합니다. 진행 중인 웹 요청은 중단될 수 있습니다.

```bash
sudo systemctl restart php8.3-fpm
```

```bash
sudo systemctl is-active php8.3-fpm
```

```bash
sudo nginx -t
```

nginx 검사까지 성공한 뒤 다시 불러옵니다. 검사 실패 시 reload하지 않고 오류 위치와 20단계 백업을 확인합니다.

```bash
sudo systemctl reload nginx
```

위 reload는 설정 변경을 적용하는 절차입니다. nginx나 zlib·OpenSSL 같은 공유 라이브러리를 갱신했다면 **nginx master도 새 라이브러리로 실행해야 하므로 reload만으로 마치지 않습니다.** 진행 중인 요청과 수업·채점 작업이 없는 유지보수 시간에 다음을 실행합니다. 이 재시작은 짧은 서비스 중단을 일으킬 수 있습니다.

```bash
sudo nginx -t
```

```bash
sudo systemctl restart nginx
```

```bash
sudo systemctl is-active nginx
```

```bash
sudo needrestart -r l
```

다른 재시작 대상도 실제 사용 중인 작업을 확인한 뒤 처리합니다. 중단을 피하려고 보류한 서비스는 이름과 유지보수 예정 시각을 기록하고, 재시작 전까지 해당 프로세스에는 수정된 라이브러리가 반영되지 않았다고 표시합니다.

`dpkg-query`, FPM 버전 및 사이트 건강의 PHP 버전을 다시 대조합니다. `Installed`와 `Candidate`가 같다는 사실만으로 모든 취약점이 해결됐다고 판정하지 않습니다. WordPress **대시보드 → 업데이트**에서 코어·테마·외부 플러그인의 업데이트도 확인하고, OJ 플러그인은 해당 설치 안내서에 따라 ZIP 교체와 필요한 스키마 갱신을 완료합니다.

기존 운영 서버는 5단계의 **GET 응답 헤더 확인**을 이어서 수행합니다. 브라우저에서 로그인·로그아웃, 비밀번호 복구 화면, 문제 조회, 시험 계정의 제출·결과, 관리자 화면을 확인합니다. 사용하는 애드온의 첨부파일·읽기자료와 실시간 교실도 확인합니다. 기능 오류가 발생하면 오류 로그와 변경 패키지·설정을 대조하고 백업 복구 또는 수정 절차를 진행합니다. 서비스가 정상이고 필요한 커널 업데이트의 재부팅 여부까지 확인한 뒤 점검을 마칩니다.

### 실행 중인 커널 확인

패키지 설치와 실행 중인 커널은 별개입니다. 다음 결과가 다르거나 재부팅 필요 파일이 있으면 새 커널이 아직 사용되지 않을 수 있습니다.

```bash
uname -r
```

```bash
dpkg-query -W -f='${binary:Package} ${Version}\n' linux-generic linux-image-generic
```

```bash
test ! -f /var/run/reboot-required || cat /var/run/reboot-required
```

재부팅을 보류하면 **새 커널 설치 완료·실행 중인 커널은 이전 버전·재부팅 예정 시각**을 기록합니다. 설치 완료를 커널 보안 수정의 활성화 완료로 표시하지 않습니다. 수업·채점·실시간 경기가 없는 유지보수 시간을 정하고 20단계 백업과 콘솔 복구 수단을 확인한 뒤에만 다음을 실행합니다. 패키지 설치 직후 자동으로 재부팅하지 않습니다.

```bash
sudo systemctl reboot
```

SSH 연결이 끊긴 뒤 서버가 돌아오면 다시 접속해 확인합니다.

```bash
uname -r
```

```bash
sudo systemctl is-active nginx php8.3-fpm
```

```bash
sudo systemctl --failed --no-pager
```

```bash
sudo needrestart -r l
```

새 커널 실행과 nginx·PHP-FPM·채점기·사용 중인 실시간 서비스의 정상 상태, 실제 로그인·문제 조회·시험 제출 결과를 확인한 뒤 재부팅 점검을 마칩니다.

### 실패 서비스의 사용 여부와 부팅 대기

실패 표시만으로 서비스를 미사용으로 판단하지 않습니다. 해당 부팅의 시작 오류와 실제 앱 설정·클라이언트·소켓·역의존성을 먼저 확인하고, 결과를 기록한 뒤 필요한 항목만 수정합니다. 인증정보·캐시 키와 값·설정 원문은 점검 기록에 복사하지 않습니다.

**memcached:** 현재 `judge.codingpanda.kr` 서버에서는 IPv6가 꺼져 있는데 `::1`에 바인딩하려다 실패한 것으로 확인했습니다. WordPress는 기본 캐시를 사용하고 `object-cache.php`·외부 객체 캐시가 없으며 PHP 세션은 `files`입니다. 앱·설정 파일, 다른 가상 호스트·컨테이너, 연결 소켓과 역의존성까지 확인해 사용처가 없다는 근거를 모았습니다. 다른 서버는 같은 결과가 확인될 때만 아래 정리를 선택합니다. PHP 확장이 설치됐다는 사실이나 잠깐 연결이 없다는 사실만으로 판단하지 않습니다.

```bash
sudo systemctl disable memcached.service
sudo systemctl reset-failed memcached.service
sudo systemctl is-enabled memcached.service
```

`disabled`를 확인하고 패키지와 `/etc/memcached.conf`는 보존합니다. 나중에 사용하는 앱이 생기면 `sudo vi /etc/memcached.conf`로 열어 `/-l`로 수신 주소를 찾고 실제 클라이언트의 IPv4·UNIX 소켓 접속 방식에 맞춥니다. 현재처럼 IPv6가 꺼져 있고 IPv4로 연결할 때는 `-l 127.0.0.1`로 제한한 뒤 `sudo systemctl enable --now memcached.service`와 상태·앱 연결을 확인합니다. 외부 전체 주소에 수신하도록 바꾸지 않습니다.

**networkd 부팅 대기:** 현재 서버는 networkd가 실제 네트워크를 관리하며 Docker·HUSTOJ·nginx가 `network-online.target`에 의존하므로 대기 서비스를 유지합니다. `eth0`은 유일한 기본 경로이고 정상 연결됐지만, 연결되지 않은 `eth1`·`eth2`·`eth3`도 필수 DHCP 대상으로 대기하여 120초 뒤 실패했습니다. 다른 서버에서는 인터페이스·기본 경로·역의존성을 먼저 확인합니다.

```bash
networkctl list --no-pager
ip route show default
sudo systemctl list-dependencies --reverse network-online.target --all --no-pager
```

20단계로 Netplan 원본을 백업하고, 현재 `eth0` 생성 파일의 SHA-256을 메모한 뒤 엽니다. 아래 파일명은 현재 서버 기준이므로 다른 서버에서는 실제 인터페이스와 원본 파일을 확인합니다. Netplan 원본은 연결 설정을 담으므로 root 소유를 확인하고 `600`으로 제한해 다른 사용자의 접근과 과도한 권한 경고를 방지합니다. 대상은 이 네트워크 설정 파일 하나입니다.

```bash
sudo sha256sum /run/systemd/network/10-netplan-eth0.network
stat -c '%a %U:%G %n' /etc/netplan/00-installer-config.yaml
sudo chmod 600 /etc/netplan/00-installer-config.yaml
sudo vi /etc/netplan/00-installer-config.yaml
```

`/eth1:`로 해당 블록을 찾고 `dhcp4`·`dhcp6`와 같은 들여쓰기로 `optional: true`를 한 줄 추가합니다. `/eth2:`와 `/eth3:`에도 각각 추가하되 **eth0의 주소·DHCP·기본 경로 설정은 유지**합니다. 기존 `optional`이 있으면 중복하지 않고 값을 맞춥니다. 저장 후 설정 파일만 생성합니다.

```bash
sudo netplan generate
sudo sha256sum /run/systemd/network/10-netplan-eth0.network
sudo systemctl cat systemd-networkd-wait-online.service
```

생성이 성공하고 eth0 파일 해시가 이전과 같으며, 생성된 wait-online의 **두 ExecStart 모두 eth0만 기다리는지** 확인합니다. 결과가 다르면 원인을 확인하기 전 다음으로 진행하지 않습니다.

```bash
sudo systemctl daemon-reload
sudo systemctl show systemd-networkd-wait-online.service -p ExecStart
sudo systemctl reset-failed systemd-networkd-wait-online.service
sudo systemctl start systemd-networkd-wait-online.service
sudo systemctl status systemd-networkd-wait-online.service --no-pager
```

`show`의 실제 로드 명령도 eth0만 포함하는지 확인한 뒤 `start`를 실행합니다. 성공한 oneshot은 `active (exited)`일 수 있습니다. 이 절차는 현재 대기 unit을 복구하고 다음 부팅용 설정을 준비하며, 실행 중인 네트워크 전체를 다시 적용하지 않습니다. `netplan apply`·networkd 재시작·`networkctl reload`는 이 복구에 필요하지 않습니다. SSH·사이트·채점기 상태를 확인하고 실제 시험 제출로 정상 판정을 확인한 뒤 마칩니다.

## 3. 도메인 연결 (nginx)

도메인 관리 사이트(가비아 등)에서 내 도메인의 **A 레코드**를 이 서버 IP로 먼저 등록해 둡니다.

```
sudo vi /etc/nginx/sites-enabled/default
```

- `/server_name` 으로 `server_name _;` 줄을 찾습니다.
- `_` 자리에 도메인을 적어 `server_name oj.school.kr;`로 바꾸고 저장합니다.

```
sudo nginx -t
```

`syntax is ok`, `test is successful`이 나와야 합니다.

```
sudo systemctl reload nginx
```

## 4. 방화벽

```
sudo ufw allow 'OpenSSH'
```

```
sudo ufw allow 'Nginx Full'
```

```
sudo ufw enable
```

질문에 `y`를 누릅니다. OpenSSH를 먼저 허용했으므로 접속이 끊기지 않습니다.

```
sudo ufw status
```

`OpenSSH`, `Nginx Full`이 `ALLOW`로 보이면 됩니다.

## 5. HTTPS 인증서

```
sudo apt install certbot python3-certbot-nginx
```

```
sudo certbot --nginx -d oj.school.kr
```

질문에 답합니다: 이메일 입력 → 약관 `Y` → 이메일 정보 제공 `N`. `Successfully deployed certificate`가 나오면 성공입니다.

```
sudo systemctl status certbot.timer
```

`active (waiting)`이면 인증서가 **자동 갱신**됩니다. `q`를 눌러 나옵니다.

```
sudo certbot renew --dry-run
```

`Congratulations, all simulated renewals succeeded`가 나오면 갱신 시험 성공입니다.

관리자 화면 **설정 → 일반**에서 "WordPress 주소"와 "사이트 주소"를 `https://oj.school.kr`로 바꾸고 저장합니다.

### HTTPS 응답 보안 헤더

모든 플러그인의 공통 작업입니다. 기존 운영 서버는 20단계에서 설정을 백업한 뒤 진행합니다. 아래 예시는 `judge.codingpanda.kr`의 HTTPS 인증서와 HTTP→HTTPS 이동이 정상인 상태를 전제로 합니다. 앱은 HTML의 프레임 제한·참조자 정책과 인증 화면의 캐시 금지를, nginx는 HTTPS의 HSTS와 전체 응답의 콘텐츠 형식 보호를 담당합니다.

```bash
sudo vi /etc/nginx/snippets/oj-security-headers.conf
```

새 파일에 `i`를 누르고 아래 내용을 넣습니다. 이미 파일이 있으면 `/Strict-Transport-Security`와 `/X-Content-Type-Options`로 찾아 같은 지시문을 중복 추가하지 않고 값을 맞춥니다.

```nginx
add_header Strict-Transport-Security "max-age=86400" always;
add_header X-Content-Type-Options "nosniff" always;
fastcgi_hide_header X-Content-Type-Options;
```

HSTS는 먼저 **하루(86400초)**로 시작합니다. HTTPS와 인증서 자동 갱신을 계속 확인한 뒤 기간을 늘리며, 별도 검토 없이 `includeSubDomains`나 `preload`를 추가하지 않습니다. 마지막 줄은 PHP-FPM이 보내는 같은 `nosniff` 헤더를 숨겨 nginx에서 한 번만 내보내기 위한 것입니다. CSP·`X-Frame-Options`·`Referrer-Policy`·`Cache-Control`은 이 스니펫에 넣지 않습니다. 앱의 화면별 정책을 그대로 전달해야 합니다.

```bash
sudo vi /etc/nginx/sites-enabled/default
```

1. `/server_name`으로 `judge.codingpanda.kr`이 포함된 서버를 찾고, `/listen`으로 **443 HTTPS `server { ... }` 블록**인지 확인합니다. 다른 파일을 사용하는 서버라면 실제 활성 사이트 파일을 엽니다.
2. 해당 HTTPS `server` 블록 안에 다음 한 줄을 추가합니다. 같은 include가 이미 있으면 추가하지 않습니다.

```nginx
include /etc/nginx/snippets/oj-security-headers.conf;
```

3. `/add_header`로 같은 블록과 포함된 설정을 살펴봅니다. HSTS·nosniff의 기존 중복 설정은 한 곳으로 합칩니다. 하위 `location`에 별도 `add_header`가 있으면 상위 헤더를 상속하지 않을 수 있으므로 **그 location에도 위 include를 한 번 추가**합니다. 기존 CSP 등을 무조건 지우지 말고 적용 대상을 확인합니다.
4. `/fastcgi_pass`로 PHP 처리 위치를 찾아 앱의 `Content-Security-Policy`, `X-Frame-Options`, `Referrer-Policy`, `Cache-Control`을 숨기거나 다른 값으로 바꾸는 설정이 없는지 확인합니다. CSP는 여러 번 보내면 정책들이 함께 적용되므로 서버에 같은 프레임 정책을 덧붙이지 않습니다. 기존 별도 정책이 필요하면 앱 정책과 함께 동작하는지 먼저 검토합니다.

`add_header`의 상속과 `always` 동작은 [nginx 공식 문서](https://nginx.org/en/docs/http/ngx_http_headers_module.html)를 참고합니다. 현재 nginx가 지원하는 지시문만 사용하며, 새 버전에만 있는 상속 지시문을 그대로 복사하지 않습니다.

```bash
sudo nginx -t
```

`syntax is ok`, `test is successful`일 때만 다시 불러옵니다.

```bash
sudo systemctl reload nginx
```

### GET 응답 헤더 확인

아래 명령은 페이지 본문을 버리는 **일반 GET**입니다. `curl -I`의 HEAD 결과만으로 판정하지 않습니다. 앱 ZIP을 교체하고 2단계의 PHP-FPM 재시작까지 마친 뒤 실행합니다.

```bash
curl --silent --show-error --max-time 20 --dump-header - --output /dev/null https://judge.codingpanda.kr/login
```

```bash
curl --silent --show-error --max-time 20 --dump-header - --output /dev/null 'https://judge.codingpanda.kr/login?mode=forgot'
```

```bash
curl --silent --show-error --max-time 20 --dump-header - --output /dev/null https://judge.codingpanda.kr/wp-login.php
```

```bash
curl --silent --show-error --max-time 20 --dump-header - --output /dev/null http://judge.codingpanda.kr/login
```

- HTTPS 응답: `Strict-Transport-Security: max-age=86400`, `X-Content-Type-Options: nosniff`가 각각 한 번씩 보이는지 확인합니다.
- OJ 로그인·복구 HTML: `X-Frame-Options: SAMEORIGIN`, CSP의 `frame-ancestors 'self'` 지시문, `Referrer-Policy: no-referrer`, `Cache-Control: private, no-store`, `X-Robots-Tag: noindex,nofollow`를 확인합니다. 헤더 이름의 대소문자와 쉼표 뒤 공백은 달라도 됩니다. 같은 로그인 화면의 비밀번호 재설정·계정 전환 mode에도 적용됩니다. 실제 복구 키가 포함된 URL이나 헤더 기록은 공유하지 않습니다.
- 일반 OJ HTML은 기본 `Referrer-Policy: strict-origin-when-cross-origin`과 `Cache-Control: private, no-store`를 사용합니다. 로그인 후 계정 설정·쪽지·제출·운영 화면도 저장하지 않습니다. 파일 응답·애드온 미리보기는 용도에 따라 별도 정책을 사용할 수 있습니다. 정적 자산까지 앱의 캐시 정책이나 CSP로 일괄 덮어쓰지 않습니다.
- HTTP 응답은 같은 주소의 HTTPS로 이동하는지 `Location`을 확인합니다. 별도 보안 플러그인·WAF를 사용한다면 실제 로그인 경로까지 속도 제한과 관리자 2단계 인증이 적용되는지 확인합니다.

헤더가 빠지거나 중복되면 실제 사이트 블록·하위 location 상속·PHP 전달 헤더·보안 플러그인·프록시/CDN 캐시 순서로 확인합니다. 보안 플러그인이나 nginx가 앱의 CSP·`X-Frame-Options`·`Referrer-Policy`를 제거하지 않도록 합니다. 변경 후 로그인과 관리자 화면, 파일·미리보기, 실시간 기능이 정상인지 브라우저에서도 확인합니다.

외부 캐시를 사용한다면 업데이트 전에 저장된 개인화 응답도 제거합니다. 캐시 플러그인·프록시·CDN의 관리 화면에서 페이지 캐시를 비우고, WordPress 로그인 쿠키가 있는 요청과 `/login`, `/user-settings`, `/messages`, `/submissions`, `/oj-console/` 경로를 캐시에서 제외합니다. `Cache-Control`과 `Set-Cookie`를 무시하고 HTML을 저장하도록 설정하지 않습니다. nginx에서 캐시를 직접 설정했다면 `sudo vi /etc/nginx/sites-enabled/default`로 열어 `/fastcgi_ignore_headers`와 `/proxy_ignore_headers`를 찾습니다. 해당 지시문의 값에 `Cache-Control` 또는 `Set-Cookie`가 있으면 그 항목을 제거하고, 값이 남지 않으면 해당 지시문 줄을 삭제합니다. 이 지시문이 없으면 추가하지 않습니다. 수정한 뒤 위의 `sudo nginx -t`와 `sudo systemctl reload nginx`를 실행합니다. 브라우저 개발자 도구의 네트워크 탭에서 본인 계정 페이지의 `private, no-store`를 확인하며 로그인 쿠키와 응답 본문은 공유하지 않습니다.

### 업로드 실행과 민감 파일 접근 차단

20단계로 nginx 설정을 백업한 뒤 실제 사이트 설정을 엽니다. 현재 서버는 `sites-enabled/default`가 실제 설정 파일이므로 다른 예제 파일을 수정하지 않습니다.

```bash
sudo vi /etc/nginx/sites-enabled/default
```

`/location ~`로 PHP 처리 블록을 찾고, **그 블록보다 위의 같은 HTTPS server 블록 안에** 다음을 추가합니다. 기존 보안 location이 있으면 중복하지 않고 같은 제한이 적용되는지 확인합니다.

```nginx
location ~* ^/wp-content/uploads/.*\.(?:php[0-9]*|phtml|phar)(?:/|$) {
    deny all;
}
location ~ /\.(?!well-known(?:/|$)) {
    deny all;
}
location ~* ^/wp-config\.php(?:[.~].*)?$ {
    deny all;
}
location = /wp-content/debug.log {
    deny all;
}
```

업로드 자료의 정적 열람과 인증서의 `.well-known` 경로는 유지하고, PHP로 실행될 수 있는 업로드·숨김 파일·설정 백업·디버그 로그의 직접 요청만 차단합니다. `sudo nginx -t` 성공 후 `sudo systemctl reload nginx`로 적용합니다. `/login`과 업로드된 정상 이미지, 사용하는 실시간 교실을 확인합니다. 제한을 확인하려고 실제 웹셸을 업로드하지 않습니다.

WordPress 설정 파일의 내용은 화면이나 채팅에 출력하지 않습니다. 웹 PHP가 `www-data:www-data`로 실행되고 현재 파일도 해당 소유자·그룹인 것을 먼저 확인한 경우 다음 권한으로 다른 로컬 사용자의 읽기를 제한합니다.

```bash
stat -c '%a %U:%G %n' /home/judge/src/web/wp-config.php
sudo chmod 640 /home/judge/src/web/wp-config.php
```

소유자·그룹이 다르면 PHP가 읽을 수 있는 그룹인지 먼저 확인합니다. 디버그 로그가 이미 있으면 내용은 출력하지 않고 크기·소유자·그룹만 확인한 뒤, 웹 PHP가 계속 기록할 수 있는 소유 관계일 때 권한을 제한합니다.

```bash
stat -c '%a %U:%G %s %n' /home/judge/src/web/wp-content/debug.log
```

```bash
sudo chmod 640 /home/judge/src/web/wp-content/debug.log
```

대용량 `debug.log`도 **삭제·비우기·덮어쓰기하지 않고 그대로 보존**합니다. 위 nginx의 직접 접근 차단과 파일 권한 제한을 함께 적용하고, 20단계 백업에 포함합니다. 파일 내용을 브라우저·채팅에 게시하지 않습니다.

```bash
sudo vi /home/judge/src/web/wp-config.php
```

`/DISALLOW_FILE_EDIT`로 기존 선언을 먼저 찾습니다. 있으면 값을 `true`로 바꾸고, 없으면 `/wp-settings.php`로 `require_once ABSPATH . 'wp-settings.php';`를 찾아 그 줄 **위에** 다음을 한 번 추가합니다.

```php
define('DISALLOW_FILE_EDIT', true);
```

이 설정은 WordPress 내장 테마·플러그인 코드 편집기를 제한하며 ZIP 교체 업데이트는 유지합니다. 저장 후 문법과 로그인·관리자 화면을 확인합니다.

```bash
php8.3 -l /home/judge/src/web/wp-config.php
```

### 기본 WordPress 로그인 입구 확인

OJ 로그인 런타임을 사용할 수 있는 설치 상태에서 환경 준비 검사를 통과하고 **로그인과 계정** 기능이 켜져 있으면, 위 `wp-login.php`의 일반 GET 응답은 **302**, `Location`은 `/login`이어야 합니다. 하위 폴더 설치는 해당 설치 경로가 앞에 붙습니다. 이동된 OJ 화면의 보안 헤더는 앞의 `/login` GET으로 확인합니다. 기본 로그인에 보낸 **POST는 303**으로 이동하며 입력한 아이디·비밀번호는 전달하지 않습니다. HEAD는 302를 사용하지만 GET 확인을 함께 진행합니다.

복구 입구도 자격증명 없이 일반 GET으로 확인합니다.

```bash
curl --silent --show-error --max-time 20 --dump-header - --output /dev/null 'https://judge.codingpanda.kr/wp-login.php?action=lostpassword'
```

`302`와 `/login?mode=forgot`로의 `Location`을 확인합니다. `retrievepassword`도 같은 화면으로 연결합니다. 기존 복구 메일의 `rp`·`resetpass` GET 링크는 `key`와 `login`만 OJ 비밀번호 재설정 화면으로 옮깁니다. 실제 복구 링크로 공개 점검을 수행하거나 키를 기록·공유하지 않습니다. 기본 회원가입 입구는 OJ `/register`로 연결하며, 데이터 이전용 제한 런타임에서는 `/login`으로 연결합니다.

초기 설치·업그레이드 대기처럼 OJ 로그인이 아직 가동되지 않는 상태, 환경 준비 오류 또는 **WordPress가 검증한 복구 모드**에서는 기본 로그인을 유지합니다. `interim-login`·`reauth` 같은 query 값만으로 예외가 열리지는 않습니다. 로그아웃(`logout`), 암호로 보호된 글(`postpass`), 요청 확인(`confirmaction`), 로그인된 관리자의 관리자 이메일 확인(`confirm_admin_email`)은 WordPress가 처리합니다. `/wp-admin/`, `admin-post.php`, `admin-ajax.php` 전체를 nginx에서 막지 않습니다.

업데이트 후 브라우저에서 `/login`으로 로그인한 뒤 `/wp-admin/`을 직접 열어 관리자 화면 접근을 확인합니다. 관리자 화면으로 자동 복귀하는지는 별도 동작이므로 기본 로그인 주소의 이동만으로 판정하지 않습니다. 관리자 세션 만료 시 재로그인 안내의 링크를 눌러 새 탭에서 OJ 로그인 화면이 열리는지도 확인합니다. 이 설정은 XML-RPC·REST·다른 플러그인의 인증까지 일괄 차단하지 않습니다.

### 로그인 장애 시 서버에서 비상 해제

OJ 로그인 장애로 관리자 화면에 들어갈 수 없을 때만 서버 관리자가 설정합니다. 20단계의 설정 백업을 확인한 뒤 다음 파일을 엽니다.

```bash
sudo vi /home/judge/src/web/wp-config.php
```

`/require_once`로 찾고 `n`으로 `require_once ABSPATH . 'wp-settings.php';` 줄까지 이동합니다. `O`를 눌러 **그 줄 위에** 다음 한 줄을 추가하고 저장합니다. 같은 상수가 이미 있으면 중복 선언하지 않고 값을 `true`로 바꿉니다.

```php
define('OJ_PLATFORM_ALLOW_WORDPRESS_LOGIN', true);
```

이 상수는 OJ가 등록하는 기본 로그인 입구 차단과 관리자 재인증 화면 설정을 함께 해제합니다. `https://judge.codingpanda.kr/wp-login.php`에서 기본 WordPress 로그인이 열리는지 확인하고 로그인 장애의 원인을 해결합니다. 저장한 설정이 바로 반영되지 않으면 2단계의 PHP-FPM 재시작 절차를 따라 다시 확인합니다.

복구를 마치면 같은 파일을 열어 `/OJ_PLATFORM_ALLOW_WORDPRESS_LOGIN`으로 찾고 추가했던 줄을 삭제합니다. 원래부터 선언돼 있던 설정이라면 기존 값으로 복원합니다. OJ `/login` 로그인과 `/wp-admin/` 접근, 기본 `wp-login.php` GET의 302 이동을 다시 확인합니다. 비상 해제 상수를 켠 채 점검을 마치지 않습니다.

## 6. phpMyAdmin (DB 관리 도구)

```
sudo apt install phpmyadmin
```

- 웹서버 선택 화면: **아무것도 선택하지 않고** `Tab`으로 `<Ok>`에 가서 `Enter` (HUSTOJ는 nginx 사용)
- dbconfig-common 설정: `<Yes>`
- phpMyAdmin 암호: 원하는 암호 입력 후 `<Ok>`

```
sudo ln -s /usr/share/phpmyadmin /home/judge/src/web/dpma
```

웹 주소 `/dpma`로 phpMyAdmin이 열리게 연결합니다. 이름(`dpma`)은 남이 추측하기 어려운 다른 이름으로 바꿔도 됩니다.

브라우저에서 `https://oj.school.kr/dpma`를 열어 로그인 화면이 나오면 성공입니다.

## 7. 짧은 주소 사용과 업로드 크기 (nginx)

WordPress와 OJ 화면 주소(`/oj-console/...`)가 열리도록 nginx 주소 규칙을 바꿉니다. 두 파일을 같은 방법으로 고칩니다.

```
sudo vi /etc/nginx/sites-enabled/default
```

1. `/try_files` 로 찾아 `try_files $uri $uri/ =404;` 줄을 `try_files $uri $uri/ /index.php?$args;`로 고칩니다. (`n`으로 다음 것도 찾아 모두 고침)
2. `/server_name` 으로 `server_name oj.school.kr;` 줄을 찾고 `o`를 눌러 아래에 다음 줄을 추가합니다(이미 `client_max_body_size` 줄이 있으면 값만 `136m`으로 고침).

```
client_max_body_size 136m;
```

3. 저장하고 나갑니다.

```
sudo vi /etc/nginx/sites-available/default
```

같은 파일이 아니라면 위 1~3번을 똑같이 고칩니다(같은 내용이 이미 보이면 그대로 나갑니다).

```
sudo nginx -t
```

```
sudo systemctl reload nginx
```

## 8. PHP 확장과 업로드 한도

```
sudo apt install php8.3-xml php8.3-mysql php8.3-intl php8.3-mbstring php8.3-zip php8.3-curl
```

OJ 플러그인에 필요한 PHP 기능을 설치합니다. AI의 실제 연결 주소 고정에는 `php8.3-curl`이 필요하며, CLI와 FPM의 확장 활성화는 별도로 확인합니다. AI 제공자는 직접 HTTPS로 연결해야 하고 WordPress HTTP 프록시를 통한 AI 요청은 거부합니다.

```
sudo vi /etc/php/8.3/fpm/conf.d/99-oj.ini
```

새 파일에 `i`를 누르고 아래 4줄을 입력한 뒤 저장합니다.

```
upload_max_filesize = 128M
post_max_size = 136M
max_execution_time = 120
max_input_time = 120
```

```
sudo systemctl restart php8.3-fpm
```

## 9. 플러그인 업로드 권한

```
sudo chown -R www-data:www-data /home/judge/src/web/wp-content
```

관리자 화면에서 플러그인 ZIP을 올릴 수 있게 합니다. 하지 않으면 업로드할 때 FTP 정보를 묻습니다.

## 10. 웹에서 SPJ 컴파일 환경 설정

OJ Platform이 특수 채점기(SPJ)를 웹에서 컴파일할 수 있게 합니다.

```
sudo apt install -y bubblewrap gcc g++
```

```
sudo vi /etc/apparmor.d/bwrap
```

새 파일에 아래 내용을 입력하고 저장합니다.

```
abi <abi/4.0>,

include <tunables/global>

profile bwrap /usr/bin/bwrap flags=(unconfined) {
  userns,

  include if exists <local/bwrap>
}
```

```
sudo apparmor_parser -r /etc/apparmor.d/bwrap
```

```
sudo systemctl reload apparmor
```

```
sudo aa-status | grep bwrap
```

결과에 `bwrap`이 보이면 등록된 것입니다.

```
sudo -u www-data /usr/bin/bwrap --ro-bind / / --dev /dev --proc /proc --unshare-all /bin/true
```

```
echo $?
```

**`0`이 나와야 합니다.** 다른 숫자나 오류가 나오면 위 파일 내용을 다시 확인합니다.

## 11. 예약 작업 (1분마다)

```
sudo vi /etc/cron.d/oj-wp-cron
```

새 파일에 아래 한 줄을 입력하고 저장합니다.

```
* * * * * www-data curl -s -o /dev/null https://oj.school.kr/wp-cron.php
```

## 12. 채점 DB 정보 확인

```
sudo cat /home/judge/etc/judge.conf
```

`OJ_HOST_NAME`, `OJ_USER_NAME`, `OJ_PASSWORD`, `OJ_DB_NAME`(`jol`), `OJ_PORT_NUMBER` 값을 메모합니다. OJ Platform 설정 화면에 넣습니다.

```
timedatectl
```

`Time zone`이 `Etc/UTC`이면 OJ Platform DB 설정의 "기존 제출 일시가 UTC" 칸을 체크합니다.

**여기까지 마치면 관리자 화면에서 OJ Platform을 업로드합니다.** 아래는 해당 플러그인을 쓸 때 진행합니다.

---

# B. 플러그인별 설정

## 13. AI 활동지 (OJ AI Dashboard)

```
sudo mkdir /home/judge/oj-ai-private
```

```
sudo chown -R www-data:judge /home/judge/oj-ai-private
```

```
sudo vi /home/judge/src/web/wp-config.php
```

`/wp-settings` 로 `require_once ABSPATH . 'wp-settings.php';` 줄을 찾고, `O`를 눌러 **그 위에** 아래 줄을 넣은 뒤 저장합니다.

```
define('OJ_AI_PRIVATE_STORAGE_DIR', '/home/judge/oj-ai-private');
```

13단계를 마쳤으면 PHP를 다시 시작합니다.

```
sudo systemctl restart php8.3-fpm
```

## 15. 읽기자료 파일 업로드 (ReadContents)

```
sudo mkdir /home/judge/src/readcontents-imports
```

```
sudo chown -R www-data:www-data /home/judge/src/readcontents-imports
```

콘텐츠 ZIP을 서버의 `/tmp/`에 업로드한 뒤 아래 명령으로 웹 루트 밖 저장 폴더로 옮깁니다.

```
sudo mv /tmp/{{content_package}} /home/judge/src/readcontents-imports/
```

```
sudo chown www-data:www-data /home/judge/src/readcontents-imports/{{content_package}}
```

## 16. 채점기 설정 — 파이썬 실행 허용과 추가 모듈

HUSTOJ 채점기는 학생 프로그램이 쓰는 **시스템 콜**(운영체제 기능 호출)을 허용 목록으로 검사합니다. matplotlib·pandas 같은 모듈을 쓰는 파이썬 코드는 기본 목록에 없는 시스템 콜을 써서 "런타임 오류"로 채점될 수 있습니다. 채점기 소스를 고치고 다시 만들어 적용합니다.

### 16-1. 현재 채점 방식 확인

```
uname -m
```

CPU 종류를 확인합니다. 고칠 허용 목록 파일이 정해집니다(모두 `/home/judge/src/core/judge_client/` 폴더).

| 결과 | 허용 목록 파일 |
|---|---|
| `x86_64` | `okcalls64.h` |
| `aarch64` (ARM) | `okcalls_aarch64.h` |

```
sudo grep -E "^OJ_USE_DOCKER|^OJ_INTERNAL_CLIENT|^OJ_PYTHON_FREE" /home/judge/etc/judge.conf
```

채점 설정을 확인합니다. `OJ_USE_DOCKER` 값에 따라 적용 방법이 다릅니다.

| `OJ_USE_DOCKER` | 채점 방식 | 진행할 단계 |
|---|---|---|
| `1` | Docker 안에서 채점 | 16-3 → 16-4 → 16-5 → 16-6 |
| `0` | Docker 없이 서버에서 직접 채점 | 16-3 → 16-4 → 16-7 |

ARM 서버처럼 Docker 채점이 동작하지 않는 서버는 16-2단계에서 `OJ_USE_DOCKER=0`으로 바꿉니다.

### 16-2. Docker 사용 여부 바꾸기 (필요할 때만)

```
sudo vi /home/judge/etc/judge.conf
```

- `/OJ_USE_DOCKER` 로 찾아 값을 고칩니다. Docker 없이 채점하려면 `OJ_USE_DOCKER=0`, Docker로 채점하려면 `OJ_USE_DOCKER=1`입니다.
- 저장하고 나갑니다.

```
sudo pkill judged
```

```
sudo judged
```

채점 프로그램을 다시 시작해야 설정이 적용됩니다.

### 16-3. 파이썬의 execve 허용 횟수 늘리기 (x86_64)

파이썬 모듈은 실행 중에 다른 프로그램을 여러 번 실행(execve)합니다. 기본 설정은 R 언어만 여러 번 허용하므로 파이썬도 16회까지 허용합니다.

```
sudo vi /home/judge/src/core/judge_client/judge_client.cc
```

1. `/SYS_execve %` 로 찾습니다. 아래와 비슷한 두 줄이 나옵니다.

```
call_counter[SYS_execve %  call_array_size ]= 1;
if(lang == LANG_R ) call_counter[SYS_execve %  call_array_size ]= 8;
```

2. 둘째 줄을 다음처럼 고칩니다. `LANG_PYTHON` 조건을 넣고 숫자를 `16`으로 바꿉니다.

```
if(lang == LANG_R || lang == LANG_PYTHON ) call_counter[SYS_execve %  call_array_size ]= 16;
```

3. 저장하고 나갑니다.

> ARM(`aarch64`)은 이 줄을 쓰지 않습니다(바로 위 `#ifdef __aarch64__` 부분이 대신 쓰임). ARM의 파이썬 허용 목록(`okcalls_aarch64.h`의 `LANG_YV`)에는 execve(221번)가 이미 횟수 제한 없이 들어 있으므로 고치지 않아도 됩니다.

### 16-4. 파이썬 시스템 콜 허용 목록 추가

어떤 번호를 추가할지는 **채점 결과의 오류 메시지**로 확인합니다.

1. 관리자 계정으로 matplotlib 등을 쓰는 파이썬 정답 코드를 제출합니다.
2. 결과가 "런타임 오류"이면 제출 상세의 오류 내용에서 다음 문장을 찾습니다.

```
called a Forbidden system call:번호
```

3. 16-1단계에서 정한 파일을 엽니다(아래는 `x86_64` 예).

```
sudo vi /home/judge/src/core/judge_client/okcalls64.h
```

4. `/LANG_YV` 로 찾습니다. 이것이 파이썬 허용 목록입니다. `LANG_PV`는 Pascal 목록이므로 고치지 않습니다.
5. `{` 다음의 숫자 목록 끝(마지막 쉼표 뒤)에 커서를 두고 `i`를 눌러 오류에 나온 번호와 쉼표를 입력합니다. 예: 오류 번호가 `293`이면 `293,`
6. 저장하고 나갑니다.

이어서 Docker 채점은 16-6, 직접 채점은 16-7단계로 적용하고 같은 코드를 재채점합니다. 다른 번호로 또 오류가 나면 이 단계를 반복합니다.

### 16-5. Docker 이미지 설정 (Docker 채점, 처음 한 번)

Docker 이미지에 파이썬 모듈을 넣고, 이 서버에서 고친 채점기 소스를 쓰도록 설정합니다.

```
sudo vi /home/judge/src/install/Dockerfile
```

1. `/apt-get -y upgrade` 로 아래 줄을 찾습니다.

```
RUN rm -rf /var/lib/apt/list/* && apt-get clean && apt-get update && apt-get -y upgrade
```

그 줄에서 `o`를 눌러 아래에 다음 4줄을 추가합니다.

```
RUN DEBIAN_FRONTEND=noninteractive apt-get -y install --no-install-recommends python3-numpy
RUN DEBIAN_FRONTEND=noninteractive apt-get -y install --no-install-recommends python3-pandas
RUN DEBIAN_FRONTEND=noninteractive apt-get -y install --no-install-recommends python3-matplotlib
RUN DEBIAN_FRONTEND=noninteractive apt-get -y install --no-install-recommends python3-seaborn
```

2. `/hustoj.tar.gz` 로 아래 4줄을 찾습니다.

```
RUN \
    cd /home/judge/ && \
    wget dl.hustoj.com/hustoj.tar.gz && \
    tar xzf hustoj.tar.gz
```

4줄 모두 맨 앞에 `#`을 넣어 주석 처리하고, 그 아래에 다음 줄을 추가합니다. 인터넷에서 원본을 새로 받지 않고, 이 서버에서 고친 소스(16-3·16-4)를 이미지에 넣습니다.

```
COPY . /home/judge/src/
```

3. `/COPY testlib` 로 찾아 두 줄을 고칩니다.

| 원래 줄 | 고친 줄 |
|---|---|
| `COPY testlib.h /usr/include` | `COPY install/testlib.h /usr/include` |
| `COPY makeout.sh /usr/bin` | `COPY install/makeout.sh /usr/bin` |

4. 저장하고 나갑니다.

```
sudo vi /home/judge/src/install/docker.sh
```

`/docker build` 로 `docker build -t hustoj .` 줄을 찾아 다음처럼 고치고 저장합니다.

```
docker build -t hustoj -f /home/judge/src/install/Dockerfile /home/judge/src
```

### 16-6. 적용 — Docker 이미지 다시 만들기 (Docker 채점)

`OJ_INTERNAL_CLIENT=1`(기본값)이면 Docker 이미지 **안에 들어 있는 채점기**로 채점합니다. 그래서 **`judge_client.cc`나 `okcalls` 파일을 고칠 때마다 이미지를 다시 만들어야** 고친 내용이 적용됩니다.

```
cd /home/judge/src/install
```

```
sudo bash docker.sh
```

Docker 이미지를 다시 만듭니다. 시간이 오래 걸립니다.

```
sudo pkill judged
```

```
sudo judged
```

### 16-7. 적용 — 직접 채점 (`OJ_USE_DOCKER=0`)

Docker 없이 채점하므로 파이썬 모듈을 채점하는 서버에 직접 설치합니다(처음 한 번).

```
sudo apt install python3-numpy python3-pandas python3-matplotlib python3-seaborn
```

허용 목록을 고칠 때마다 채점기를 다시 컴파일합니다.

```
cd /home/judge/src/core
```

```
sudo bash make.sh
```

`done!`이 나오면 됩니다. 컴파일 오류가 나면 16-4에서 쉼표를 빠뜨리지 않았는지 확인합니다.

```
sudo pkill judged
```

```
sudo judged
```

> 직접 채점에서는 파이썬이 격리 폴더(chroot) 안에서 실행됩니다. 이때 설치한 모듈을 찾지 못해 `ModuleNotFoundError`가 나면, `judge.conf`에서 `OJ_PYTHON_FREE=1`로 바꾸고 채점 프로그램을 다시 시작합니다. 파이썬을 격리 없이 실행해 서버의 모듈을 그대로 쓰게 하는 설정입니다. 시스템 콜 허용 목록 검사는 그대로 적용됩니다.

**확인**: 16-4단계의 파이썬 정답 코드를 재채점해 "정답"이 나오면 완료입니다.

## 17. matplotlib 채점 환경 (그림 채점)

```
sudo mkdir /home/judge/data/_capture
```

```
sudo chown www-data:judge /home/judge/data/_capture
```

```
sudo chmod 2775 /home/judge/data/_capture
```

정답 그림을 캡처해 두는 폴더입니다.

---

# C. 선택

## 18. Teaching Tools 공통 실시간 서버 (선택)

티칭툴의 판서와 수업 신호를 실시간으로 전달하는 서버를 설치합니다. Node.js 24 이상, Ubuntu/Debian, systemd, 기존 nginx 사이트가 필요합니다. Node가 없거나 오래되었으면 설치기가 24를 설치합니다.

**변경 파일**

- `/opt/oj-realtime-hub/config.json`: 앱 등록·비밀키·허용 출처. 웹에서 공개하지 않습니다.
- `/opt/oj-realtime-hub/modules/teaching-tools/`: 판서 중계 모듈.
- `/etc/systemd/system/oj-realtime-hub.service`: 공통 서비스, 내부 `127.0.0.1:3210`.
- `/etc/nginx/snippets/oj-realtime-hub.conf`: `/classroom-live` 및 상태 경로 전달.
- `/etc/nginx/sites-enabled/default`: 해당 사이트 블록에 스니펫 연결.

설치기는 `/opt/.oj-realtime-backup.*`에 기존 서버·설정을 백업합니다. nginx 검사나 인증 확인이 실패하면 이전 설치로 복구를 시도하고 백업 경로를 표시합니다. 충돌하는 경로나 다른 프로그램이 사용하는 포트는 덮어쓰지 않습니다. 설치된 공통 런타임이 더 최신이면 낮추지 않습니다.

**서버 갱신은 실시간 연결을 재시작합니다.** 수업이 없는 시간에 실행합니다. 플러그인 업데이트·주소·비밀키 변경 후에는 설정 화면의 새 명령을 사용합니다.

### 18-2. Teaching Tools 설치·업데이트

티칭툴 ZIP을 업로드·활성화하고 **교실 도구 → 설정**에서 실시간 전송을 켜 `wss://oj.school.kr/classroom-live` 주소를 저장합니다. **서버 원클릭 설치** 칸의 실제 명령을 SSH에서 실행합니다. 설치기가 요청하면 설정 화면의 `ojhub1...` 코드를 붙여 넣습니다. 코드는 숨김 입력으로 읽어 명령줄 인수나 셸 기록에 남기지 않습니다.

```bash
sudo bash /home/judge/src/web/wp-content/plugins/teaching-tools/realtime-server/install.sh
```

다른 nginx 사이트 파일을 쓰면 명령 끝에 실제 경로를 붙입니다.

```bash
sudo bash /home/judge/src/web/wp-content/plugins/teaching-tools/realtime-server/install.sh /etc/nginx/sites-enabled/oj.school.kr
```

설치된 서버를 **기존 설정 그대로 업데이트**하려면 새 티칭툴 ZIP을 먼저 업로드하고 아래 명령을 실행합니다. 설정 코드를 다시 입력하지 않습니다. 설정 화면의 **기존 설정 유지 업데이트 → 업데이트 명령 복사**에서도 서버의 실제 경로가 들어간 명령을 확인할 수 있습니다.

```bash
sudo bash /home/judge/src/web/wp-content/plugins/teaching-tools/realtime-server/install.sh --update
```

주소·인증키·사용자 지정 포트·환경값과 저장된 데이터는 유지합니다. nginx·systemd 유닛·자동 시작 설정은 변경하지 않고 모듈 코드를 갱신합니다. 기존 서버를 백업하고 서비스를 재시작한 뒤 인증을 확인하며, 실패하면 이전 서버를 복구합니다. 최초 설치나 주소·비밀키를 의도적으로 변경할 때는 `--update` 없이 일반 설치 명령과 새 설정 코드를 사용합니다. 기존 티칭툴 단독 `.env` 허브도 이전합니다.

```bash
sudo systemctl status oj-realtime-hub --no-pager
```

```bash
curl http://127.0.0.1:3210/classroom-live/healthz
```

**확인**: 설정 화면의 **버전·연결 다시 확인**에서 앱 등록·비밀키 인증까지 성공하고, 설치된 티칭툴 모듈·공통 서버가 동봉 버전보다 낮지 않은지 확인합니다. 업데이트 필요 안내가 남으면 `--update` 명령을 실행합니다. 참여 코드로 학생을 연결해 교사 판서가 전달되는지도 확인합니다. 서버가 없거나 연결이 끊기면 REST 폴링으로 대체되며, 실시간 연결 중에도 저장본 복구 조회를 유지합니다.

### nginx 충돌 시

사이트 파일에 직접 작성한 `location /classroom-live`가 있으면 설치기가 중단합니다. 아래 명령으로 파일을 열고 해당 경로를 검색해 **충돌한 location 블록만** 제거한 후 다시 설치합니다. 다른 경로는 유지합니다. 기존 `include snippets/oj-realtime-hub.conf;`는 자동 재사용합니다.

```bash
sudo vi /etc/nginx/sites-enabled/default
```

### 서버 등록을 제거할 때

WordPress 플러그인 비활성화·삭제는 서버를 중지하지 않습니다. 등록까지 제거하려면 수업이 없는 시간에 다음 순서로 진행합니다.

```bash
sudo cp -p /opt/oj-realtime-hub/config.json /opt/oj-realtime-hub/config.json.before-remove
```

```bash
sudo vi /opt/oj-realtime-hub/config.json
```

`/"id": "teaching-tools"`로 찾아 `apps` 배열에서 해당 앱 객체를 삭제합니다. 다른 앱 객체·인증키는 유지하고, 남은 배열의 마지막 객체 뒤 쉼표를 제거합니다. 모두 제거했다면 `"apps": []`로 남깁니다.

```bash
sudo node -e 'require("/opt/oj-realtime-hub/src/registration").validateConfig(require("/opt/oj-realtime-hub/config.json")); console.log("OK")'
```

검사가 실패하면 재시작하지 말고 백업을 복구합니다.

```bash
sudo cp -p /opt/oj-realtime-hub/config.json.before-remove /opt/oj-realtime-hub/config.json
```

검사에 성공한 경우 사이트 파일에서 `include snippets/oj-realtime-hub.conf;` 줄을 찾아 제거합니다. 다른 모듈이 사용 중이면 해당 include를 유지합니다.

```bash
sudo vi /etc/nginx/sites-enabled/default
```

```bash
sudo nginx -t
```

nginx 검사에 성공한 뒤 서버와 nginx를 다시 불러옵니다.

```bash
sudo systemctl restart oj-realtime-hub
```

```bash
sudo systemctl reload nginx
```

`apps`가 비어 있고 더 이상 사용하는 모듈이 없을 때만 `sudo systemctl disable --now oj-realtime-hub`로 서비스를 중지합니다. 모듈 폴더·백업은 자동 삭제하지 않습니다.

## 19. 첨부파일 바이러스 검사 ClamAV — OJ AI Dashboard

```
sudo apt install clamav clamav-daemon
```

```
sudo systemctl enable --now clamav-daemon
```

```
sudo vi /home/judge/src/web/wp-config.php
```

`/wp-settings` 로 찾고 `O`를 눌러 그 위에 아래 줄을 넣은 뒤 저장합니다.

```
define('OJ_AI_CLAMD_SOCKET', 'unix:///var/run/clamav/clamd.ctl');
```

```
sudo systemctl restart php8.3-fpm
```

## 20. 백업 — 업데이트 전에

운영 중인 서버의 PHP·nginx·WordPress·플러그인을 바꾸기 전에 진행합니다. DB, 웹 파일과 서버 설정을 같은 시점에 보관합니다. 아래 폴더 이름의 날짜는 작업일로 바꾸고, 이전 백업 폴더가 있으면 새 이름을 사용합니다. 백업은 웹 루트 안에 두지 않습니다.

```bash
umask 077
```

```bash
mkdir -m 700 ~/oj-security-backup-20261023
```

```
sudo grep DB_NAME /home/judge/src/web/wp-config.php
```

WordPress DB 이름을 확인합니다(두 번째 따옴표 안의 값, 예: `wordpress`).

```
sudo mysqldump --single-transaction --routines --triggers --events wordpress > ~/oj-security-backup-20261023/wordpress.sql
```

`wordpress` 자리에 확인한 DB 이름을 넣습니다.

```
sudo mysqldump --single-transaction --routines --triggers --events jol > ~/oj-security-backup-20261023/jol.sql
```

```
sudo tar -czf ~/oj-security-backup-20261023/web.tar.gz -C /home/judge/src web
```

```bash
sudo tar -czf ~/oj-security-backup-20261023/server-config.tar.gz -C / etc/nginx etc/php/8.3
```

별도 플랫폼 DB나 웹 루트 밖 AI 저장소를 쓰면 해당 DB·폴더도 같은 백업에 포함합니다. 백업 중 쓰기가 계속되면 DB와 파일 시점이 어긋날 수 있으므로 점검 시간에 수행합니다. `--single-transaction`은 트랜잭션을 지원하는 테이블에 적용되며, 그 밖의 테이블이 있으면 쓰기를 중지한 상태에서 백업합니다.

```bash
sudo gzip -t ~/oj-security-backup-20261023/web.tar.gz ~/oj-security-backup-20261023/server-config.tar.gz
```

```bash
ls -lh ~/oj-security-backup-20261023
```

모든 명령이 오류 없이 끝났는지, SQL 파일이 비어 있지 않은지 확인합니다. 백업에는 DB 비밀번호·개인정보가 포함될 수 있으므로 관리자만 접근할 수 있는 별도 저장소에도 복사하고 시험 환경에서 복원을 확인합니다. 운영 사이트에서 복원 시험을 하지 않습니다. phpMyAdmin(`/dpma`)의 **내보내기**로 DB를 백업해도 웹 파일·서버 설정 백업은 따로 필요합니다. 백업을 마쳤으면 2단계 또는 진행 중이던 업데이트 단계로 돌아갑니다.

## 21. 서버 준비 명령 복사

플러그인 목록의 **서버 준비 필요** 안내 또는 각 플러그인 설정의 **서버 설치·복구**에서 AI 저장소(13), 읽기자료 폴더(15), 그림 캡처 폴더(17), ClamAV(19)의 명령을 복사합니다. AI 활동지는 업로드 후 활성화 전에 준비합니다. 실시간 서버(18)는 각 플러그인 설정의 **서버 설치·복구** 창을 사용합니다.

명령은 WordPress와 같은 Ubuntu/Debian 서버의 SSH에서 실행합니다. Bash 진입점이 필요한 PHP CLI를 자동 준비하고, AI·ClamAV 설정 후 해당 PHP-FPM 서비스를 재시작합니다. 기존 값이 다른 설정은 덮어쓰지 않습니다. `wp-config.php` 백업은 웹 루트 밖 `../.oj-server-backups/`에 관리자 전용으로 보관합니다. 기존 데이터의 소유권은 재귀 변경하지 않습니다.

실행 후 화면을 새로고침해 저장소 준비 상태를 확인하고 활성화합니다. AI 필수 확장과 ClamAV 상태는 해당 플러그인의 시스템 상태에서도 확인합니다. 실패하면 SSH에 표시된 원인을 해결하고 같은 명령을 다시 실행합니다. 도메인·HTTPS·방화벽·채점기 허용 목록은 기존 단계대로 진행합니다.

AI 활동지 준비 명령 예시(기본 서버 경로):

```bash
sudo bash /home/judge/src/web/wp-content/plugins/oj-ai-dashboard/resources/server/install.sh ai /home/judge/src/web 8.3 --apply
```

AI 설치기는 `oj-ai-dashboard`에 있으며 `ai`·`clamav`를 제공합니다. 읽기자료는 `readcontents-library`의 `readcontents`, 그림 채점은 `oj-platform`의 `capture`를 사용합니다. 공통 화면에는 업로드된 플러그인만 표시합니다. 실제 서버 경로·PHP 버전은 설치 화면에서 생성한 명령을 우선합니다.

설치·폴더 권한·서비스 재시작은 `install.sh`가 담당하고, `wp-config-helper.php`는 WordPress 설정 파일 검사·백업·수정만 담당합니다. 서버 작업에는 웹 PHP 버전과 같은 CLI를 사용하며 웹 PHP 버전을 자동 변경하지 않습니다.

OJ 서버 설치는 상시 메뉴에 두지 않습니다. 필수 준비가 빠진 업로드된 플러그인에만 설치 안내를 표시합니다. 활성화 전에는 플러그인 목록의 안내를 사용하고, 활성화 후에는 OJ 콘솔 첫 화면의 안내에서 해당 플러그인 설정으로 이동합니다. 필수 저장소·확장을 준비하면 안내는 자동으로 사라집니다. 선택 기능 설치는 사용 여부를 확인한 뒤 진행합니다.
