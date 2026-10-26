# ML Playgrounds 업스트림 기록

이 플러그인의 `resources/playground/`는 다음 업스트림의 정적 빌드 산출물입니다. **1.4.2은 1.2.4의 내장 앱과 패치를 변경 없이 사용**하며 서버 CSV 통계·데이터 설명·평가 근거 확인과 교사 등급 조정 화면을 보완합니다. 1.2.4는 상단 제출 경고의 긴 사유를 별도 확인창으로 분리해 다시 빌드했습니다. 대규모 분류 결과 메모리 보완·학습 전 안내, 과제 정책 예방 검사와 기존 OJ 보호·제출 기능을 유지합니다. 승인 기준 시험 서버 OJ dev.107 / ML RC6에서의 G1 기술 인수는 과거 1.0.0 기록이며 새 기능의 현장 검증을 뜻하지 않습니다. 현재 내장 앱의 빌드 기록은 아래 1.2.4 절을 따르며 이전 버전 수치와 해시는 당시 산출물에만 해당합니다.

- 프로젝트: [itmir913/ml-playgrounds](https://github.com/itmir913/ml-playgrounds)
- 플러그인 릴리스: `1.4.2` (0.12.1 고정, 1.2.4 내장 앱 유지; 데이터 맥락·서버 통계·근거 확인·등급 조정 보완, 서버 설치는 별도)
- 기준 브랜치: `main`
- 기준 커밋: `2276d1f98d6b7c5c11608a27b20f3c0709aa1542`
- 업스트림 버전: `0.12.1`
- 확보일: `2026-08-31`
- 라이선스: MIT (`LICENSES/ML-Playgrounds-MIT.txt`)
- 번들 의존성 고지: `resources/playground/third-party-notices.txt`

## 빌드

업스트림 저장소를 위 커밋으로 detached checkout하고, 이 플러그인의 `patches/ml-playgrounds-oj-bridge.patch`를 적용한 뒤 `frontend/`에서 lockfile 기반으로 빌드했습니다.

```sh
git clone https://github.com/itmir913/ml-playgrounds.git
cd ml-playgrounds
git checkout --detach 2276d1f98d6b7c5c11608a27b20f3c0709aa1542
git apply /path/to/oj-ml-playgrounds/patches/ml-playgrounds-oj-bridge.patch
cd frontend
npm ci
npm run ci
```

아래 내용은 이전 0.2.9에서 번들을 제작할 당시의 기록입니다. 1.0.0-rc.1과 rc.2에서는 내장 SPA를 재빌드하지 않았습니다. rc.3에서는 포트폴리오 원클릭 양식 적용 브리지를 추가하고 전체 소스 검사와 재빌드를 수행했습니다. 새 빌드 기록은 아래 rc.3 절을 따릅니다.

번들 제작 당시 검증 도구는 Node.js `v24.15.0`, npm `11.12.1`, `uv 0.12.7`이었습니다. `npm ci`는 507개 package를 설치했고 audit 결과 취약점은 0개였습니다. 브리지 패치는 깨끗한 고정 커밋에서 whitespace error를 포함한 forward check, 실제 적용과 reverse check를 모두 통과했습니다. backbones, lint와 typecheck가 통과했으며, Vitest는 worker 4 전체 재실행에서 112개 test file, 2,539개 통과와 2개 의도적 skip을 기록했습니다. `npm run fixtures:check`는 새로 실행한 scikit-learn `1.9.0` fixture와 일치했습니다.

기본 최대 병렬 실행에서는 개발 PC의 동시 부하로 서로 다른 두 개의 5초 제한 테스트가 각각 한 번 timeout했습니다. 두 테스트는 단독 재실행에서 통과했고, worker 4로 제한한 전체 suite도 위 결과로 통과했습니다.

`npm run build`는 `vue-tsc --build && vite build`이며, 최종 산출물은 175개 파일, 총 8,840,788 bytes입니다. `frontend/dist/`를 `resources/playground/`에 미러링한 뒤 상대 경로별 SHA-256이 모두 일치함을 확인했습니다. 업스트림 `.git`, 소스 트리, `.cache`, `node_modules`는 플러그인에 포함하지 않습니다.

MobileNetV2 모델 가중치는 업스트림 정책대로 번들에 포함하지 않습니다. 이미지 기능을 처음 사용할 때 학생 브라우저가 다음 원본에서 약 12.4MB를 내려받습니다.

```text
https://storage.googleapis.com/tfjs-models/savedmodel/mobilenet_v2_1.0_224/model.json
```

## OJ 호스트 패치

`patches/ml-playgrounds-oj-bridge.patch`는 다음 변경을 보존합니다.

- `frontend/src/oj-host-v2.ts`의 configure-once, frame nonce, same-origin 브리지
- `frontend/src/oj-storage-scope.ts`와 `frontend/src/project/oj-encrypted-storage.ts`의 사용자별 AES-GCM vault
- 검증된 configure 이전 Vue mount·IndexedDB 접근 차단과 정적 번들의 top-level 직접 실행 거부
- 소유자 미확인 legacy DB 삭제, scope별 locale/theme/view preference, 현재 scope 전체 삭제
- BroadcastChannel revoke와 Web Locks shared/exclusive barrier를 이용한 다중 탭 저장 중단·삭제 직렬화
- 플러그인 부모 wrapper와 결합해 BFCache 복원, pagehide, 세션 교체와 저장소 폐기 시 키·nonce·iframe을 버리는 fail-closed 처리
- 임베디드 툴바의 OJ 직접 제출 action, 현재 메모리 프로젝트의 `.mlpx` 직렬화·업로드 함수와 한국어·영어 제출 문구
- OJ 통합 처리 내용에 맞춘 한·영 개인정보·저장 고지와 호스트 제공 개인정보 처리방침 URL

브리지 프로토콜 버전은 `2`입니다.

1. iframe은 128-bit 무작위 `frameNonce`를 만들어 같은 출처 부모에 `{type:'oj-ml:ready',version:2,frameNonce}`를 보냅니다.
2. 같은 iframe window와 같은 출처의 부모만 다음 설정을 한 번 보낼 수 있습니다.

```text
{
  type: 'oj-ml:configure',
  version: 2,
  frameNonce,
  assignmentId,
  attemptId?,
  restRoot,
  nonce,
  maxBytes?,
  assignmentPolicy: {version: 1, allowedTaskTypes, allowedAlgorithms},
  storageScope,
  storageSession,
  storageKey,
  storageDatabase,
  storageKeyVersion: 1,
  deviceMode: 'shared',
  privacyUrl
}
```

3. iframe은 scope·세션·키·DB 이름을 엄격히 검사하고 사용자 저장소를 설정한 뒤에만 앱을 mount합니다. 잘못된 origin/source/frame nonce, 두 번째 configure와 top-level 직접 실행은 거부합니다.
4. `attemptId`가 있으면 임베디드 툴바에 `OJ에 제출하기`가 표시됩니다. 학생이 누르면 SPA는 현재 메모리 프로젝트를 `.mlpx` Blob으로 직렬화해 부모 window나 다운로드 폴더를 거치지 않고 REST endpoint에 직접 업로드합니다.

```text
POST {restRoot}/attempts/{encodeURIComponent(attemptId)}/submit
X-WP-Nonce: {nonce}
Content-Type: multipart/form-data
artifact: <project.mlpx>
```

5. 부모에는 `{type:'oj-ml:submission-status',version:2,frameNonce,state,...}` 형태의 작은 상태만 보냅니다. 로컬 삭제에는 `oj-ml:storage-wipe-request`, `oj-ml:storage-wiped`, `oj-ml:storage-error`를 사용합니다.
6. 같은 `storageScope`의 탭들은 키·nonce가 없는 BroadcastChannel 메시지 `{type:'oj-ml:storage-revoke',version:1,scope}`를 공유합니다. 수신 탭은 즉시 저장 generation을 폐기하고 원시 키를 zeroize하며 신규 저장을 차단한 뒤, 진행 중 작업 정리·DB close·shared Web Lock 해제를 수행합니다. BroadcastChannel별 ACK는 없으며, 삭제 주체가 같은 scope의 exclusive Web Lock을 획득한 사실이 모든 shared lease 해제를 증명하는 barrier입니다. 폐기된 scope는 같은 문서 생명주기에서 다시 열 수 없습니다.
7. peer·DB blocking·bootstrap failure로 인한 iframe 내부 폐기는 키가 없는 `{type:'oj-ml:storage-revoked',version:2,frameNonce}`로 부모에 한 번 전달됩니다. local wipe는 이 알림 대신 원래 frame nonce를 보존한 `storage-wiped` 또는 `storage-error` 응답을 사용합니다. 부모는 source·origin·nonce를 검증한 뒤 세션·attempt·REST nonce·저장 키 참조와 iframe을 즉시 버리고 terminal 안내를 유지합니다. 이 상태에서 자동 새로고침을 예약하지 않으므로 bootstrap 실패가 reload loop로 이어지지 않습니다.

`assignmentPolicy`는 서버가 저장한 과제 조건을 부모가 전달하는 UI 예방 계약입니다. `version`은 `1`이며 `allowedTaskTypes`는 비어 있지 않은 분류·회귀·군집 유형 목록, `allowedAlgorithms`는 모델 ID 목록입니다. 모델 목록이 명시적으로 비어 있으면 모델 제한이 없다는 뜻입니다. 정책이 누락되거나 구조가 잘못되면 `null`로 취급합니다. 저장소·출처 검사를 통과한 앱의 mount와 기존 프로젝트 열람은 유지하지만, 새 유형·모델 선택, 학습 시작과 직접 제출은 오류 안내와 함께 차단합니다. 학생의 기존 선택·실험 기록을 자동 삭제하거나 수정하지 않습니다. 이 UI 계약은 권한이나 최종 제출 검사를 대신하지 않으며 서버는 최신 과제 정책으로 다시 검증합니다.

설정 메시지와 `restRoot`는 iframe과 같은 출처일 때만 받아들이고, 암호화 키는 URL·HTML 속성·IndexedDB에 기록하지 않습니다. `privacyUrl`은 HTTP(S)만 허용합니다. 암호화는 Web Crypto API를 사용하므로 이 OJ 패치 번들은 HTTPS 전용이며, 공용 PC 모드는 Web Crypto, IndexedDB, BroadcastChannel, Web Locks 지원 브라우저에서만 mount됩니다. `maxBytes`가 있으면 Blob 생성 후 업로드 전에 크기를 검사합니다.

`attemptId`가 없으면 기존 `.mlpx` 다운로드는 유지되지만, 브리지 설정 없는 독립 실행은 이 플러그인용 번들에서 차단됩니다. 독립 실행은 OJ 패치를 적용하지 않은 업스트림 원본 빌드를 사용해야 합니다. WordPress 부모 화면의 수동 파일 선택 제출은 직접 제출 장애 때만 펼치는 fallback으로 유지합니다.

## 갱신 절차

### 1.2.4 제출 경고 레이아웃 보완 — 2026-10-15

고정 높이 툴바의 인라인 `details`가 긴 제출 차단 사유를 본문 위로 펼치던 구조를 제거했습니다. 툴바에는 제출 버튼과 짧은 사유 버튼만 남기고 기존 네이티브 `AppDialog`로 설명을 표시합니다. 창은 뷰포트 안에서 줄바꿈·스크롤하며 닫기·Esc, 접근성 이름·설명 연결, 첫 설명의 초기 포커스를 제공합니다. 좁은 화면에서는 버튼을 아이콘으로 축약하고 툴바 간격을 줄이되 높이는 유지합니다. 정책 문제가 해결되거나 프로젝트가 바뀌면 열린 창을 닫습니다. 제출 가드·업로드·메모리 보완·스키마·업스트림 버전은 변경하지 않았으며 릴리스 1.2.3의 서버 설정 도구도 보존했습니다.

전체 ESLint·Prettier·typecheck, backbones, scikit-learn **1.9.0**의 새 실행 fixture 검사를 통과했습니다. Vitest worker 4 결과는 **121개 파일, 2,644개 통과, 기존 의도적 skip 2개**입니다. 경고의 열기·닫기·재열기·네이티브 닫기 상태 동기화, 긴 사용자 문자열의 이스케이프, ARIA 연결, 프로젝트 변경 시 닫힘, 좁은 화면 간격과 제출 차단 유지 회귀검사를 포함합니다. Vue/jsdom 및 CSS 구조 검사이며 실제 브라우저의 시각·포커스 동작을 실측한 것은 아닙니다. 운영 서버 설치는 별도입니다.

production build는 **174개 파일, 8,897,239 bytes**이며 `frontend/dist/`와 `resources/playground/`의 경로·SHA-256이 모두 일치합니다. 교체된 이전 해시 자산 28개만 배포 소스에서 제거했고 이전 1.2.3 ZIP은 변경하지 않았습니다. entry는 `assets/index--AEKCct3.js`, SHA-256은 `b2b1ce8dcd15ffa444485adf940ef44670df72a6de20820de79063c5c62c992c`입니다. 빌드 CSS에서 확인창 크기·줄바꿈 제한을 확인했으며 기존 random-js의 crypto 외부화 경고가 있지만 빌드는 성공했습니다.

고정 커밋 archive에서 **62파일 패치**를 재생성했습니다. SHA-256은 `600b3e0360b9597ae276e53819f9b5de4a632bf11b8da029763419007e781933`입니다. 상위 Git 저장소의 경로 필터를 배제하고 깨끗한 원본에서 whitespace check·실제 적용·reverse check를 수행한 뒤 **473파일**을 빌드 소스와 LF 정규화 해시로 대조했습니다. 빌드 전후 소스 변화가 없으며, 이전 패치 대비 차이는 툴바·제출 버튼·아이콘·한영 문구·확인창 CSS·관련 테스트의 8파일뿐입니다. 새 의존성이나 모델·데이터 변환은 없습니다.

### 1.2.1 대규모 분류 메모리 보완 — 2026-10-15

고정 커밋·업스트림 0.12.1·의존성 lock·MLPX 형식 2·DB 스키마 4는 유지합니다. 범주가 50개를 넘는 새 브라우저 분류 평가에서는 제곱 크기의 전체 혼동 행렬을 만들지 않습니다. 행과 범주별 집계로 정확도·F1·범주별 점수를 계산하며 평가 대상을 표본으로 줄이지 않습니다. 기존 파일의 혼동 행렬은 수정하지 않고 화면에서 최대 20행 × 20열씩 표시합니다. 범주별 점수·모델 매개변수에도 페이지를 적용하고 셀마다 팝업을 생성하던 구조를 공유 상세 보기 하나로 바꿨습니다.

학습 전 안내는 실제 결측 처리·표본 추출·라벨 정규화와 별도 테스트 데이터에 맞춰 범주 수를 셉니다. 많은 범주로 진행하려면 명시적으로 확인해야 하며, 수치형 타깃이면 회귀 검토를 안내합니다. 설정·데이터가 바뀌면 확인을 무효화하고, 현재 과제 정책과 중복 학습 여부를 다시 검사합니다. 데이터를 임의로 범주화하거나 유형을 자동 변경하지 않습니다. 저장은 단일 실행으로 직렬화하고 최신 변경의 후속 저장·실패 재시도·저장소 폐기 처리를 유지합니다.

Node.js **24.15.0** / npm **11.12.1**에서 전체 ESLint·Prettier·typecheck와 Vitest worker 4 실행이 통과했습니다. 결과는 **121개 파일, 2,638개 통과, 기존 의도적 skip 2개**입니다. 새 검사는 합성 **8,760행 × 14열 / 2,166범주**의 안내·확인·취소·정책 변경·중복 클릭, 큰 결과 패널의 DOM 수·페이지 이동, 저장 큐의 경합·실패·폐기를 포함합니다. 이 UI 검사는 Vue/jsdom 기반이며 실제 Chrome의 전체 메모리 실측을 대신하지 않습니다.

별도의 Node **256 MiB 힙 제한** 자식 프로세스에서 실제 지표 계산 소스로 **8,760행 / 2,166범주 / 세 차례 평가**를 실행하여 전체 행의 정확도·지원 수, 유한 F1과 제곱 행렬 미생성을 확인했습니다. 세 모델의 실제 대규모 학습을 같은 제한에서 완료했다는 뜻은 아닙니다. PHP 검사에서 혼동 행렬 없는 2,166범주 제출의 정상 수용과 금지 알고리즘 정책 유지도 통과했습니다. `backbones`와 scikit-learn **1.9.0**의 새 실행 fixture 대조도 통과했습니다. 로컬 uv/PHP 임시 파일의 샌드박스 권한 오류는 승인된 로컬 재실행으로 해결했으며 운영 서버 설정은 바꾸지 않았습니다.

최종 production build는 **175개 파일, 8,896,050 bytes**입니다. `frontend/dist/`와 `resources/playground/`의 상대 경로·SHA-256이 모두 일치하며, 이전 해시 자산 63개를 제거했습니다. 이전 릴리스 1.2.0 ZIP은 변경하지 않아 복구 자료로 남습니다. entry는 `assets/index-DasVVZZs.js`, SHA-256은 `524590327e57b70120dc7d46dd46105454330399dae2dfffc44de23b4e85d33b`입니다. 라이선스 고지와 UTF-8·BOM 없음 검사를 유지합니다. 기존 random-js의 Node crypto 외부화 경고와 빌드 시간 안내는 남으며 빌드는 성공했습니다.

고정 커밋 archive(SHA-256 `5dd11f4375e286a37f93c0808a336af2215b1bc30042190e1e7dc874534e94b1`)에서 재생성한 **59파일 패치**의 SHA-256은 `a9a5f7e6caf9ec08c53debc15b9fef84989cfa6b8447889e29cf90dbe49dac80`입니다. 깨끗한 원본에서 forward whitespace check·실제 적용·reverse check를 통과했고, 적용 후 **473파일**을 빌드 소스와 LF 정규화 대조했습니다. 빌드 전후 소스 내용도 동일합니다. 상위 통합 저장소의 경로 필터가 검사를 건너뛰지 않도록 Git 탐색 경계를 지정하고 실제 적용 파일까지 대조했습니다.

이번 검증은 로컬 소스·번들·제출 검사입니다. 운영 서버 설치나 실제 공용 PC Chrome 검증은 수행하지 않았습니다. 특성 확장·모델 학습·기존 대형 파일 해제·다른 탭이 쓰는 메모리까지 무제한으로 허용하는 변경은 아니며, 모든 데이터와 기기에서 OOM이 절대 발생하지 않는다고 보장하지 않습니다.

### 1.2.0 과제 정책 예방 검사 — 2026-10-15

업스트림 0.12.1과 고정 커밋, MLPX/DB 형식, 의존성 lock은 유지합니다. 호스트의 과제 정책에 맞춰 새 유형·모델 선택과 학습 시작을 검사하고, 현재 선택뿐 아니라 과거 학습 기록의 금지 모델도 직접 제출 전에 안내합니다. 기존 프로젝트의 자료·선택·기록은 보존하며, 충돌하는 과거 기록이 있으면 과제용 새 프로젝트를 만들도록 안내합니다.

직접 제출은 검사한 프로젝트 snapshot을 내보내는 동안 같은 프로젝트가 유지되는지 확인합니다. 비동기 내보내기 중 프로젝트가 바뀌면 전송하지 않으며, 일반 다운로드 흐름은 유지합니다. 정책 누락이나 불일치 때문에 학생의 작업 화면을 없애거나 자동 새로고침하지 않습니다.

이번 변경은 기존 OJ 패치 적용본과 전체 원본을 LF 정규화해 대조한 뒤 재생성합니다. 고정 archive의 기존 패치 forward check·실제 적용·reverse check도 먼저 확인했습니다. Python·scikit-learn fixture 재생성 검사는 이번 변경에서 완료하지 않았으며, 아래 RC6 당시 통과 기록을 이번 통과로 간주하지 않습니다.

최종 동결 소스에서 Node.js **24.15.0** / npm **11.12.1**의 전체 ESLint·Prettier·typecheck와 Vitest worker 4 검사가 통과했습니다. Vitest 결과는 **117개 파일, 2,601개 통과, 기존 의도적 skip 2개**입니다. 추가 검사에는 실제 유형·모델 선택 컴포넌트, 정책 누락, 기존 기록 보존, 직접 제출의 snapshot 변경 차단이 포함됩니다. 기존 패치 파일 25개의 CRLF를 LF로 정리할 때 각 파일의 정규화 전후 내용이 같은지 확인했습니다.

설치된 의존성 507개의 version·resolved·integrity 메타데이터가 고정 lock과 일치했습니다. 패키지를 새로 설치하거나 업데이트하지 않았으며 이 비교는 모든 설치 파일의 바이트 검증을 뜻하지 않습니다. Backbone 5개 파일은 기존 로컬 캐시를 고정 SHA-256과 대조하고 `scripts/fetch-backbone.mjs` 검사를 통과했으며 네트워크 다운로드는 필요하지 않았습니다.

최종 `npm run build`는 **174개 파일, 8,884,353 bytes**를 생성했습니다. `frontend/dist/`와 `resources/playground/`의 상대 경로 목록 및 SHA-256이 모두 일치하며, 이전 해시 자산 41개는 제거했습니다. entry는 `assets/index-TJDuyBFj.js`, SHA-256은 `e732952b326d8fd7f5e7c25b3d2af653b3dba8b403153696912b6aa8b4e296ae`입니다. `third-party-notices.txt`가 포함되고 텍스트 자산의 UTF-8·BOM 없음·한글 Unicode escape 없음도 확인했습니다. 기존 random-js의 Node crypto 외부화 경고와 빌드 플러그인 소요 시간 안내는 남으며 production build는 성공했습니다.

재현 기준은 고정 커밋 archive(SHA-256 `5dd11f4375e286a37f93c0808a336af2215b1bc30042190e1e7dc874534e94b1`)입니다. 새 **46파일** 패치의 SHA-256은 `e768561f322c609bd33060b517d3a463cc5b9fde3ff0ea48625f21c7451409e8`이며, 새로 푼 원본에서 `git apply --check --whitespace=error-all`, 실제 적용, reverse check를 모두 통과했습니다. 적용 후 전체 원본 466파일은 빌드 소스와 LF 정규화 내용이 같고, 빌드 전후 소스 SHA-256 변화도 없습니다. 기존 RC6 패치 대비 의미 변경은 이번 기능의 18파일뿐입니다. Windows 줄바꿈 차이가 있는 파일까지 원본 바이트가 동일하다고 주장하지 않습니다. 이 기록은 로컬 소스·번들 검증이며 설치 ZIP 제작이나 운영 서버 설치·실사용 검증은 별도입니다.

### rc.6 공용 PC 삭제 보완 — 2026-09-11

기준 태그·커밋·MLPX/DB 형식은 바꾸지 않았습니다. 동일 저장소의 exclusive lock을 먼저 예약하고 revoke·진행 작업 정리·shared lease 해제를 수행한 뒤 삭제합니다. 암호화 vault의 IndexedDB `blocked`는 요청 취소가 아니므로 success/error까지 삭제 Promise와 잠금을 유지합니다. legacy bootstrap 정리의 빠른 실패 동작은 별도 유지합니다.

부모 플러그인은 페이지 내 명시적 확인과 새 계정 검증을 유지하고, bootstrap만 메시지를 처리하도록 중복 dispatcher를 제거합니다. 부모의 결과 확인 시간 초과는 삭제 성공이나 native 요청 취소를 뜻하지 않습니다. 부모가 iframe을 제거한 뒤의 브라우저 내부 요청 정착까지 단위 검사로 보장한다고 주장하지 않습니다.

Node.js 24.15.0 환경에서 전체 ESLint·Prettier·typecheck와 Vitest worker 4 검사가 통과했습니다. Vitest는 **114개 파일, 2,564개 통과, 기존 의도적 skip 2개**입니다. 기존 wipe race 검사에 새 탭의 shared lock 추월 차단과 blocked 중 exclusive 유지 조건을 추가했습니다. 포맷 검사에서 Windows 줄바꿈을 수정했으며, 기존 32파일은 LF 변환만, 새 storage-scope 함수는 Prettier 서식만 추가 변경했습니다.

Backbone 메타데이터 검사와 scikit-learn **1.9.0**의 새 실행 fixture 일치 검사도 통과했습니다. `uv`의 일반 실행 권한 오류 후 승인된 로컬 재실행으로 확인했으며 서버에 Python·uv를 설치한 것이 아닙니다.

재빌드 결과는 **174개 파일, 8,846,111 bytes**이며 `dist/`와 `resources/playground/`의 모든 상대 경로별 SHA-256이 일치합니다. entry는 `assets/index-Df3yuN22.js`, SHA-256 `c6812c88c1d43e76aa03f2d6e7b36cd315fed9ae94b2944e26dd3178761b8fd0`입니다. 기존 random-js의 Node crypto 외부화 경고가 있으며 production build는 성공했습니다. 이전 RC5 자산은 개발 작업공간에 별도 보존했고 동결 RC5 ZIP은 변경하지 않았습니다.

공식 고정 커밋 archive에 기존 패치의 적용·역검사를 먼저 확인하고 새 패치도 `git apply --check --whitespace=error-all`, 적용·역검사를 통과했습니다. 새 패치 SHA-256은 `29e06a942d32e3e941063d6aa22d1e6c9ab6df205f20a58013620a9608c82ea1`입니다. 적용본 39개와 빌드 소스의 LF 정규화 내용이 일치하며 Windows checkout의 줄바꿈 차이를 원본 바이트 일치라고 표현하지 않습니다.

실제 설치 의존성 507개의 버전·resolved·integrity 메타데이터가 고정 lock과 일치했습니다. 다른 OS/CPU용 optional 항목은 미설치 상태이며 전체 설치 파일 바이트 검증을 뜻하지 않습니다. 위 내용은 RC6 빌드 당시의 증거입니다. 이후 RC6 서버 설치와 실제 공용 PC G1 기술 인수를 별도로 확인했으며, 이 빌드 검사 자체가 현장 검사를 대신한 것은 아닙니다. 1.0.0에 같은 번들을 유지하며 [기술 승인 기록](docs/release-1.0.md)과 최종 패키지 대조를 함께 확인합니다.

### rc.3 포트폴리오 원클릭 브리지 및 빌드 기록

업스트림 버전과 고정 커밋은 그대로입니다. 기존 OJ 패치 위에 `oj-portfolio-template.ts`의 추가 전용 어댑터와 v2 메시지를 더했습니다. 프로젝트를 자동 생성하거나 기존 답변·첨부·문항을 덮어쓰지 않습니다. 부모 화면에서 학생이 적용 버튼을 누를 때만 현재 프로젝트에 새 문항을 붙입니다.

앱 mount 후와 프로젝트 변경 시 다음 상태를 같은 출처 부모로 보냅니다. `projectToken`은 현재 열기 주기의 임시 난수이며 프로젝트 열기·닫기·전환 때 바뀝니다. 프로젝트명은 표시용이고 권한 판단에 쓰지 않습니다.

```text
oj-ml:portfolio-project
{version:2, frameNonce, assignmentId, storageSession,
 projectId:string|null, projectToken:string|null, projectName:string|null}
```

부모 요청과 응답은 다음 계약을 사용합니다. `template`에는 문항만 허용되며 학생 답변, 파일, 스크립트, URL 실행 요청은 받지 않습니다. 필수 문항 등 서버 평가 메타데이터는 부모가 제거한 뒤 전달합니다.

```text
oj-ml:portfolio-template-apply
{version:2, frameNonce, assignmentId, storageSession, requestId,
 projectId, projectToken, template:{sections:[{id,title,description?}],locale?}}

oj-ml:portfolio-template-result
{version:2, frameNonce, assignmentId, storageSession, requestId,
 projectId, projectToken, state:'applied'|'unchanged'|'error', added, code?}
```

- source·origin·frame nonce·과제·저장 세션을 모두 검증합니다. `requestId`는 영문·숫자·밑줄·하이픈 16~80자입니다.
- 활성 프로젝트 ID와 토큰이 일치해야 합니다. 프로젝트 전환·저장소 폐기 후 늦게 도착한 요청은 적용하지 않습니다.
- 문항은 최대 30개이고 메시지 양식은 64KiB 이하입니다. 전체 포트폴리오의 기존 답변과 첨부를 합친 앱 상한도 적용합니다.
- 중복 requestId의 동일 요청은 같은 완료 응답을 돌려줍니다. 내용이 달라지면 충돌로 거부하며, 별도 동시 요청은 busy로 거부합니다. 완료 캐시는 최대 32개이고 세션 폐기 시 비웁니다.
- 같은 문항 ID에 다른 제목이나 안내문이 이미 있으면 전체 적용을 거부합니다. 기존 답변을 새 질문에 잘못 붙이거나 몰래 수정하지 않습니다.
- 기존 추가 전용 merge를 사용해 답변·첨부를 보존합니다. 로컬 암호화 저장이 완료돼야 성공을 알리며, 저장 실패 시 메모리 작업을 유지하고 오류를 보냅니다. 같은 활성 프로젝트일 때 포트폴리오 단계로 이동합니다.
- 다운로드·수동 가져오기 경로도 유지합니다. 이전 내장 앱이 캐시돼 브리지를 제공하지 않으면 부모는 원클릭 적용을 활성화하지 않습니다.

rc.3 검증은 Node.js 24.15.0 / npm 11.12.1의 기존 lockfile 설치 환경에서 실행했습니다. 새 의존성은 추가하지 않았습니다. 전체 lint·Prettier·typecheck가 통과했고, Vitest worker 4 실행은 **114개 파일, 2,564개 통과, 2개 의도적 skip**입니다. 신규 검사는 출처·세션·요청 상관관계·중복 처리·프로젝트 전환·저장 실패·폐기 시 늦은 응답 차단·답변 보존을 포함합니다. `fixtures:check`는 scikit-learn 1.9.0 재실행 결과와 일치했고 `backbones` 메타데이터 검사도 통과했습니다.

production build는 **174개 파일, 8,845,518 bytes**입니다. `dist/`와 배포 `resources/playground/`의 모든 상대 경로별 SHA-256이 일치합니다. 이전 175개 산출물은 개발 작업공간 `_notes/tmp/oj-ml-rc3-playground-before-bridge`에 백업하고 새 빌드로 교체했습니다. Vite는 기존 random-js의 Node crypto 외부화 경고를 표시했으며 빌드는 성공했습니다.

새 패치는 깨끗한 고정 커밋에서 `git apply --check --whitespace=error-all`, 실제 적용, reverse check를 통과했습니다. 패치 SHA-256은 `680be69cecd5ce140e215a821c4c6bbed3e464b0593d21fb9cd70c30dc3d0eb2`, 새 entry asset `assets/index-BoM_Updl.js`의 SHA-256은 `06e95403500308382ee2eb09873c7e8afc5daf706b74045ce0cfb6b2f7552c38`입니다.

### 다음 업스트림 갱신

1. 새 업스트림 커밋과 `package-lock.json`을 검토하고 `npm ci`를 실행합니다.
2. OJ 패치를 새 소스 구조에 맞게 다시 적용합니다.
3. `npm run ci`로 backbones, lint, typecheck, Vitest, fixture 재현 검사와 production build를 모두 통과시킵니다.
4. 기존 `resources/playground/` 전체를 새 `dist/`로 미러링하여 이전 해시 asset을 남기지 않고 상대 경로별 SHA-256 일치를 확인합니다.
5. 신규 파일은 `git add -N`으로 diff에 포함한 뒤 `git diff --binary --full-index`로 공식 패치를 다시 생성합니다.
6. 깨끗한 고정 커밋에서 새 패치의 forward `git apply --check --whitespace=error-all`을 실행합니다.
7. MIT 원문, `third-party-notices.txt`, 커밋·버전·빌드 환경과 개인정보 고지를 다시 확인합니다.
