<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\ProblemBookService;
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class AddPersonalProblemBookActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_book_personal_add';

    public function __construct(
        private readonly ProblemBookService $books,
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
            $this->books->addPersonal(
                $userId,
                new ProblemId($problemId),
                $request->bodyString('note')
            );

            return $this->responses->redirect(
                '/problems/' . $problemId,
                new UiMessage(
                    'problem_books.personal_added',
                    MessageTone::Success,
                    '내 문제집에 추가 완료',
                    '문제집 화면에서 저장한 문제를 확인할 수 있습니다.'
                ),
                $userId
            );
        } catch (DomainException | InvalidArgumentException $error) {
            return $this->failure($error->getMessage(), $problemId, $userId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                '내 문제집에 추가하지 못했습니다.',
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
        return $this->responses->redirect(
            $problemId > 0 ? '/problems/' . $problemId : '/problems',
            new UiMessage(
                'problem_books.personal_add_failed',
                MessageTone::Danger,
                '내 문제집 추가 실패',
                $message
            ),
            $userId
        );
    }
}
