# OJ ML Playgrounds 1.0.0 설치 안내

2026-09-11 패키징 시점 안내: 합의한 기관용 기술 운영 승인 범위를 통과했습니다. **1.0.0은 검증된 RC6 실행 코드·번들을 유지하는 정식 승격본**입니다. 승인 기준 시험 서버 **OJ dev.107 / ML RC6 / 스키마 4**에서 G1 실제 삭제·다중 탭 종료·재로그인 후 미노출과 서버 자료 보존을 확인했습니다. 실제 1.0.0 설치 결과와 최신 서버 상태는 배포 폴더의 외부 검증 기록을 따릅니다. 서버 관리·로그·백업·복원은 운영자 직접 수행 영역으로 인계했으며 아래 일반 운영 안내는 관리 경로나 백업 위치를 다시 제출하라는 요청이 아닙니다. 정확한 승인 범위와 한계는 [기술 승인 기록](docs/release-1.0.md)을 따릅니다.

**이미 스키마 4인 rc.3~rc.6는 1.0.0 설치에 추가 DB 변경이 없습니다.** rc.1/rc.2의 스키마 3 또는 그 이전 설치는 스키마 4로 DB 업데이트해야 합니다. 운영자의 DB·비공개 저장소·기존 플러그인 백업 → 검증된 1.0.0 ZIP 전체 교체 → PHP-FPM/OPcache와 관련 캐시 갱신 → OJ 콘솔 교사 메뉴 › ML 실습 › 설정에서 스키마 확인 → 학생·교사 회귀 확인 순서로 진행하고, 스키마가 4보다 낮으면 **DB 업데이트 및 검증**을 실행하세요. 기존 실습 탭은 작업을 보존한 후 모두 닫고 새로 여세요.

1.0.0은 RC6에서 보완한 페이지 안의 명시적인 삭제 확인·취소 창, 자동 새로고침 없이 유지되는 삭제 결과, 단일 메시지 처리 경로와 다중 탭 삭제 잠금 순서를 유지합니다. 배타 잠금 요청을 먼저 대기열에 넣고 저장 권한 폐기 → 기존 공유 잠금 해제 → 배타 잠금 안에서 삭제 순서로 진행합니다. IndexedDB 삭제가 막혀 UI 대기 시간이 끝나더라도 이미 시작한 삭제 요청이 끝나기 전에는 잠금을 조기에 풀지 않습니다. 별도 개인정보 URL이 없으면 OJ Platform의 `/privacy`를 이어받습니다. rc.5의 영수증 시각·서버 횟수 표시 수정은 유지하며 시도 차감 정책은 바꾸지 않습니다.

[화면 모드 사용 안내](docs/display-modes.md)에 rc.4에서 도입한 화면 확장·설정·현장 확인 항목이 있습니다. 양식·루브릭·자료 설정과 재검토 절차는 기존 [rc.3 사용 안내](docs/workflow-rc3.md)를 계속 사용합니다. 기술 승인과 실제 서버 버전 교체는 구분하며 자동 배포하지 않습니다.

이 문서는 Ubuntu/Debian 계열 서버의 **Nginx + PHP-FPM 8.3 + WordPress** 환경을 기준으로 합니다. 다른 배포판에서도 설정 항목은 같지만 패키지명과 서비스명이 다를 수 있습니다.

## 버전 구분

- WordPress 플러그인: `OJ ML Playgrounds 1.0.0`
- 고정한 원본 GitHub 태그: `ml-playgrounds 0.12.1` (2026-08-31 확인)
- 이 플러그인에 내장된 ML Playgrounds 앱: `0.12.1`
- 내장 앱 기준 커밋: `2276d1f98d6b7c5c11608a27b20f3c0709aa1542`
- 설치 파일: `oj-ml-playgrounds-1.0.0.zip` (최종 검증·패키징 후 제공; 실제 해시는 함께 제공되는 검증 기록과 대조)

WordPress 플러그인과 원본 앱은 서로 다른 버전 체계입니다. WordPress에 설치할 파일을 고를 때에는 **플러그인 버전 1.0.0**을 확인하십시오. 내장 앱은 업스트림 0.12.1에 OJ 패치를 적용한 RC6 검증 번들을 유지합니다. 정식 승격을 위해 앱을 다시 변경하지 않으며 RC5 이하의 구번들과 혼합하지 마십시오.

원본 `0.12.1`은 이 배포본에 반영되었습니다. 내장 앱에는 OJ 제출 bridge, 사용자별 암호화 저장소와 공용 PC 다중 탭 삭제 패치를 적용한 정적 자산이 들어 있습니다. `resources/playground/`만 패치하지 않은 업스트림 빌드로 덮어쓰면 이 보호와 제출 연동이 깨질 수 있으므로, 업스트림 패치 재적용과 전체 검증 없이 교체하지 마십시오.

`1.0.0`의 공용 PC 검증·파일 선택 보호·직접 제출·화면 확장은 `resources/js/public-pc.js`, `resources/js/public-pc-bootstrap.js`, `resources/js/addon.js`, `resources/js/display-mode.js`, `resources/css/addon.css`, `resources/css/display-mode.css`와 내장 playground가 한 세트로 동작합니다. 파일 몇 개만 덮어쓰지 말고 ZIP 전체로 교체한 뒤 PHP-FPM/OPcache와 WordPress·프록시·CDN·브라우저 캐시를 함께 비우십시오. 구버전과 신버전 자산이 섞이면 보호 로직은 안전을 위해 실습 화면을 잠글 수 있습니다.

## 1.0 업데이트 시 추가 단계

기존 0.2.x 또는 스키마 3인 rc.1/rc.2에서 업데이트한다면 먼저 [업그레이드·백업·롤백 안내](docs/upgrade-1.0.md)를 확인하세요. **ZIP 교체 후 OJ 콘솔 교사 메뉴 › ML 실습 › 설정 → DB 업데이트 및 검증**으로 스키마 4를 설치해야 합니다. 이미 스키마 4인 rc.3~rc.6 설치는 추가 DB 변경이 없습니다. 최초 활성화는 스키마를 설치하지만 일반 페이지 요청은 DB를 자동 변경하지 않습니다.

