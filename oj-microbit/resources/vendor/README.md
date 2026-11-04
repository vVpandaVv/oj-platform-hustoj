# USB 업로드 브라우저 자산

`microbit-usb.js`는 Micro:bit Educational Foundation의 `@microbit/microbit-connection` 1.0.1에서 USB 연결과 Universal HEX 전송 기능만 묶은 파일입니다. 인터넷에서 코드를 동적으로 가져오지 않습니다. 원문 라이선스와 실제 번들에 포함된 의존성은 `THIRD_PARTY_NOTICES.txt`에 있습니다.

재생성은 `resources/tools`에서 `npm ci --ignore-scripts` 후 `npm run build`로 실행합니다. 빌드 도구와 의존성 버전은 `package-lock.json`으로 고정합니다. `tools/node_modules`는 개발용이며 배포 파일이 아닙니다.

학생이 HTTPS 페이지의 **기기 연결**을 직접 눌러 브라우저에서 장치를 선택합니다. **USB 업로드**는 MakeCode 임베드가 생성한 실제 HEX를 연결 라이브러리로 전송합니다. 실제 보드가 없는 자동 테스트는 전송 성공을 인증하지 않습니다.

MakeCode iframe 통신 어댑터는 공개 PXT 프로토콜을 사용합니다. 참고: <https://github.com/microsoft/pxt/blob/master/webapp/public/controller.html>, <https://github.com/microsoft/pxt/blob/master/webapp/src/iframeworkspace.ts>. 편집기는 Microsoft가 운영하는 외부 서비스이며 이 번들에 포함되지 않습니다.
