# Kaggle 계약 검토 — 2026-09-10

결론: 기존 검색·파일 목록·단일 파일 다운로드 경로와 legacy username/key 인증 방식은 공식 SDK 정의와 부합한다. 공개 Iris 검색·파일 목록을 **인증 없이 실제 조회**해 기존 JSON 필드도 확인했다. 파일 목록의 `nextPageToken` 누락을 발견하여 전체 목록이 한도 안에서 완성되거나 명시적 오류를 반환하도록 수정했다. **실계정 인증·키 만료·파일 다운로드·운영 서버 수용 검증은 수행하지 않았으며 R5는 미완료다.**

## 확인한 공식 자료

`Kaggle/kaggle-api`의 현재 공식 저장소는 `Kaggle/kaggle-cli`로 연결된다. 변경 가능한 main 대신 아래 revision을 조회했다. 두 저장소의 GitHub 전체 트리는 `truncated=false`였다.

| 공식 저장소 | 고정 revision | 확인한 계약 |
|---|---|---|
| [Kaggle CLI](https://github.com/Kaggle/kaggle-cli/tree/68dcbdcf32709f90350e3d40b2801ecab3b8c35b) | `68dcbdcf32709f90350e3d40b2801ecab3b8c35b` | legacy key 인증, 파일 페이지, 단일 파일 다운로드의 ZIP 처리 |
| [Kaggle Python SDK](https://github.com/Kaggle/kaggle-sdk-python/tree/f983c97287bf274ebab506aac85eda6efebe3b32) | `f983c97287bf274ebab506aac85eda6efebe3b32` | 요청 endpoint, JSON 필드, HTTP 인증 |

[공식 웹 API 안내](https://www.kaggle.com/docs/api)는 동적 페이지여서 이번 텍스트 조회에는 본문이 나타나지 않았다. 계약 세부사항은 공식 저장소 코드와 실제 공개 GET 응답으로 대조했다. 커뮤니티 사본이나 출처 불명 OpenAPI 변환본은 근거로 사용하지 않았다.

## 계약 대조

| 항목 | 공식 정의·실측 | OJ Code Lab 판단 |
|---|---|---|
| 인증 | [CLI 인증](https://github.com/Kaggle/kaggle-cli/blob/68dcbdcf32709f90350e3d40b2801ecab3b8c35b/src/kaggle/api/kaggle_api_extended.py#L1463)은 access token, legacy API key, OAuth를 구분한다. [SDK HTTP 인증](https://github.com/Kaggle/kaggle-sdk-python/blob/f983c97287bf274ebab506aac85eda6efebe3b32/kagglesdk/kaggle_http_client.py#L264)은 username/password 쌍을 세션 인증으로 지정한다. | `Basic base64(username:key)`는 legacy key 방식에 대응한다. 새 Bearer access token을 같은 입력으로 지원한다고 주장할 수 없다. |
| 검색 | [SDK 목록 요청](https://github.com/Kaggle/kaggle-sdk-python/blob/f983c97287bf274ebab506aac85eda6efebe3b32/kagglesdk/datasets/types/dataset_api_service.py#L2376)의 `/api/v1/datasets/list`에 `search`, `page` 정의. 실제 legacy REST는 최상위 배열과 `ref`, `title`, `subtitle`, `totalBytesNullable`을 반환했다. | 현재 검색 파서와 일치한다. |
| 파일 목록 | [SDK 파일 요청](https://github.com/Kaggle/kaggle-sdk-python/blob/f983c97287bf274ebab506aac85eda6efebe3b32/kagglesdk/datasets/types/dataset_api_service.py#L1998)은 `/api/v1/datasets/list/{owner_slug}/{dataset_slug}`, `pageSize`, `pageToken`을 정의한다. [응답 필드](https://github.com/Kaggle/kaggle-sdk-python/blob/f983c97287bf274ebab506aac85eda6efebe3b32/kagglesdk/datasets/types/dataset_api_service.py#L2984)는 `datasetFiles`, `errorMessage`, `nextPageToken`이다. 실제 파일에는 `name`, `nameNullable`, `totalBytes`가 있었다. | 이전 파서는 파일 필드는 읽었지만 다음 페이지를 누락했다. 이번 수정은 토큰을 따라 읽는다. |
| 단일 파일 다운로드 | [SDK 다운로드 요청](https://github.com/Kaggle/kaggle-sdk-python/blob/f983c97287bf274ebab506aac85eda6efebe3b32/kagglesdk/datasets/types/dataset_api_service.py#L1612)은 `/api/v1/datasets/download/{owner_slug}/{dataset_slug}/{file_name}`과 `raw` 필드를 정의한다. [CLI](https://github.com/Kaggle/kaggle-cli/blob/68dcbdcf32709f90350e3d40b2801ecab3b8c35b/src/kaggle/api/kaggle_api_extended.py#L5589)는 큰 단일 파일도 서버 ZIP으로 받을 수 있어 선택적 압축 해제를 제공한다. | 경로는 일치한다. 기존 ZIP 응답의 415 거부를 유지한다. 모든 Kaggle CSV를 바로 가져올 수 있다는 보장은 없다. 실제 다운로드는 이번에 실행하지 않았다. |

새 SDK의 RPC 응답 타입에는 `datasets` envelope가 있지만, 같은 SDK가 호환용 legacy endpoint도 정의한다. 이번 실제 legacy GET은 배열을 반환했으므로 RPC 타입만 보고 기존 검색이 고장 났다고 판단하지 않았다. 향후 RPC로 전환한다면 envelope·pagination을 함께 변경해야 한다.

## 공개 GET 실측

한국 시간 2026-09-10, UTC 2026-09-09 15시대에 로컬 Python HTTPS로 4개 GET을 실행했다. Authorization·쿠키·계정 정보를 보내지 않았고 자동 리다이렉트는 껐다. 응답은 요청마다 최대 2,000,000바이트만 읽도록 제한했다. 아래 응답은 모두 그보다 작고 JSON 파싱에 성공했다. 응답 본문 전체나 쿠키·토큰 원문은 파일에 저장하지 않았다. 다운로드 endpoint에는 요청하지 않았다.

| 요청 | HTTP·바이트 | 관찰 결과 | 응답 SHA-256 |
|---|---|---|---|
| `/api/v1/datasets/list?search=iris&page=1` | 200 · 73,147 | 최상위 배열 20개, 첫 ref `uciml/iris`, title `Iris Species`, `totalBytesNullable=3687` | `8eb975f6e3ef2e964d422f01de756435a48afc673eafee768e1e6d80bc646209` |
| `/api/v1/datasets/list/uciml/iris` | 200 · 745 | `datasetFiles` 2개, 첫 파일 `Iris.csv`, `totalBytes=5107`, 후속 토큰 없음 | `e93e33fd568ba2dcb7429f87ea8cee2e5648279462626b5ca3057f7dbe0baab8` |
| 같은 파일 목록 + `pageSize=1` | 200 · 703 | `Iris.csv` 1개와 후속 토큰 | `80341631c2db9cf62682f7b4350007cbb70724960f3cf97146462eeb2c21b7db` |
| 같은 파일 목록 + `pageSize=1&pageToken=<첫 응답 토큰>` | 200 · 432 | `database.sqlite` 1개, 후속 토큰 없음 | `566000318a5c9ab01f5c5233658368c4e934ae0107bdf03a8e2f1f5020975a04` |

공개 데이터의 검색 순위·조회 수·페이지 토큰 등은 변하므로 응답 해시는 이 조회 기록을 식별하며 이후 같은 해시를 보장하지 않는다. 두 페이지 실측은 **공식 API의 페이지 계약 확인**이다. 플러그인에 유효한 실계정을 설정한 종단간 성공으로 계산하지 않는다.

## 적용한 수정과 한도

`src/Datasets/Kaggle_Client.php::files()`의 반환 형식은 기존 `[{name, size}, ...]`를 유지한다. `nextPageToken`이 있으면 다음 요청을 이어가고, 완료된 경우에만 전체 배열을 반환한다. caller의 권한 검사, 자격 증명 저장, 다른 origin으로 리다이렉트할 때 인증 헤더 제거 정책은 유지한다.

| 경계 | 값·실패 동작 |
|---|---|
| 페이지당 요청 크기 | `pageSize=100` |
| 페이지 수 | 최대 10. 10번째에도 후속 토큰이 있으면 오류 |
| 누적 파일 수 | 최대 1,000개. 초과 시 오류 |
| 전체 HTTP 시간 예산 | 20초. 모든 페이지와 각 리다이렉트가 같은 deadline을 공유. 남은 시간을 소수초 그대로 WordPress `timeout`에 전달 |
| 토큰 | 최대 4,096바이트 문자열. 공백·제어문자·잘못된 UTF-8 또는 다른 자료형 거부. 토큰은 opaque 값으로 쿼리 인코딩 |
| 토큰 반복 | 즉시 반복 또는 이전 토큰으로 돌아오는 순환 모두 오류 |
| 응답 | 기존 2,000,001바이트 전송 sentinel과 2,000,000바이트 본문 제한 유지. HTTP·전송·JSON·파일 목록 형식 오류는 부분 성공으로 바꾸지 않음 |
| 실패 상태 | 시간 초과 504, 페이지·파일 한도 413, 잘못된 토큰·반복·목록 형식·upstream 오류는 명시적 WP_Error |

WordPress가 공식 지원하는 HTTP `timeout` 인자를 사용하며 별도의 비표준 연결 시간 인자는 추가하지 않았다. 실제 전송 시간 제한의 집행은 WordPress HTTP transport에 맡기고, 응답 후에도 deadline을 다시 검사해 시간 초과 결과를 성공으로 반환하지 않는다.

관리자 UI는 받은 파일 목록에서 항목을 선택하는 방식이다. 현재 확인한 UI에는 별도 파일명 직접 입력 경로가 없어 오류 메시지에 존재하지 않는 우회 방법을 안내하지 않았다. 압축 파일의 415 미지원 안내와 실계정 설정 필요 조건은 그대로다.

## 검증과 남은 수용 항목

독립 `tests/kaggle-pagination.php`의 **21개 시나리오가 PHP 8.1.34와 8.5.5에서 각각 통과**했다. 두 페이지 결합, 토큰 인코딩, 기존 목록 형태, 후속 페이지 전송·HTTP·JSON 실패, 잘못된 파일 목록, upstream 오류, 토큰 형식·반복·순환, 10페이지·1,000파일 경계, 전체 20초 및 0.25초 남은 예산, 리다이렉트 시간 누적, 인증 헤더 제거, 응답 크기 한도를 확인했다. 시간 테스트는 가상 시계를 사용한 HTTP fixture이며 실제로 20초를 기다리거나 네트워크 장애를 일으키지 않았다.

- 최종 클라이언트 SHA-256: `752bd3886cc87330d08eb60692789627a30d6e27a0ae64e3be579b9ca0f51cb9`
- 독립 테스트 SHA-256: `2579e2aafe1257fe59f36cd69ea30b2bba44d056bc85928987e39f847459aed0`

실계정의 유효 legacy key, 잘못된 키·폐기/만료 키, 운영 서버에서의 검색→파일 선택→제한 내 CSV 다운로드, 접근 제한 자료, 실다운로드 리다이렉트와 ZIP·용량 제한 안내는 남은 수용 항목이다. 이 문서를 R5 통과 증빙으로 바꾸지 않는다. 코드 변경 후 배포 ZIP을 다시 만들고 해당 소스에 대한 전체 회귀 결과를 릴리스 기록에 연결해야 한다.