### 설치 후 삭제·안내 유지 확인

- 식별된 시험 계정과 합성 자료로만 검사합니다. **이 기기의 내 실습 데이터 삭제**를 누르면 페이지 안에 확인창이 나타나야 합니다. 먼저 **취소**하여 프로젝트·서버 제출이 그대로이고 삭제 요청이 시작되지 않았는지 확인합니다.
- 같은 계정의 실습을 두 탭에서 열고 확인창의 **로컬 데이터 삭제**를 명시적으로 누릅니다. 성공 또는 실패 안내가 자동 새로고침으로 사라지거나 서로 바뀌지 않아야 합니다. 성공은 실제 삭제 완료 응답 뒤에만 표시되어야 합니다.
- 성공 후 다른 탭에서 이전 프로젝트를 저장할 수 없어야 하며 새로고침·재로그인 후 삭제한 로컬 프로젝트가 다시 나타나지 않아야 합니다. 기존 서버 제출·평가, 다른 계정의 자료, 별도로 내려받은 파일은 보존돼야 합니다.
- 다른 탭의 DB 연결로 삭제가 막히거나 시간이 초과되면 **삭제 결과 미확인**으로 기록합니다. 메시지만으로 완료를 판정하지 말고 안내에 따라 모든 실습 탭을 닫은 뒤 다시 확인합니다.
- ML의 별도 개인정보 URL이 비어 있으면 적용 URL과 출처가 OJ Platform `/privacy`로 표시되는지 확인합니다. 이 링크는 개인정보 문서를 새로 게시하지 않습니다. 현재 서버의 `/privacy`에는 게시 문서가 없다는 관찰이 남아 있으며 RC6 설치로 자동 해결되지 않습니다.

위 목록은 새 설치 환경의 검수 절차입니다. 현재 사이트에서는 RC6로 취소 보존·명시 삭제·두 탭 종료·재로그인 후 미노출·서버 자료 보존과 개인정보 URL 연결을 확인했습니다. 실제 통과 범위는 [기술 승인 기록](docs/release-1.0.md)을 따르며 전체 목록이나 다른 환경까지 통과로 확대하지 않습니다.

### rc.5에서 도입한 표시 수정 유지 확인

- 같은 제출의 영수증 접수 시각과 결과 화면 시각이 같은 브라우저 시간대에서 일치하는지 확인합니다. 서버는 UTC ISO 8601을 전달하고 브라우저가 현지 시각으로 표시합니다.
- 새 **실습 시작**으로 서버 시도가 생성되면 제출 전에도 사용 횟수가 증가합니다. 기존 시도 이어하기나 동일 영수증 재전달은 추가 차감하지 않으며, 추가 허용·환급 등에 따라 시도 번호와 사용 횟수는 항상 같지 않을 수 있습니다. rc.5는 이 기존 서버 정책을 바꾸지 않고 조회 결과를 표시합니다.
- 새 시도 생성·직접 제출·파일 제출 성공 뒤 과제 안내의 최대·사용 횟수가 서버의 결과 화면과 일치하고, 같은 iframe과 프로젝트가 유지되는지 확인합니다. 횟수 조회 실패 안내가 나오면 접수 영수증은 보존되므로 중복 제출을 요구하기 전에 결과 화면을 확인합니다.
- 기존의 직접 제출 → 안전 바로보기 → 비공개 평가 저장 → 공개 → 학생 확인과 타 학생 접근 차단도 다시 시험합니다.

운영 설정 기본값은 파일당 25 MiB, 총 10 GiB, 여유 공간 512 MiB, 동시 검사 2개, 무기한 보존·삭제 유예입니다. 설정과 별도 개인정보 URL은 OJ 콘솔 **교사 메뉴 › ML 실습 › 설정**(OJ Platform 2.0.x는 학습 활동 › ML 실습 설정)에서 바꿉니다. 사이트 관리자와 역할 화면(`/oj-console/roles`)에서 'ML 실습 설정' 권한을 받은 교사가 설정할 수 있습니다. 개인정보 URL을 비워두면 정상 연결된 OJ Platform의 개인정보 안내를 이어받습니다. 교사·학생 기능은 [사용 안내](docs/teacher-guide.md)를 참고하세요.

### 실습 화면 기본 설정

사이트 관리자와 'ML 실습 설정' 권한을 받은 교사는 **OJ 콘솔 → 교사 메뉴 › ML 실습 › 설정**에서 다음 두 항목을 저장할 수 있습니다. 별도 WordPress 설정 폼이나 서버 패키지는 추가하지 않습니다.

- **실습 시작 시 집중 모드로 넓게 열기**: 기본 꺼짐. 켜면 학생이 실습을 시작한 뒤 브라우저 안의 집중 레이아웃으로 열립니다. 브라우저 전체화면 권한을 자동 요청하지 않습니다.
- **실습 시작 후 과제·양식 안내 기본 접기**: 기본 켜짐. 과제·양식 안내는 접되 학생이 도구 모음에서 다시 열 수 있습니다. 공용 PC 보호 상태와 로컬 데이터 삭제 버튼은 접지 않습니다.

설정 변경은 **다음에 여는 페이지부터 적용**되며 진행 중인 실습을 강제 새로고침하지 않습니다. 학생의 **전체화면** 버튼이 브라우저 정책으로 거부되어도 집중 모드로 계속 작업할 수 있습니다. 전체화면 허용을 위해 기존 보안 정책을 완화할 필요는 없습니다. 사용 순서와 교사 채점 화면의 너비 조절은 [화면 모드 안내](docs/display-modes.md)를 따르세요.

## 무엇이 어디에 필요한가

| 항목 | 운영 서버 | 학생 공용 PC | 개발·재빌드 PC |
|---|---:|---:|---:|
| WordPress/OJ Platform | 필요 | 불필요 | 선택 |
| PHP `ZipArchive` | 필요(제출 검사·안전 바로보기) | 불필요 | 플러그인 실행에는 불필요 |
| `uv` | 불필요 | 불필요 | 업스트림 fixture 재현 검사 때만 필요 |
| 최신 Chrome 또는 Edge | 관리자 확인용 | 필요 | 선택 |
| HTTPS | 필요 | HTTPS 사이트 접속 | 필요 |

