# 1.0 RC 시험 서버 점검 체크리스트

대상: `1.0.0-rc.9`. 갱신일: 2026-11-04. 기능 기준은 [FEATURE-FREEZE.md](FEATURE-FREEZE.md)입니다. 아래 항목은 **기본 미완료**이며 실제 실행 결과와 검수자를 기록한 뒤 체크합니다. 운영 WordPress·HUSTOJ에 시험 부하·실패 주입을 실행하지 않습니다.

## 도구의 범위

| 도구 | 수행하는 일 | 하지 않는 일 |
|---|---|---|
| `host-check.sh snapshot/compare` | 지정 HUSTOJ 컨테이너와 daemon/config/repo의 설치 전후 메타데이터 비교, 호스트 용량 요약 | 컨테이너·daemon·네트워크·볼륨 변경, 실제 채점 처리량 승인 |
| `install-cycle.sh plan/install/rollback` | 계획 출력 또는 명시적 허용 후 실제 설치기 2회 실행·진단 / canary 실패 주입 뒤 이전 서비스 복원 확인 | HUSTOJ 관리, DB 백업·복원, 전원 차단 복구 보장 |
| `wp-db-check.php` | SHORTINIT 부트스트랩, 스키마·인덱스·InnoDB·실제 요약 SQL·EXPLAIN·시간 측정 | DDL/자료 쓰기·전체 COUNT·원문 덤프·EXPLAIN ANALYZE·행 잠금 승인 |
| `wp-nonce.php` | 명시적 허용 후 일반 WP 계정으로 정상 부트스트랩하여 시험 계정 4명의 세션 없는 nonce 준비 | 계정·학급 생성, 앱 암호 유효성 승인. 정상 WP hooks의 부작용이 없다는 보장 |
| `rest-check.mjs` | 기본 읽기 / 별도 허용 시 자기 시험 과제·프로젝트·채점·권한·제한 부하·자기 과제 회수 | 기존 과제 변경·영구 삭제, HUSTOJ 자동 제출, 전체 학급 규모 인증 |

보고서의 `PENDING`, `SKIP`, `manualPending`, `manual_approval_pending`은 미승인입니다. 종료 코드 0은 해당 명령이 완료됐다는 뜻입니다. 특히 REST는 자동 검사에 실패가 없어도 수동 검증 때문에 `overall:PENDING`을 유지합니다. 원본 로그·비밀 설정은 공유하지 않고 비밀 없는 JSON 요약과 수동 판정만 보관합니다.

REST의 읽기 전용은 도구가 GET만 보내고 자기 시험 자료를 변경하지 않는다는 뜻입니다. 정상 WordPress 인증·플러그인·캐시 hooks는 GET에서도 내부 기록을 쓸 수 있습니다. SQL 읽기 전용 proxy를 사용하는 DB 도구와 구분합니다.

## 실제 사이트 디자인

- [ ] rc.9 설치 버전과 CSS/JS의 버전 쿼리를 확인하고 사이트·최적화·CDN·브라우저 캐시를 갱신했다.
- [ ] 생성의 첫 안내 폼·동작·코드·공개 네 화면 및 학생 보기를 [원본 비교](DESIGN-FIDELITY-1.0.0-rc.9.md)와 대조했다. 실제 테마·추가 CSS·모바일 가상 키보드에서도 확인했다.

## A. 시험 환경과 백업

- [ ] 운영과 분리한 Ubuntu 24.04 amd64 시험 서버/VM, 실제 WordPress·MySQL/MariaDB·PHP 8.3·기존 HUSTOJ Docker를 준비했다.
- [ ] 시험 주소·OS/PHP/WP/DB/Docker 버전·CPU/RAM/디스크·예상 학급 규모·동시 제출 패턴을 판정 기록에 적었다.
- [ ] 기존 0.5.1/rc.1의 과제·저장 프로젝트·이력·점수·버전·학급 범위 중 대표 자료를 정하고 복구 후 대조할 비밀 없는 표식을 남겼다.
- [ ] 중앙 서버 준비 20단계로 DB·웹 파일·서버 설정을 같은 시점에 백업하고 별도 시험 복제 환경에서 복원할 수 있다. WordPress DB와 HUSTOJ DB를 혼동하지 않는다.
- [ ] 설치 ZIP과 `.sha256`을 같은 폴더에 두고 체크섬을 확인했다. ZIP을 WordPress에 설치·활성화했다. 아직 기존 실행기를 유지한 상태에서 롤백 시험을 먼저 할 수 있다.

