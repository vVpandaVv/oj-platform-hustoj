<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizReapplyService;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizRequestFactory;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class SaveQuizActionHandler implements ActionHandler
{
    private const ACTION = 'oj_quiz_save';

    public function __construct(
        private readonly QuizService $quizzes,
        private readonly QuizReapplyService $reapply,
        private readonly QuizRequestFactory $requests,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        $problemId = max(0, $request->bodyInt('problem_id'));
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $userId = $actor->userId();
            $problem = new ProblemId($problemId);
            $definition = $this->requests->definition($request);
            $intent = $request->bodyString('intent');
            if ($intent === 'publish') {
                $managed = $this->quizzes->managed($userId, $problem);
                $source = $managed['published'];
                $includeInProgress = $source !== null
                    && $request->bodyBool('reapply_in_progress');
                $includeGraded = $source !== null
                    && $request->bodyBool('reapply_graded');
                $this->reapply->assertCanRequest(
                    $userId,
                    $problem,
                    $definition,
                    $includeInProgress,
                    $includeGraded
                );
                $revision = $this->quizzes->publish(
                    $userId,
                    $problem,
                    $definition
                );
                $job = null;
                if ($source !== null) {
                    try {
                        $job = $this->reapply->enqueue(
                            $userId,
                            $source,
                            $revision,
                            $includeInProgress,
                            $includeGraded
                        );
                    } catch (Throwable $error) {
                        $this->errors->report($error);

                        return $this->responses->redirect(
                            '/oj-console/quizzes/' . $problemId . '/edit',
                            new UiMessage(
                                'quizzes.published_reapply_failed',
                                MessageTone::Warning,
                                '새 버전 게시 완료',
                                'v' . $revision->revisionNumber()
                                    . '은 게시했지만 기존 응시 재적용 작업은 등록하지 못했습니다.'
                            ),
                            $userId
                        );
                    }
                }
                if ($job !== null) {
                    return $this->responses->redirect(
                        '/oj-console/jobs/' . $job->jobId(),
                        new UiMessage(
                            'quizzes.published_reapply_registered',
                            MessageTone::Success,
                            '새 버전 게시 및 재적용 등록',
                            'v' . $revision->revisionNumber()
                                . '을 게시하고 작업 ' . $job->jobId()
                                . '번을 등록했습니다.'
                        ),
                        $userId
                    );
                }
                $message = new UiMessage(
                    'quizzes.published',
                    MessageTone::Success,
                    '퀴즈 게시 완료',
                    '새 불변 버전 v' . $revision->revisionNumber()
                        . '을 게시했습니다.'
                        . (($includeInProgress || $includeGraded)
                            ? ' 재적용할 기존 응시는 없었습니다.'
                            : '')
                );
            } elseif ($intent === 'save_draft') {
                $this->quizzes->saveDraft($userId, $problem, $definition);
                $message = new UiMessage(
                    'quizzes.draft_saved',
                    MessageTone::Success,
                    '퀴즈 초안 저장 완료',
                    '게시 버전과 응시 결과는 변경하지 않았습니다.'
                );
            } else {
                throw new DomainException('퀴즈 저장 작업이 올바르지 않습니다.');
            }

            return $this->responses->redirect(
                '/oj-console/quizzes/' . $problemId . '/edit',
                $message,
                $userId
            );
        } catch (DomainException | InvalidArgumentException $error) {
            return $this->failure($error->getMessage(), $problemId, $userId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                '퀴즈를 저장하지 못했습니다.',
                $problemId,
                $userId
            );
        }
    }

    private function failure(
        string $message,
        int $problemId,
        int $userId
    ): HttpResponse {
        $path = $problemId > 0
            ? '/oj-console/quizzes/' . $problemId . '/edit'
            : '/oj-console/quizzes';

        return $this->responses->redirect(
            $path,
            new UiMessage(
                'quizzes.save_failed',
                MessageTone::Danger,
                '퀴즈 저장 실패',
                $message
            ),
            $userId
        );
    }
}
