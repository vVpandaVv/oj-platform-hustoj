<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Auth\Presentation\Route;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Application\PublicProfileSectionRegistry;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserDirectoryService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Domain\PublicProfileSection;
use CodingPanda\OjPlatform\Modules\Auth\Domain\UserPreferences;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;

final class UserProfilePageHandler implements RouteHandler
{
    public function __construct(
        private readonly PageResponder $pages,
        private readonly CurrentAccountService $accounts,
        private readonly UserDirectoryService $directory,
        private readonly UserPreferenceRepository $preferences,
        private readonly PublicProfileSectionRegistry $sections,
        private readonly RouteUrlProvider $routes,
        private readonly Authorization $permissions,
        private readonly FeatureFlags $features,
        private readonly SiteDateFormatter $dates
    ) {
    }

    public function handle(RouteMatch $route, HttpRequest $request): HttpResponse
    {
        $rawUserId = $route->parameters()['user_id'] ?? '';
        if (preg_match('/^\d+$/', $rawUserId) !== 1) {
            return $this->notFound($request);
        }

        $target = $this->directory->find((int) $rawUserId);
        if ($target === null) {
            return $this->notFound($request);
        }

        $currentUserId = $this->accounts->current()->identity()?->userId() ?? 0;
        $targetUserId = $target->identity()->userId();
        $isOwner = $currentUserId === $targetUserId;
        $canManageUsers = $this->permissions->can($currentUserId, 'oj_manage_users');
        if (
            ! $isOwner
            && ! $canManageUsers
            && $target->profile()->accessStatus() !== AccessStatus::Active
        ) {
            return $this->notFound($request);
        }

        $preference = $this->preferences->find($targetUserId)
            ?? new UserPreferences($targetUserId);
        if (
            ! $isOwner
            && ! $canManageUsers
            && $preference->profileVisibility() === 'hidden'
        ) {
            return $this->pages->authenticated(
                '비공개 프로필',
                $this->emptyState('공개하지 않은 프로필입니다.'),
                $request,
                403
            );
        }

        $identity = $target->identity();
        $profile = $target->profile();
        $canMessage = ! $isOwner
            && $this->featureEnabled('messaging')
            && $profile->accessStatus() === AccessStatus::Active;
        $content = $this->profileHeader(
            $identity->displayName(),
            $identity->userLogin(),
            $identity->registeredAt(),
            $isOwner,
            $canMessage ? $targetUserId : null
        );
        if ($preference->profileVisibility() === 'hidden') {
            $content .= '<div class="ojp-notice ojp-notice--info"><strong>비공개 프로필</strong>'
                . '<p>현재 프로필은 본인과 사용자 운영자에게만 표시됩니다.</p></div>';
        }
        $content .= $this->basicInformation(
            $profile->affiliation(),
            $profile->bio(),
            ($isOwner || $canManageUsers) ? $profile->accessStatus() : null
        );

        if (($isOwner || $canManageUsers) && $preference->activityVisibility() === 'hidden') {
            $content .= '<div class="ojp-notice ojp-notice--info"><strong>학습 활동 비공개</strong>'
                . '<p>학습 통계와 최근 해결 문제는 다른 사용자에게 표시되지 않습니다.</p></div>';
        }

        $showActivity = $isOwner
            || $canManageUsers
            || $preference->activityVisibility() === 'members';
        if ($showActivity) {
            foreach ($this->sections->sections(
                $currentUserId,
                $targetUserId,
                $identity->userLogin()
            ) as $section) {
                $content .= $this->section($section);
            }
        }

        return $this->pages->authenticated(
            $identity->displayName(),
            $content,
            $request
        );
    }

    private function profileHeader(
        string $displayName,
        string $userLogin,
        ?string $registeredAt,
        bool $isOwner,
        ?int $messageRecipientUserId
    ): string {
        $action = $isOwner
            ? '<a class="ojp-button" href="'
                . $this->escape($this->routes->path('auth.settings'))
                . '">프로필 편집</a>'
            : ($messageRecipientUserId === null
                ? ''
                : '<a class="ojp-button" href="'
                    . $this->escape($this->routes->path('messaging.compose', ['user_id' => $messageRecipientUserId]))
                    . '"><span aria-hidden="true">✉️</span> 쪽지 보내기</a>');
        $joined = $this->dateOnly($registeredAt);

        return '<header class="ojp-page-header ojp-profile-header"><div class="ojp-profile-identity">'
            . '<span class="ojp-profile-avatar" aria-hidden="true">'
            . $this->escape($this->initial($displayName)) . '</span><div>'
            . '<p class="ojp-eyebrow">사용자 프로필</p><h1>'
            . $this->escape($displayName) . '</h1><p>@'
            . $this->escape($userLogin)
            . ($joined === '' ? '' : ' · 가입 ' . $this->escape($joined))
            . '</p></div></div>' . $action . '</header>';
    }

