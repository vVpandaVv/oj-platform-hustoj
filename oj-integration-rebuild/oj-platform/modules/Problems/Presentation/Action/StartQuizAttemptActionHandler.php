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
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class StartQuizAttemptActionHandler implements ActionHandler
{
    private const ACTION = 'oj_quiz_start_attempt';

    public function __construct(
        private readonly QuizService $quizzes,
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
            $this->guard->throttle(
                'quiz_start',
                (string) $userId,
                20,
                60
            );
            $attempt = $this->quizzes->start(
                $userId,
                new ProblemId($problemId),
                strtolower(trim($request->bodyString('attempt_key')))
            );
            $path = $attempt->status() === 'graded'
                ? '/quizzes/' . $problemId . '/attempts/' . $attempt->id()
                : '/quizzes/' . $problemId . '/attempt';

            return $this->responses->redirect(
                $path,
                new UiMessage(
                    'quizzes.attempt_started',
                    MessageTone::Info,
                    '퀴즈 응시 시작',
                    '최종 제출 전까지 같은 응시를 계속할 수 있습니다.'
                ),
                $userId
            );
        } catch (DomainException | InvalidArgumentException $error) {
            return $this->failure($error->getMessage(), $problemId, $userId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                '퀴즈 응시를 시작하지 못했습니다.',
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
        $path = $problemId > 0 ? '/quizzes/' . $problemId : '/quizzes';

        return $this->responses->redirect(
            $path,
            new UiMessage(
                'quizzes.attempt_start_failed',
                MessageTone::Danger,
                '퀴즈 응시 시작 실패',
                $message
            ),
            $userId
        );
    }
}
