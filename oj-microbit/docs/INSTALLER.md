# 마이크로비트 실행기 한 명령 설치

현재 플러그인 ZIP을 WordPress에 설치·활성화한 뒤 **서버 SSH**에서 실행합니다. 웹 화면에서 root 명령을 실행하는 기능은 없습니다. **설정 → OJ Microbit**에서 실제 사이트 경로에 맞는 명령을 복사할 수 있습니다.

```bash
sudo bash /home/judge/src/web/wp-content/plugins/oj-microbit/runner/install.sh
```

지원 환경은 Ubuntu 24.04 amd64, 실행 중인 systemd, PHP 8.3 CLI와 POSIX 확장, 같은 호스트의 단일 WordPress입니다. 다른 OS·ARM·WordPress 컨테이너/원격 호스트·멀티사이트는 이 자동 설치 대상이 아닙니다. Docker가 이미 있으면 실행 가능한 기존 로컬 설치를 재사용하고, 전혀 없을 때만 공식 저장소에서 설치합니다. 기존 HUSTOJ Docker가 있는 경로에서는 APT·저장소·Docker 서비스 시작/재시작을 실행하지 않습니다. 필요한 curl·tar·xz·sha256sum·ss와 CA 인증서가 없으면 별도 점검 시간에 준비하도록 중단합니다. 설치에는 다운로드용 인터넷과 sudo 권한이 필요합니다. 실제 micro:bit 기기는 필요하지 않습니다.

포트 사전 점검용 `ss`(iproute2)는 신규 Docker 호스트에서도 미리 필요합니다. 명령이 없으면 패키지를 변경하기 전에 중단합니다. 기본 Ubuntu 서버와 다른 최소 설치 환경은 이를 먼저 준비합니다.

## 자동 처리 순서

1. OS·플랫폼·WordPress 경로·실행 계정을 검사하고 중복 설치 잠금을 잡습니다. APT보다 먼저 기존 Docker를 판별하고 다른 서비스의 포트 점유를 거부합니다.
2. 전용 Node 24.22.1을 공식 SHA-256으로 확인해 별도 경로에 설치합니다. 시스템의 전역 Node와 기존 버전 폴더는 바꾸지 않습니다.
3. 일반 WordPress 계정으로 SHORTINIT 부트스트랩하여 플러그인 활성 상태·기존 옵션·상수를 확인합니다. 플러그인·테마·스키마 업그레이드·cron은 실행하지 않습니다.
4. 기존 실행기 키를 우선 재사용하고, 없으면 WordPress 키, 둘 다 없으면 새 난수 키를 사용합니다. 키는 명령행·화면에 출력하지 않고 stdin과 소유자 제한 임시 파일로 전달합니다.
5. Docker와 실행기 계정을 준비하고 실행기·Docker 저장 파일시스템의 여유 공간이 각각 4GiB 이상인지 확인한 뒤 root 소유·서비스에서 쓰기 불가한 버전 디렉터리에 `npm ci --ignore-scripts`로 배치합니다. 신규 Docker 설치는 서명키 지문을 확인하고 충돌 패키지는 제거하지 않습니다.
6. 동봉 Dockerfile의 로컬 작업 이미지를 빌드하고 root 전용 백업 후 systemd 환경·유닛을 등록합니다. 활성화 직전 포트를 다시 확인하며 기존 마이크로비트 서비스의 MainPID만 재사용을 허용합니다. Docker를 암묵적으로 시작하는 Requires 의존성을 넣지 않습니다.
7. 인증된 health와 실제 PXT GPIO high/low 두 시나리오의 100점·실제 출력·해시·격리를 확인합니다. 이미지 존재만으로 설치 성공을 선언하지 않습니다.
8. 마지막에 WordPress 실행 주소·인증키만 자동 설정하고 성공을 표시합니다. 페이지를 새로고침해 준비 상태와 학생 채점을 확인합니다.

