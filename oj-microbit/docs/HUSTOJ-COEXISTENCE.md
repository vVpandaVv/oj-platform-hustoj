# HUSTOJ Docker 공존 최종 점검

대상: OJ Microbit 0.4.2. 점검일: 2026-10-28. 구성: WordPress 호스트 직접 설치 + 기존 HUSTOJ Docker + 같은 로컬 Docker의 마이크로비트 작업 이미지.

## 점검 결과와 반영 사항

설치기는 HUSTOJ 컨테이너·이미지·볼륨·네트워크·DB를 수정하는 기능을 갖지 않습니다. 기존 Docker를 재사용하는 경로는 패키지 설치/갱신·저장소 수정·Docker 서비스 시작/재시작을 하지 않도록 보완했습니다. 소스 검사와 외부 명령을 대체한 Bash 회귀를 수행했으며 **실제 HUSTOJ가 실행 중인 Ubuntu에서 적용한 결과는 아닙니다**. 검증 결과는 [VALIDATION.md](VALIDATION.md)에 남깁니다.

| 발견한 간접 영향 경로 | 0.4.2 조치 |
|---|---|
| 기존 Docker 판단 전에 공통 도구를 APT로 설치하면 패키지 후처리가 기존 서비스에 영향을 줄 가능성 | APT보다 먼저 Docker 판별. 재사용 경로는 도구·CA만 확인하며 누락 시 중단. 기존 Docker를 고치려고 패키지를 재설치하지 않음 |
| Docker CLI가 없지만 daemon·socket·관련 패키지/자료가 남아 있는 구성 | 신규 설치로 간주하지 않고 중단. 기존 구성을 운영자가 별도 복구 |
| 생성 유닛의 Requires=docker.service가 기존 daemon 외의 generic 서비스를 시작할 가능성 | Requires 제거. After는 시작 순서만 지정하며 유닛 의존성으로 Docker를 시작하지 않음 |
| 중단된 Docker에 읽기 전용 조회를 보내도 docker.socket이 daemon을 깨울 가능성 | Docker 조회 전에 socket activation 상태를 확인. 실패 복원 때도 실행 상태를 먼저 확인하고 불가하면 이전 파일만 복원 후 마이크로비트 서비스만 중지·수동 복구 안내 |
| 실행기 포트를 다른 서비스가 사용 | ss로 소유권 확인. 현재 활성 마이크로비트 서비스의 MainPID만 허용하고 나머지·미식별·복수 외부 소유자는 중단. 활성화 직전 재검사. 프로세스를 종료하지 않음 |
| npm·Docker 빌드가 공유 디스크를 소모 | 설치 경로와 DockerRootDir 파일시스템을 df로 각 한 번 확인. 각 4GiB 미만이면 npm/이미지 빌드 전에 중단. HUSTOJ 자료를 지워 공간을 확보하지 않음 |
| 다른 소스로 같은 버전 폴더·이미지 태그를 덮어쓸 가능성 | root 소유·쓰기 제한·소스 해시·이미지 라벨을 대조하고 불일치 시 중단. 이전 버전 보존 |

## 허용하는 변경 범위

- 전용 Node `/opt/oj-microbit-node-24.22.1`, 버전 경로 `/opt/oj-microbit/0.4.2/runner`, root 전용 실행기 설정·백업·설치 잠금, `oj-microbit` 서비스 계정/docker 그룹만 준비합니다. 시스템 전역 Node를 교체하지 않습니다.
- 이미지 빌드는 `oj-microbit-worker:0.4.2`만 생성하며 daemon 설정·HUSTOJ Compose 파일·기존 컨테이너·볼륨·네트워크를 변경하지 않습니다. 빌드 캐시·기본 이미지·디스크 사용량은 늘어날 수 있습니다.
- systemd는 유닛 목록을 다시 읽고 **oj-microbit-runner.service만** 활성화·재시작·복원합니다. Docker·HUSTOJ·웹 서버·PHP-FPM을 재시작하지 않습니다. 유닛의 의존성으로 Docker를 시작하지도 않습니다.
- WordPress 연결은 자체 `oj_microbit_settings` 옵션 한 행을 비교 후 갱신합니다. 과제·학생 기록과 다른 설정을 보존합니다. ZIP 활성화의 자체 스키마 점검은 별도이며 HUSTOJ DB에 연결하지 않습니다.
- 실제 채점과 설치 canary는 이름을 직접 생성한 `oj-microbit-job-…` 일회성 컨테이너만 실행·회수합니다. 전체 컨테이너를 대상으로 한 stop/rm/prune, compose down, Docker 네트워크/볼륨 정리는 없습니다.

Docker가 완전히 없는 신규 호스트만 공식 저장소·필요 패키지를 설치하고 새 Docker 서비스를 시작합니다. 이것은 기존 HUSTOJ Docker 재사용 경로와 다른 분기입니다. Docker가 중단됐거나 CLI·socket이 불완전한 서버는 자동 수리하지 않습니다.

