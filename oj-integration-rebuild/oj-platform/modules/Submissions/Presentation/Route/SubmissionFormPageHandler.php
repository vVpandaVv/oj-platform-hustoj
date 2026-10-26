<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Submissions\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferenceProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemNavigationContext;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblem;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionProblemProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionContextProviderRegistry;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionFormRenderer;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;
use DomainException;

final class SubmissionFormPageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountProvider $accounts,
        private readonly UserSubmissionPreferenceProvider $preferences,
        private readonly SubmissionProblemProvider $problems,
        private readonly SubmissionFormRenderer $formRenderer,
        private readonly SubmissionContextProviderRegistry $contexts,
        private readonly SubmissionHtml $html,
        private readonly ?\CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionDraftService $drafts = null
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $response = $this->respond($route, $request);
        return $request->queryString('draft') === '' ? $response : $this->privateDraftResponse($response);
    }

    private function respond(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }
        $preferences = $this->preferences->forUser($identity->userId());
        $problemId = (int) (
            $route->parameters()['problem_id']
            ?? $request->queryString('problem_id', '0')
        );
        if ($problemId < 1) {
            return $this->problemChooser($request);
        }
        try {
            $formContext = $this->formContext($request);
            $problemNavigation = ProblemNavigationContext::fromQueryValues(
                (string) $formContext['contest_id'],
                (string) $formContext['contest_problem_number'],
                (string) ($formContext['class_id'] ?? '')
            );
            $forcedBlocklyProfile = $this->contexts->blocklyProfile(
                $formContext['contest_id'],
                $formContext['class_id'],
                $formContext['group_id']
            );
        } catch (DomainException $error) {
            return $this->pages->authenticated(
                '제출',
                '<div class="ojp-empty-state"><h1>제출 경로를 확인해 주세요.</h1>'
                    . '<p>' . $this->html->escape($error->getMessage()) . '</p></div>',
                $request,
                400
            );
        }
        $problem = $this->problems->findVisible(
            $identity->userId(),
            $problemId,
            $problemNavigation->accessContext()
        );
        if (! $problem instanceof SubmissionProblem) {
            return $this->pages->authenticated(
                '제출',
                $this->notFound(),
                $request,
                404
            );
        }
        try {
            $policy = $this->problems->codePolicy(
                $identity->userId(),
                $problemId,
                $problemNavigation->accessContext()
            );
        } catch (\Throwable) {
            return $this->pages->authenticated(
                '제출',
                $this->notFound(),
                $request,
                404
            );
        }

        $prefill = null;
        $reference = $request->queryString('draft');
        if ($reference !== '') {
            try {
                if ($this->drafts === null) { throw new DomainException('제출 초안 연결을 사용할 수 없습니다.'); }
                $prefill = $this->drafts->load($reference, $identity->userId(), $problemId, $formContext);
            } catch (DomainException $error) {
                return $this->pages->authenticated('제출 초안',
                    '<div class="ojp-empty-state"><h1>제출 초안을 불러올 수 없습니다.</h1><p>'
                    . $this->html->escape($error->getMessage()) . '</p><p>활동 창의 초안은 유지됩니다. 활동에서 다시 제출 화면을 열어 주세요.</p></div>', $request, 400);
            }
        }
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">제출</p><h1>문제 '
            . $problemId . ' · ' . $this->html->escape($problem->title())
            . '</h1><p>작성한 코드를 확인하고 채점을 요청합니다.</p></div>'
            . '<div class="ojp-page-actions"><a class="ojp-button" href="'
            . $this->html->escape($problemNavigation->detailUrl($problemId))
            . '">문제로 돌아가기</a></div></header>'
            . $this->formRenderer->render(
                $problemId,
                $policy,
                $forcedBlocklyProfile,
                $preferences,
                $formContext,
                $identity->userId(),
                $prefill
            );

        return $this->pages->authenticated('코드 제출', $content, $request);
    }

    private function privateDraftResponse(HttpResponse $response): HttpResponse
    {
        return new HttpResponse($response->status(), array_replace($response->headers(),
            ['Cache-Control' => 'private, no-store', 'Referrer-Policy' => 'no-referrer']), $response->body());
    }

    private function problemChooser(HttpRequest $request): HttpResponse
    {
        $options = '';
        foreach ($this->problems->listVisible('', 100) as $problem) {
            $options .= '<option value="' . $problem->problemId() . '">'
                . $problem->problemId() . ' · '
                . $this->html->escape($problem->title()) . '</option>';
        }
        $content = '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">제출</p><h1>제출할 문제 선택</h1>'
            . '<p>문제를 선택하면 해당 문제의 코드 정책과 작성 화면을 불러옵니다.</p>'
            . '</div></header><section class="ojp-section">'
            . '<form class="ojp-form-grid" method="get" action="/submit">'
            . '<label class="ojp-field"><span class="ojp-label">문제</span>'
            . '<select class="ojp-select" name="problem_id" required>'
            . '<option value="">문제를 선택하세요</option>' . $options
            . '</select></label><div class="ojp-form-actions">'
            . '<button class="ojp-button ojp-button--primary" type="submit">'
            . '작성 화면 열기</button></div></form></section>';

        return $this->pages->authenticated('제출', $content, $request);
    }

    /**
     * @return array{
     *   contest_id: int,
     *   contest_problem_number: int,
     *   class_id: ?int,
     *   group_id: ?int
     * }
     */
    private function formContext(HttpRequest $request): array
    {
        $contestId = max(0, $this->queryInt($request, 'contest_id'));
        $classValue = $this->queryInt($request, 'class_id');
        $groupValue = $this->queryInt($request, 'group_id');
        $classId = $classValue > 0 ? $classValue : null;
        $groupId = $groupValue > 0 ? $groupValue : null;
        $active = ($contestId > 0 ? 1 : 0)
            + ($classId !== null ? 1 : 0)
            + ($groupId !== null ? 1 : 0);
        if ($active > 1) {
            throw new DomainException(
                '수업·대회·그룹 제출 경로는 하나만 선택할 수 있습니다.'
            );
        }

        return [
            'contest_id' => $contestId,
            'contest_problem_number' => $this->queryInt(
                $request,
                'contest_problem_number',
                -1
            ),
            'class_id' => $classId,
            'group_id' => $groupId,
        ];
    }

    private function queryInt(
        HttpRequest $request,
        string $key,
        int $default = 0
    ): int {
        $value = trim($request->queryString($key, (string) $default));

        return preg_match('/^-?\d+$/', $value) === 1
            ? (int) $value
            : $default;
    }

    private function notFound(): string
    {
        return '<header class="ojp-page-header"><div>'
            . '<p class="ojp-eyebrow">제출</p><h1>문제를 찾을 수 없습니다.</h1>'
            . '<p>문제가 공개되어 있는지 또는 접근 권한이 있는지 확인해 주세요.</p>'
            . '</div></header><a class="ojp-button" href="/problems">문제 목록</a>';
    }
}
