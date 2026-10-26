# OJ AI Dashboard 개발 도구

문서 상태: 확정  
작성일: 2026-06-13  
대상 단계: 4단계

## 1. 로컬 도구 기준

현재 개발 도구는 의존성 설치 전에도 기본 검사가 가능하도록 구성한다.

- PHP: `D:\php\php.exe`를 우선 사용
- Node.js: PATH의 `node` 사용
- npm: PowerShell 실행 정책을 피하기 위해 `npm.cmd` 사용
- Composer: 설치되어 있으면 사용하고, 없으면 Composer 기반 검사는 건너뜀
- PHPCS, PHPStan, PHPUnit: 설치되어 있으면 실행하고, 없으면 경고로 처리

## 2. 기본 명령

PHP 문법 검사:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\lint-php.ps1
```

하네스 검증:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\validate-plugin-harness.ps1
```

전체 품질 점검:

```powershell
powershell -NoProfile -ExecutionPolicy Bypass -File .\scripts\check-quality.ps1
```

JS/CSS lint:

```powershell
node .\scripts\lint-assets.mjs
```

JS/CSS build:

```powershell
node .\scripts\build-assets.mjs
```

npm script:

```powershell
npm.cmd run check
```

## 3. Composer script

Composer가 설치된 환경에서는 다음 명령을 사용할 수 있다.

```powershell
composer run lint:php
composer run check:harness
composer run check
```

## 4. 선택 도구

아래 도구는 설치되어 있으면 `scripts/check-quality.ps1`에서 실행한다.

- PHPCS
- PHPStan
- PHPUnit

설치되지 않은 경우 4단계에서는 실패가 아니라 경고로 처리한다.

## 5. 산출물

`node scripts/build-assets.mjs`는 `build/asset-manifest.json`을 만든다. `build/`는 생성 산출물이므로 git에 포함하지 않는다.