명령은 기본 웹 루트 `/home/judge/src/web`, OS 계정 `www-data` 예시입니다. 실제 계정이 다르면 옵션과 소유자를 함께 바꿉니다. 다음 호스트명과 컨테이너명은 **예시**이며 실제 시험 주소·정확한 이름으로 바꿉니다. 모든 서버 명령은 생성된 중앙 설치 안내서 **22-5단계**에도 있습니다.

```bash
sha256sum --check oj-microbit-1.0.0-rc.9.zip.sha256
sudo install -d -m 0700 /root/oj-microbit-acceptance
sudo install -d -m 0700 -o www-data -g www-data /var/lib/oj-microbit-acceptance
sudo vi /home/judge/src/web/wp-content/oj-microbit-acceptance.json
```

표식 파일 내용입니다. 비밀값을 넣지 않습니다. `baseUrl`은 마지막 `/`까지 실제 WordPress 주소와 같아야 합니다. 공개 URL `wp-content/oj-microbit-acceptance.json`에서 이 파일만 읽을 수 있어야 합니다.

```json
{"testServer":true,"marker":"oj-microbit-acceptance-v1","baseUrl":"https://microbit-trial.example.org/"}
```

```bash
sudo chmod 0644 /home/judge/src/web/wp-content/oj-microbit-acceptance.json
```

## B. HUSTOJ 기준 상태와 설치·복구

`--hustoj-container`는 정확한 이름 또는 전체 64자리 ID만 받습니다. 필요한 컨테이너가 여러 개면 옵션을 반복합니다(최대 8개). 같은 시험의 전후에는 같은 목록을 사용합니다. 처음부터 실행 중이어야 합니다. 보고서는 새 이름으로 만들며 덮어쓰지 않습니다.

```bash
sudo docker ps --no-trunc --format 'table {{.ID}}\t{{.Names}}\t{{.Status}}'
sudo bash /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/host-check.sh snapshot --label before --wp-root /home/judge/src/web --hustoj-container hustoj-judge --output /root/oj-microbit-acceptance/before.json
bash /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/install-cycle.sh plan
```

기존 실행기가 활성 상태인 시험 서버에서 **새 RC 실행기 설치가 실패한 뒤 이전 버전으로 복원되는지** 먼저 확인합니다. 이 명령은 canary 단계에서 의도적으로 실패하므로 운영 서버에 실행하지 않습니다. 하위 설치기가 실패한 것을 관측하고 이전 env/unit·활성/자동 시작 상태·인증 health·WordPress 연결이 복원돼야 도구가 성공합니다. 기존 실행기나 보호된 파일이 없으면 중단합니다. 새 Node·이미지·버전 폴더는 설치기 정책대로 남을 수 있습니다.

```bash
sudo bash /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/install-cycle.sh rollback --wp-root /home/judge/src/web --wp-user www-data --output-dir /root/oj-microbit-acceptance/rollback-rc6 --allow-test-service-changes
sudo bash /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/install-cycle.sh install --wp-root /home/judge/src/web --wp-user www-data --output-dir /root/oj-microbit-acceptance/install-rc6 --allow-test-service-changes
sudo bash /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/host-check.sh snapshot --label after --wp-root /home/judge/src/web --hustoj-container hustoj-judge --output /root/oj-microbit-acceptance/after-install.json
sudo bash /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/host-check.sh compare --before /root/oj-microbit-acceptance/before.json --after /root/oj-microbit-acceptance/after-install.json --output /root/oj-microbit-acceptance/compare-install.json
```

- [ ] 의도적 실패 시험의 `report.json`이 복원을 확인했다. 시간 초과·중단·비정상 실패면 private log를 로컬에서 확인하고 수동 복구했다.
- [ ] 정상 설치·같은 버전 재실행·설치 후 진단이 통과했고 실제 GPIO canary가 100점이다.
- [ ] HUSTOJ ID·시작 시각·재시작 횟수·이미지·마운트·네트워크와 daemon/config/repo가 같았다. 새 마이크로비트 이미지 때문에 용량이 증가하는 것은 별도로 기록했다.
- [ ] 시험 서버 재부팅 후 기존 HUSTOJ와 마이크로비트가 모두 준비됐다. 재부팅은 이 도구가 수행하지 않는다. 재부팅으로 시작 시각이 바뀌므로 이 구간은 별도 근거로 남긴다.
- [ ] 별도 시험 복제 환경에서 DB/웹/서비스 백업을 복원하고 대표 자료·기존 점수·프로젝트·버전을 대조했다. 같은 운영 호스트에 덤프를 덮어쓰지 않는다. VM 전체 복원은 HUSTOJ도 되돌릴 수 있으므로 설치 전후 무변경 비교와 분리한다.

