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
use CodingPanda\OjPlatform\Modules\Problems\Presentation\ProblemBookRequestFactory;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class SaveProblemBookActionHandler implements ActionHandler
{
    private const ACTION = 'oj_problem_book_save_category';

    public function __construct(
        private readonly ProblemBookService $books,
        private readonly ProblemBookRequestFactory $requests,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        $bookId = max(0, $request->bodyInt('book_id'));
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $userId = $actor->userId();
            $input = $this->requests->book($request);
            $book = $this->books->save(
                $userId,
                $input['book_id'],
                $input['parent_id'],
                $input['name'],
                $input['slug'],
                $input['description'],
                $input['visibility'],
                $input['sort_order'],
                $input['problem_ids']
            );

            return $this->responses->redirect(
                '/oj-console/problem-books?book_id=' . $book->id(),
                new UiMessage(
                    'problem_books.saved',
                    MessageTone::Success,
                    '문제집 저장 완료',
                    '공용 문제집의 정보와 문제 구성을 저장했습니다.'
                ),
                $userId
            );
        } catch (DomainException | InvalidArgumentException $error) {
            return $this->failure($error->getMessage(), $bookId, $userId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure(
                '공용 문제집을 저장하지 못했습니다.',
                $bookId,
                $userId
            );
        }
    }

    private function failure(string $message, int $bookId, int $userId): HttpResponse
    {
        $path = '/oj-console/problem-books'
            . ($bookId > 0 ? '?book_id=' . $bookId : '');

        return $this->responses->redirect(
            $path,
            new UiMessage(
                'problem_books.save_failed',
                MessageTone::Danger,
                '문제집 저장 실패',
                $message
            ),
            $userId
        );
    }
}
