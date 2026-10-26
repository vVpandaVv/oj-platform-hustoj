<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Settings\Presentation\Console;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCandidateService;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderCatalog;
use CodingPanda\OjPlatform\Modules\Settings\Application\AiProviderSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Domain\AiProviderProfile;
use DomainException;

final class AiProviderSettingsPageHandler implements RouteHandler
{
    public function __construct(
        private readonly ConsoleResponder $pages,
        private readonly AiProviderCatalog $catalog,
        private readonly AiProviderSettingsService $settings,
        private readonly AiProviderCandidateService $candidates,
        private readonly CurrentAccountProvider $accounts,
        private readonly ActionUrlProvider $actionUrls,
        private readonly ActionSecurity $security
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        unset($route);
        $snapshot = $this->settings->snapshot();
        $profileKey = trim($request->queryString('profile_key'));
        $selectedProfile = $profileKey !== ''
            ? $this->settings->find($profileKey)
            : null;
        $copySourceKey = array_key_exists('copy_from', $request->query())
            ? trim($request->queryString('copy_from'))
            : null;
        $copySource = $copySourceKey !== null && $copySourceKey !== ''
            ? $this->settings->find($copySourceKey)
            : null;
        if ($copySourceKey !== null && ! $copySource instanceof AiProviderProfile) {
            throw new DomainException('복제할 원본 AI 연결 프로필을 찾을 수 없습니다.');
        }
        if ($copySource !== null && $selectedProfile !== null) {
            throw new DomainException('복제할 프로필에는 사용하지 않는 새 프로필 키를 입력해 주세요.');
        }
        $connectionProfile = $copySource ?? $selectedProfile;
        $definitions = $this->catalog->all();
        $selectedProviderKey = $connectionProfile?->providerKey()
            ?? $definitions[0]->key();
        $selectedDefinition = $this->catalog->get($selectedProviderKey);
        $actorUserId = $this->accounts->current()->identity()?->userId() ?? 0;
        $proof = $profileKey !== '' && $actorUserId > 0
            ? $this->candidates->proofFor($profileKey, $actorUserId)
            : null;

        $providerOptions = '';
        $examples = '';
        foreach ($definitions as $definition) {
            $providerOptions .= '<option value="'
                . OperationsHtml::escape($definition->key())
                . '" data-default-base-url="'
                . OperationsHtml::escape($definition->defaultBaseUrl())
                . '" data-default-model="'
                . OperationsHtml::escape($definition->defaultModel())
                . '" data-authentication-methods="'
                . OperationsHtml::escape(implode(',', $definition->authenticationMethods()))
                . '"' . OperationsHtml::selected(
                    $definition->key(),
                    $selectedProviderKey
                ) . '>' . OperationsHtml::escape($definition->label()) . '</option>';
            $examples .= '<section class="ojp-provider-example" data-ojp-provider-example="'
                . OperationsHtml::escape($definition->key()) . '"'
                . ($definition->key() === $selectedProviderKey ? '' : ' hidden')
                . '><h3>' . OperationsHtml::escape($definition->label())
                . '</h3><div class="ojp-two-column"><pre><code>'
                . OperationsHtml::escape($definition->configurationExample())
                . '</code></pre><pre><code>'
                . OperationsHtml::escape($definition->requestExample())
                . '</code></pre></div></section>';
        }

        $selectedTasks = $copySource !== null ? [] : ($selectedProfile?->allowedTaskTypes()
            ?? $this->catalog->allTaskKeys());
        $taskChecks = '';
        foreach ($this->catalog->allTaskKeys() as $taskType) {
            $badge = $this->catalog->isAddonTask($taskType)
                ? ' <span class="ojp-badge">애드온</span>'
                : '';
            $taskChecks .= '<label><input type="checkbox" name="task_types[]" value="'
                . OperationsHtml::escape($taskType) . '"'
                . OperationsHtml::checked(in_array($taskType, $selectedTasks, true))
                . '> ' . OperationsHtml::escape($this->catalog->taskLabel($taskType))
                . $badge . '</label>';
        }

        $profileRows = '';
        foreach ($this->settings->profiles() as $profile) {
            $profileRows .= '<tr><td><strong>'
                . OperationsHtml::escape($profile->name()) . '</strong><br><code>'
                . OperationsHtml::escape($profile->key()) . '</code></td><td>'
                . OperationsHtml::escape($profile->providerKey()) . '</td><td>'
                . OperationsHtml::escape($profile->model()) . '</td><td>'
                . OperationsHtml::escape(implode(', ', $profile->allowedTaskTypes()))
                . '</td><td><span class="ojp-badge ojp-badge--success">검사 완료</span></td>'
                . '<td><a class="ojp-button ojp-button--small" href="'
                . '/oj-console/settings/ai-provider?profile_key='
                . rawurlencode($profile->key()) . '">수정</a> '
                . '<a class="ojp-button ojp-button--small" href="/oj-console/settings/ai-provider?copy_from='
                . rawurlencode($profile->key()) . '">복제</a></td></tr>';
        }
        if ($profileRows === '') {
            $profileRows = '<tr><td colspan="6">등록된 AI 연결 프로필이 없습니다.</td></tr>';
        }

        $savePanel = $proof === null
            ? ''
            : '<div class="ojp-notice ojp-notice--success"><strong>후보 검사 완료</strong>'
                . '<p>15분 안에 검사한 동일 후보를 저장할 수 있습니다.</p></div>'
                . '<form method="post" action="'
                . OperationsHtml::escape($this->actionUrls->actionUrl()) . '">'
                . $this->security->hiddenFields('oj_save_ai_provider_settings')
                . '<input type="hidden" name="operation" value="save">'
                . '<input type="hidden" name="profile_key" value="'
                . OperationsHtml::escape($profileKey) . '">'
                . '<input type="hidden" name="expected_revision" value="'
                . $snapshot->revision() . '">'
                . '<button class="ojp-button ojp-button--primary" type="submit">'
                . '검사한 프로필 저장</button></form>';

        $baseUrl = $connectionProfile?->baseUrl()
            ?? $selectedDefinition->defaultBaseUrl();
        $model = $connectionProfile?->model()
            ?? $selectedDefinition->defaultModel();
        $authentication = $connectionProfile?->authenticationMethod()
            ?? $selectedDefinition->authenticationMethods()[0];
        $authenticationOptions = '';
        foreach ($selectedDefinition->authenticationMethods() as $method) {
            $authenticationOptions .= '<option value="'
                . OperationsHtml::escape($method) . '"'
                . OperationsHtml::selected($method, $authentication) . '>'
                . ($method === 'bearer' ? 'Bearer' : 'X-API-Key')
                . '</option>';
        }

        $content = OperationsHtml::header(
            '공통 AI 기반',
            'AI 연결 프로필',
            '연결과 모델을 검사한 뒤 문제 생성·테스트케이스·피드백 작업에 재사용합니다.'
        )
            . '<section class="ojp-section"><div class="ojp-table-wrap"><table class="ojp-table">'
            . '<thead><tr><th>프로필</th><th>Provider</th><th>모델</th><th>허용 작업</th>'
            . '<th>상태</th><th>관리</th></tr></thead><tbody>'
            . $profileRows . '</tbody></table></div></section>'
            . '<div class="ojp-two-column"><section class="ojp-section">'
            . ($copySource !== null
                ? '<div class="ojp-notice ojp-notice--info"><strong>AI 연결 프로필 복제</strong><p>'
                    . OperationsHtml::escape($copySource->name())
                    . '의 연결과 모델로 새 프로필을 만듭니다. 기존 프로필과 작업 배정은 유지합니다. '
                    . '새 프로필 키와 이름을 입력하고 필요한 작업만 선택해 검사해 주세요.</p></div>'
                : '')
            . '<form class="ojp-form-stack"'
            . ' method="post" action="' . OperationsHtml::escape($this->actionUrls->actionUrl())
            . '" data-ojp-provider-form>'
            . $this->security->hiddenFields('oj_save_ai_provider_settings')
            . ($copySource !== null ? '<input type="hidden" name="copy_from" value="'
                . OperationsHtml::escape($copySource->key()) . '">' : '')
            . '<div class="ojp-form-grid">'
            . $this->field(
                'profile_key',
                '프로필 키',
                $profileKey,
                true,
                'text',
                $selectedProfile instanceof AiProviderProfile
            )
            . $this->field(
                'profile_name',
                '표시 이름',
                $selectedProfile?->name() ?? '',
                true
            )
            . '<label class="ojp-field"><span class="ojp-label">Provider</span>'
            . '<select class="ojp-select" name="provider_key" data-ojp-provider-select>'
            . $providerOptions . '</select></label>'
            . $this->field(
                'base_url',
                'Base URL',
                $baseUrl,
                true,
                'url',
                false,
                'data-ojp-provider-base-url'
            )
            . '<label class="ojp-field"><span class="ojp-label">인증 방식</span>'
            . '<select class="ojp-select" name="authentication_method"'
            . ' data-ojp-provider-authentication>' . $authenticationOptions
            . '</select></label>'
            . $this->field('secret', 'API 비밀값', '', false, 'password')
            . $this->field(
                'model',
                '모델 ID',
                $model,
                true,
                'text',
                false,
                'data-ojp-provider-model'
            )
            . $this->field(
                'timeout_seconds',
                '전체 제한 시간(초)',
                (string) ($connectionProfile?->timeoutSeconds() ?? 60),
                true,
                'number'
            )
            . '</div><div class="ojp-check-grid">' . $taskChecks . '</div>'
            . '<label><input type="checkbox" name="allow_submission_source" value="1" aria-describedby="ai-source-policy"'
            . OperationsHtml::checked(
                $copySource === null && ($selectedProfile?->allowsSubmissionSource() ?? false)
            ) . '> 이 프로필에 제출 코드 전송을 승인합니다</label>'
            . '<p class="ojp-field-help" id="ai-source-policy">자체 서버·외부 AI 모두 기본 차단입니다. 제출 AI 피드백 또는 제출 코드 사용 애드온 작업을 선택하고,'
            . ' 전송 대상과 데이터 보관 정책을 확인한 뒤 승인해 주세요. 연결이나 모델을 바꾸면 다시 승인해야 합니다.</p>'
            . '<p class="ojp-field-help">비밀값을 비우면 연결 대상·인증 방식이 같은 '
            . ($copySource !== null ? '원본' : '현재') . ' 프로필의 값을 서버에서만 사용합니다.</p>'
            . '<div class="ojp-form-actions ojp-inline-actions"><button class="ojp-button ojp-button--primary" type="submit" name="operation" value="test">후보 연결 검사</button>'
            . '<button class="ojp-button" type="submit" name="operation" value="reset_probe" formnovalidate>검사 대기 초기화</button></div>'
            . '<p class="ojp-field-help">같은 관리자·프로필의 검사는 한 번에 하나만 실행합니다. 완료 후 10초 뒤 재검사할 수 있으며,'
            . ' 초기화하면 대기를 해제합니다. 진행 중인 검사와 AI 서버 자체 요청 한도는 초기화하지 않습니다.</p></form>'
            . $savePanel . '</section><aside class="ojp-section"><h2>Provider 입력 예시</h2>'
            . '<p>선택한 Provider에 맞는 주소·인증·통신 예시가 표시됩니다.</p>'
            . $examples . '</aside></div>';

        return $this->pages->respond(
            'AI 연결 프로필',
            $content,
            'operations.ai_provider',
            $request
        );
    }

    private function field(
        string $name,
        string $label,
        string $value,
        bool $required = false,
        string $type = 'text',
        bool $readonly = false,
        string $dataAttribute = ''
    ): string {
        return '<label class="ojp-field"><span class="ojp-label">'
            . OperationsHtml::escape($label) . '</span><input class="ojp-input" type="'
            . OperationsHtml::escape($type) . '" name="' . OperationsHtml::escape($name)
            . '" value="' . OperationsHtml::escape($value) . '"'
            . ($required ? ' required' : '')
            . ($readonly ? ' readonly' : '')
            . ($dataAttribute !== '' ? ' ' . $dataAttribute : '')
            . '></label>';
    }
}
