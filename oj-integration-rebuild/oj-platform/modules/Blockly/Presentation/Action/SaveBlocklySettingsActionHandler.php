<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Blockly\Presentation\Action;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklySettingsService;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use Throwable;

final class SaveBlocklySettingsActionHandler implements ActionHandler
{
    private const ACTION = 'oj_save_blockly_settings';

    public function __construct(
        private readonly BlocklySettingsService $settings,
        private readonly ActionAccessGuard $guard,
        private readonly ActionResponse $responses,
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
                'oj_manage_submission_settings'
            );
            $actorUserId = $actor->userId();
            $this->settings->save(
                $request->bodyBool('enabled'),
                strtolower(trim(
                    $request->bodyString('default_profile', 'standard')
                )),
                $request->bodyInt('maximum_workspace_kb', 512),
                $request->bodyInt('expected_revision')
            );

            return $this->responses->redirect(
                '/oj-console/submissions/block-coding',
                new UiMessage(
                    'blockly.settings_saved',
                    MessageTone::Success,
                    'Blockly 설정 저장 완료',
                    '새로 여는 블록 편집기에 설정을 적용합니다.'
                ),
                $actorUserId
            );
        } catch (Throwable $error) {
            $this->errors->report($error);

            return $this->responses->redirect(
                '/oj-console/submissions/block-coding',
                new UiMessage(
                    'blockly.settings_failed',
                    MessageTone::Danger,
                    'Blockly 설정 저장 실패',
                    $error instanceof \DomainException
                        || $error instanceof \InvalidArgumentException
                            ? $error->getMessage()
                            : '설정 또는 Blockly 파일 상태가 변경되었습니다. 화면을 새로 고친 뒤 다시 저장해 주세요.'
                ),
                $actorUserId
            );
        }
    }
}
