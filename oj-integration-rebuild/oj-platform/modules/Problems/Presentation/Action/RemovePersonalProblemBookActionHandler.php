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

final class RemovePersonalProblemBookActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_book_personal_remove';

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
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $userId = $actor->userId();
            $this->books->removePersonal(
                $userId,
                new ProblemId($request->bodyInt('problem_id'))
            );

            return $this->responses->redirect(
                '/problem-books',
                new UiMessage(
                    'problem_books.personal_removed',
                    MessageTone::Success,
                    '내 문제집에서 제거 완료',
                    '공용 문제집 구성에는 영향을 주지 않았습니다.'
                ),
                $userId
            );
        } catch (DomainException | InvalidArgumentException $error) {
            return $this->failure($error->getMessage(), $userId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                '내 문제집에서 제거하지 못했습니다.',
                $userId
            );
        }
    }

    private function failure(string $message, int $userId): HttpResponse
    {
        return $this->responses->redirect(
            '/problem-books',
            new UiMessage(
                'problem_books.personal_remove_failed',
                MessageTone::Danger,
                '내 문제집 제거 실패',
                $message
            ),
            $userId
        );
    }
}
