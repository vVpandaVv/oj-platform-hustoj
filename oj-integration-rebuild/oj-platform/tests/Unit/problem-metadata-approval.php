<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentity;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemEditCommand;
use CodingPanda\OjPlatform\Modules\Problems\Domain\CodeEntryMode;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\Action\ApplyProblemMetadataDraftsActionHandler;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;

require __DIR__ . '/problems.php';
$assertions = 0;

$guard = new class implements ActionAccessGuard {
    public int $userId = 1;
    public bool $validNonce = true;

    public function verifyNonce(string $actionKey, HttpRequest $request): void
    {
        problemsSame('oj_problem_bulk_apply_metadata_draft', $actionKey, 'Protect the bulk approval action.');
        if (! $this->validNonce) {
            throw new DomainException('요청을 확인할 수 없습니다.');
        }
    }

    public function authenticatedActor(string $actionKey, HttpRequest $request): UserIdentity
    {
        $this->verifyNonce($actionKey, $request);
        return new UserIdentity($this->userId, 'admin', '', '관리자');
    }

    public function actor(string $actionKey, HttpRequest $request, string $capability): UserIdentity
    {
        return $this->authenticatedActor($actionKey, $request);
    }

    public function throttle(string $scope, string $identifier, int $limit, int $windowSeconds): void {}
};
$responses = new class implements ActionResponse {
    public ?UiMessage $message = null;

    public function redirect(string $path, UiMessage $message, int $userId = 0): HttpResponse
    {
        $this->message = $message;
        return HttpResponse::redirect($path);
    }
};
$pages = new class implements ConsoleResponder {
    public function respond(string $title, string $content, string $currentMenu, HttpRequest $request, int $status = 200): HttpResponse
    {
        problemsSame('problems.index', $currentMenu, 'Use the existing problem console shell.');
        return HttpResponse::html($content, $status);
    }
};
$handler = new ApplyProblemMetadataDraftsActionHandler($bulkApplyService, $guard, $responses, $bulkApplyErrors, $pages);
$makeTarget = function (string $title) use ($editor, $gateway, $metadata, $topics, $aiInputs, $formDrafts): array {
    $id = $editor->create(1, new ProblemEditCommand(problemDraft($title), null, [], CodeEntryMode::FullCode, null, ''));
    $hash = $aiInputs->hash($aiInputs->problem($gateway->find($id), $metadata->find($id)));
    $reference = $formDrafts->store('problem_metadata', 1, 'problem', (string) $id->value(), [
        'difficulty_score' => 2,
        'topic_keys' => ['simulation'],
        'confidence' => 0.8,
        'rationale' => '반복 처리 문제입니다.',
        'problem_snapshot_hash' => $hash,
        'dictionary_version' => $topics->version(),
    ]);
    return ['problem_id' => $id->value(), 'reference' => $reference->value()];
};
$post = static function (array $targets, int $jobId = 47): HttpRequest {
    return new HttpRequest('POST', '/oj-action', [], [
        'job_id' => (string) $jobId,
        'targets' => array_map(static fn (array $target): string => $target['problem_id'] . '|' . $target['reference'], $targets),
    ]);
};
$rows = static function (string $html): DOMNodeList {
    $document = new DOMDocument();
    @$document->loadHTML('<?xml encoding="UTF-8">' . $html);
    return (new DOMXPath($document))->query('//tbody/tr');
};

$success = $makeTarget('승인 대상');
$changed = $makeTarget('변경 대상');
$expired = $makeTarget('만료 대상');
$gateway->update(new \CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId($changed['problem_id']), problemDraft('생성 후 변경된 문제'));
$aiDrafts->discard($expired['reference'], 1);
$mixed = $handler->handle($post([$success, $changed, $expired]));
$mixedHtml = $mixed->body();
problemsSame(200, $mixed->status(), 'Partial approval opens a detailed result, not a counts-only redirect.');
problemsSame('private, no-store', $mixed->headers()['Cache-Control'] ?? '', 'Do not cache draft review links.');
problemsAssert(str_contains($mixedHtml, '1개 승인, 2개 실패'), 'Show accurate mixed counts.');
problemsSame(2, $rows($mixedHtml)->length, 'Show every failed problem as a separate row.');
foreach ([$changed, $expired] as $target) {
    $url = '/oj-console/problems/' . $target['problem_id'] . '/edit';
    problemsAssert(str_contains($mixedHtml, 'href="' . $url . '"'), 'Link each failed problem to its editor.');
    problemsAssert(str_contains($mixedHtml, $url . '?metadata_draft=' . rawurlencode($target['reference'])), 'Link to the matching draft, not another problem.');
}
problemsAssert(! str_contains($mixedHtml, '/problems/' . $success['problem_id'] . '/edit'), 'Do not list approved problems as failures.');
problemsAssert(str_contains($mixedHtml, '문제 내용이 변경되었습니다'), 'Keep the changed-source reason.');
problemsAssert(str_contains($mixedHtml, '보관 시간이 지났습니다'), 'Keep the missing/expired draft reason.');
problemsAssert(str_contains($mixedHtml, 'href="/oj-console/jobs/47"'), 'Return to the originating job.');
$changedReference = new \CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference($changed['reference']);
problemsAssert($formDrafts->find($changedReference, 1) !== null, 'Failed approval does not consume the draft.');

