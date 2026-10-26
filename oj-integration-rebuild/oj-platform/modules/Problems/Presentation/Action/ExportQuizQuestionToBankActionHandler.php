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
use CodingPanda\OjPlatform\Modules\Problems\Domain\ProblemId;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class ExportQuizQuestionToBankActionHandler implements ActionHandler
{
    private const ACTION = 'oj_quiz_export_to_bank';

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
        $problemId = max(0, $request->bodyInt('problem_id'));
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $userId = $actor->userId();
            $this->bank->exportToBank(
                $userId,
                new ProblemId($problemId),
                $request->bodyString('question_key')
            );

            return $this->responses->redirect(
                '/oj-console/quizzes/' . $problemId . '/edit',
                new UiMessage(
                    'quiz_bank.exported',
                    MessageTone::Success,
                    '문항 은행 저장 완료',
                    '이 문항을 문항 은행에 저장했습니다.'
                ),
                $userId
            );
        } catch (DomainException $error) {
            return $this->failure($error->getMessage(), $problemId, $userId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failure('문항을 은행에 저장하지 못했습니다.', $problemId, $userId);
        }
    }

    private function failure(
        string $message,
        int $problemId,
        int $userId
    ): HttpResponse {
        return $this->responses->redirect(
            '/oj-console/quizzes/' . $problemId . '/edit',
            new UiMessage(
                'quiz_bank.export_failed',
                MessageTone::Danger,
                '문항 은행 저장 실패',
                $message
            ),
            $userId
        );
    }
}
