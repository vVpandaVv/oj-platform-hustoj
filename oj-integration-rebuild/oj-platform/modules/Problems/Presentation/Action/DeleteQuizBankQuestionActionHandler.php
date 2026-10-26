<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Problems\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Problems\Application\QuizBankService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class DeleteQuizBankQuestionActionHandler implements ActionHandler
{
    private const ACTION = 'oj_quiz_bank_delete';

    public function __construct(
        private readonly QuizBankService $bank,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        $bankId = max(0, $request->bodyInt('bank_id'));
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $userId = $actor->userId();
            $this->bank->delete($userId, $bankId);

            return $this->responses->redirect(
                '/oj-console/quiz-bank',
                new UiMessage(
                    'quiz_bank.deleted',
                    MessageTone::Success,
                    '문항 은행 삭제 완료',
                    '문항 은행에서 이 문항을 제거했습니다. 이미 가져다 쓴 퀴즈는 영향받지 않습니다.'
                ),
                $userId
            );
        } catch (DomainException $error) {
            return $this->failure($error->getMessage(), $userId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure('문항을 삭제하지 못했습니다.', $userId);
        }
    }

    private function failure(string $message, int $userId): HttpResponse
    {
        return $this->responses->redirect(
            '/oj-console/quiz-bank',
            new UiMessage(
                'quiz_bank.delete_failed',
                MessageTone::Danger,
                '문항 은행 삭제 실패',
                $message
            ),
            $userId
        );
    }
}