모델 학습 계산은 학생 브라우저에서 수행됩니다. 서버는 과제, 학생별 제출, `.mlpx` 원본, 검사 결과와 평가를 저장합니다. 따라서 운영 서버나 학생 PC에 `uv`를 설치할 필요가 없습니다.

## 1. 설치 전 요구 사항

- WordPress 6.6 이상
- WordPress 단일 사이트 설치(멀티사이트 미지원)
- PHP 8.3 이상 (`8.3.6`과 같은 8.3 계열; 배포판에서 보안 업데이트한 패치 버전 권장)
- 전용 DB 스키마, InnoDB, 64-bit PHP. 동일 DB를 다른 WordPress 설치와 공유하지 않음
- OJ Platform `2.0.0-dev.33` 이상, `3.0.0` 미만
- OJ Addon API `>=1.1.0 <2.0.0`
- HTTPS
- 웹 문서 루트 밖의 제출 전용 디렉터리
- PHP-FPM 사용자의 제출 디렉터리 쓰기 권한
- 과제의 최대 `.mlpx` 크기보다 큰 PHP와 Nginx 업로드 한도
- 공용 PC에서는 Web Crypto, IndexedDB, BroadcastChannel, Web Locks를 지원하는 최신 Chrome 또는 Edge

PHP CLI에서 `already enabled`가 출력되었다면 같은 확장을 중복 설치할 필요가 없습니다. 다만 실제 웹 요청을 처리하는 PHP-FPM에도 같은 확장이 로드되는지는 확인하십시오.

```bash
php8.3 -v
php8.3 -r 'echo class_exists("ZipArchive") ? "ZipArchive enabled\n" : "ZipArchive missing\n";'
sudo /usr/sbin/php-fpm8.3 -i | grep -E 'Zip|zip support|Zip version'
nginx -v
```

`php-fpm8.3` 실행 파일의 위치가 다르면 `command -v php-fpm8.3`으로 확인합니다. FPM에서도 `Zip support => enabled`가 보이면 완료입니다. `ZipArchive`가 없더라도 제출 파일은 보존되지만 자동 사전검사가 `system_pending`에 머물 수 있습니다.

## 2. PHP-FPM 사용자 확인

다음 명령으로 PHP-FPM worker 사용자를 확인합니다.

```bash
grep -RHE '^[[:space:]]*(user|group)[[:space:]]*=' /etc/php/8.3/fpm/pool.d/
```

Ubuntu의 기본값은 대개 `www-data`입니다. 아래 예시는 `www-data`를 사용하므로, 출력이 다르면 실제 사용자와 그룹으로 바꾸십시오.

## 3. 비공개 제출 저장소를 먼저 설정

이 설정은 **플러그인 활성화 전에** 하는 것을 권장합니다. 기본 경로를 PHP-FPM이 만들 수 없으면 `oj-ml/v1` REST route 전체가 등록되지 않아 화면에 “URL과 요청한 메소드에 일치하는 라우트를 찾을 수 없습니다.”가 표시될 수 있습니다.

```bash
sudo install -d -o www-data -g www-data -m 0750 /srv/private/oj-ml-playgrounds
sudo -u www-data test -w /srv/private/oj-ml-playgrounds && echo writable || echo not-writable
namei -l /srv/private/oj-ml-playgrounds
```

WordPress의 `wp-config.php`에서 “That's all, stop editing” 주석보다 위에 다음을 추가합니다.

```php
define('OJ_ML_PLAYGROUNDS_PRIVATE_DIR', '/srv/private/oj-ml-playgrounds');
```

경로 조건은 다음과 같습니다.

- WordPress 설치 경로와 Nginx `root` 바깥이어야 합니다.
- PHP-FPM worker가 디렉터리를 읽고 쓸 수 있어야 합니다.
- Nginx가 이 경로를 정적 파일로 제공하면 안 됩니다.
- PHP-FPM의 `open_basedir`를 사용하면 이 경로를 허용 목록에 포함하고, SELinux/AppArmor 정책도 쓰기를 허용해야 합니다.
- 여러 웹 서버 노드를 사용하면 모든 노드가 같은 WordPress 데이터베이스, 플러그인 버전·파일, WordPress 인증 SALT와 공유 artifact 저장소를 사용해야 합니다. 노드별 SALT나 플러그인 파일이 다르면 주기적인 세션 검증이 실패할 수 있습니다.
- 데이터베이스와 이 디렉터리는 같은 복구 시점으로 백업해야 합니다.
- PHP 임시 디렉터리와 이 저장소의 용량·inode를 모니터링합니다.

## 4. PHP와 Nginx 업로드 한도

플러그인의 압축 `.mlpx` 기본 상한은 **25 MiB**, 과제 정책의 하드 상한은 250 MiB입니다. 실제 허용치는 과제 한도와 운영 설정 중 작은 값입니다. 아래 값은 **25 MiB 기본 과제용 예시**입니다. 더 큰 과제를 허용하면 PHP, PHP-FPM pool, Nginx, CDN/WAF의 제한을 모두 함께 높이십시오. `post_max_size`와 Nginx 제한은 multipart overhead를 고려해 `upload_max_filesize`보다 커야 합니다.

`/etc/php/8.3/fpm/php.ini` 예시:

```ini
file_uploads = On
upload_max_filesize = 32M
post_max_size = 40M
memory_limit = 256M
max_input_time = 120
max_execution_time = 120
```

해당 Nginx 사이트의 `server` 블록 예시:

```nginx
client_max_body_size 40m;
```

ZIP 안전 검사 정책의 기본값/하드 상한은 다음과 같습니다.

- ZIP 항목 수: 10,000 / 20,000
- 총 비압축 크기: 250 MiB / 500 MiB
- 단일 항목 크기: 100 MiB / 250 MiB
- 압축률: 100 / 200
- JSON 문서 크기: 2 MiB / 4 MiB