$allFailed = $handler->handle($post([$changed, $expired], 0));
problemsAssert(str_contains($allFailed->body(), '0개 승인, 2개 실패'), 'All-failed results retain each failure instead of replacing them with a generic error.');
problemsSame(2, $rows($allFailed->body())->length, 'All-failed results show every failed problem.');
problemsAssert(str_contains($allFailed->body(), 'ojp-notice--danger'), 'Use an error tone for all-failed results.');
problemsAssert(str_contains($allFailed->body(), 'href="/oj-console/problems"'), 'Use a safe return path without a job.');

$allSuccess = $makeTarget('전체 승인');
$approved = $handler->handle($post([$allSuccess]));
problemsSame(302, $approved->status(), 'Successful batches retain the existing redirect flow.');
problemsSame('/oj-console/jobs/47', $approved->headers()['Location'] ?? '', 'Return to the correct job after success.');
problemsSame('1개 문제의 메타데이터를 승인했습니다.', $responses->message?->body(), 'Keep success feedback.');

$internal = $makeTarget('내부 오류');
$gateway->failUpdateOnCall = $gateway->nextUpdateCall();
$internalResponse = $handler->handle($post([$internal]));
$gateway->failUpdateOnCall = null;
problemsAssert(str_contains($internalResponse->body(), '내부 처리 오류로 승인하지 못했습니다'), 'Display a safe reason for unexpected failures.');
problemsAssert(! str_contains($internalResponse->body(), 'Judge 문제 복구 실패'), 'Never expose raw infrastructure exceptions.');
problemsSame('Judge 문제 복구 실패', $bulkApplyErrors->errors[array_key_last($bulkApplyErrors->errors)]->getMessage(), 'Retain the underlying error in system logging.');

$guard->userId = 21;
$denied = $handler->handle($post([$internal]));
problemsAssert(str_contains($denied->body(), '적용할 권한이 없습니다'), 'Keep per-problem authorization checks.');
$guard->userId = 1;
$guard->validNonce = false;
$before = $gateway->events;
problemsSame(302, $handler->handle($post([$internal]))->status(), 'Reject an invalid nonce.');
problemsSame($before, $gateway->events, 'Invalid nonces must not save any metadata.');
$guard->validNonce = true;
problemsSame(302, $handler->handle(new HttpRequest('POST', '/oj-action', [], ['targets' => ['bad']]))->status(), 'Retain empty/invalid selection feedback.');
problemsSame('승인할 AI 초안을 선택해 주세요.', $responses->message?->body(), 'Explain an invalid selection.');

$report = new ReflectionMethod($handler, 'failureReport');
$escaped = $report->invoke($handler, $post([$changed]), '/oj-console/jobs/47', [$changed], 0, [
    $changed['problem_id'] => '<script>alert("secret")</script>',
]);
problemsAssert(! str_contains($escaped->body(), '<script>'), 'Failure reasons must be escaped.');
problemsAssert(str_contains($escaped->body(), '&lt;script&gt;'), 'Escaped diagnostic text remains readable.');
$manyTargets = [];
$manyFailures = [];
for ($id = 1000; $id < 1100; ++$id) {
    $manyTargets[] = ['problem_id' => $id, 'reference' => $changed['reference']];
    $manyFailures[$id] = 'AI 초안의 보관 시간이 지났습니다.';
}
$many = $report->invoke($handler, $post($manyTargets), '/oj-console/jobs/47', $manyTargets, 0, $manyFailures);
problemsSame(100, $rows($many->body())->length, 'Do not silently truncate large failure lists.');

if (($argv[1] ?? '') === '--render' && isset($argv[2])) {
    $directory = $argv[2];
    if (! is_dir($directory)) mkdir($directory, 0777, true);
    $css = file_get_contents($pluginRoot . '/resources/ui/css/oj-ui-system.css');
    foreach (['mixed' => $mixedHtml, 'all-failed' => $allFailed->body(), 'escaped' => $escaped->body()] as $name => $html) {
        file_put_contents($directory . '/' . $name . '.html', '<!doctype html><html lang="ko"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><title>AI 메타데이터 승인 결과</title><style>' . $css . '</style></head><body class="ojp-app"><main class="ojp-main">' . $html . '</main></body></html>');
    }
}
echo "Metadata bulk approval: $assertions checks passed.\n";
