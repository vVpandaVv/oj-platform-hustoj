<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupBulkMemberImportService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class GroupBulkMembersActionHandler implements ActionHandler
{
    private const ACTION = 'oj_group_bulk_members';

    public function __construct(
        private readonly GroupBulkMemberImportService $imports,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorUserId = 0;
        $groupId = $request->bodyInt('group_id');
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $actorUserId = $actor->userId();
            $result = $this->imports->enqueue(
                $actor->userId(),
                $groupId,
                $request->bodyString('members_csv')
            );
            return $this->responses->redirect(
                '/oj-console/jobs/' . $result->jobId(),
                new UiMessage(
                    'groups.member_import_registered',
                    MessageTone::Success,
                    '구성원 일괄 등록 작업 등록',
                    '구성원 등록 작업을 등록했습니다. 항목별 결과를 확인해 주세요.'
                ),
                $actorUserId
            );
        } catch (DomainException $error) {
            return $this->responses->redirect(
                '/oj-console/groups/' . $groupId . '/bulk-members',
                new UiMessage(
                    'groups.member_import_rejected',
                    MessageTone::Danger,
                    '구성원 일괄 등록 작업 거부',
                    $error->getMessage()
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/groups/' . $groupId . '/bulk-members',
                new UiMessage(
                    'groups.member_import_failed',
                    MessageTone::Danger,
                    '구성원 일괄 등록 작업 실패',
                    '작업을 등록하지 못했습니다. 잠시 뒤 다시 시도해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}
