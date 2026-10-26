<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Classes\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class ClassLifecycleActionHandler implements ActionHandler
{
    private const ACTION = 'oj_class_lifecycle_command';

    public function __construct(
        private readonly ClassService $classes,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorId = 0;
        $operation = $request->bodyString('operation');
        try {
            if (! in_array($operation, ['archive', 'restore'], true)) {
                throw new DomainException('정의되지 않은 수업 보관 작업입니다.');
            }
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorId = $actor->userId();
            $this->classes->setArchived(
                $actorId,
                $request->bodyInt('class_id'),
                $operation === 'archive'
            );

            return $this->responses->redirect(
                $operation === 'archive'
                    ? '/oj-console/classes'
                    : '/oj-console/classes/deleted',
                new UiMessage(
                    'classes.lifecycle_saved',
                    MessageTone::Success,
                    $operation === 'archive' ? '수업 보관 완료' : '수업 복원 완료',
                    '수업 관계와 학습 기록은 그대로 유지됩니다.'
                ),
                $actorId
            );
        } catch (DomainException $error) {
            return $this->failed($operation, $error->getMessage(), $actorId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failed(
                $operation,
                '수업 상태를 변경하지 못했습니다.',
                $actorId
            );
        }
    }

    private function failed(
        string $operation,
        string $message,
        int $actorId
    ): HttpResponse {
        return $this->responses->redirect(
            $operation === 'restore'
                ? '/oj-console/classes/deleted'
                : '/oj-console/classes',
            new UiMessage(
                'classes.lifecycle_failed',
                MessageTone::Danger,
                '수업 상태 변경 실패',
                $message
            ),
            $actorId
        );
    }
}
