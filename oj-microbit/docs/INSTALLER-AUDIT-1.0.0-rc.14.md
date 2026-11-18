# 실행기 업데이트·HUSTOJ 공존·복구 최종 점검 — 1.0.0-rc.14

점검일: 2026-11-05. 구성: WordPress 호스트 직접 설치 + 기존 HUSTOJ Docker + 같은 Docker의 마이크로비트 실행 작업. 실제 운영 서버를 변경하지 않고 소스·실제 설치 함수·외부 명령/호스트 모의 환경·실제 PXT 컴파일로 확인했습니다.

## 같은 설치 명령으로 업데이트되는 조건

먼저 WordPress에 최신 플러그인 ZIP을 설치한 뒤, **설정 → OJ Microbit**의 기존 설치 명령을 서버 SSH에서 다시 실행합니다. 스크립트는 **현재 설치된 플러그인 안의 runner/package.json 버전**을 읽습니다. 설치 명령 자체가 인터넷에서 플러그인 최신 버전을 선택하는 방식은 아닙니다.

- 같은 버전·같은 소스·같은 이미지 라벨이면 재사용합니다. 다른 내용으로 같은 버전을 덮어쓰지 않습니다.
- 새 버전이면 /opt/oj-microbit/버전/runner와 oj-microbit-worker:버전을 준비합니다. npm·이미지 빌드 동안 기존 실행기 설정을 먼저 바꾸지 않습니다.
- 현재 실행기의 releaseVersion·PXT runtime·운영 Docker 격리·oj-live/1, GPIO 채점과 실제 PXT 컴파일 자체 검사를 통과해야 WordPress 연결을 확정합니다.
- --check는 서비스 WorkingDirectory·환경의 이미지/모드·정확한 릴리스·health·WordPress 연결을 읽기 전용으로 검사합니다. 새 GPIO/컴파일 작업은 만들지 않습니다. 업데이트 실패 후 이전 실행기로 복구된 상태를 새 버전 성공으로 오인하지 않습니다.

## HUSTOJ 보호 경로

| 대상 | 설치·업데이트·복구의 처리 |
|---|---|
| 기존 Docker 패키지·저장소·daemon 설정 | 정상 Docker 재사용 분기에서 APT·교체·설정 편집·서비스 시작/재시작 없음 |
| HUSTOJ 컨테이너·이미지·볼륨·네트워크·DB·Compose | 관리·삭제·재시작 명령 없음, 자원 정리/prune 없음 |
| 실행기 포트 | ss와 활성 실행기 MainPID로 확인, 외부·미식별·혼합 소유자이면 중단, 점유 프로세스를 종료하지 않음 |
| 서비스 제어 | oj-microbit-runner.service만 변경, daemon-reload는 유닛 정의 다시 읽기이며 Docker/HUSTOJ 재시작 명령이 아님 |
| Docker가 멈춘 경우 | socket activation을 깨우지 않음. 위험한 legacy Requires가 있으면 이전 파일을 복원하고 실행기만 중지·수동 복구 안내 |
| WordPress DB | 기존 연결 옵션 한 행의 CAS 갱신만 사용, 과제·프로젝트·제출·다른 옵션과 HUSTOJ DB는 변경하지 않음 |
| 디스크 | 설치 경로와 Docker 저장 파일시스템 여유 확인, 부족하면 중단. HUSTOJ 자료 삭제로 공간을 확보하지 않음 |

이미지 빌드·컴파일·채점은 CPU·메모리·디스크 I/O를 공유합니다. **기존 자원 설정을 건드리지 않는다는 검사 결과가 성능 영향 0을 보증하지는 않습니다.** 실제 HUSTOJ 동시 제출·초기 컴파일 부하는 시험 서버에서 측정해야 합니다.

## 실패 시 복구 판정