제출 파일·접수 영수증을 먼저 확정한 뒤 가능한 경우 같은 요청에서 검사합니다. 검사 실패·서버 중단 시 접수는 유지되고 WP-Cron 또는 WP-CLI가 재시도합니다. 큰 과제를 사용할 때에는 PHP-FPM pool의 `request_terminate_timeout`, Nginx의 `client_body_timeout`·`fastcgi_read_timeout`과 CDN/WAF timeout도 실제 최대 파일로 시험하십시오. `upload_tmp_dir`가 존재하고 PHP-FPM worker가 쓸 수 있는지도 확인합니다.

ZIP은 디스크에 압축 해제하지 않고 stream으로 검사합니다. 다만 PHP 임시 업로드와 비공개 저장소의 `.part` 또는 최종 파일이 동시에 존재하므로 압축 파일 크기의 약 2배, Nginx request buffering 임시 파일까지 포함하면 동시 업로드당 약 3배의 디스크 여유를 잡으십시오.

설정 후 문법을 확인하고 서비스를 반영합니다.

```bash
sudo nginx -t
sudo systemctl restart php8.3-fpm
sudo systemctl reload nginx
```

## 5. 플러그인 설치와 활성화

### WordPress 관리자 화면

1. OJ Platform을 먼저 설치하고 활성화합니다.
2. **플러그인 → 새 플러그인 추가 → 플러그인 업로드**에서 `oj-ml-playgrounds-1.0.0.zip`을 선택합니다.
3. 같은 플러그인의 구버전이 있으면 WordPress의 “현재 파일로 교체” 절차를 사용합니다. 디렉터리에 일부 파일만 덮어쓰지 마십시오.
4. **OJ ML Playgrounds**를 활성화합니다.

### WP-CLI 사용 시

다음 예시는 WordPress가 `/var/www/html`에 있고 ZIP을 `/tmp`에 올린 경우입니다.

```bash
cd /var/www/html
sudo -u www-data wp plugin get oj-platform --fields=name,status,version
sudo -u www-data wp plugin install /tmp/oj-ml-playgrounds-1.0.0.zip --force
sudo -u www-data wp plugin activate oj-ml-playgrounds
sudo -u www-data wp plugin get oj-ml-playgrounds --fields=name,status,version
```

정식 설치 후 확인할 버전은 `1.0.0`입니다. 업데이트 후에는 오래된 OPcache가 남지 않도록 PHP-FPM을 재시작하십시오. 기존 실습 탭은 작업을 보존한 후 모두 닫고, 관련 캐시를 갱신한 뒤 새로 여십시오.

```bash
sudo systemctl restart php8.3-fpm
```

## 6. 개인정보 처리방침 URL 연결

공용 PC 배포에서는 기관의 실제 개인정보 처리방침이 공개되어 있는지 확인해야 합니다. **OJ 콘솔 → 교사 메뉴 › ML 실습 › 설정 → 기관 개인정보 안내**를 비워두면 정상 연결된 OJ Platform의 공개 개인정보 경로인 `home_url('/privacy')`를 이어받습니다. 별도 기관 주소를 사용할 때만 이 설정에 HTTPS 주소를 저장하세요. 설정 화면에서 실제 적용 URL과 출처를 확인할 수 있습니다.

적용 순서는 **유효한 `oj_ml_playgrounds_privacy_url` 필터 값 → 유효한 ML 별도 주소 → 정상 OJ 연결의 `/privacy` → ML 기본 기술 안내**입니다. 잘못된 주소는 다음 순위로 넘어가며, 플랫폼이 없거나 정상 연결되지 않은 경우에도 별도 주소나 필터가 없으면 ML 기본 기술 안내를 제공합니다. 기본 기술 안내는 기관의 최종 개인정보 처리방침을 대신하지 않습니다.

ML은 링크만 이어받으며 OJ의 문서 내용, 게시 상태나 보존 정책을 복제하거나 자동 설정하지 않습니다. OJ 개인정보 문서의 실제 공개 여부와 기관 정책은 별도로 확인하세요. 이용약관(`/terms`)은 개인정보 안내를 대신하지 않습니다.

MU 플러그인 디렉터리가 없으면 먼저 만듭니다. 아래 WordPress 경로와 계정은 실제 환경에 맞게 바꾸십시오.

```bash
sudo install -d -o www-data -g www-data -m 0755 /var/www/html/wp-content/mu-plugins
```

파일: `wp-content/mu-plugins/oj-ml-playgrounds-site-policy.php`

```php
<?php
/** Site policy for OJ ML Playgrounds. */

add_filter(
    'oj_ml_playgrounds_privacy_url',
    static fn (): string => 'https://school.example/privacy'
);
```

필터가 필요한 경우에만 `https://school.example/privacy`를 실제 기관 URL로 바꾸십시오. 기존 기관 필터의 `http`/`https` URL 호환은 유지하지만 운영 환경에서는 HTTPS를 사용하십시오. 설정 화면에 직접 저장하는 별도 주소는 HTTPS만 허용합니다.

## 7. OJ 권한 배정

플러그인은 WordPress 역할을 임의로 수정하지 않습니다. OJ Platform의 역할/권한 화면에서 다음 capability를 배정합니다.

- 학습자: `oj_ml_playgrounds_use`, `oj_ml_playgrounds_submit`
- 결과 열람·평가 교사: `oj_ml_playgrounds_report`, `oj_ml_playgrounds_review`
- 과제 작성·게시 교사: 위 교사 권한에 `oj_ml_playgrounds_manage` 추가
- 운영자: `oj_ml_playgrounds_settings`

평가 담당자는 결과 페이지를 열기 위해 `REPORT`와 평가 저장을 위해 `REVIEW`가 모두 필요합니다. 실제 WordPress capability 이름은 OJ Platform의 공개 capability mapper가 결정합니다.

### 교사가 제출 내용을 확인하는 방법

