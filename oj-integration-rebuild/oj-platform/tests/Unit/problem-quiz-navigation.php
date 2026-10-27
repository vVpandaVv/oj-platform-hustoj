<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use CodingPanda\OjPlatform\Modules\Problems\Domain\QuizResultPolicy;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizRequestFactory;

require_once dirname(__DIR__) . '/Support/problem-quiz-navigation.php';

$problemQuizChecks = 0;
function problemQuizAssert(bool $condition, string $message): void
{
    global $problemQuizChecks;
    ++$problemQuizChecks;
    if (! $condition) { throw new RuntimeException($message); }
}

$env = problemQuizEnvironment();
$id = $env['id'];
$render = static fn (): string => $env['view']->render($env['loader']->load(2, $id), []);
problemQuizAssert(! str_contains($render(), '퀴즈 풀기'), '퀴즈가 없으면 진입점을 표시하지 않아야 합니다.');
$draft = $env['service']->createDraft(1, $id, quizDefinition('초안 전용 제목'));
problemQuizAssert(! str_contains($render(), '초안 전용 제목'), '초안 퀴즈는 학습자에게 노출하면 안 됩니다.');
$revision = $env['service']->publish(1, $id, quizDefinition('게시한 퀴즈 <script>'));
$problemPage = $render();
problemQuizAssert(substr_count($problemPage, '퀴즈 풀기') === 2, '상단과 본문에서 퀴즈로 이동할 수 있어야 합니다.');
problemQuizAssert(str_contains($problemPage, '연결된 퀴즈') && str_contains($problemPage, '문항 1개 · 총점 2점 · 최대 1회 응시'), '게시 퀴즈의 기본 정보를 보여야 합니다.');
problemQuizAssert(str_contains($problemPage, '게시한 퀴즈 &lt;script&gt;') && ! str_contains($problemPage, '<script>'), '퀴즈 제목을 이스케이프해야 합니다.');
problemQuizAssert(! str_contains($problemPage, '2와 2를 더하면 4입니다.'), '문제 상세에서 퀴즈 정답 해설을 공개하면 안 됩니다.');
$env['service']->publish(1, $id, quizDefinition('비활성 퀴즈', enabled: false));
problemQuizAssert(! str_contains($render(), '퀴즈 풀기'), '비활성 퀴즈는 진입점을 표시하지 않아야 합니다.');
problemQuizAssert($env['loader']->load(0, $id) === null, '비로그인 사용자에게 문제나 퀴즈를 노출하면 안 됩니다.');

