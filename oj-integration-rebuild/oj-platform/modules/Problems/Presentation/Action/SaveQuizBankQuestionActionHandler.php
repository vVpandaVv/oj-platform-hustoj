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
use CodingPanda\OjPlatform\Modules\Problems\Presentation\QuizRequestFactory;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use InvalidArgumentException;
use Throwable;

final class SaveQuizBankQuestionActionHandler implements ActionHandler
{
    private const ACTION = 'oj_quiz_bank_save';

    public function __construct(
        private readonly QuizBankService $bank,
        private readonly QuizRequestFactory $requests,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        $bankId = $request->bodyInt('bank_id') > 0 ? $request->bodyInt('bank_id') : null;
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $userId = $actor->userId();
            $item = $this->bank->save(
                $userId,
                $bankId,
                $this->requests->bankQuestion($request)
            );

            return $this->responses->redirect(
                '/oj-console/quiz-bank/' . $item->id() . '/edit',
                new UiMessage(
                    'quiz_bank.saved',
                    MessageTone::Success,
                    '문항 은행 저장 완료',
                    '문항 은행에 저장했습니다.'
                ),
                $userId
            );
        } catch (DomainException | InvalidArgumentException $error) {
            return $this->failure($error->getMessage(), $bankId, $userId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure('문항을 저장하지 못했습니다.', $bankId, $userId);
        }
    }

    private function failure(
        string $message,
        ?int $bankId,
        int $userId
    ): HttpResponse {
        $path = $bankId === null
            ? '/oj-console/quiz-bank/new'
            : '/oj-console/quiz-bank/' . $bankId . '/edit';

        return $this->responses->redirect(
            $path,
            new UiMessage(
                'quiz_bank.save_failed',
                MessageTone::Danger,
                '문항 은행 저장 실패',
                $message
            ),
            $userId
        );
    }
}