| 시점·실패 | 판정과 동작 |
|---|---|
| npm/이미지 빌드·사전 점검 실패 | 기존 서비스·환경·WordPress 연결 유지 |
| 활성화 뒤 health·GPIO·컴파일 검사 실패 | 이전 환경·유닛·enabled/active 상태 복원, 이전 포트·키로 인증 health GET 재확인 |
| 연결 확정 전 INT·TERM | 오류 복구와 임시 작업 정리 적용 |
| 이전 서비스 health 확인 실패 | 파일 복구와 정상 서비스 복구를 구분하고 수동 확인 필요 표시 |
| 서비스 enabled 상태 등 복원 명령 실패 | 실패를 무시하지 않고 자동 복구 점검 필요 표시 |
| Docker 중단·의존성 활성화 위험 | Docker를 시작하지 않고 실행기를 중지, 이전 파일과 백업 유지·수동 복구 표시 |
| WordPress 연결이 성공 확정된 뒤 출력/검증 오류 | 새 실행기·WordPress 주소/키의 일관성을 유지하고 --check 안내 |
| 전원 차단·SIGKILL·DB 응답 유실·복구 작업 자체 실패 | 자동 정상화를 보장하지 않음, 백업·서비스·DB를 대조하는 수동 복구 필요 |

자동 복구는 **마이크로비트 실행기 환경과 서비스**를 대상으로 합니다. WordPress 플러그인 ZIP이나 전체 DB를 자동으로 이전 버전으로 내리지 않습니다. 이전 실행기가 새 기능을 지원하지 않으면 설치 전 플러그인·DB 백업도 함께 검토해야 합니다. 이전 릴리스·이미지·OS 준비물·Node와 root 전용 백업은 자동 삭제하지 않습니다.

복구 후 'Previous runner authenticated health verified.'는 이전 서비스의 인증 응답이 확인됐다는 뜻입니다. 업데이트 명령은 실패 종료를 유지합니다. 새 ZIP 기준 --check가 이전 버전 복원을 알릴 수 있으며, 이것을 새 버전 업데이트 성공으로 바꾸지 않습니다. Docker 불가나 복원 명령 실패 때는 정상 복구로 보고하지 않습니다.

## 검사 근거

- Bash 설치기 **32개 검사**: 기존26개에 RC13→14·컴파일 실패·이전 포트/환경/유닛/health 복원·복원 health 불가·INT/TERM·연결 확정 후 오류·이미지 불일치·enabled 복원 실패를 추가/확장했습니다. 기존31개와 새1개를 개별 확인하고 공식 패키징에서도 전체를 실행합니다.
- 실제 PXT 설치 자체 검사 **4개**: GPIO 실제 결과, read-only health, 컴파일 실제 산출물·해시·프로토콜·격리, 정확한 릴리스와 이전 서비스 health의 구분을 검증했습니다. Docker 격리 마커/호스트 명령은 모의 경계이며 실제 Docker에서 실행했다는 뜻은 아닙니다.
- 모의 HUSTOJ 파일의 내용·해시·mtime·모드와 허용 명령 목록을 대조했습니다. 컨테이너·이미지·볼륨·네트워크·daemon/config/repo·서비스 상태를 보존했습니다.
- 현장 점검 Python 도구의 이전 릴리스 판별 변경은 **14개 오프라인 검사**를 통과했습니다. 전체 도구 검사는26개입니다.
- PHP1,264·프런트171·설치기32·실행기110·동결4·REST15·Python26, 총 **1,622개**는 공식 릴리스 킷의 패키징 필수 게이트입니다. 설치·WordPress 연결 도우미를 포함한 제품 **65개 파일**을 동결했습니다.
- 학생 브라우저 코드는 rc.13과 같은 해시이며 [rc.13의42개 흐름](browser-focused-validation-1.0.0-rc.13.json)을 유지합니다. 이번 변경은 설치·health 메타데이터와 자산 버전 표기입니다. 상태 유지·채점 모델·DB SQL은 변경하지 않았습니다.

근거: [설치 검사 요약](installer-validation-1.0.0-rc.14.json), [전체 검사 요약](rc-validation-1.0.0-rc.14.json), [동결 목록](feature-freeze-1.0.0-rc.14.json), [공존 점검서](HUSTOJ-COEXISTENCE.md).

## 실제 서버에서 남은 확인

Ubuntu24.04·PHP8.3·실제 systemd/Docker·WordPress 권한/옵션 CAS·MySQL과 HUSTOJ 동시 부하는 별도입니다. [체크리스트](ACCEPTANCE-CHECKLIST.md)와 scripts/acceptance의 host-check/install-cycle 도구로 시험 서버에서 전후 HUSTOJ ID·StartedAt·RestartCount·마운트·네트워크·daemon 설정 해시, 설치/실패 복원, DB/파일 백업 복구와 서비스 로그를 기록합니다. 설치 실패 주입 도구는 운영 서버에서 실행하지 않습니다. 실제 보드·HEX/WebUSB는 이번 설치 감사 범위에 포함하지 않습니다.
