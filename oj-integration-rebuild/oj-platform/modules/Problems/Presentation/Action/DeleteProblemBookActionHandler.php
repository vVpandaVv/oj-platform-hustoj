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
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class DeleteProblemBookActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_book_delete_category';

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
            $this->books->delete($userId, $request->bodyInt('book_id'));

            return $this->responses->redirect(
                '/oj-console/problem-books',
                new UiMessage(
                    'problem_books.deleted',
                    MessageTone::Success,
                    '문제집 삭제 완료',
                    '공용 목록에서 문제집을 제외했습니다.'
                ),
                $userId
            );
        } catch (DomainException $error) {
            return $this->failure($error->getMessage(), $userId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure('공용 문제집을 삭제하지 못했습니다.', $userId);
        }
    }

    private function failure(string $message, int $userId): HttpResponse
    {
        return $this->responses->redirect(
            '/oj-console/problem-books',
            new UiMessage(
                'problem_books.delete_failed',
                MessageTone::Danger,
                '문제집 삭제 실패',
                $message
            ),
            $userId
        );
    }
}
