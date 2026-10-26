<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Groups\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Groups\Application\GroupMemberNamesService;
use DomainException;
use JsonException;
use Throwable;

final class SaveGroupMemberNamesActionHandler implements ActionHandler
{
    public const ACTION = 'oj_group_save_member_names';

    public function __construct(
        private readonly GroupMemberNamesService $names,
        private readonly ActionAccessGuard $guard,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        try {
            $actor = $this->guard->authenticatedActor(self::ACTION, $request);
            $payload = $request->bodyString('name_changes');
            if (strlen($payload) > 131072) {
                throw new DomainException('이름 변경 목록이 너무 큽니다.');
            }
            $changes = json_decode($payload, true, 8, JSON_THROW_ON_ERROR);
            if (! is_array($changes)) {
                throw new DomainException('이름 변경 목록이 올바르지 않습니다.');
            }

            return $this->json(['results' => $this->names->save($actor->userId(), $request->bodyInt('group_id'), $changes)]);
        } catch (JsonException) {
            return $this->json(['error' => '이름 변경 목록이 올바르지 않습니다.'], 400);
        } catch (DomainException $error) {
            return $this->json(['error' => $error->getMessage()], 403);
        } catch (Throwable $error) {
            $this->errors->report($error);
            return $this->json(['error' => '저장하지 못했습니다. 로그인 상태와 권한을 확인하고 다시 시도해 주세요.'], 500);
        }
    }

    private function json(array $body, int $status = 200): HttpResponse
    {
        return new HttpResponse($status, [
            'Content-Type' => 'application/json; charset=UTF-8',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ], json_encode($body, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }
}