foreach ([
    'general' => ProblemNavigationContext::general(),
    'class' => ProblemNavigationContext::class(31),
    'contest' => ProblemNavigationContext::contest(77, 2),
] as $name => $navigation) {
    $env = problemQuizEnvironment($name !== 'general');
    $id = $env['id'];
    $revision = $env['service']->publish(1, $id, quizDefinition('연결 퀴즈', policy: QuizResultPolicy::ScoreOnly));
    $query = problemQuizQuery($navigation);
    $request = new HttpRequest('GET', '/quizzes/1024', $query);
    $state = $env['loader']->load(2, $id, $navigation->accessContext());
    problemQuizAssert($state?->quiz()?->id() === $revision->id(), "$name: 접근 가능한 문제의 게시 퀴즈를 조회해야 합니다.");
    $html = $env['view']->render($state, [], $navigation);
    problemQuizAssert(str_contains($html, htmlspecialchars($navigation->quizUrl(1024), ENT_QUOTES)), "$name: 문제에서 퀴즈로 문맥을 전달해야 합니다.");
    $detail = $env['handlers']['detail']->handle(problemQuizRoute(), $request);
    problemQuizAssert($detail->status() === 200, "$name: 퀴즈 상세를 열 수 있어야 합니다.");
    problemQuizAssert(str_contains($detail->body(), htmlspecialchars($navigation->detailUrl(1024), ENT_QUOTES)), "$name: 원래 문제로 돌아갈 수 있어야 합니다.");
    foreach ($query as $key => $value) {
        problemQuizAssert(str_contains($detail->body(), 'name="' . $key . '" value="' . $value . '"'), "$name: 시작 폼에 문맥을 포함해야 합니다.");
    }
    $beforeStart = $env['handlers']['attempt']->handle(problemQuizRoute(), $request);
    problemQuizAssert($beforeStart->status() === 404, "$name: 응시 시작 전에는 답안 폼을 열 수 없습니다.");
    problemQuizAssert(str_contains($beforeStart->body(), htmlspecialchars($navigation->quizUrl(1024), ENT_QUOTES)), "$name: 오류 화면에서도 퀴즈 복귀 문맥을 유지해야 합니다.");
    $start = $env['handlers']['start']->handle(new HttpRequest('POST', '/action', [], [
        ...$query, 'problem_id' => '1024', 'attempt_key' => str_repeat('b', 64),
    ]));
    problemQuizAssert(($start->headers()['Location'] ?? '') === $navigation->quizUrl(1024, 'attempt'), "$name: 응시 시작 후 같은 문맥으로 이동해야 합니다.");
    $attempt = $env['service']->attempt(2, $id, null, $navigation->accessContext())['attempt'];
    $continued = $env['handlers']['detail']->handle(problemQuizRoute(), $request)->body();
    problemQuizAssert(str_contains($continued, '응시 계속하기') && ! str_contains($continued, '>응시 시작<'), "$name: 기존 응시를 계속해야 합니다.");
    $attemptPage = $env['handlers']['attempt']->handle(problemQuizRoute(), $request);
    problemQuizAssert($attemptPage->status() === 200 && str_contains($attemptPage->body(), 'data-oj-quiz-attempt'), "$name: 퀴즈 답안 폼을 표시해야 합니다.");
    foreach ($query as $key => $value) {
        problemQuizAssert(str_contains($attemptPage->body(), 'name="' . $key . '" value="' . $value . '"'), "$name: 제출 폼에 문맥을 포함해야 합니다.");
    }
    $question = $revision->definition()->questions()[0];
    $submit = $env['handlers']['submit']->handle(new HttpRequest('POST', '/action', [], [
        ...$query, 'problem_id' => '1024', 'attempt_id' => (string) $attempt->id(),
        'answers' => [$question->id() => (string) $question->correctChoice()->id()],
    ]));
    problemQuizAssert(($submit->headers()['Location'] ?? '') === $navigation->quizUrl(1024, 'attempts/' . $attempt->id()), "$name: 제출 후 결과 URL에 문맥을 유지해야 합니다.");
    $result = $env['handlers']['result']->handle(problemQuizRoute($attempt->id()), $request);
    problemQuizAssert($result->status() === 200 && str_contains($result->body(), '2 / 2'), "$name: 제출한 답안을 채점해야 합니다.");
    problemQuizAssert(! str_contains($result->body(), '2와 2를 더하면 4입니다.'), "$name: 점수만 공개 정책을 유지해야 합니다.");
    problemQuizAssert(str_contains($result->body(), htmlspecialchars($navigation->detailUrl(1024), ENT_QUOTES)), "$name: 결과에서 문제로 돌아갈 수 있어야 합니다.");
    $exhausted = $env['handlers']['detail']->handle(problemQuizRoute(), $request)->body();
    problemQuizAssert(str_contains($exhausted, '응시 횟수 사용 완료') && ! str_contains($exhausted, '>응시 시작<'), "$name: 기존 응시 제한을 유지해야 합니다.");
    $statistics = $env['handlers']['statistics']->handle(problemQuizRoute(), $request);
    problemQuizAssert($statistics->status() === 200 && str_contains($statistics->body(), htmlspecialchars($navigation->detailUrl(1024), ENT_QUOTES)), "$name: 통계에서도 문제 복귀 문맥을 유지해야 합니다.");
    $again = $env['handlers']['start']->handle(new HttpRequest('POST', '/action', [], [
        ...$query, 'problem_id' => '1024', 'attempt_key' => str_repeat('d', 64),
    ]));
    problemQuizAssert(($again->headers()['Location'] ?? '') === $navigation->quizUrl(1024), "$name: 응시 횟수 제한을 서버에서도 유지해야 합니다.");
    problemQuizAssert(count($env['service']->visible(2, $id, $navigation->accessContext())['attempts']) === 1, "$name: 재시도로 새 응시를 생성하면 안 됩니다.");

    $env['accounts']->userId = 4;
    problemQuizAssert($env['handlers']['result']->handle(problemQuizRoute($attempt->id()), $request)->status() === 404, "$name: 다른 사용자의 응시 결과를 열 수 없어야 합니다.");
    $env['accounts']->userId = 2;
    if ($name !== 'general') {
        problemQuizAssert($env['loader']->load(2, $id) === null, "$name: 비공개 문제를 일반 경로로 열 수 없어야 합니다.");
        $generalRequest = new HttpRequest('GET', '/quizzes/1024');
        problemQuizAssert($env['handlers']['detail']->handle(problemQuizRoute(), $generalRequest)->status() === 404, "$name: 문맥 누락으로 비공개 퀴즈 접근을 우회하면 안 됩니다.");
        foreach ($env['providers'] as $provider) { $provider->allowed = false; }
        problemQuizAssert($env['loader']->load(2, $id, $navigation->accessContext()) === null, "$name: 문제 접근 권한을 다시 검사해야 합니다.");
        foreach (['detail', 'attempt', 'result', 'statistics'] as $page) {
            problemQuizAssert($env['handlers'][$page]->handle(problemQuizRoute($attempt->id()), $request)->status() === 404, "$name: 권한이 취소된 $page 요청은 거절해야 합니다.");
        }
        $blocked = $env['handlers']['start']->handle(new HttpRequest('POST', '/action', [], [
            ...$query, 'problem_id' => '1024', 'attempt_key' => str_repeat('c', 64),
        ]));
        problemQuizAssert(($blocked->headers()['Location'] ?? '') === $navigation->quizUrl(1024), "$name: 시작 실패에서도 문맥을 유지해야 합니다.");
        $blockedSubmit = $env['handlers']['submit']->handle(new HttpRequest('POST', '/action', [], [
            ...$query, 'problem_id' => '1024', 'attempt_id' => (string) $attempt->id(), 'answers' => [],
        ]));
        problemQuizAssert(($blockedSubmit->headers()['Location'] ?? '') === $navigation->quizUrl(1024, 'attempt'), "$name: 권한 취소 후 제출도 거절하고 문맥을 유지해야 합니다.");
    }
}

