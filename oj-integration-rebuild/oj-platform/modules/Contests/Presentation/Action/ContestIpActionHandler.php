<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Contests\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Contests\Application\ContestService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class ContestIpActionHandler implements ActionHandler
{
    public function __construct(
        private readonly string $action,
        private readonly bool $admin,
        private readonly ContestService $contests,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $actorId = 0;
        $contestId = $request->bodyInt('contest_id');
        try {
            $actor = $this->guard->authenticatedActor($this->action, $request);
            $actorId = $actor->userId();
            if ($this->admin) {
                $operation = $request->bodyString('operation', 'save');
                if (in_array($operation, ['approve', 'reject'], true)) {
                    $this->contests->resolveAllowedIpChange(
                        $actorId,
                        $contestId,
                        $request->bodyInt('request_id'),
                        $operation === 'approve'
                    );
                    $event = $operation === 'approve' ? 'approved' : 'rejected';
                } else {
                    $targetId = $request->bodyInt('target_user_id');
                    $ip = trim($request->bodyString('ip_address'));
                    $this->contests->changeAllowedIp(
                        $actorId,
                        $contestId,
                        $targetId,
                        $ip === '' ? null : $ip,
                        true
                    );
                    $event = 'saved';
                }
            } else {
                $event = $this->contests->submitAllowedIpChange(
                    $actorId,
                    $contestId,
                    trim((string) $request->header('x-oj-client-ip')),
                    $request->bodyString('reason')
                );
            }
            [$title, $message] = match ($event) {
                'approved' => ['IP 변경 요청 승인', '요청한 IP를 허용 IP로 반영했습니다.'],
                'rejected' => ['IP 변경 요청 거절', 'IP 변경 요청을 거절했습니다.'],
                'requested' => ['IP 변경 요청 완료', '감독자에게 IP 변경 요청을 보냈습니다.'],
                'registered' => ['접속 IP 등록 완료', '현재 접속 IP를 등록했습니다.'],
                default => ['허용 IP 저장 완료', '대회 허용 IP를 저장했습니다.'],
            };

            return $this->responses->redirect(
                $this->path($contestId),
                new UiMessage(
                    'contests.ip_' . $event,
                    MessageTone::Success,
                    $title,
                    $message
                ),
                $actorId
            );
        } catch (DomainException $error) {
            return $this->failed($contestId, $error->getMessage(), $actorId);
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->failed(
                $contestId,
                'IP 요청을 처리하지 못했습니다.',
                $actorId
            );
        }
    }

    private function path(int $contestId): string
    {
        return $this->admin
            ? '/oj-console/contests/' . $contestId . '/ip-check'
            : '/contests/' . $contestId . '/ip-check';
    }

    private function failed(
        int $contestId,
        string $message,
        int $actorId
    ): HttpResponse {
        return $this->responses->redirect(
            $this->path($contestId),
            new UiMessage(
                'contests.ip_failed',
                MessageTone::Danger,
                'IP 요청 처리 실패',
                $message
            ),
            $actorId
        );
    }
}
