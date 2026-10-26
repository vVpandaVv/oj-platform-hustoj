<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Modules\AccessRouter\Application\ActionHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressWebRuntime;
use CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard\DashboardBlockRegistry;
use CodingPanda\OjPlatform\Modules\AdminConsole\Presentation\ConsolePageResponder;
use CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\Auth\Application\AccountErasureParticipantRegistry;
use CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Application\PublicProfileSectionRegistry;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAccountErasureJobAccessRule;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserCsvImportJobAccessRule;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthorizedPasswordResetter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ManagedUserNameUpdater;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LearnerAccountProvisioner;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LoginThrottleControl;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceBatchProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferenceProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Module as AuthModule;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Modules\PublicUi\Access\ConsoleRouteAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\PublicUi\Module as PublicUiModule;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\HomeBlockRegistry;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\HomeNoticeProviderRegistry;
use CodingPanda\OjPlatform\Modules\PublicUi\Presentation\PageResponder;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\HomeSettingsService;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SiteSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SystemSettingsService;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;

final class AccountShellBundle
{
    /**
     * @param array<string, object> $services
     */
    public function __construct(
        private readonly AuthModule $authModule,
        private readonly PublicUiModule $publicUiModule,
        private readonly WordPressWebRuntime $webRuntime,
        private readonly RouteHandlerRegistry $routeHandlers,
        private readonly ActionHandlerRegistry $actionHandlers,
        private readonly array $services
    ) {
    }

    public function authModule(): AuthModule
    {
        return $this->authModule;
    }

    public function publicUiModule(): PublicUiModule
    {
        return $this->publicUiModule;
    }

    public function webRuntime(): WordPressWebRuntime
    {
        return $this->webRuntime;
    }

    public function routeHandlers(): RouteHandlerRegistry
    {
        return $this->routeHandlers;
    }

    public function actionHandlers(): ActionHandlerRegistry
    {
        return $this->actionHandlers;
    }

    public function currentAccounts(): CurrentAccountService
    {
        return $this->typedService(CurrentAccountService::class);
    }

    public function users(): WordPressUserGateway
    {
        return $this->typedService(WordPressUserGateway::class);
    }

    public function publicShell(): \CodingPanda\OjPlatform\Modules\PublicUi\Shell\PublicShell
    {
        return $this->typedService(\CodingPanda\OjPlatform\Modules\PublicUi\Shell\PublicShell::class);
    }

    public function userImportJobAccessRule(): UserCsvImportJobAccessRule
    {
        return $this->typedService(UserCsvImportJobAccessRule::class);
    }

    public function accountErasureParticipants(): AccountErasureParticipantRegistry
    {
        return $this->typedService(AccountErasureParticipantRegistry::class);
    }

    public function accountErasureJobAccessRule(): UserAccountErasureJobAccessRule
    {
        return $this->typedService(UserAccountErasureJobAccessRule::class);
    }

    public function learnerAccountProvisioner(): LearnerAccountProvisioner
    {
        return $this->typedService(LearnerAccountProvisioner::class);
    }

    public function authorizedPasswordResetter(): AuthorizedPasswordResetter
    {
        return $this->typedService(AuthorizedPasswordResetter::class);
    }

    public function managedUserNames(): ManagedUserNameUpdater
    {
        return $this->typedService(ManagedUserNameUpdater::class);
    }

    public function preferences(): UserPreferenceRepository
    {
        return $this->typedService(UserPreferenceRepository::class);
    }

    public function profiles(): UserProfileRepository
    {
        return $this->typedService(UserProfileRepository::class);
    }

    public function preferenceBatch(): UserPreferenceBatchProvider
    {
        return $this->typedService(UserPreferenceBatchProvider::class);
    }

    public function submissionPreferences(): UserSubmissionPreferenceProvider
    {
        return $this->typedService(UserSubmissionPreferenceProvider::class);
    }

    public function publicPages(): PageResponder
    {
        return $this->typedService(PageResponder::class);
    }

    public function homeView(): \CodingPanda\OjPlatform\Modules\PublicUi\Home\HomePageView
    {
        return $this->typedService(\CodingPanda\OjPlatform\Modules\PublicUi\Home\HomePageView::class);
    }

    public function homeNotices(): HomeNoticeProviderRegistry
    {
        return $this->typedService(HomeNoticeProviderRegistry::class);
    }

    public function homeBlocks(): HomeBlockRegistry
    {
        return $this->typedService(HomeBlockRegistry::class);
    }

    public function publicProfileSections(): PublicProfileSectionRegistry
    {
        return $this->typedService(PublicProfileSectionRegistry::class);
    }

    public function dashboardBlocks(): DashboardBlockRegistry
    {
        return $this->typedService(DashboardBlockRegistry::class);
    }

    public function publicMenuSettings(): PublicMenuSettingsService
    {
        return $this->typedService(PublicMenuSettingsService::class);
    }

    public function homeSettings(): HomeSettingsService
    {
        return $this->typedService(HomeSettingsService::class);
    }

    public function siteSettings(): SiteSettingsService
    {
        return $this->typedService(SiteSettingsService::class);
    }

    public function systemSettings(): SystemSettingsService
    {
        return $this->typedService(SystemSettingsService::class);
    }

    public function consolePages(): ConsolePageResponder
    {
        return $this->typedService(ConsolePageResponder::class);
    }

    public function consoleMenuAccessRules(): ConsoleMenuAccessRuleRegistry
    {
        return $this->typedService(ConsoleMenuAccessRuleRegistry::class);
    }

    public function consoleRouteAccessRules(): ConsoleRouteAccessRuleRegistry
    {
        return $this->typedService(ConsoleRouteAccessRuleRegistry::class);
    }

    public function actionSecurity(): ActionSecurity
    {
        return $this->typedService(ActionSecurity::class);
    }

    public function actionUrls(): ActionUrlProvider
    {
        return $this->typedService(ActionUrlProvider::class);
    }

    public function actionGuard(): ActionGuard
    {
        return $this->typedService(ActionGuard::class);
    }

    public function loginThrottle(): LoginThrottleControl
    {
        return $this->typedService(LoginThrottleControl::class);
    }

    public function actionResponder(): ActionResponder
    {
        return $this->typedService(ActionResponder::class);
    }

    public function uiAssets(): UiAssetLocator
    {
        return $this->typedService(UiAssetLocator::class);
    }

    public function bindInto(BuildContainer $container): void
    {
        foreach ($this->services as $serviceId => $service) {
            $container->bind($serviceId, $service);
        }
    }

    /**
     * @template T of object
     * @param class-string<T> $serviceId
     * @return T
     */
    private function typedService(string $serviceId): object
    {
        $service = $this->services[$serviceId] ?? null;
        if (! is_object($service) || ! $service instanceof $serviceId) {
            throw new \LogicException('계정·셸 조립 서비스가 없습니다: ' . $serviceId);
        }

        return $service;
    }
}