이 점검의 서비스 제어 보장은 설치기와 생성 유닛에 대한 것입니다. 운영 실행기의 health·채점은 Docker에 조회·실행 요청을 보내므로, 관리자가 Docker 서비스를 중단하고 docker.socket은 켜 둔 구성에서는 운영 요청이 daemon을 다시 깨울 수 있습니다. 운영 중지 정책은 socket activation도 함께 확인하고, 그 상태의 health·채점 동작을 현장 시험에 포함합니다. 실행 중인 HUSTOJ daemon을 그대로 재사용하는 정상 경로와 구분합니다.

## 실제 서버에서 남길 공존 근거

먼저 운영과 같은 시험 서버에서 실행합니다. 실제 운영의 최초 이미지 빌드·업데이트는 점검 시간에 수행합니다.

1. 설치 전 HUSTOJ 컨테이너 ID·이름·StartedAt·RestartCount·상태, 사용 이미지 ID, 연결된 볼륨·네트워크, Docker 서비스 ActiveEnterTimestamp와 daemon 설정/저장소의 해시를 기록합니다. 전체 inspect 원문에는 환경 비밀값이 있을 수 있으므로 필요한 필드만 수집합니다.
2. 설치기의 `--dry-run`, 정상 설치, 같은 버전 재실행, 새 버전 업데이트, `--check` 후 같은 항목을 비교합니다. 새 마이크로비트 이미지·작업만 생겨야 하며 기존 HUSTOJ ID·시작 시각·재시작 수·마운트·네트워크는 같아야 합니다.
3. 포트 충돌·도구 누락·디스크 부족과 빌드/health/연결 실패를 시험 환경에서 재현합니다. 기존 서비스 보존과 이전 실행기 환경·유닛·활성 상태 복원을 확인합니다. 충돌 테스트를 위해 운영 HUSTOJ를 중지하지 않습니다.
4. HUSTOJ 정상 제출과 마이크로비트 GPIO·서보·I²C 채점을 동시에 실행합니다. 설치 전후 HUSTOJ 지연·오류율·대기량, 호스트 CPU·메모리·I/O·디스크 여유를 같은 부하에서 비교합니다. 허용폭을 시험 전에 정하고 초과하면 동시성·호스트 자원을 조정하거나 실행기 호스트를 분리합니다.
5. 재부팅 후 기존 HUSTOJ와 실행기 준비 상태, 실제 GPIO canary와 WordPress 학생 채점을 확인합니다. 실행기 유닛은 Docker를 강제로 시작하지 않으므로 기존 Docker의 자체 부팅 설정도 확인합니다.

읽기 전용 확인 명령:

```bash
sudo docker ps --no-trunc --format 'table {{.ID}}\t{{.Names}}\t{{.Status}}\t{{.Ports}}'
sudo docker image ls --digests
sudo docker volume ls
sudo docker network ls
sudo systemctl show docker.service --property=ActiveState --property=ActiveEnterTimestamp
sudo ss -H -ltnp 'sport = :3037'
sudo docker info --format '{{.DockerRootDir}}'
df -Pk /opt/oj-microbit
sudo bash /home/judge/src/web/wp-content/plugins/oj-microbit/runner/install.sh --check
```

기록에는 실제 OS·Docker·PHP·WordPress 버전, 설치 ZIP 체크섬, 전후 값, 부하 조건과 결과를 함께 남깁니다. daemon 설정·인증키·학생 소스 원문은 공유 보고서에 넣지 않습니다. `--check`는 읽기 전용 상태·설정 검사이며 새 canary 작업을 실행하지 않습니다.

## 보장 범위

회귀 검사는 실제 `detect_docker_policy`·`ensure_tools`·`ensure_docker`·포트/디스크 검사 함수를 실행하고 외부 명령·OS socket/CA를 모사합니다. HUSTOJ 컨테이너·이미지·볼륨·네트워크와 daemon/config/repo sentinel의 내용·시각·모드 보존, 허용 명령만 실행됨을 대조합니다. 실제 apt·systemd·Docker·Linux 권한·네트워크·운영 WordPress DB는 실행하지 않았습니다.

기존 자원을 변경하지 않는 것과 **공유 자원의 성능 영향이 전혀 없는 것**은 다릅니다. 작업당 CPU 1개·메모리 384MiB·40초, 실행기 동시 2개 제한이 있지만 API·컴파일·이미지 빌드·Docker daemon의 전체 사용량 상한은 아닙니다. 4GiB는 시작 전 여유 공간 하한이며 이후 증가를 예약하거나 감시하지 않습니다. 운영 부하 승인은 실제 병행 시험이 필요합니다. 정식 출시의 승인 기준은 [RELEASE-PLAN.md](RELEASE-PLAN.md)에 정리했습니다.
