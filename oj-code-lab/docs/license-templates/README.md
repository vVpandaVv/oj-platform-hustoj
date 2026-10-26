# 코드 라이선스 검토 템플릿

이 폴더는 개발 검토용이며 공개 ZIP에 포함하지 않는다. 템플릿 보관은 OJ Code Lab 코드에 라이선스를 적용하거나 소유자 승인을 대신하지 않는다. 현재 소유자의 라이선스 결정 보류 상태는 유지한다.

`gpl-2.0.txt`는 2026-09-09 GNU 공식 배포 서버의 [GPL v2 표준 본문](https://ftp.gnu.org/gnu/Licenses/gpl-2.0.txt) 17,984바이트를 변경 없이 저장한 파일이다. SHA-256: `edaef632cbb643e4e7a221717a6c441a4c1a7c918e6e4d56debc3d8739b233f6`. 빌더의 검토 후보 `GPL-2.0-or-later`는 이 본문을 사용한다. “or later” 선택은 소유자 승인 기록과 실제 플러그인·readme 선언에 명시해야 한다.

소유자가 선택한 뒤에만 표준 본문을 루트 `LICENSE.txt`로 복사하고, main/readme의 `License: GPL-2.0-or-later` 및 `License URI: https://www.gnu.org/licenses/old-licenses/gpl-2.0.html`을 일치시킨다. `docs/release-gates.json`에는 `license_decision: approved_by_owner`와 `code_license`의 `spdx`, `text_sha256`, `source_url`, 구체적인 `approval_reference`를 기록한다. 라이선스 외에 R1–R7 모두 해당 정식 버전의 `verified_version`과 검증 근거가 필요하다.

다른 라이선스를 선택하면 그 선택에 맞는 공식 본문·출처·해시·선언 일치 검사를 먼저 추가한다. 이 폴더나 테스트 fixture를 승인 기록으로 사용하지 않는다.
