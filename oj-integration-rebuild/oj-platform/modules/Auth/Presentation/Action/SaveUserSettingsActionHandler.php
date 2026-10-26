<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Action;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserSettingsInput;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionLanguageAvailability;
use CodingPanda\OjPlatform\Ui\Editor\AceEditorThemeCatalog;
use CodingPanda\OjPlatform\Ui\Message\MessageTone;
use CodingPanda\OjPlatform\Ui\Message\UiMessage;
use DomainException;
use Throwable;

final class SaveUserSettingsActionHandler implements ActionHandler
{
    private const ACTION = 'oj_save_user_settings';

    public function __construct(
        private readonly CurrentAccountService $accounts,
        private readonly UserAccountService $accountService,
        private readonly SubmissionLanguageAvailability $languages,
        private readonly ActionGuard $guard,
        private readonly ActionResponder $responses
    ) {
    }

    public function handle(HttpRequest $request): HttpResponse
    {
        $identity = $this->accounts->current()->identity();
        if ($identity === null) {
            return HttpResponse::redirect('/login');
        }

        $mode = $request->bodyString('mode');
        $isRankingVisibility = $mode === 'ranking-visibility';
        try {
            $this->guard->verifyNonce(self::ACTION, $request);
            if ($isRankingVisibility) {
                $this->accountService->updateRankingVisibility(
                    $identity->userId(),
                    $request->bodyString('ranking_visibility', 'members')
                );
            } else {
                $input = $this->settingsInput($request);
                if ($mode === 'profile-completion') {
                    $this->accountService->completeProfile(
                        $identity->userId(),
                        $input
                    );
                } else {
                    $this->accountService->updateProfile(
                        $identity->userId(),
                        $input
                    );
                }
            }

            return $this->responses->redirect(
                $isRankingVisibility ? '/ranking' : '/user-settings',
                new UiMessage(
                    'auth.settings_saved',
                    MessageTone::Success,
                    '저장 완료',
                    $isRankingVisibility
                        ? '랭킹 공개 범위를 저장했습니다.'
                        : '프로필과 환경설정을 저장했습니다.'
                ),
                $identity->userId()
            );
        } catch (DomainException $error) {
            return $this->failure($identity->userId(), $isRankingVisibility, $error->getMessage());
        } catch (Throwable) {
            return $this->failure(
                $identity->userId(),
                $isRankingVisibility,
                $isRankingVisibility
                    ? '랭킹 공개 범위를 다시 확인해 주세요.'
                    : '입력한 계정 정보를 다시 확인해 주세요.'
            );
        }
    }

    private function settingsInput(HttpRequest $request): UserSettingsInput
    {
        return new UserSettingsInput(
            $request->bodyString('email'),
            $request->bodyString('display_name'),
            $request->bodyString('affiliation'),
            $request->bodyString('birth_date'),
            $request->bodyString('bio'),
            $request->bodyString('guardian_code'),
            $request->bodyString('profile_visibility', 'members'),
            $request->bodyString('activity_visibility', 'members'),
            $this->defaultLanguage($request),
            $request->bodyString(
                'editor_theme',
                AceEditorThemeCatalog::DEFAULT_THEME
            ),
            $request->bodyString('default_editor', 'code'),
            $request->bodyInt('editor_font_size', 16),
            $request->bodyString('code_template'),
            $request->bodyBool('inline_submission_panel')
        );
    }

    private function defaultLanguage(HttpRequest $request): ?int
    {
        $value = trim($request->bodyString('default_language'));
        if ($value === '') {
            return null;
        }
        if (preg_match('/^\d+$/D', $value) !== 1) {
            throw new DomainException('기본 제출 언어가 올바르지 않습니다.');
        }
        $languageId = (int) $value;
        if (! $this->languages->isAllowedForSite($languageId)) {
            throw new DomainException('기본 제출 언어가 올바르지 않습니다.');
        }

        return $languageId;
    }

    private function failure(
        int $userId,
        bool $rankingVisibility,
        string $message
    ): HttpResponse {
        return $this->responses->redirect(
            $rankingVisibility ? '/ranking' : '/user-settings',
            new UiMessage(
                'auth.settings_failed',
                MessageTone::Danger,
                '저장 실패',
                $message
            ),
            $userId
        );
    }
}