1. OJ 콘솔의 **학생별 제출 결과**에서 과제를 선택합니다.
2. 학생 행 아래 **최신 제출 내용 및 평가 보기**를 펼칩니다.
3. 프로젝트명, 과제·데이터 유형, 실행 수·알고리즘, 데이터 행·열·특성 수와 무결성 상태를 확인합니다.
4. `REVIEW` 또는 `MANAGE` 권한 계정이면 **제출 내용 안전 바로보기**가 자동으로 열립니다. 포트폴리오 답안, 설정·알고리즘과 MLPX에 기록된 지표만 표시되며 학습·모델·전처리기·데이터셋·이미지·첨부파일은 실행하거나 재계산하지 않습니다.
5. 실제 프로젝트 파일이 필요한 경우에만 **원본 .mlpx 다운로드**를 누릅니다. 내려받은 파일은 신뢰되지 않은 학생 제출물이므로 운영 사이트에서 inline 실행하지 말고 격리된 검토 환경에서만 다룹니다.

`REPORT` 전용 계정에는 안전 바로보기, 원본 다운로드 버튼과 평가 초안 피드백이 제공되지 않습니다. 안전 바로보기와 원본은 `accepted`, `under_review`, `graded` 상태만 허용하며, 요청마다 REVIEW/MANAGE capability, POST nonce, 현재 OJ 수업 Context의 `evaluate` 권한, 저장 파일의 크기와 SHA-256을 다시 검사합니다. 바로보기는 검증된 비공개 임시 사본에서 네 개의 JSON 문서만 읽고 경로·학생 식별자·원시 첨부 정보를 제거합니다. 원본 응답은 `Content-Disposition: attachment`, `X-Content-Type-Options: nosniff`, `Cache-Control: private, no-store`, `Vary: Cookie`를 사용합니다. Nginx에 비공개 저장소용 public `alias`나 `location`을 추가할 필요가 없으며 추가해서도 안 됩니다.

교사 결과 표는 학생별 최신 접수 제출을 우선 표시하고 **전체 제출 이력**에서 한 학생의 모든 시도를 확인합니다. 학생도 결과 페이지에서 자기 이력과 공개 평가를 볼 수 있습니다. 미접수·취소 시도가 이전 접수 결과를 가리지 않습니다. 현재 수업 권한이 사라져도 접수된 자기 기록은 조회할 수 있지만, 새 참여와 교사 조회는 현재 OJ 권한을 검사합니다.

### 1.0.0 과제 수정·보관·학생 노출 확인

- `draft` 과제는 게시 전에 수정하거나 삭제할 수 있습니다.
- `published` 과제에서는 제목, 설명, 마감 시각과 최대 시도 횟수만 수정할 수 있습니다. 최대 시도 횟수 감소 요청은 거부되며 과제 유형과 제출 정책은 바꿀 수 없습니다.
- 보관한 과제는 학생 목록에서 숨겨지지만 제출 원본, 검사·평가와 교사 결과는 그대로 보존됩니다.
- 마감 시각이 지난 미제출 과제는 학생 목록에서 숨겨지고, 제출 이력이 있는 과제는 접힌 **과거 제출 결과**에 남습니다.
- 영구적으로 실행할 수 없는 버튼에는 대기 커서를 표시하지 않으며, 실제 요청 처리 중인 상태에만 진행 표시가 나타나는지 확인합니다.

현재 OJ Platform mapper가 만드는 실제 capability 이름은 다음과 같습니다.

- `oj_addon_oj_ml_playgrounds_use`
- `oj_addon_oj_ml_playgrounds_submit`
- `oj_addon_oj_ml_playgrounds_manage`
- `oj_addon_oj_ml_playgrounds_review`
- `oj_addon_oj_ml_playgrounds_report`
- `oj_addon_oj_ml_playgrounds_settings`

## 8. 설치 후 검증

### 버전과 Addon API

WordPress 루트에서 실행합니다.

```bash
sudo -u www-data wp plugin get oj-ml-playgrounds --fields=name,status,version
sudo -u www-data wp eval '
printf("OJ Platform: %s\n", defined("OJ_PLATFORM_VERSION") ? OJ_PLATFORM_VERSION : "missing");
printf("OJ Addon API: %s\n", defined("OJ_PLATFORM_ADDON_API_VERSION") ? OJ_PLATFORM_ADDON_API_VERSION : "missing");
'
```

OJ Platform은 `>=2.0.0-dev.33 <3.0.0`, Addon API는 `>=1.1.0 <2.0.0`이어야 합니다.

### 단일 사이트와 데이터베이스

```bash
sudo -u www-data wp eval 'echo is_multisite() ? "UNSUPPORTED\n" : "OK\n";'
sudo -u www-data wp option get oj_ml_playgrounds_db_version
sudo -u www-data wp db query "SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('oj_ml_assignments','oj_ml_assignment_targets','oj_ml_assignment_learners','oj_ml_attempts','oj_ml_artifacts','oj_ml_evaluations','oj_ml_reviews','oj_ml_events') ORDER BY TABLE_NAME"
sudo -u www-data wp db query "SHOW GRANTS FOR CURRENT_USER()"
```

정상 기준은 다음과 같습니다.

- 멀티사이트 검사: `OK`
- DB schema 버전: `4`
- 다음 8개 테이블이 모두 존재하고 engine이 `InnoDB`

```text
oj_ml_assignments
oj_ml_assignment_targets
oj_ml_assignment_learners
oj_ml_attempts
oj_ml_artifacts
oj_ml_evaluations
oj_ml_reviews
oj_ml_events
```

활성화·업그레이드 때 WordPress DB 사용자에게 이 테이블의 `CREATE`·`ALTER` 권한이, 운영 중에는 필요한 CRUD 권한이 있어야 합니다. 테이블명은 WordPress table prefix를 사용하지 않으므로 서로 다른 WordPress 설치가 같은 DB schema를 공유하지 않게 하십시오.

### REST route

서버의 플러그인 파일과 route 등록 여부를 확인합니다.