    private function basicInformation(
        ?string $affiliation,
        ?string $bio,
        ?AccessStatus $status
    ): string {
        $items = [
            ['소속', trim((string) $affiliation) === '' ? '공개된 정보 없음' : (string) $affiliation],
        ];
        if ($status !== null) {
            $items[] = [
                '이용 상태',
                $status === AccessStatus::Active ? '이용 가능' : '이용 제한',
            ];
        }
        $details = '';
        foreach ($items as [$label, $value]) {
            $details .= '<div><dt>' . $this->escape($label) . '</dt><dd>'
                . $this->escape($value) . '</dd></div>';
        }
        $bioText = trim((string) $bio);

        return '<section class="ojp-section"><div class="ojp-section-heading"><div>'
            . '<h2>프로필</h2><p>사용자가 공개한 기본 정보입니다.</p></div></div>'
            . '<dl class="ojp-detail-list">' . $details . '</dl>'
            . ($bioText === '' ? '' : '<div class="ojp-profile-bio"><h3>소개</h3><p>'
                . nl2br($this->escape($bioText)) . '</p></div>')
            . '</section>';
    }

    private function section(PublicProfileSection $section): string
    {
        $metrics = '';
        foreach ($section->metrics() as $metric) {
            $metrics .= '<div class="ojp-metric"><span>'
                . $this->escape($metric->label()) . '</span><strong>'
                . $this->escape($metric->value()) . '</strong></div>';
        }
        $metricClass = count($section->metrics()) === 4
            ? 'ojp-metric-grid ojp-metric-grid--four'
            : 'ojp-metric-grid';
        $activities = '';
        $highlights = '';
        foreach ($section->highlights() as $highlight) {
            $highlights .= '<span class="ojp-badge">' . $this->escape($highlight) . '</span>';
        }
        if ($highlights !== '') {
            $highlights = '<div class="ojp-profile-topics"><strong>최근 학습 주제</strong>'
                . '<div>' . $highlights . '</div></div>';
        }
        foreach ($section->activities() as $activity) {
            $activities .= '<tr><td><a href="' . $this->escape($activity->url())
                . '"><strong>' . $this->escape($activity->title()) . '</strong></a>'
                . ($activity->description() === '' ? '' : '<span class="ojp-table-note">'
                    . $this->escape($activity->description()) . '</span>')
                . '</td><td>' . $this->escape($this->dateOnly($activity->occurredAt()))
                . '</td></tr>';
        }
        $activityContent = $activities === ''
            ? '<div class="ojp-empty-state"><p>표시할 최근 해결 문제가 없습니다.</p></div>'
            : '<div class="ojp-table-wrap"><table class="ojp-table"><thead><tr>'
                . '<th>최근 해결 문제</th><th>해결일</th></tr></thead><tbody>'
                . $activities . '</tbody></table></div>';

        return '<section class="ojp-section"><div class="ojp-section-heading"><div><h2>'
            . $this->escape($section->title()) . '</h2><p>'
            . $this->escape($section->summary()) . '</p></div></div>'
            . ($metrics === '' ? '' : '<div class="' . $metricClass . '">' . $metrics . '</div>')
            . $highlights
            . $activityContent . '</section>';
    }

    private function featureEnabled(string $key): bool
    {
        try {
            return $this->features->isEnabled($key);
        } catch (\Throwable) {
            return false;
        }
    }

    private function notFound(HttpRequest $request): HttpResponse
    {
        return $this->pages->authenticated(
            '사용자 없음',
            $this->emptyState('사용자를 찾을 수 없습니다.'),
            $request,
            404
        );
    }

    private function emptyState(string $message): string
    {
        return '<div class="ojp-empty-state"><p>' . $this->escape($message) . '</p></div>';
    }

    private function initial(string $value): string
    {
        return preg_match('/^./u', trim($value), $match) === 1
            ? $match[0]
            : '?';
    }

    private function dateOnly(?string $value): string
    {
        $value = trim((string) $value);
        if ($value === '') {
            return '';
        }

        return $this->dates->format($value, 'Y-m-d');
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