## C. 실제 DB 스키마·실행계획·시간

```bash
sudo -u www-data /usr/bin/php8.3 /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/wp-db-check.php --wp-root /home/judge/src/web --iterations 5
```

처음에는 스키마·인덱스·InnoDB·기본 대표 조회를 확인합니다. 최종 시험에서는 실제 소유 교사/과제/학생/중간 커서 ID와 그룹·기존 수업 ID를 명시해 다시 실행합니다. 아래 숫자는 예시이며 실제 시험 자료 번호로 바꿉니다. --class-id는 수업 번호(기존 배포와 새 수업 목록), --assignment-id는 새 수업 배정 번호, --group-id는 기존 그룹 제한 배포 번호입니다. 실제 존재하는 범위를 지정하고 없는 범위 옵션은 뺍니다.

```bash
sudo sh -c 'umask 077; set -C; runuser -u www-data -- /usr/bin/php8.3 /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/wp-db-check.php --wp-root /home/judge/src/web --owner-id 10 --exercise-id 25 --learner-id 20 --before-id 5000 --group-id 7 --class-id 9 --assignment-id 12 --iterations 5 > /root/oj-microbit-acceptance/db-middle.json'
```

- [ ] 스키마 5·필수 인덱스·과제/관계 테이블 InnoDB가 확인됐다. v1/2/3/4 자료가 있다면 해당 백업의 복제 환경에서도 이전 자료·요약·소유권을 보존하며 업그레이드했다.
- [ ] 실제 데이터가 있는 첫/중간/마지막 커서와 특정 교사·과제에 편중된 자료로 측정했다. 빈 결과나 작은 fixture만으로 대량 승인하지 않는다.
- [ ] 요약 SQL에 큰 JSON·전체 COUNT·깊은 OFFSET·과제별 추가 조회가 없다. 그룹·수업 후보 UNION은 인덱스와 제한된 후보를 사용한다. 전체 계획의 읽은 행 수·정렬·잠금 대기를 검토했다.
- [ ] SQL 측정과 Repository 전체 측정, 실제 REST 지연을 구분했다. 적은 반복의 p95는 참고 수치이며 장시간 수업 부하 승인을 대신하지 않는다.
- [ ] 두 세션 동시 저장의 CAS 충돌, 관계 INSERT/COMMIT 실패, 개인정보 삭제와 저장/실행 경합을 **실제 MySQL**에서 검증했다. 읽기 도구는 이 항목을 자동 승인하지 않는다.
- [ ] WP-Cron 실행·보관 정리·개인정보 삭제 배치 완료와 재개를 실제로 확인했다. 단순 예약 존재와 실제 완료를 구분했다.

## D. 시험 계정·그룹(학급)·REST 권한

운영 학생 계정을 사용하지 않습니다. 관리자와 별개로 교사 A/B·학생 A/B **서로 다른 비관리자 시험 계정 4개**를 만듭니다. 교사는 `oj_microbit_teach`, 학생은 `oj_microbit_learn` 권한을 갖고 학생에게 교사 권한은 주지 않습니다. 교사 A가 관리하는 수업(class)에 학생 A를 참여시키고 학생 B는 수업 밖에 둡니다. 같은 공개 문제를 두 수업에 적용할 수 있도록 수업 두 개를 준비합니다. 기존 그룹/수업 제한 배포는 별도 호환 시험으로 확인합니다. 각 프로필에서 시험용 Application Password를 하나씩 발급합니다. 암호·nonce를 셸 인수나 공유 기록에 넣지 않습니다.

```bash
sudo -u www-data sh -c 'umask 077; set -C; cat /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/config.example.json > /var/lib/oj-microbit-acceptance/accounts.json'
sudo -u www-data vi /var/lib/oj-microbit-acceptance/accounts.json
```

파일의 실제 `baseUrl`·시험 사용자명·Application Password·`classIds.teacherA`/`studentA`/`studentB`·HUSTOJ 읽기 주소를 채웁니다. 예시의 비밀번호는 작동하는 자격증명이 아닙니다. 수업 배열을 비워 두면 수업 시험이 미완료로 남고 두 번째 수업이 없으면 수업 간 격리는 PENDING입니다. 학생 A는 두 수업 모두 참여시키고 학생 B는 첫 수업 밖에 둡니다. 학생 B가 두 번째 수업에만 참여하면 수업별 권한과 미제출 표시도 대조할 수 있습니다. groupIds는 기존 제한 배포 호환 시험에만 사용합니다. 같은 숫자 ID라도 그룹과 수업을 서로 다른 대상으로 검증합니다. 입력과 출력 파일은 웹 루트 밖에 유지합니다.