Node 24.22.1은 [2026-10-21 보안 릴리스](https://nodejs.org/en/blog/release/v24.22.1)로 고정했습니다. Ubuntu 설치기의 linux-x64 SHA-256은 `6c172a9ab6718f7b801fb5a7bd486af2ee5e32758086a04b84fcce2aa77095e6`입니다. Docker 설치는 [공식 apt 저장소 절차](https://docs.docker.com/engine/install/ubuntu/)를 따르며 다운로드한 원격 셸을 바로 실행하지 않습니다.

## 옵션과 진단

```bash
# 사이트 경로나 실제 PHP/WordPress 계정이 기본값과 다를 때
sudo bash /home/judge/src/web/wp-content/plugins/oj-microbit/runner/install.sh --wp-root /home/judge/src/web --wp-user www-data

# 포트 3037을 다른 서비스가 사용하고 있을 때
sudo bash /home/judge/src/web/wp-content/plugins/oj-microbit/runner/install.sh --port 4040

# 설치 계획만 출력: 패키지/네트워크/잠금/설정 변경 없음
sudo bash /home/judge/src/web/wp-content/plugins/oj-microbit/runner/install.sh --dry-run

# 설치 후 읽기 전용 진단: health/설정 확인, GPIO 실행 작업은 생성하지 않음
sudo bash /home/judge/src/web/wp-content/plugins/oj-microbit/runner/install.sh --check
```

기본 WordPress 경로는 `/home/judge/src/web`입니다. 계정은 wp-config.php 소유자로 판별하며 root 소유라면 www-data를 선택합니다. 실제 계정이 다르면 `--wp-user`를 사용합니다. 주소는 동일 호스트의 `http://127.0.0.1:포트`로만 설정합니다. 환경 파일의 기존 포트를 재사용하며 명시한 `--port`가 우선합니다. 인증키를 받는 명령행 옵션은 없습니다.

## 기존 자료·실패·재실행

- `/opt/oj-microbit/1.0.0-rc.9/runner`, `/opt/oj-microbit-node-24.22.1`에 배치합니다. 정식 숫자 버전과 `-rc.N` 후보 버전을 지원합니다. 같은 버전의 소스와 이미지 해시가 같으면 재사용하고, 다르면 덮어쓰지 않습니다. 수동 설치 폴더와 자동 설치 폴더를 같은 버전에서 섞지 않습니다.
- `/etc/oj-microbit/runner.env`는 root 전용, `/etc/systemd/system/oj-microbit-runner.service`는 root 소유입니다. 환경 파일·서비스 파일·WordPress 옵션과 이전 실행 경로는 `/opt/oj-microbit/backups/`의 root 전용 디렉터리에 보관합니다.
- WordPress의 보관 기간·삭제 설정·다른 옵션 키와 과제·프로젝트·채점 기록은 유지합니다. 설정은 options 한 행만 조건부로 갱신하고, 설치 중 관리자의 변경은 원래 직렬화 값과 SQL에서 비교해 보호합니다. 실제 WordPress 옵션 API가 준비되는 위치는 [공식 SHORTINIT 소스](https://github.com/WordPress/WordPress/blob/6.6/wp-settings.php)를 기준으로 확인했습니다.
- wp-config.php 상수가 실행 주소·키를 지정하면 동일 값일 때 재사용하고, 충돌하면 중단합니다. 스크립트는 wp-config.php를 자동 편집하지 않습니다.
- 연결 완료 전 npm·빌드·재시작·GPIO·WordPress 설정이 실패하면 이전 환경·유닛·서비스 활성 상태로 복원합니다. 단, 설치 중 Docker가 중단되면 이를 깨우지 않도록 이전 파일을 복원하고 마이크로비트 서비스만 중지하며 수동 복구를 안내합니다. 첫 설치라면 그때 새로 만든 연결 파일을 제거합니다. 이미 준비한 Node·OS 패키지·이미지와 이전 버전 폴더는 자동 삭제하지 않습니다.
- 전원 차단·강제 종료·DB 응답 유실과 같은 중간 상태는 자동 복구를 보장하지 않습니다. 백업을 대조하고 `--check`, `sudo systemctl status oj-microbit-runner --no-pager`와 서버 로그로 확인합니다. 배포·업데이트는 점검 시간에 수행합니다.

## 오류 확인

| 표시 | 확인할 내용 |
|---|---|
| 지원 OS/systemd/PHP 오류 | Ubuntu 24.04 amd64와 `/usr/bin/php8.3` CLI·POSIX, 실행 중인 systemd 확인 |
| WordPress 경로/계정 오류 | --wp-root의 wp-load.php·wp-config.php와 --wp-user의 읽기 권한 확인 |
| plugin_inactive | WordPress에서 OJ Microbit을 먼저 활성화 |
| url_constant_conflict / token_constant_conflict | wp-config.php의 OJ_MICROBIT_RUNNER_URL/TOKEN 확인. 키를 출력하지 말고 포트·기존 환경의 불일치를 정리 |
| settings_changed | 설치 중 관리자가 바꾼 옵션을 보존한 상태. 설정을 확인하고 다시 실행 |
| wordpress_bootstrap_aborted | 점검 모드·wp-config.php의 종료 처리 등으로 CLI 부트스트랩이 완료되지 않음 |
| 이미 관리되지 않는 폴더/다른 소스/알 수 없는 이미지 | 원본을 보존하며 중단. 새 릴리스 또는 수동 설치 절차로 처리 |
| Existing Docker is not available / required tool missing | 기존 Docker·CA·도구를 별도 점검 시간에 복구. 설치기는 패키지 갱신이나 Docker 재시작을 하지 않음 |
| Port ... already in use / unidentified listener | 점유 프로세스를 종료하지 말고 용도를 확인. 실행기만 다른 --port로 설치 |
| At least 4 GiB ... | 설치 경로와 Docker 저장 파일시스템 여유 공간 확인. 기존 HUSTOJ 이미지·볼륨을 지우는 정리를 설치기에 추가하지 않음 |
| GPIO/health 실패 | Docker 실행 권한·이미지·서비스 로그·포트·인증 상태 확인. 기존 설정의 자동 복원 결과도 확인 |

이 버전은 Windows에서 Bash 모의 설치와 PHP/실제 PXT 검증을 수행했습니다. **Ubuntu 패키지 설치·실제 systemd/Docker·POSIX 권한·실제 WordPress DB 연결은 이 PC에서 실행하지 않았습니다.** 운영 서버에 적용하기 전에 같은 구성의 시험 서버에서 새 설치·재실행·업데이트·실패 복원을 확인합니다.

HUSTOJ 컨테이너·볼륨·네트워크·DB를 관리하는 명령은 없습니다. 다만 이미지 빌드와 작업 실행은 CPU·메모리·디스크 I/O를 공유합니다. 4GiB 검사는 설치 전 최소 여유 확인이며 빌드 중 용량 증가나 수업 부하를 보장하지 않습니다. 최초 빌드는 점검 시간에 진행하고 [공존 점검서](HUSTOJ-COEXISTENCE.md)의 병행 부하를 확인합니다. 정식 출시 기능·오류 검증은 [RELEASE-PLAN.md](RELEASE-PLAN.md)를 따릅니다.

RC 시험 서버에서는 동봉 `scripts/acceptance/`의 전후 상태·설치/실패 복원·DB·REST 도구와 [시험 체크리스트](ACCEPTANCE-CHECKLIST.md)를 사용합니다. `install-cycle.sh plan`은 무변경이며 install/rollback은 시험 표식과 명시적 서비스 변경 허용을 요구합니다. 이 도구는 기존 실행기에 영향을 줄 수 있어 운영 서버에서 실행하지 않습니다. 현장 결과를 자동 PASS로 채우지 않습니다.