```bash
grep -n 'session/storage-context' wp-content/plugins/oj-ml-playgrounds/src/Rest/Routes.php
curl -i https://school.example/wp-json/oj-ml/v1
curl -i https://school.example/wp-json/oj-ml/v1/assignments
curl -i https://school.example/wp-json/oj-ml/v1/session/storage-context
```

- namespace 요청이 `200`이면 route 등록이 정상입니다.
- 로그인하지 않은 `assignments` 요청이 `OJ_ML_AUTHENTICATION_REQUIRED`와 `401`을 반환하면 route 자체는 등록된 것입니다.
- 로그인 쿠키와 REST nonce 없이 `storage-context`를 호출했을 때 인증·권한 오류가 나오는 것은 정상일 수 있습니다.
- `rest_no_route`가 나오면 정상 상태가 아닙니다.

브라우저에서는 학생 계정으로 OJ 실습 화면을 열고 개발자 도구의 Network 탭에서 `session/storage-context`가 `200`인지 확인합니다. 사이트 관리자 또는 `SETTINGS` 권한 계정으로 `GET /wp-json/oj-ml/v1/console/health`를 호출했을 때 `zipExtension`, `storageConfigured`가 참이고 `runtime.state`가 `ready`인지 확인하면 실제 PHP-FPM 웹 SAPI 상태까지 검증할 수 있습니다.

정상 Runtime 응답의 `runtime.code`는 오류가 없다는 뜻의 빈 문자열 `""`입니다. 1.0.0 상태 화면은 이를 **없음 (정상)**으로 표시합니다. `runtime.state`가 `ready`인데 code 필드가 누락되거나 공백 문자열이면 정상으로 간주하지 않으며 `unknown` 또는 `invalid`로 표시합니다.

### 서버 조립 진단

route가 없으면 WordPress 루트에서 다음을 실행합니다.

```bash
sudo -u www-data wp eval 'global $oj_ml_playgrounds_composition_root; var_export($oj_ml_playgrounds_composition_root instanceof OJML\Playgrounds\Core\Composition_Root ? $oj_ml_playgrounds_composition_root->diagnostics() : null);'
sudo -u www-data wp eval 'var_export(OJML\Playgrounds\Platform\Addon_Integration::environment_available());'
sudo -u www-data wp eval '$s=new \OJML\Playgrounds\Storage\PrivateArtifactStore(); var_export(["root"=>$s->root(),"writable"=>is_writable($s->root())]);'
```

정상 결과는 `ready`가 참이고 `code`가 빈 문자열이며 환경 계약이 `true`, 저장 경로의 쓰기 가능 여부가 `true`입니다. `addon_composition_failed`이면 비공개 저장소 경로·권한과 데이터베이스 업그레이드를 먼저 점검하십시오. WP-CLI는 CLI SAPI를 사용하므로 최종 기준은 위 `console/health`의 PHP-FPM 결과입니다.

### 캐시와 iframe 보안 헤더

프록시·CDN·페이지 캐시는 `/wp-json/oj-ml/`, `/oj-addons/oj-ml-playgrounds/`, `/oj-console/addons/oj-ml-playgrounds/` 경로를 캐시 대상에서 제외해야 합니다. 업데이트 뒤에는 기존 full-page cache와 플러그인 정적 자산 캐시를 지운 뒤 학생 화면을 다시 확인하십시오.

브라우저 개발자 도구의 Network 탭에서 로그인한 `session/storage-context` 응답을 확인합니다.

- `Cache-Control`에 `private, no-store`가 포함되어야 합니다.
- `Vary`에 `Cookie`가 포함되어야 합니다.
- CDN의 cache hit 헤더나 다른 사용자의 캐시된 응답이 나타나면 안 됩니다.

OJ 부모 화면과 playground iframe은 같은 origin에서 열려야 합니다. 실제 부모·iframe 응답에 보안 헤더를 사용한다면 다음 조건을 만족해야 합니다.

- `X-Frame-Options`는 `SAMEORIGIN`이거나 생략되어야 하며 `DENY`이면 안 됩니다.
- 부모 CSP의 `frame-src`가 `'self'`를 허용해야 합니다.
- iframe CSP의 `frame-ancestors`가 `'self'`를 허용해야 하며 `'none'`이면 안 됩니다.

다음 명령은 헤더의 1차 확인용입니다. 로그인 쿠키가 필요한 최종 확인은 브라우저 개발자 도구에서 수행하십시오.

```bash
curl -sSI https://school.example/oj-addons/oj-ml-playgrounds/
curl -sSI https://school.example/wp-json/oj-ml/v1/session/storage-context
```

### Cron과 재검사

스키마가 준비되면 플러그인이 `oj_ml_recover` WP-Cron 이벤트를 5분 간격으로 등록합니다. 복구 대상은 이미 파일이 접수된 `quarantined`·`system_pending` 상태이며, 재시도 시각·횟수와 동시 검사 한도를 적용합니다. 자동 복구는 최대 5회이며 점검 모드에서는 실행하지 않습니다. WP-Cron 실제 실행 시점은 사이트 요청과 서버의 Cron 구성에 따라 늦어질 수 있습니다.

`ZipArchive`, 저장 파일·SHA-256, 디스크와 권한 문제가 남아 있으면 기다리는 것만으로 해결되지 않습니다. 원인을 해결한 후 운영 설정 화면의 검사 복구 또는 `wp oj-ml recover`를 사용할 수 있습니다. `needs_resubmit` 반려는 자동 복구 대상이 아니므로 학생의 새 제출 또는 권한 있는 교사의 사유를 남기는 **기존 제출 재검토** 절차를 사용합니다. 이 과정은 제출된 파일을 검사할 뿐 학생 모델을 학습·실행하지 않습니다.

## 9. `rest_no_route` 오류 해결 순서

화면에 “URL과 요청한 메소드에 일치하는 라우트를 찾을 수 없습니다.”가 표시되면 다음 순서로 확인합니다. 이 오류는 `ZipArchive`가 이미 활성화되었는지와 직접 관련이 없습니다.

