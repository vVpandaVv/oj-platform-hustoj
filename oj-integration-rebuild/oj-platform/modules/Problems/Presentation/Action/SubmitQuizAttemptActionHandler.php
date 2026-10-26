<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizRequestFactory;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class SubmitQuizAttemptActionHandler implements ActionHandler
{
    private const ACTION = 'oj_quiz_submit_attempt';

    public function __construct(
        private readonly QuizService $quizzes,
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
        $attemptId = max(0, $request->bodyInt('attempt_id'));
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $userId = $actor->userId();
            $this->guard->throttle(
                'quiz_submit',
                (string) $userId,
                30,
                60
            );
            $context = $this->quizzes->attempt(
                $userId,
                new ProblemId($problemId),
                $attemptId
            );
            $attempt = $this->quizzes->submit(
                $userId,
                new ProblemId($problemId),
                $attemptId,
                $this->requests->answers(
                    $request,
                    $context['revision']->definition()
                )
            );

            return $this->responses->redirect(
                '/quizzes/' . $problemId . '/attempts/' . $attempt->id(),
                new UiMessage(
                    'quizzes.attempt_submitted',
                    MessageTone::Success,
                    '퀴즈 제출 완료',
                    '답안과 점수를 한 번 확정했습니다.'
                ),
                $userId
            );
        } catch (DomainException | InvalidArgumentException $error) {
            return $this->failure($error->getMessage(), $problemId, $userId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                '퀴즈 답안을 제출하지 못했습니다.',
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
            ? '/quizzes/' . $problemId . '/attempt'
            : '/quizzes';

        return $this->responses->redirect(
            $path,
            new UiMessage(
                'quizzes.attempt_submit_failed',
                MessageTone::Danger,
                '퀴즈 제출 실패',
                $message
            ),
            $userId
        );
    }
}
