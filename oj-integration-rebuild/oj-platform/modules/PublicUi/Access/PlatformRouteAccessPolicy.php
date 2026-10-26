<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\PublicUi\Access;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AccessDecision;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteAccessPolicy;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\AuthenticationRequirement;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteArea;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AccessStatus;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PublicUi\Shell\PublicShell;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SystemOperationPolicy;
use Throwable;

final class PlatformRouteAccessPolicy implements RouteAccessPolicy
{
    /**
     * @param array<string, list<string>> $consoleCapabilities
     */
    public function __construct(
        private readonly CurrentAccountProvider $accounts,
        private readonly RegistrationPolicyProvider $registrationPolicy,
        private readonly FeatureFlags $features,
        private readonly Authorization $permissions,
        private readonly PublicShell $shell,
        private readonly ConsoleRouteAccessRuleRegistry $consoleRouteRules,
        private readonly array $consoleCapabilities,
        private readonly SystemOperationPolicy $operations
    ) {
    }

    public function decide(RouteMatch $route, HttpRequest $request): AccessDecision
    {
        $definition = $route->route();
        $account = $this->accounts->current();

        if (
            $definition->authentication() === AuthenticationRequirement::Authenticated
            && ! $account->isLoggedIn()
        ) {
            if ($definition->responseType() === 'file') {
                return AccessDecision::reject(HttpResponse::unauthorized());
            }

            $location = '/login';
            if ($request->path() !== '/') {
                $location .= '?redirect_to=' . rawurlencode($request->path());
            }

            return AccessDecision::reject(HttpResponse::redirect($location));
        }

        if (
            $this->operations->maintenanceEnabled()
            && $definition->area() !== RouteArea::Console
            && ! $this->maintenanceRouteAllowed($definition->key())
        ) {
            $description = $this->operations->maintenanceMessage();
            $endsAt = $this->operations->maintenanceEndsAt();
            if ($endsAt !== null) {
                $description .= ' 예상 종료: ' . $endsAt;
            }
            return AccessDecision::reject(HttpResponse::html(
                $this->shell->guest(
                    '점검 중',
                    '<section class="ojp-auth-panel"><h1>현재 점검 중입니다.</h1><p>'
                        . $this->escape($description)
                        . '</p></section>'
                ),
                503
            ));
        }

        try {
            if (! $this->features->isEnabled($definition->featureKey())) {
                return AccessDecision::reject(HttpResponse::notFound(
                    $this->shell->guest(
                        '페이지 없음',
                        $this->message('페이지를 찾을 수 없습니다.', '현재 사용할 수 없는 기능입니다.')
                    )
                ));
            }
        } catch (Throwable) {
            return AccessDecision::reject(HttpResponse::notFound(
                $this->shell->guest(
                    '페이지 없음',
                    $this->message('페이지를 찾을 수 없습니다.', '현재 사용할 수 없는 기능입니다.')
                )
            ));
        }

        if ($definition->authentication() === AuthenticationRequirement::Anonymous) {
            if ($definition->key() === 'auth.register' && ! $this->registrationPolicy->current()->isEnabled()) {
                return AccessDecision::reject(HttpResponse::notFound(
                    $this->shell->guest(
                        '회원가입',
                        $this->message('회원가입을 받지 않습니다.', '관리자가 가입 기능을 열면 이용할 수 있습니다.')
                    )
                ));
            }

            if ($definition->key() === 'auth.login' && $account->isLoggedIn()) {
                if ($account->needsProfileCompletion()) {
                    return AccessDecision::reject(
                        HttpResponse::redirect('/user-settings?mode=profile-completion')
                    );
                }

                if ($account->isActive()) {
                    return AccessDecision::reject(HttpResponse::redirect('/'));
                }

                $profile = $account->profile();
                $title = $profile?->accessStatus() === AccessStatus::Pending
                    ? '가입 승인 대기'
                    : '이용이 중지된 계정';

                return AccessDecision::reject(HttpResponse::forbidden(
                    $this->shell->guest(
                        $title,
                        $this->message($title, '현재 계정으로 서비스 화면에 접근할 수 없습니다.')
                    )
                ));
            }

            return AccessDecision::allow();
        }

        if ($account->needsProfileCompletion()) {
            if ($definition->key() === 'auth.settings') {
                return AccessDecision::allow();
            }

            return AccessDecision::reject(
                HttpResponse::redirect('/user-settings?mode=profile-completion')
            );
        }

        $profile = $account->profile();
        if ($profile?->accessStatus() !== AccessStatus::Active) {
            $title = $profile?->accessStatus() === AccessStatus::Pending
                ? '가입 승인 대기'
                : '이용이 중지된 계정';

            return AccessDecision::reject(HttpResponse::forbidden(
                $this->shell->guest(
                    $title,
                    $this->message($title, '현재 계정으로 서비스 화면에 접근할 수 없습니다.')
                )
            ));
        }

        if ($definition->area() === RouteArea::Console) {
            $identity = $account->identity();
            $capabilities = $this->consoleCapabilities[$definition->key()] ?? [];
            if (
                $identity === null
                || (
                    ($capabilities === []
                        || ! $this->permissions->canAny($identity->userId(), $capabilities))
                    && ! $this->consoleRouteRules->allows($identity->userId(), $route)
                )
            ) {
                if ($identity === null) {
                    return AccessDecision::reject(HttpResponse::redirect('/login'));
                }

                return AccessDecision::reject(HttpResponse::forbidden(
                    $this->shell->authenticated(
                        '접근 제한',
                        $this->message('접근할 수 없습니다.', '이 화면을 볼 권한이 없습니다.'),
                        $identity,
                        [],
                        false
                    )
                ));
            }
        }

        return AccessDecision::allow();
    }

    private function maintenanceRouteAllowed(string $routeKey): bool
    {
        return in_array($routeKey, [
            'auth.login',
            'auth.lost_password',
            'auth.settings',
            'auth.agreements',
            'auth.guardian_consent',
        ], true) || str_starts_with($routeKey, 'legal.');
    }

    private function message(string $title, string $description): string
    {
        return '<section class="ojp-auth-panel">'
            . '<h1>' . $this->escape($title) . '</h1>'
            . '<p>' . $this->escape($description) . '</p>'
            . '<a class="ojp-button ojp-button--primary" href="/login">로그인으로 이동</a>'
            . '</section>';
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
