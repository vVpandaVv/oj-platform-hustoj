<?php

declare(strict_types=1);

$pluginRoot = dirname(__DIR__, 2);
$fixturePath = $pluginRoot . '/tests/Visual/r05-problems.html';
$fixture = is_file($fixturePath) ? (string) file_get_contents($fixturePath) : '';
$publicListSource = (string) file_get_contents(
    $pluginRoot . '/modules/Problems/Presentation/Route/ProblemListPageHandler.php'
);
$adminListSource = (string) file_get_contents(
    $pluginRoot . '/modules/Problems/Presentation/Console/ProblemAdminListPageHandler.php'
);
$assertions = 0;

function problemsUiAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;

    if (! $condition) {
        throw new RuntimeException($message);
    }
}

problemsUiAssert($fixture !== '', '문제 화면 검증 파일이 있어야 합니다.');
problemsUiAssert(preg_match('//u', $fixture) === 1, '문제 화면 검증 파일은 UTF-8이어야 합니다.');
problemsUiAssert(
    preg_match('/\\\\u[0-9a-f]{4}/i', $fixture) !== 1,
    '문제 화면 문구에 유니코드 이스케이프를 사용할 수 없습니다.'
);
problemsUiAssert(
    str_contains($fixture, 'href="../../resources/ui/css/oj-ui-system.css"'),
    '문제 화면은 단일 공통 CSS를 불러와야 합니다.'
);
problemsUiAssert(
    ! str_contains($fixture, '<style')
        && preg_match('/\bstyle\s*=\s*["\']/', $fixture) !== 1,
    '문제 화면 검증 파일에 별도 CSS를 둘 수 없습니다.'
);

$document = new DOMDocument();
$previous = libxml_use_internal_errors(true);
$loaded = $document->loadHTML(
    $fixture,
    LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD
);
libxml_clear_errors();
libxml_use_internal_errors($previous);
problemsUiAssert($loaded, '문제 화면 검증 HTML을 해석할 수 있어야 합니다.');

$xpath = new DOMXPath($document);
foreach (['list', 'detail', 'admin'] as $screen) {
    problemsUiAssert(
        $xpath->query('//template[@id="screen-' . $screen . '"]')->length === 1,
        sprintf('%s 문제 화면이 정확히 하나 있어야 합니다.', $screen)
    );
}
problemsUiAssert(
    $xpath->query(
        '//template[@id="screen-list"]//*[contains(@class, "ojp-status-badge--info")'
            . ' and normalize-space(.)="특수 채점"]'
    )->length === 1,
    '사용자 문제 목록은 특수 채점 문제 제목 옆에 정보 배지를 표시해야 합니다.'
);
problemsUiAssert(
    $xpath->query(
        '//template[@id="screen-admin"]//*[contains(@class, "ojp-status-badge--info")'
            . ' and normalize-space(.)="특수 채점"]'
    )->length === 1,
    '관리자 문제 목록은 특수 채점 문제 제목 옆에 같은 정보 배지를 표시해야 합니다.'
);
problemsUiAssert(
    $xpath->query('//template[@id="screen-admin"]//select[@name="judging"]/option')->length === 3,
    '관리자 문제 목록은 전체·일반·특수 채점 필터를 제공해야 합니다.'
);
problemsUiAssert(
    str_contains($publicListSource, 'specialJudgeBadge($problem->specialJudge())')
        && str_contains($adminListSource, 'specialJudgeBadge($problem->specialJudge())'),
    '실제 사용자·관리자 문제 목록은 공통 특수 채점 배지 렌더러를 사용해야 합니다.'
);
problemsUiAssert(
    str_contains($adminListSource, "queryString('judging')")
        && str_contains($adminListSource, "'standard' => false")
        && str_contains($adminListSource, "'special' => true"),
    '실제 관리자 문제 목록은 채점 방식 필터를 검색 조건으로 해석해야 합니다.'
);

problemsUiAssert(
    $xpath->query('//template[@id="screen-detail"]//h1')->length === 1,
    '문제 상세 제목은 제출 편집기와 중복하지 않고 한 번만 표시해야 합니다.'
);
problemsUiAssert(
    $xpath->query('//template[@id="screen-detail"]//a[@href="/submit/1000"]')->length === 1,
    '문제 상세에서 제출 화면으로 바로 이동할 수 있어야 합니다.'
);
problemsUiAssert(
    $xpath->query('//template[@id="screen-detail"]//a[@href="/oj-console/problems/1000/edit"]')->length === 1,
    '문제 관리자에게 문제 수정 동선을 보여야 합니다.'
);
problemsUiAssert(
    $xpath->query('//template[@id="screen-detail"]//a[@href="/oj-console/problems/1000/test-data"]')->length === 1,
    '문제 관리자에게 테스트 데이터 동선을 보여야 합니다.'
);
problemsUiAssert(
    $xpath->query('//template[@id="screen-detail"]//*[@class="ojp-problem-runtime"]')->length === 1,
    '시간과 메모리 제한은 문제 제목 바로 아래의 공통 영역이어야 합니다.'
);
problemsUiAssert(
    $xpath->query('//template[@id="screen-detail"]//*[@class="ojp-problem-detail-grid"]')->length === 1
        && $xpath->query('//template[@id="screen-detail"]//*[@class="ojp-problem-aside"]')->length === 1,
    '문제 상세는 최초 시안의 본문·요약 2열 구조를 유지해야 합니다.'
);
problemsUiAssert(
    $xpath->query('//template[@id="screen-detail"]//*[@class="ojp-problem-questions"]')->length === 1,
    '문제 상세에 문제별 질문과 답변 영역을 제공해야 합니다.'
);
problemsUiAssert(
    $xpath->query('//template[@id="screen-admin"]//*[contains(concat(" ", normalize-space(@class), " "), " ojp-bulk-bar ")]')->length === 1,
    '관리자 목록은 선택 작업 막대를 제공해야 합니다.'
);
problemsUiAssert(
    str_contains($fixture, '전체 메타데이터 생성')
        && str_contains($fixture, '메타데이터 생성'),
    '관리자 목록은 전체 및 선택 문제 메타데이터 생성을 제공해야 합니다.'
);

fwrite(
    STDOUT,
    sprintf("R-05 문제 화면 E2E 계약 검사 통과: assertion %d개\n", $assertions)
);
