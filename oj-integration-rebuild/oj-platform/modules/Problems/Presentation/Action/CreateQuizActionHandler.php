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

final class CreateQuizActionHandler implements ActionHandler
{
    private const ACTION = 'oj_quiz_create';

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
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $userId = $actor->userId();
            $problem = new ProblemId($problemId);
            $this->quizzes->createDraft(
                $userId,
                $problem,
                $this->requests->definition($request)
            );

            return $this->responses->redirect(
                '/oj-console/quizzes/' . $problemId . '/edit',
                new UiMessage(
                    'quizzes.created',
                    MessageTone::Success,
                    '퀴즈 초안 생성 완료',
                    '문항을 검토한 뒤 새 버전으로 게시할 수 있습니다.'
                ),
                $userId
            );
        } catch (DomainException | InvalidArgumentException $error) {
            return $this->failure($error->getMessage(), $problemId, $userId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                '퀴즈 초안을 만들지 못했습니다.',
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
        $path = '/oj-console/quizzes/new';
        if ($problemId > 0) {
            $path .= '?problem_id=' . $problemId;
        }

        return $this->responses->redirect(
            $path,
            new UiMessage(
                'quizzes.create_failed',
                MessageTone::Danger,
                '퀴즈 초안 생성 실패',
                $message
            ),
            $userId
        );
    }
}