1. 정식 1.0.0을 설치한 서버라면 설치 버전이 `1.0.0`인지 확인합니다.
2. OJ 콘솔 교사 메뉴 › ML 실습 › 설정의 서버 상태에서 DB 스키마가 `4`인지 확인합니다. 그보다 낮으면 백업 후 **DB 업데이트 및 검증**을 실행합니다.
3. `Routes.php`에 `session/storage-context`가 있는지 확인합니다.
4. `OJ_ML_PLAYGROUNDS_PRIVATE_DIR`이 웹 루트 밖의 실제 절대 경로인지 확인합니다.
5. PHP-FPM worker가 해당 디렉터리에 쓸 수 있는지 확인합니다.
6. 위의 조립 진단 명령을 실행합니다.
7. PHP-FPM을 재시작해 오래된 OPcache를 제거합니다.
8. Nginx가 WordPress 요청을 `index.php`로 넘기고 `/wp-json/oj-ml/` 응답을 프록시나 CDN에 캐시하지 않는지 확인합니다.

일반적인 WordPress Nginx 설정의 핵심은 다음과 같습니다. 기존 사이트 설정과 충돌하지 않도록 `server` 블록 안의 현재 `location /`을 확인해 조정하십시오.

```nginx
location / {
    try_files $uri $uri/ /index.php?$args;
}
```

`assignments`와 `session/storage-context`가 모두 `rest_no_route`이면 플러그인 조립 전체 실패일 가능성이 큽니다. `assignments`는 등록되고 `session/storage-context`만 없으면 1.0.0 프런트엔드와 구버전 PHP 파일이 섞였거나 OPcache가 오래된 상태일 수 있으므로, 검증한 1.0.0 ZIP으로 전체 교체 후 PHP-FPM을 재시작하십시오.

## 10. 공용 PC 배포 확인

반드시 HTTPS에서 최신 Chrome 또는 Edge로 다음을 시험합니다.

- JavaScript, IndexedDB와 WordPress의 same-origin 쿠키 인증을 허용하고 인증 쿠키에 `Secure`, `HttpOnly`와 적절한 `SameSite` 정책을 적용합니다.
- `/wp-json/oj-ml/`, `/oj-addons/oj-ml-playgrounds/`, `/oj-console/addons/oj-ml-playgrounds/`를 프록시·CDN의 API 캐시와 full-page cache에서 제외합니다.
- WAF와 reverse proxy가 REST의 `GET`·`POST`·`PATCH`·`DELETE`, multipart body, `Cookie`와 `X-WP-Nonce`를 차단하거나 제거하지 않게 합니다.
- OJ 부모 화면과 내장 playground iframe은 같은 origin이어야 합니다. `wp-content`를 별도 CDN origin으로 바꾸거나 Cookie·`X-WP-Nonce`·`X-Forwarded-Proto`를 제거하지 마십시오. WordPress `home`·`siteurl`도 외부 HTTPS 주소와 일치시킵니다.
- 실제 검증한 Chrome/Edge 버전과 검증일을 운영 기록에 남깁니다. Safari와 Firefox는 현재 공용 PC 운영 대상으로 보장하지 않습니다.
- 브라우저 계정 동기화, roaming profile, 게스트 모드, 종료 시 사이트 데이터 삭제, 확장 프로그램과 다운로드 폴더의 클라우드 동기화 정책을 기관 정책으로 고정하고 실제 학생 계정으로 시험합니다.
- WordPress 인증 SALT가 바뀌면 기존 브라우저 vault를 다시 열지 못할 수 있습니다. 서버 이전이나 SALT 회전 전 제출·내보내기 가능 여부를 확인하고, 회전 후 공용 PC의 해당 사이트 데이터를 초기화합니다.
- 이미지 실습을 사용하면 학생 브라우저가 `storage.googleapis.com`에서 약 12.4MB의 MobileNetV2 파일을 처음 한 번 내려받을 수 있으므로 기관 방화벽과 개인정보 처리방침을 확인합니다.
- 이 보호는 정상 UI의 계정 간 우발적 노출을 줄이는 장치입니다. 관리자 권한·악성 확장 프로그램·개발자 도구까지 격리해야 하면 학생별 OS/브라우저 프로필이나 수업마다 초기화되는 가상 데스크톱을 사용합니다.

- **실습 시작 → 새 프로젝트 → 만들기**를 차례로 눌러 프로젝트 홈이 표시되고 iframe 영역이 빈 화면으로 바뀌지 않는지 확인합니다.
- 실습의 데이터 추가·프로젝트 가져오기에서 **파일 선택**을 누르고 운영체제 파일 탐색기에서 파일을 선택하거나 취소합니다. 선택 창이 열린 동안에는 불투명한 보호 안내만 보이고, 복귀 후 현재 OJ 계정 확인이 끝나면 프로젝트가 다시 표시되며 선택한 파일은 정확히 한 번만 반영되어야 합니다. 계속 빈 화면이거나 프로젝트 iframe이 사라지면 캐시를 비운 뒤 1.0.0 전체 ZIP 설치 여부를 다시 확인합니다.
- 임베디드 툴바의 **OJ에 제출하기**를 누르면 현재 메모리 프로젝트가 브라우저 안에서 `.mlpx`로 만들어져 서버에 바로 제출되고, 다운로드 파일 없이 시도 번호·접수 시각·SHA-256 영수증이 표시되어야 합니다.
- 결과 제출 영역의 **바로 제출이 안 될 때 파일로 제출**은 기본으로 접혀 있어야 합니다. 직접 제출에 실패했을 때만 펼쳐 이미 내보낸 파일을 선택하는 fallback으로 사용합니다.
- 45초 주기 검증 응답을 지연시키고 다른 창이나 iframe에 포커스를 둬도 같은 프로젝트 화면이 계속 표시되어야 합니다. 문서를 숨겼다가 다시 표시할 때만 보호막 뒤에서 strict 검증하며, 성공하면 같은 프로젝트로 돌아와야 합니다.
- `storage-revoked` 또는 검증 실패를 유도했을 때 terminal 안내 한 번으로 멈추고 자동 새로고침·빈 화면·상태 문구 교대가 반복되지 않아야 합니다.
- 작업표시줄이나 Alt+Tab으로 별도 파일 탐색기 창을 연 뒤 외부에서 드래그하는 방식은 `hidden → visible` strict 검증과 겹쳐 drop이 차단될 수 있습니다. 실습 화면 안의 **파일 선택** 버튼으로 운영체제 파일 선택 창을 여는 방식을 사용합니다.
- 실습 iframe 안을 클릭한 상태에서 Alt+Tab으로 다른 창에 포커스를 옮겨도 포커스 손실만으로 프로젝트 화면이 숨겨지지 않는지 확인합니다. 브라우저 문서가 실제 hidden 상태가 되었다가 돌아온 경우에만 보호막 뒤에서 현재 OJ 세션을 strict 검증하고, 성공하면 같은 프로젝트를 다시 표시해야 합니다.