```bash
sudo -u www-data /usr/bin/php8.3 /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/wp-nonce.php --wp-root /home/judge/src/web --config /var/lib/oj-microbit-acceptance/accounts.json --output /var/lib/oj-microbit-acceptance/accounts-with-nonces.json --allow-wordpress-bootstrap
sudo -u www-data /opt/oj-microbit-node-24.22.1/bin/node /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/rest-check.mjs --config /var/lib/oj-microbit-acceptance/accounts-with-nonces.json --report /var/lib/oj-microbit-acceptance/rest-readonly.json
sudo -u www-data /opt/oj-microbit-node-24.22.1/bin/node /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/rest-check.mjs --config /var/lib/oj-microbit-acceptance/accounts-with-nonces.json --report /var/lib/oj-microbit-acceptance/rest-writes.json --allow-test-writes
```

nonce 준비는 정상 WordPress를 부트스트랩합니다. 도구가 직접 계정/자료를 바꾸지는 않지만 플러그인·현재 사용자 hooks의 동작은 가능하므로 표시된 시험 서버에서만 실행합니다. 앱 암호와 nonce의 실제 HTTP 인증은 다음 REST 검사에서 확인합니다.

- [ ] 인증 없는 요청·잘못된 nonce가 거부되고 각 계정의 실제 역할이 구분됐다.
- [ ] 공개 전 점검, 지문 변경 거부, 학생 프로젝트 저장/복원·CAS 충돌·타인 프로젝트/이력 차단이 통과했다.
- [ ] 위조 점수·trace는 성적에 반영되지 않고 실제 정답은 100점이다. 숨김 조건·trace·오류 세부가 학생 응답과 개인정보 내보내기에 없다.
- [ ] 기존 제한 배포는 해당 소속 밖 접근을 거부한다. 새 공개 문제의 일반 풀이는 수업 밖에도 허용하고 수업 배정 경로만 수업 밖 접근을 거부한다. 교사 B는 교사 A 원본/일반 제출을 관리하지 못하며 수업 관리 권한으로 허용된 배정 결과만 확인한다.
- [ ] 별도 수동 시험에서 학생 수업 탈퇴·수업 비활성화·공개 회수·교사 수업 관리 권한 회수 전후를 확인했다. 실행 중 회수 후 결과 저장도 차단됐다. 도구는 실제 그룹·수업 관계를 변경하지 않는다.
- [ ] 브라우저에서 GPIO/PWM/출력 순서/서보/I²C 대표 과제의 블록↔소스·저장·채점·오류 후 코드 보존을 확인했다.

- [ ] 신규 설치와 스키마 1/2/3/4 → 5에서 새 수업 배정·프로젝트·최근 결과·제출 배정 식별자와 필수 인덱스/InnoDB를 확인했다. 기존 제출은 일반 배정 0으로 보존되고 기존 제한 배포는 자동 공개되지 않았다.
- [ ] 새 공개 문제는 수업 밖 학생 B도 목록·설명·일반 저장·실험·채점·일반 내역을 사용할 수 있다. 수업 적용이 원본 공개 범위를 좁히지 않는다.
- [ ] 같은 문제의 일반 풀이·수업 A·수업 B 프로젝트와 제출 내역이 서로 섞이지 않는다. 일반 100점이 수업 미제출 상태를 바꾸지 않는다. 배정 위조·다른 문제·다른 수업 ID·클라이언트 점수는 거부되고 일반 제출로 대체되지 않는다.
- [ ] 수업 밖 학생 B는 해당 배정의 저장·실험·제출·결과를 열 수 없다. 채점 도중 참여·배정·공개 상태를 회수하면 새 결과 저장이 차단된다.
- [ ] 수업 교사는 다른 교사의 공개 문제를 적용할 수 있고 해당 수업 결과만 본다. 원본 작성자라도 수업 관리 권한이 없으면 그 수업의 기록을 일반 교사 API로 우회 조회할 수 없다. 원본 숨김 조건도 노출되지 않는다.
- [ ] 현재 수업 명단의 최근 결과와 미제출 학생, 0점·컴파일 오류 제출을 구분했다. 명단 리비전 변경은 재조회 안내로 처리하고 명단 한 페이지+일괄 요약이며 학생별 추가 SQL·전체 제출 집계가 없다.
- [ ] 회수·재적용·원본 비공개·삭제 후 새 작업 차단과 과거 결과 보존을 확인했다. 같은 배정 재활성화는 이전 내역을 유지하며 새 배정으로 일반 기록을 소급하지 않는다.
- [ ] 링크 새로고침·뒤로 이동·미저장 취소와 context별 브라우저 초안 복구를 확인했다. 전체 삭제·개인정보/기간 정리가 수업 저장소까지 작은 배치로 처리된다.

