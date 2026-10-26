# OJ 공통 실시간 서버

Teaching Tools의 판서와 수업 신호를 처리하는 Node.js 서버입니다.

## 설치

Ubuntu 서버에서 [Teaching Tools 서버 설치 안내](../../_release-kit/guides/_server.md#18-2-티칭툴-설치)를 따릅니다.

- 설치 경로: `/opt/oj-realtime-hub`
- 서비스: `oj-realtime-hub.service`
- 내부 주소: `127.0.0.1:3210`
- 수업 경로: `/classroom-live`

## 개발

```bash
npm ci
npm test
```

`realtime-addon.json`으로 모듈을 등록하고 앱별 인증키와 채널로 연결을 관리합니다.