1. 학생 A가 프로젝트를 저장하고 새로고침했을 때 자기 프로젝트가 복원되는지 확인합니다.
2. A가 로그아웃한 뒤 같은 브라우저 프로필로 학생 B가 로그인했을 때 A의 프로젝트가 보이지 않는지 확인합니다.
3. 다시 A로 로그인했을 때 A의 암호화된 로컬 프로젝트만 복원되는지 확인합니다.
4. 같은 시험 계정으로 실습 탭을 두 개 열고 페이지 내 삭제 확인창에서 먼저 취소하여 자료가 유지되는지 확인한 뒤, 대상을 다시 확인하고 명시적으로 삭제합니다. 실제 성공 안내가 유지되고 다른 탭의 저장도 중단되는지 확인합니다.
5. 삭제 성공 뒤 새로고침·같은 계정 재로그인 시 해당 로컬 프로젝트가 다시 나타나지 않고, 이미 서버에 제출한 결과와 타 계정 자료는 남아 있는지 확인합니다. 실패·시간 초과는 성공으로 판정하지 않습니다.
6. 사용자가 별도로 내보낸 경우 다운로드 폴더의 `.mlpx`, CSV, 이미지와 클립보드 기록은 자동 삭제되지 않음을 학생에게 안내합니다.
7. playground의 정적 `index.html` 주소를 직접 열었을 때 앱이 시작되거나 IndexedDB가 생성되지 않고 fail-closed되는지 확인합니다.
8. 실습 중 뒤로 가기·앞으로 가기로 BFCache 복원을 유도했을 때 이전 iframe이 재사용되지 않고 strict 로그인 확인 뒤 현재 계정의 작업 공간만 열리는지 확인합니다.
9. 45초 세션 검증 요청을 지연시켜도 화면과 포커스가 유지되고, 불일치·오류가 확정된 경우에만 iframe이 terminal 상태로 잠기는지 확인합니다.
10. terminal 상태와 `storage-revoked`에서 자동 새로고침과 상태 문구 교대가 반복되지 않고, 원인을 해결한 뒤 사용자가 명시적으로 새로고침해 복구할 수 있는지 확인합니다.
11. 다른 탭·브라우저 프로필·동기화 계정·roaming profile에서 로컬 실습 데이터가 의도치 않게 복원되거나 공유되지 않는지 확인합니다.

데이터 삭제 범위를 혼동하지 마십시오.

| 동작 | 브라우저 암호화 vault | 다운로드·원본 파일 | 서버 제출물 |
|---|---|---|---|
| OJ 로그아웃 | 삭제되지 않고 화면 접근만 잠김 | 유지 | 유지 |
| “이 기기의 내 실습 데이터 삭제” | 현재 OJ 사용자 scope 삭제 | 유지 | 유지 |
| 다운로드 폴더 정리 | 영향 없음 | 직접 삭제 | 영향 없음 |
| 일반 플러그인 비활성화·삭제 | 영향 없음 | 영향 없음 | 기본적으로 유지 |

학생의 수업 종료 순서는 다음과 같습니다.

1. OJ 서버 제출 완료와 시도 번호·영수증 확인
2. “이 기기의 내 실습 데이터 삭제” 실행 및 성공 확인
3. 별도로 파일을 내보낸 경우 다운로드 폴더의 `.mlpx`, CSV, 이미지와 클립보드 기록 직접 삭제
4. OJ 로그아웃
5. 모든 OJ 탭과 브라우저 창 닫기

전체 인수 기준은 `docs/public-pc-deployment.md`를 따르십시오.

## 11. 백업과 업데이트

- 플러그인 8개 테이블과 설정·개인 양식·CSV 자료 메타데이터가 든 WordPress 옵션을 포함하여 DB를 백업합니다. `/srv/private/oj-ml-playgrounds`도 `teaching-assets/` CSV 원본을 포함해 전체를 같은 복구 시점으로 백업합니다.
- 업데이트는 새 ZIP으로 플러그인 디렉터리 전체를 교체하고 PHP-FPM을 재시작합니다.
- 일반 비활성화·삭제만으로 데이터베이스 8개 테이블과 제출 artifact가 자동 삭제되지는 않습니다.
- `wp-config.php`에서 `OJ_ML_PLAYGROUNDS_DELETE_DATA`를 `true`로 설정한 뒤 제거하면 8개 add-on 테이블과 버전·설정·개인 양식·CSV 자료 메타데이터 옵션은 영구 삭제되지만, 비공개 저장소의 `.mlpx`와 `teaching-assets/` CSV 원본은 남습니다. 실행 전에 함께 백업하고, 제거 후에는 보존·감사·파기 정책에 따라 원본을 별도로 처리하십시오.
- 로컬 브라우저 삭제는 서버 제출물이나 다운로드 폴더 파일을 지우지 않습니다.

설치 완료 후에는 실제 학생 계정의 테스트 프로젝트에서 임베디드 툴바의 **OJ에 제출하기**를 눌러 다운로드 없이 제출하고, 학생별 시도, SHA-256 영수증, 자동 검사와 교사 평가 화면까지 확인하십시오. 접힌 수동 `.mlpx` 업로드는 별도의 fallback 시험에서만 사용합니다.
