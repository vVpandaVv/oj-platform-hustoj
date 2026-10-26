# 외부 구성요소와 데이터 고지

## UI 아이콘

play·bug·plus SVG는 Lucide/Feather 계열 아이콘을 사용한다. 원본 리비전은 기존 코드에 고정되어 있지 않다. 원저작권 및 ISC/MIT 전문은 `docs/Lucide-Feather-LICENSE.txt`에 보존한다. 자체 GPL 선언은 이 고지를 대체하지 않는다.

## 번들 데이터

새 배포 ZIP은 [공개 데이터 검증 매니페스트](public-datasets.json)에 재배포 승인 상태와 내용 해시가 기록된 CSV만 포함한다. [출처·라이선스·변환 내역](PUBLIC_DATASETS.md)을 함께 제공한다. 기존 개발 자료의 권리 미확인 CSV는 배포 ZIP에서 제외하며, 설치 사이트의 기존 자료는 삭제하지 않는다.

## Markdown 미리보기

텍스트 셀은 markdown-it 15.0.3(MIT)의 공식 브라우저 UMD 번들을 로컬에서 읽는다. [공식 npm 패키지](https://registry.npmjs.org/markdown-it/-/markdown-it-15.0.3.tgz)의 `dist/browser/markdown-it.umd.min.js` 원본을 `resources/vendor/markdown-it/markdown-it.js`라는 이름으로 보존했다. 파일 내용은 변경하지 않았다. 2026-10-20에 npm의 SHA-512 무결성 값과 실제 패키지를 대조했으며 번들 SHA-256은 `391f2aa638e75e6c7859d0a5c5650ef0956722a45998f17e05b83bc655778e1e`이다. MIT 전문은 `resources/vendor/markdown-it/LICENSE.txt`에 있다.

번들에는 entities 8.1.0(BSD-2-Clause), linkify-it 6.1.0(MIT), mdurl 2.1.0(MIT), punycode.js 2.3.1(MIT), uc.micro 3.0.0(MIT)가 포함된다. [공식 15.0.3 태그의 package-lock.json](https://github.com/markdown-it/markdown-it/blob/15.0.3/package-lock.json)과 번들의 소스맵을 사용하여 각 구성요소를 확인하고, 소스맵의 해당 원문을 각 버전의 공식 npm 패키지와 대조했다. 이들의 저작권·조건·면책 전문은 `resources/vendor/markdown-it/DEPENDENCY-LICENSES.txt`에 함께 보존한다. 명령행 전용 argparse는 브라우저 번들에 포함되지 않는다.

Markdown 원시 HTML과 자동 링크 인식은 끈다. 직접 작성한 링크는 HTTP·HTTPS·메일·문서 내 앵커만, 이미지는 HTTP·HTTPS만 허용한다. 외부 이미지 요청에는 referrer를 보내지 않는다. 이 기능은 플랫폼 내부 편집기를 수정하거나 외부 CDN에서 Markdown 런타임을 내려받지 않는다. vendor 파일에는 Code Lab의 GPL 선언이나 플랫폼 연결 추가 허락을 적용하지 않으며, 패키저는 원본과 고지 파일의 고정 해시를 따로 검사한다.

## 외부 런타임

Python 실행에는 jsDelivr의 버전 고정 경로 https://cdn.jsdelivr.net/pyodide/v0.26.4/full/ 를 사용한다. Pyodide 본체·패키지 바이너리는 이 ZIP에 들어 있지 않다. numpy·pandas·matplotlib 등의 버전은 Pyodide 0.26.4 패키지 목록에 따른다. seaborn 로딩 실패 시 PyPI에서 seaborn==0.13.2를 설치한다. 버전 업그레이드는 Worker·실행 중단·input·그래프·데이터 가져오기 수용 검증 후 별도 릴리즈로 수행한다.

각 외부 런타임과 패키지의 라이선스는 해당 공급자가 제공하는 조건을 따른다. 이 플러그인의 라이선스가 외부 데이터나 패키지의 조건을 덮어쓰지 않는다. 네트워크 요청과 전달 정보는 [개인정보·외부 연결 안내](PRIVACY.md)를 참조한다.

## 서버 데이터 처리

1.1.0의 ZIP 읽기는 서버 PHP zip 확장이 제공하는 `ZipArchive`를 사용한다. 별도 압축 라이브러리나 JSONL 파서를 Code Lab ZIP에 번들하지 않는다. JSONL 검증은 PHP 내장 JSON 기능을, CSV·JSONL 표 읽기는 기존 Pyodide의 pandas를 사용한다. 서버 PHP·zip 확장 및 관련 라이브러리는 설치 환경의 구성요소이며 각자의 라이선스를 유지한다.

## 자체 코드

OJ Code Lab 자체 코드는 GPL-2.0-or-later로 제공한다. 최상위 LICENSE.txt의 GPLv2 원문과 플러그인 헤더·readme.txt의 'version 2 or later' 선언을 함께 적용한다. 이 허락은 타인의 데이터·라이브러리·플랫폼 권리를 변경하지 않는다.

최상위 PLATFORM-LINKING-EXCEPTION.txt는 해당 고지를 가진 자체 코드에만 적용하는 프로젝트 전용 추가 허락이다. OJ Platform 공개 Addon API v1과 문서화된 등록·런타임 연결·버전 상수를 통한 결합에 한정하며 Code Lab의 GPL 소스 제공·고지 의무를 유지한다. 플랫폼 구현 코드 복사나 비공개 API 접근에는 적용하지 않는다. 다른 구성요소의 이용허락을 대신하지 않으며 결합 제품 전체의 재배포 권한을 보증하지 않는다. 수정자는 자신의 기여에 이 추가 허락을 적용할 의무가 없다.

## OJ Platform과 Ace

OJ Platform은 별도 설치하는 의존성이다. 플랫폼 PHP 파일과 Ace 자산은 Code Lab ZIP에 포함하지 않는다. 1.1.0에서 플랫폼 코드는 수정하지 않으며 기존 공개 Editor API와 Ace 자산을 사용한다. Code Lab의 라이선스가 플랫폼의 별도 라이선스나 재배포 허락을 변경하지 않는다.

Ace 편집기는 플랫폼이 제공하는 같은 출처의 자산을 사용한다. 검증한 플랫폼의 Ace 1.44.0은 BSD-3-Clause이며 원 저작권자 표시는 Ajax.org B.V.이다. 자산을 재배포하는 배포자는 원문 저작권·조건·면책 문구와 플랫폼의 THIRD_PARTY_NOTICES.txt를 보존해야 한다. [Ace 1.44.0 라이선스](https://github.com/ajaxorg/ace-builds/blob/v1.44.0/LICENSE), [Pyodide 프로젝트·라이선스 설명](https://pyodide.org/en/stable/project/about.html)을 참조한다. Pyodide 자체와 그 패키지들도 각자의 라이선스를 유지한다.
