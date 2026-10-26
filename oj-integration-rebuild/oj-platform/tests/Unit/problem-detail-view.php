<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSection;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemDetailSectionItem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicRecord;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Domain\DifficultyScore;
use CodingPanda\OjPlatform\Modules\Problems\Domain\JudgeProblem;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemMetadata;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemTopic;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemVisibility;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemDetailPageState;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemDetailPageView;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemHtml;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;

$pluginRoot = dirname(__DIR__, 2);
require_once $pluginRoot . '/src/Kernel/Autoloader.php';
\CodingPanda\OjPlatform\Kernel\Autoloader::register($pluginRoot);

$assertions = 0;

function problemDetailAssert(bool $condition, string $message): void
{
    global $assertions;
    ++$assertions;
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

$topics = new class () implements ProblemTopicDictionary {
    /** @var list<ProblemTopicRecord> */
    private array $items;

    public function __construct()
    {
        $this->items = [
            new ProblemTopic('algorithm', '알고리즘', null, []),
            new ProblemTopic('prefix-sum', '누적 합', 'algorithm', []),
        ];
    }

    public function all(): array
    {
        return $this->items;
    }

    public function find(string $key): ?ProblemTopicRecord
    {
        foreach ($this->items as $item) {
            if ($item->key() === $key) {
                return $item;
            }
        }

        return null;
    }

    public function assertSelectableDetails(array $detailKeys): void
    {
    }

    public function version(): string
    {
        return 'test';
    }

    public function createCategory(
        string $key,
        string $label,
        array $aliases,
        int $actorUserId
    ): void {
    }

    public function createDetail(
        string $categoryKey,
        string $key,
        string $label,
        array $aliases,
        int $actorUserId
    ): void {
    }
};

$actionUrls = new class () implements ActionUrlProvider {
    public function actionUrl(): string
    {
        return '/wp-admin/admin-post.php';
    }

    public function ajaxUrl(): string
    {
        return '/wp-admin/admin-ajax.php';
    }
};

$security = new class () implements ActionSecurity {
    public function hiddenFields(string $actionKey): string
    {
        return '<input type="hidden" name="action" value="'
            . htmlspecialchars($actionKey, ENT_QUOTES, 'UTF-8') . '">';
    }

    public function verify(string $actionKey, HttpRequest $request): bool
    {
        return true;
    }
};

$assets = new class () implements UiAssetLocator {
    public function stylesheetUrl(): string
    {
        return '/oj-ui-system.css';
    }

    public function scriptUrl(): string
    {
        return '/oj-ui.js';
    }

    public function mathJaxLoaderUrl(): string
    {
        return '/mathjax-loader.js';
    }

    public function iconSpriteUrl(): string
    {
        return '/lucide.svg';
    }

    public function faviconUrl(): string
    {
        return '/favicon.svg';
    }
};

$errors = new class () implements ErrorReporter {
    /** @var list<\Throwable> */
    public array $reported = [];

    public function report(\Throwable $error): void
    {
        $this->reported[] = $error;
    }
};

$problemId = new ProblemId(1024);
$problem = new JudgeProblem(
    $problemId,
    '구간 합 구하기',
    '길이가 \\(N\\)인 수열의 구간 합을 구합니다.',
    '첫째 줄에 \\(N\\)과 \\(M\\)이 주어집니다.',
    '각 질문의 답 \\[S_i\\]를 출력합니다.',
    "5 1\n1 2 3 4 5\n1 3\n\\(N\\)",
    '6',
    '누적 합 \\(P_i = P_{i-1} + A_i\\)를 사용합니다.',
    'OJ Platform',
    1,
    256,
    true,
    ProblemVisibility::Published,
    48,
    100,
    'eval/exec/\/ /<img src=x>',
    "from sys import stdin\nhidden_prefix_fixture = 42 #hidden",
    '',
    'hidden_suffix_fixture() #hidden',
    null
);
$metadata = new ProblemMetadata(
    $problemId,
    new DifficultyScore(3),
    ['prefix-sum'],
    CodeEntryMode::FullCode,
    null,
    null,
    'manual',
    '',
    1,
    'test',
    'manual'
);
$state = new ProblemDetailPageState($problem, $metadata, true, true, true);
$view = new ProblemDetailPageView(
    new ProblemHtml($topics),
    $actionUrls,
    $security,
    $assets,
    $errors
);
$html = $view->render($state, [
    new ProblemDetailSection(
        'questions',
        '질문과 답변',
        [new ProblemDetailSectionItem('질문 제목', '/community/1', '댓글 2', '답변 있음')],
        '질문하기',
        '/community/new?problem_id=1024',
        '전체 질문',
        '/community?problem_id=1024'
    ),
]);

problemDetailAssert(preg_match('//u', $html) === 1, '문제 상세 HTML은 UTF-8이어야 합니다.');
problemDetailAssert(
    str_contains($html, 'from sys import stdin')
        && ! str_contains($html, 'hidden_prefix_fixture')
        && ! str_contains($html, 'hidden_suffix_fixture')
        && ! str_contains($html, '#hidden'),
    '사용자 문제 상세 HTML에서 숨긴 앞·뒤 코드 줄과 표식을 제외해야 합니다.'
);
problemDetailAssert(
    str_contains($html, '뒤에 붙는 코드: 비공개 코드'),
    '전부 숨긴 뒤 코드는 원문 대신 비공개 상태만 표시해야 합니다.'
);
foreach (['eval', 'exec', '/', '&lt;img src=x&gt;'] as $word) {
    problemDetailAssert(
        str_contains($html, '<code class="ojp-code-chip">' . $word . '</code>'),
        '문제 상세에는 해석된 금지어를 개별 표시하고 HTML을 이스케이프해야 합니다.'
    );
}
problemDetailAssert(
    ! str_contains($html, 'eval/exec') && ! str_contains($html, '<img src=x>'),
    '구분자 원문이나 실행 가능한 HTML이 금지어에 표시되면 안 됩니다.'
);
problemDetailAssert(
    ! str_contains($html, '\\u'),
    '문제 상세 문구에 유니코드 이스케이프를 사용할 수 없습니다.'
);
problemDetailAssert(
    substr_count($html, '<h1>구간 합 구하기</h1>') === 1,
    '문제 제목은 한 번만 표시해야 합니다.'
);
problemDetailAssert(
    str_contains($html, 'ojp-status-badge--info')
        && str_contains($html, '>특수 채점</span>'),
    '특수 채점 문제 상세는 목록과 같은 정보 배지를 표시해야 합니다.'
);
problemDetailAssert(
    str_contains($html, 'class="ojp-problem-runtime"')
        && strpos($html, 'class="ojp-problem-runtime"') > strpos($html, '<h1>'),
    '시간과 메모리 제한은 제목 바로 아래에 있어야 합니다.'
);
problemDetailAssert(
    str_contains($html, 'class="ojp-problem-detail-grid"')
        && str_contains($html, 'class="ojp-problem-aside"'),
    '본문과 문제 요약은 공통 2열 구조를 사용해야 합니다.'
);
problemDetailAssert(
    str_contains($html, 'href="/submit/1024"'),
    '제출 버튼은 문제 상세 첫 화면에 있어야 합니다.'
);
problemDetailAssert(
    str_contains($html, 'href="/oj-console/problems/1024/edit"')
        && str_contains($html, 'href="/oj-console/problems/1024/test-data"'),
    '관리자에게 문제와 테스트 데이터 수정 동선을 제공해야 합니다.'
);
problemDetailAssert(
    str_contains($html, 'value="oj_problem_book_personal_remove"')
        && str_contains($html, 'aria-pressed="true"'),
    '문제집 저장 상태는 같은 아이콘 작업으로 제거할 수 있어야 합니다.'
);
problemDetailAssert(
    str_contains($html, 'data-ojp-copy-target="sample-input-1024"'),
    '예시 입력을 복사할 수 있어야 합니다.'
);
problemDetailAssert(
    substr_count($html, 'data-ojp-latex') === 4,
    '설명·입력·출력·힌트만 LaTeX 렌더링 대상으로 표시해야 합니다.'
);
$sampleStart = strpos($html, '<section class="ojp-content-section" aria-labelledby="problem-samples-heading">');
$sampleEnd = is_int($sampleStart) ? strpos($html, '</section>', $sampleStart) : false;
$sampleHtml = is_int($sampleStart) && is_int($sampleEnd)
    ? substr($html, $sampleStart, $sampleEnd - $sampleStart)
    : '';
problemDetailAssert(
    str_contains($sampleHtml, 'ojp-math-ignore')
        && ! str_contains($sampleHtml, 'data-ojp-latex')
        && str_contains($sampleHtml, '\\(N\\)'),
    '예시 입출력은 LaTeX처럼 보이는 문자열도 해석하지 않고 원문으로 표시해야 합니다.'
);
problemDetailAssert(
    str_contains($html, '문제 정보')
        && str_contains($html, '정답률')
        && str_contains($html, '제출 현황'),
    '최초 DS-10 시안의 문제 요약 정보를 유지해야 합니다.'
);
problemDetailAssert(
    str_contains($html, '질문과 답변') && str_contains($html, '질문 제목'),
    '문제별 질문 영역을 문제 상세에 연결해야 합니다.'
);
problemDetailAssert($errors->reported === [], '정상 렌더링 중 오류가 기록되면 안 됩니다.');

$contestNavigation = ProblemNavigationContext::contest(77, 2);
problemDetailAssert(
    $contestNavigation->detailUrl(1024)
        === '/problems/1024?contest_id=77&contest_problem_number=2',
    '대회 문제 링크는 대회 번호와 대회 문제 순서를 함께 전달해야 합니다.'
);
$contestHtml = $view->render($state, [], ProblemNavigationContext::fromQueryValues(
    '77',
    '2'
));
problemDetailAssert(
    str_contains(
        $contestHtml,
        'href="/submit/1024?contest_id=77&amp;contest_problem_number=2"'
    ),
    '대회에서 연 문제의 제출 버튼은 대회 제출 문맥을 유지해야 합니다.'
);
problemDetailAssert(
    str_contains($contestHtml, 'href="/contests/77">대회·평가</a>')
        && str_contains($contestHtml, '대회로 돌아가기'),
    '대회 문제 상세의 돌아가기 동선은 해당 대회를 가리켜야 합니다.'
);
problemDetailAssert(
    ProblemNavigationContext::fromQueryValues('invalid', '2')->submitUrl(1024)
        === '/submit/1024',
    '유효하지 않은 대회 번호는 일반 제출 문맥으로 전달하면 안 됩니다.'
);
$classNavigation = ProblemNavigationContext::class(31);
problemDetailAssert(
    $classNavigation->detailUrl(1024) === '/problems/1024?class_id=31'
        && $classNavigation->submitUrl(1024) === '/submit/1024?class_id=31',
    '수업 문제 링크와 제출 링크는 같은 수업 문맥을 유지해야 합니다.'
);
problemDetailAssert(
    $classNavigation->returnUrl() === '/classes/31'
        && $classNavigation->returnLabel() === '수업으로 돌아가기',
    '수업 문제 상세의 돌아가기 동선은 해당 수업을 가리켜야 합니다.'
);
problemDetailAssert(
    ProblemNavigationContext::fromQueryValues('77', '2', '31')
        ->submitUrl(1024) === '/submit/1024',
    '수업과 대회 문맥이 함께 전달되면 어느 문맥도 신뢰하면 안 됩니다.'
);

problemDetailAssert(
    str_contains($html, 'ojp-problem-aside-submit')
        && substr_count($html, 'href="/submit/1024"') === 2,
    '문제 요약 사이드바에도 스크롤 없이 쓸 수 있는 제출하기 버튼이 있어야 합니다.'
);
problemDetailAssert(
    ! str_contains($html, 'ojp-problem-detail-grid--with-submission'),
    '제출 패널이 없으면 2열 구조는 기존 너비를 유지해야 합니다.'
);

$panelHtml = $view->render(
    $state,
    [],
    null,
    '<form data-oj-submission-form class="ojp-inline-submission-marker"></form>'
);
problemDetailAssert(
    str_contains($panelHtml, 'ojp-problem-detail-grid--with-submission')
        && str_contains($panelHtml, 'ojp-inline-submission-marker'),
    '제출 패널이 있으면 2열 구조가 넓어지고 패널 내용을 사이드바에 포함해야 합니다.'
);
problemDetailAssert(
    strpos($panelHtml, 'ojp-problem-aside-submit') < strpos($panelHtml, 'ojp-inline-submission-marker'),
    '제출 패널은 제출하기 버튼 아래, 사이드바 맨 위쪽에 와야 합니다.'
);

$visualOutput = getenv('OJ_PROBLEM_DETAIL_VISUAL_OUTPUT');
if (is_string($visualOutput) && trim($visualOutput) !== '') {
    $visualBody = str_replace(
        '/lucide.svg',
        '../../resources/ui/icons/lucide.svg',
        $html
    );
    $visualDocument = '<!doctype html><html lang="ko"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>문제 상세 실제 렌더링 검증</title>'
        . '<link rel="stylesheet" href="../../resources/ui/css/oj-ui-system.css">'
        . '</head><body class="ojp-app"><div class="ojp-public-shell">'
        . '<header class="ojp-public-header"><div class="ojp-public-header__inner">'
        . '<a class="ojp-brand" href="#">OJ Platform</a>'
        . '<nav class="ojp-public-nav"><a href="#">홈</a><a href="#">문제</a>'
        . '<a href="#">제출 현황</a><a href="#">학습</a></nav>'
        . '<div class="ojp-account-menu"><span>문제 관리자</span><a href="#">로그아웃</a></div>'
        . '</div></header><main class="ojp-public-main"><div class="ojp-shell">'
        . $visualBody . '</div></main></div>'
        . '<script src="../../resources/ui/js/oj-ui.js"></script>'
        . '<script src="../../resources/features/mathjax/js/loader.js"></script></body></html>';
    file_put_contents($visualOutput, $visualDocument);
}

fwrite(
    STDOUT,
    sprintf("문제 상세 화면 단위 검사 통과: assertion %d개\n", $assertions)
);
