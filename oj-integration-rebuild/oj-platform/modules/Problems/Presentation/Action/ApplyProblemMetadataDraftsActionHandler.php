<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemMetadataBulkApplyService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class ApplyProblemMetadataDraftsActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_bulk_apply_metadata_draft';

    public function __construct(
        private readonly ProblemMetadataBulkApplyService $bulkApply,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors,
        private readonly ConsoleResponder $pages
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $jobId = $request->bodyInt('job_id');
        $redirectPath = $jobId > 0 ? '/oj-console/jobs/' . $jobId : '/oj-console/problems';
        $actorUserId = 0;
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorUserId = $actor->userId();
            $targets = $this->targets($request);
            if ($targets === []) {
                throw new DomainException('승인할 AI 초안을 선택해 주세요.');
            }
            $result = $this->bulkApply->applyMany($actorUserId, $targets);
            $appliedCount = count($result['applied']);
            $failedCount = count($result['failed']);
            if ($failedCount > 0) {
                return $this->failureReport(
                    $request,
                    $redirectPath,
                    $targets,
                    $appliedCount,
                    $result['failed']
                );
            }

            return $this->responses->redirect(
                $redirectPath,
                new UiMessage(
                    'problems.metadata_draft_applied',
                    MessageTone::Success,
                    'AI 메타데이터 초안 승인',
                    sprintf('%d개 문제의 메타데이터를 승인했습니다.', $appliedCount)
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                $redirectPath,
                new UiMessage(
                    'problems.metadata_draft_apply_rejected',
                    MessageTone::Danger,
                    'AI 메타데이터 승인 실패',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                $redirectPath,
                new UiMessage(
                    'problems.metadata_draft_apply_failed',
                    MessageTone::Danger,
                    'AI 메타데이터 승인 실패',
                    '잠시 뒤 다시 시도하고 문제가 계속되면 시스템 상태를 확인해 주세요.'
                ),
                $actorUserId
            );
        }
    }

    /**
     * @param list<array{problem_id:int, reference:string}> $targets
     * @param array<int,string> $failed
     */
    private function failureReport(
        HttpRequest $request,
        string $returnPath,
        array $targets,
        int $appliedCount,
        array $failed
    ): HttpResponse {
        $references = array_column($targets, 'reference', 'problem_id');
        $rows = '';
        foreach ($failed as $problemId => $reason) {
            $editPath = '/oj-console/problems/' . (int) $problemId . '/edit';
            $reviewPath = $editPath . '?metadata_draft=' . rawurlencode($references[$problemId]);
            $rows .= '<tr><th scope="row"><a href="' . $this->escape($editPath) . '">'
                . (int) $problemId . '</a></th><td><span class="ojp-status-badge ojp-status-badge--danger">'
                . '승인 실패</span></td><td>' . $this->escape($reason) . '</td><td>'
                . '<a class="ojp-button ojp-button--small" href="' . $this->escape($reviewPath)
                . '" title="AI 메타데이터 초안 검토">검토</a></td></tr>';
        }
        $tone = $appliedCount === 0 ? 'danger' : 'warning';
        $content = '<header class="ojp-page-header"><div><p class="ojp-eyebrow">문제</p>'
            . '<h1>AI 메타데이터 승인 결과</h1></div><div class="ojp-heading-actions">'
            . '<a class="ojp-button" href="' . $this->escape($returnPath) . '">'
            . ($request->bodyInt('job_id') > 0 ? '작업으로 돌아가기' : '문제 목록')
            . '</a></div></header><div class="ojp-notice ojp-notice--' . $tone
            . '" role="status"><strong>' . sprintf('%d개 승인, %d개 실패', $appliedCount, count($failed))
            . '</strong></div><section class="ojp-section"><header class="ojp-section-heading">'
            . '<h2>승인 실패 문제</h2></header><div class="ojp-table-wrap">'
            . '<table class="ojp-table ojp-table--results"><thead><tr><th scope="col">문제 번호</th>'
            . '<th scope="col">결과</th><th scope="col">실패 사유</th><th scope="col">확인</th>'
            . '</tr></thead><tbody>' . $rows . '</tbody></table></div></section>';
        $response = $this->pages->respond(
            'AI 메타데이터 승인 결과',
            $content,
            'problems.index',
            $request
        );

        return new HttpResponse(
            $response->status(),
            array_replace($response->headers(), ['Cache-Control' => 'private, no-store']),
            $response->body()
        );
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * @return list<array{problem_id:int, reference:string}>
     */
    private function targets(HttpRequest $request): array
    {
        $body = $request->body();
        $values = is_array($body['targets'] ?? null) ? $body['targets'] : [];
        $targets = [];
        $seen = [];
        foreach ($values as $value) {
            if (! is_scalar($value)) {
                continue;
            }
            if (
                preg_match(
                    '/^(\d+)\|(draft:v1:[a-f0-9-]{36})$/',
                    trim((string) $value),
                    $matches
                ) !== 1
            ) {
                continue;
            }
            $problemId = (int) $matches[1];
            if ($problemId < 1 || isset($seen[$problemId])) {
                continue;
            }
            $seen[$problemId] = true;
            $targets[] = ['problem_id' => $problemId, 'reference' => $matches[2]];
        }

        return $targets;
    }
}
