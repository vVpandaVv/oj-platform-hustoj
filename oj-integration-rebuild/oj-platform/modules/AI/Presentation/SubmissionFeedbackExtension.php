<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\AI\Presentation;

use CodingPanda\OjPlatform\Modules\AI\Application\SubmissionFeedbackJobService;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\Problems\Contract\LearningProblemProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\AiFeedbackSubmission;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionDetailExtension;

final class SubmissionFeedbackExtension implements SubmissionDetailExtension
{
    private const ACTION = 'oj_ai_feedback_generate';

    public function __construct(
        private readonly SubmissionFeedbackJobService $feedback,
        private readonly LearningProblemProvider $problems,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security,
        private readonly AiHtml $html
    ) {
    }

    public function key(): string
    {
        return 'ai_feedback';
    }

    public function render(
        int $actorUserId,
        string $actorUserLogin,
        AiFeedbackSubmission $submission
    ): string {
        try {
            $this->feedback->assertCanAccess($actorUserId, $actorUserLogin, $submission->submissionId());
        } catch (\DomainException) {
            return '';
        }
        $problem = $this->problems->findForUser(
            $actorUserId,
            $submission->problemId()
        );
        $existing = $this->feedback->findForDisplayForActor($actorUserId, $actorUserLogin, $submission->submissionId());
        $current = $existing !== null
            && $problem !== null
            && $this->feedback->isCurrent($existing, $submission, $problem);
        $stale = $existing !== null && ! $current;
        $canGenerate = $problem !== null
            && $this->feedback->canGenerate($submission);
        if ($existing === null && ! $canGenerate) {
            return '';
        }
        if ($stale) {
            $content = '<div class="ojp-empty-state" data-oj-ai-feedback-content>'
                . '<strong>이전 피드백을 숨겼습니다.</strong>'
                . '<p>현재 제출 코드와 채점 결과, 문제 내용에 맞게 다시 생성해 주세요.</p>'
                . '</div>';
        } elseif ($current) {
            $content = $this->html->feedback($existing);
        } else {
            $content = '<div class="ojp-empty-state" data-oj-ai-feedback-content>'
                . '<strong>아직 AI 풀이 도움을 만들지 않았습니다.</strong>'
                . '<p>버튼을 누른 경우에만 현재 제출을 분석합니다.</p></div>';
        }
        $button = $canGenerate
            ? '<form class="ojp-feedback-action" method="post" action="'
                . $this->html->escape($this->actionUrls->ajaxUrl())
                . '" data-oj-ai-feedback>'
                . $this->security->hiddenFields(self::ACTION)
                . '<input type="hidden" name="operation" value="generate">'
                . '<input type="hidden" name="submission_id" value="'
                . $submission->submissionId() . '">'
                . '<input type="hidden" name="job_id" value="">'
                . '<button class="ojp-button ojp-button--primary" type="submit"'
                . ' data-oj-ai-feedback-submit>'
                . ($existing === null ? '피드백 생성' : '피드백 다시 생성')
                . '</button><button class="ojp-button" type="button"'
                . ' data-oj-ai-feedback-cancel hidden>작업 취소</button>'
                . '<span class="ojp-field-help" data-oj-ai-feedback-status>'
                . ($stale
                    ? '제출 코드나 문제 원문이 바뀌어 다시 생성해야 합니다.'
                    : '명시적으로 요청한 경우에만 생성합니다.')
                . '</span></form>'
            : '';
        $tone = $stale ? 'warning' : ($existing === null ? 'neutral' : 'success');
        $label = $stale ? '갱신 필요' : ($existing === null ? '생성 전' : '생성 완료');

        return '<section class="ojp-section" data-oj-ai-feedback-panel>'
            . '<div class="ojp-section-heading"><div><h2>AI 풀이 도움</h2>'
            . '<p>현재 코드와 오류를 바탕으로 다음 확인 단계를 안내합니다.</p>'
            . '</div><span class="ojp-badge ojp-badge--' . $tone
            . '" data-oj-ai-feedback-badge>' . $label . '</span></div>'
            . $content . $button . '</section>';
    }
}
