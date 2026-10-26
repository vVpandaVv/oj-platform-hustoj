<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\JudgeDb\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\JudgeDb\Application\JudgeConnectionCommandService;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettings;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeCredentials;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use RuntimeException;
use Throwable;

final class SaveJudgeDatabaseSettingsActionHandler implements ActionHandler
{
    public function __construct(
        private readonly JudgeConnectionCommandService $settings,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
        private readonly ErrorReporter $errors
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $userId = 0;
        try {
            $actor = $this->guard->actor(
                'oj_save_judge_db_settings',
                $request,
                'oj_manage_settings'
            );
            $userId = $actor->userId();
            $operation = trim($request->bodyString('operation'));
            if ($operation === 'test') {
                $this->guard->throttle('judge_database_probe', (string) $userId, 5, 900);
                $current = $this->settings->current();
                $readPassword = $request->bodyString('read_password');
                $writePassword = $request->bodyString('write_password');
                if ($readPassword === '' && $current !== null) {
                    $readPassword = $current->readCredentials()->password();
                }
                if ($writePassword === '' && $current !== null) {
                    $writePassword = $current->writeCredentials()->password();
                }
                $candidate = new JudgeConnectionSettings(
                    trim($request->bodyString('host')),
                    $request->bodyInt('port', 3306),
                    trim($request->bodyString('database', 'jol')),
                    new JudgeCredentials(
                        trim($request->bodyString('read_username')),
                        $readPassword
                    ),
                    new JudgeCredentials(
                        trim($request->bodyString('write_username')),
                        $writePassword
                    ),
                    $request->bodyBool('tls_enabled'),
                    ($caPath = trim($request->bodyString('tls_ca_path'))) !== ''
                        ? $caPath
                        : null,
                    $request->bodyBool('utc_storage_confirmed')
                );
                $this->settings->testCandidate(
                    $candidate,
                    $userId,
                    $request->bodyInt('expected_probe_revision')
                );
                $message = new UiMessage(
                    'judge_database.test_succeeded',
                    MessageTone::Success,
                    'Judge DB 후보 검사 완료',
                    '필수 채점 테이블과 적용 시 생성할 problem 확장 필드를 확인했습니다.'
                );
            } elseif ($operation === 'apply') {
                $this->settings->applyTestedCandidate(
                    $userId,
                    $request->bodyInt('expected_revision')
                );
                $message = new UiMessage(
                    'judge_database.applied',
                    MessageTone::Success,
                    'Judge DB 연결 적용 완료',
                    '확장 필드 준비를 확인했으며 새 요청부터 검사한 HUSTOJ 연결을 사용합니다.'
                );
            } else {
                throw new DomainException('Judge DB 처리 방식이 올바르지 않습니다.');
            }

            return $this->responses->redirect(
                '/oj-console/settings/database',
                $message,
                $userId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/settings/database',
                new UiMessage(
                    'judge_database.failed',
                    MessageTone::Danger,
                    'Judge DB 연결 처리 실패',
                    $error instanceof DomainException
                    || $error instanceof \InvalidArgumentException
                    || $error instanceof RuntimeException
                        ? $error->getMessage()
                        : '현재 운영 연결은 유지했습니다. 후보 설정을 확인해 주세요.'
                ),
                $userId
            );
        }
    }
}
