<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserBulkImportService;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class ExecuteUserImportActionHandler implements ActionHandler
{
    private const ACTION = 'oj_console_bulk_execute_users';

    public function __construct(
        private readonly UserBulkImportService $imports,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        try {
            $actor = $this->guard->actor(
                self::ACTION,
                $request,
                'oj_create_users'
            );
            $actorUserId = $actor->userId();
            $result = $this->imports->enqueue(
                $request->bodyString('draft_reference'),
                $actorUserId
            );

            return $this->responses->redirect(
                '/oj-console/jobs/' . $result->jobId(),
                new UiMessage(
                    'auth.user_import_registered',
                    MessageTone::Success,
                    '일괄 등록 작업 등록',
                    '사용자 일괄 등록 작업을 등록했습니다.'
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                '/oj-console/users/bulk-import',
                new UiMessage(
                    'auth.user_import_rejected',
                    MessageTone::Danger,
                    '일괄 등록 작업 실패',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/users/bulk-import',
                new UiMessage(
                    'auth.user_import_failed',
                    MessageTone::Danger,
                    '일괄 등록 작업 실패',
                    '작업을 등록하지 못했습니다. 잠시 뒤 다시 시도해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}
