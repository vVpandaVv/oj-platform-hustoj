<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftReference;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlImportService;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemXmlPreviewService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class ProblemXmlImportActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_xml_import';
    private const PATH = '/oj-console/problems/xml';

    public function __construct(
        private readonly ProblemXmlPreviewService $previews,
        private readonly ProblemXmlImportService $imports,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        $reference = trim($request->bodyString('draft_reference'));
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorUserId = $actor->userId();
            $operation = $request->bodyString('operation');
            if ($operation === 'preview') {
                $file = $request->file('problem_xml_file');
                if ($file === null || ! $file->isSuccessful()) {
                    throw new DomainException('분석할 XML 파일을 선택해 주세요.');
                }
                $preview = $this->previews->previewUpload(
                    $actorUserId,
                    $file->clientName(),
                    $file->temporaryPath(),
                    $file->size(),
                    trim($request->bodyString('fallback_topic_key')),
                    $request->bodyBool('allow_overwrite'),
                    $request->bodyBool('include_metadata'),
                    $request->bodyBool('include_test_data')
                );

                return $this->responses->redirect(
                    self::PATH . '?draft=' . rawurlencode($preview->reference()),
                    new UiMessage(
                        'problems.xml_preview_ready',
                        MessageTone::Success,
                        'XML 분석 완료',
                        $preview->selectableCount()
                            . '개 문제를 적용할 수 있습니다. 항목별 검증 결과를 확인해 주세요.'
                    ),
                    $actorUserId
                );
            }
            if ($operation !== 'apply') {
                throw new DomainException('문제 XML 작업 종류가 올바르지 않습니다.');
            }
            $result = $this->imports->apply(
                $actorUserId,
                $reference,
                $request->bodyIntList('selected_indexes')
            );
            $path = self::PATH;
            if ($result->reference() !== null) {
                $path .= '?result=' . rawurlencode($result->reference());
            }
            $hasFailures = $result->failedCount() > 0;

            return $this->responses->redirect(
                $path,
                new UiMessage(
                    'problems.xml_applied',
                    $hasFailures ? MessageTone::Warning : MessageTone::Success,
                    $hasFailures ? 'XML 일부 적용' : 'XML 적용 완료',
                    '생성 ' . $result->createdCount()
                        . '개, 수정 ' . $result->updatedCount()
                        . '개, 실패 ' . $result->failedCount() . '개입니다.'
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                $this->draftPath($reference),
                new UiMessage(
                    'problems.xml_rejected',
                    MessageTone::Danger,
                    '문제 XML 작업 거부',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                $this->draftPath($reference),
                new UiMessage(
                    'problems.xml_failed',
                    MessageTone::Danger,
                    '문제 XML 작업 실패',
                    '작업을 완료하지 못했습니다. 시스템 상태를 확인해 주세요.'
                ),
                $actorUserId
            );
        }
    }

    private function draftPath(string $reference): string
    {
        if ($reference === '') {
            return self::PATH;
        }

        try {
            new FormDraftReference($reference);
        } catch (InvalidArgumentException) {
            return self::PATH;
        }

        return self::PATH . '?draft=' . rawurlencode($reference);
    }
}