## E. HUSTOJ 병행 부하

config `load`는 기본 동시 2·초당 2·20건입니다. 도구 상한은 전체 200요청, 동시 4, 초당 5, 부하 60건, 전체 5분입니다. 두 시험 학생을 이용한 제한된 연동/혼잡 시험이며 수십 명의 서로 다른 실제 학생 세션을 재현한 인증은 아닙니다. 예상 학급 규모 승인은 별도 학생 세션으로 측정합니다.

```bash
sudo -u www-data /opt/oj-microbit-node-24.22.1/bin/node /home/judge/src/web/wp-content/plugins/oj-microbit/scripts/acceptance/rest-check.mjs --config /var/lib/oj-microbit-acceptance/accounts-with-nonces.json --report /var/lib/oj-microbit-acceptance/rest-load.json --allow-test-writes --load
```

도구 실행 동안 다른 시험 계정으로 HUSTOJ의 알려진 정답·오답·시간 초과 문제를 실제로 제출합니다. 제출 번호·기대/실제 판정·대기/완료 시간·동시 마이크로비트 부하를 수동 표에 적습니다. 도구의 HUSTOJ GET 지연은 웹 응답 지표이며 judged 처리량을 증명하지 않습니다. CPU·RAM·I/O·디스크 여유도 같이 기록합니다.

- [ ] 부하 전 HUSTOJ와 마이크로비트 기준 지연·처리량을 같은 자료로 측정했다.
- [ ] 병행 중 HUSTOJ 판정이 맞고 컨테이너 재시작·자료 변경이 없었다. 비교할 최대 지연/오류율/용량 허용폭을 시험 전에 정했다.
- [ ] 429가 안내한 대기 후 수동 재실행으로 회복됐고 재시도 폭주·무한 대기·중복 점수 기록이 없었다. 실제 API·SQL p50/p95와 오류율을 함께 검토했다.
- [ ] 별도 허용된 시험 요청으로 Docker 비루트·network none·read-only·cap 제거·메모리/CPU/PID·40초 제한을 관측했다. OOM·무한 루프·API 종료 뒤 잔여 작업 회수와 정상 요청 회복을 확인했다. HUSTOJ를 중지하거나 전체 Docker prune으로 정리하지 않았다.
- [ ] 부하 직후 새 `host-check snapshot`을 찍어 설치 전 상태와 비교했다. 강제 재부팅/VM 복원 시험은 별도 기준 상태로 비교했다.

초안 목표는 목록 SQL p95 100ms·목록 REST p95 500ms 이하입니다. 이는 보장된 운영 수치가 아닙니다. 서버 자원·자료량·시험 계정 수에 맞춘 실제 합격선을 판정 기록에 확정합니다.

## F. 독립 메뉴·생성 페이지·실제 설치

