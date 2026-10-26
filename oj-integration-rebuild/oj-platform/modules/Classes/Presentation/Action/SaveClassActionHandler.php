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
use CodingPanda\OjPlatform\Modules\Classes\Presentation\ClassRequestFactory;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class SaveClassActionHandler implements ActionHandler
{
    private const ACTION = 'oj_class_save';

    public function __construct(
        private readonly ClassService $classes,
        private readonly ClassRequestFactory $requests,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorId = 0;
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorId = $actor->userId();
            $classId = $this->classes->save(
                $actorId,
                $this->requests->saveCommand($request)
            );

            return $this->responses->redirect(
                '/oj-console/classes/' . $classId . '/edit',
                new UiMessage(
                    'classes.saved',
                    MessageTone::Success,
                    '수업 저장 완료',
                    '수업 정보와 담당자, 그룹, 문제 연결을 함께 저장했습니다.'
                ),
                $actorId
            );
        } catch (DomainException $error) {
            return $this->failed($request, $error->getMessage(), $actorId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failed(
                $request,
                '수업을 저장하지 못했습니다.',
                $actorId
            );
        }
    }

    private function failed(
        HttpRequest $request,
        string $message,
        int $actorId
    ): HttpResponse {
        $classId = $request->bodyInt('class_id');

        return $this->responses->redirect(
            $classId > 0
                ? '/oj-console/classes/' . $classId . '/edit'
                : '/oj-console/classes/new',
            new UiMessage(
                'classes.save_failed',
                MessageTone::Danger,
                '수업 저장 실패',
                $message
            ),
            $actorId
        );
    }
}