$env = problemQuizEnvironment(false, false);
$env['service']->publish(1, $env['id'], quizDefinition('공개 문제의 퀴즈'));
$forged = new HttpRequest('GET', '/quizzes/1024', ['class_id' => '31']);
problemQuizAssert($env['handlers']['detail']->handle(problemQuizRoute(), $forged)->status() === 404, '공개 문제라도 수업 문맥 권한이 없으면 일반 접근으로 우회하면 안 됩니다.');
$env = problemQuizEnvironment();
$env['service']->publish(1, $env['id'], quizDefinition('공유 응시'));
$shared = $env['service']->start(2, $env['id'], str_repeat('e', 64));
problemQuizAssert($env['service']->start(2, $env['id'], str_repeat('f', 64), ProblemNavigationContext::class(31)->accessContext())->id() === $shared->id(), '문제와 수업에서 같은 퀴즈를 열어도 진행 중인 응시를 공유해야 합니다.');
$flags = new class implements \CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags {
    public int $reads = 0;
    public bool $enabled = false;
    public function isEnabled(string $featureKey): bool { ++$this->reads; return $this->enabled; }
};
$disabledLoader = new \CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemDetailPageLoader(
    $env['catalog'], $env['access'], $env['books'], $errors, $env['repository'], $flags
);
problemQuizAssert($flags->reads === 0, '부팅·조립 시점에는 기능 설정을 읽으면 안 됩니다.');
$disabledState = $disabledLoader->load(2, $env['id']);
problemQuizAssert($disabledState !== null && $disabledState->quiz() === null, '퀴즈 기능을 끄면 문제는 열리되 게시 퀴즈를 조회하거나 표시하지 않아야 합니다.');
$flags->enabled = true;
problemQuizAssert($disabledLoader->load(2, $env['id'])?->quiz() !== null, '퀴즈 기능을 다시 켜면 게시 퀴즈를 표시해야 합니다.');

foreach (['GET', 'POST'] as $method) {
    $values = ['class_id' => '31', 'contest_id' => '77', 'contest_problem_number' => '2'];
    $request = new HttpRequest($method, '/quizzes/1024', $method === 'GET' ? $values : [], $method === 'POST' ? $values : []);
    problemQuizAssert(QuizRequestFactory::navigation($request)->accessContext()->isGeneral(), '충돌하는 접근 문맥을 신뢰하면 안 됩니다.');
    $invalid = new HttpRequest($method, '/quizzes/1024', $method === 'GET' ? ['class_id' => '<script>'] : [], $method === 'POST' ? ['class_id' => ['31']] : []);
    problemQuizAssert(QuizRequestFactory::navigation($invalid)->accessContext()->isGeneral(), '잘못된 문맥 입력을 URL에 반영하면 안 됩니다.');
}

fwrite(STDOUT, "문제·퀴즈 연결 검사: $problemQuizChecks checks passed.\n");