- [ ] OJ Platform 2.5.9/API 1.9 이상에서 마이크로비트 rc.8을 적용하고 재로그인·브라우저/자산 캐시 갱신 후 버전을 대조했다.
- [ ] 일반 문제·수업 메뉴에는 마이크로비트 유형 필터·목록·결과 링크가 추가되지 않는다. 전용 메뉴의 열람이 일반 문제 수정 권한을 추가하지 않는다.
- [ ] 교사는 **마이크로비트 문제 → 문제 목록 / 문제 생성**, **마이크로비트 수업** 전용 메뉴를 사용한다. 학생은 전용 문제·수업 두 메뉴를 사용하며 생성 메뉴/직접 URL은 허용되지 않는다.
- [ ] 목록에서 새 문제/세 가지 예제를 선택하면 실제 생성 전용 경로로 이동하고 해당 예제가 유지된다. 집중형 작성은 현재 단계만 표시한다. 새 문제는 빈 예시로 시작하고 단계·핀 여러 개·핀별 값·시간 모달을 진행한다. 같은 시점의 입력과 출력을 함께 적용하고 단계 수정은 모든 기존 핀을 불러온다. 취소·Escape는 원본을 보존하고 Tab/Shift+Tab은 모달 안에서 순환한다. 시작 코드를 처음 열 때만 편집기가 로딩되고 저장·재저장 뒤 같은 프레임과 코드를 유지한다. 초기 자동 정리에는 이탈 경고가 없고 실제 미저장 변경에는 경고가 한 번 나온다.
- [ ] 문제 설명·풀이·일반 제출, 수업 선택·배정별 풀이·결과·제출에서 전용 현재 메뉴가 한 개만 표시되며 일반 문제/수업을 선택한 것으로 표시되지 않는다.
- [ ] WordPress 루트 및 하위 폴더 설치에서 5개 등록 페이지 주소와 기존 생성·수업 링크를 확인했고 접두사가 사라지거나 중복되지 않는다.
- [ ] 메뉴 저장 설정의 숨김·정렬·이름, 실제 역할의 콘솔·교사·학생·수업 관리/참여 권한을 확인했다.
- [ ] 실제 테마에서 320/390/440px·데스크톱·다크·키보드 초점·빈 안내·오류·표 내부 스크롤이 정상이다. 이동 취소 시 미저장 작업을 보존하고 이동 승인 뒤 확인창이 중복되지 않는다.
- [ ] 전용 수업 결과 공개 정책을 평가 운영 요구와 대조했다. 일반 프로그래밍 수행 모드의 정책이 마이크로비트 전용 페이지에 자동 적용된다고 가정하지 않았다.

- [ ] 입력 단계 간격과 출력 유지/반응 한도를 구별했다. 즉시 켜고 0.5초 대기와 켜기 전 0.5초 대기의 채점 차이, 뒤 꺼짐 조건의 반응 한도를 확인했다.
- [ ] 여러 핀의 같은 시점 조건은 병렬 가지, 한 핀의 다음 값은 직렬 화살표로 나타난다. 새 입력 핀의 다른 예시 초기값 0을 검토했고 I²C·기존 세부 시간표가 보존된다.

- [ ] 실제 모바일의 가상 키보드·회전·확대와 스크롤에서 모달 적용/취소 버튼을 사용할 수 있고, 잘못된 핀 하나가 있어도 일부만 적용되지 않는다. 학생 보기는 한 제목·설명·장치·공개 동작과 풀이 버튼이 분명하다.

## G. 정리·판정·선택 보드 시험

- [ ] REST 보고서의 자기 생성 과제가 모두 회수됐다. `cleanupPending`·`creationOutcomeUncertain`이 남으면 보고서의 자기 시험 ID/실행 표식만 확인해 수동 회수했다. 다른 과제는 건드리지 않는다.
- [ ] 회수는 archive이므로 시험 프로젝트·채점 이력은 보관된다. 시험 VM 스냅샷 복구 또는 시험 계정의 개인정보 도구를 사용하고 배치 완료까지 확인했다.
- [ ] 시험용 Application Password를 폐기하고 웹 루트의 시험 표식과 비공개 자격 파일을 제거했다. 비밀 없는 결과만 보관했다.
- [ ] [판정 기록](ACCEPTANCE-REPORT-TEMPLATE.md)에 자동 PASS/FAIL과 남은 PENDING을 구분해 채우고, 필수 수동 항목과 승인자를 적었다. 추가 결함은 rc.5 이상의 새 버전으로 수정하며 기존 ZIP을 덮어쓰지 않는다.
- [ ] **USB 정식 지원을 포함할 때만:** V1/V2와 HTTPS Chrome/Edge의 HEX·USB 전송·최신 코드 실행·취소·분리·재연결을 실제로 확인했다. 미실시라면 보드 업로드는 미검증/시험 기능으로 표시하고 시뮬레이션·채점 승인과 분리한다.

자격 파일을 정리할 때는 확인한 개별 파일만 지웁니다.

```bash
sudo rm -- /home/judge/src/web/wp-content/oj-microbit-acceptance.json
sudo -u www-data rm -- /var/lib/oj-microbit-acceptance/accounts.json /var/lib/oj-microbit-acceptance/accounts-with-nonces.json
```

실제 시험 서버 결과는 이 문서 작성 시 실행하지 않았습니다. 도구의 로컬 모의 시험·실제 PHP/PXT 회귀는 `VALIDATION.md`에 기록하며 현장 체크박스를 대신 채우지 않습니다.
