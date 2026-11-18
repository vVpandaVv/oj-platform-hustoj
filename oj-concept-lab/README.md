# 정보 개념 체험

OJ Platform용 독립 애드온. Edu Games·코드아레나 없이 설치하고 운영합니다.

학생: `/oj-addons/oj-concept-lab/activities` · 교사: `/oj-console/addons/oj-concept-lab/manage`

## 활동

- 이상치 탐색과 대표값 비교
- 결측값 처리 방법 비교
- 데이터 시각화와 그래프 표현
- 조건에 따른 데이터 필터링과 추출
- 스택·큐·우선순위 큐
- 특성과 규칙에 따른 분류 경계
- 정렬된 자료의 이진 탐색
- 공개 범위와 개인정보 최소 공개
- 네트워크 경로와 전송 비용
- 연속 길이 부호화와 무손실 압축
- 멀티코어의 병렬 처리와 작업 배치

6개 기존 활동의 합성 자료 156개를 자체 JSON으로 포함합니다. 점수 API와 학생 평가 테이블은 사용하지 않습니다. 작업 상태는 사용자·사이트별 브라우저 저장소에 보관합니다.

공개·비공개는 교사 콘솔에서 관리합니다. 숏코드 `[oj_concept_lab]`, `[oj_concept_lab activity="data-extraction"]` 또는 블록 `oj-concept-lab/activity`를 사용할 수 있습니다.

설치·삭제 정책 원본: `_release-kit/guides/oj-concept-lab.md`. 설치 ZIP은 `_release-kit/release.cmd`만 사용합니다. 개발용 `tools/import-legacy-data.php`는 배포 대상이 아니며 실행 시 게임 플러그인을 요구하지만 설치·운영에는 영향을 주지 않습니다.
