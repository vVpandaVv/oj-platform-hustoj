<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Addon\AddonDocumentExtensions;
use CodingPanda\OjPlatform\Kernel\Addon\AddonPrivacyInventory;

use CodingPanda\OjPlatform\Kernel\Addon\AddonPublicMenuContributions;
use CodingPanda\OjPlatform\Kernel\Configuration\PhpConfigLoader;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Ui\Editor\AceCoreAssetLocator;
use CodingPanda\OjPlatform\Ui\Infrastructure\WordPress\WordPressAceCoreAssetLocator;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\ActionDispatcher;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\ActionHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteDispatcher;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteHandlerRegistry;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteMatcher;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\RouteUrlGenerator;
use CodingPanda\OjPlatform\Modules\AccessRouter\Application\SafeRedirectResolver;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ResponseEmitter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteDefinition;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteFailureResponder;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandler;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteHandlerCollection;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\SafeRedirect;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressResponseEmitter;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRewriteManager;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressRouteTransport;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressWebRuntime;
use CodingPanda\OjPlatform\Modules\AccessRouter\Infrastructure\WordPress\WordPressLoginGuard;
use CodingPanda\OjPlatform\Modules\AdminConsole\Application\MenuTree;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder;
use CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard\CapabilityShortcutDashboardProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard\DashboardBlockRegistry;
use CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard\DashboardPageHandler;
use CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard\DashboardPageLoader;
use CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard\DashboardPageView;
use CodingPanda\OjPlatform\Modules\AdminConsole\Dashboard\PendingApprovalDashboardProvider;
use CodingPanda\OjPlatform\Modules\AdminConsole\Presentation\Console\CommunityHubPageHandler;
use CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuResolver;
use CodingPanda\OjPlatform\Modules\AdminConsole\Navigation\ConsoleMenuAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\AdminConsole\Presentation\ConsolePageResponder;
use CodingPanda\OjPlatform\Modules\AdminConsole\Shell\ConsoleShell;
use CodingPanda\OjPlatform\Modules\Auth\Application\AccountErasureParticipantRegistry;
use CodingPanda\OjPlatform\Modules\Auth\Application\AccountErasureService;
use CodingPanda\OjPlatform\Modules\Auth\Application\AccountInputPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Application\PublicProfileSectionRegistry;
use CodingPanda\OjPlatform\Modules\Auth\Application\AdminUserCreationService;
use CodingPanda\OjPlatform\Modules\Auth\Application\CurrentAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Application\GuardianConsentRequestService;
use CodingPanda\OjPlatform\Modules\Auth\Application\GuardianConsentVerifier;
use CodingPanda\OjPlatform\Modules\Auth\Application\LegacyAccountTransitionService;
use CodingPanda\OjPlatform\Modules\Auth\Application\LoginService;
use CodingPanda\OjPlatform\Modules\Auth\Application\PasswordResetService;
use CodingPanda\OjPlatform\Modules\Auth\Application\RegistrationService;
use CodingPanda\OjPlatform\Modules\Auth\Application\SettingsRegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAccountService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAdministrationService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserBulkImportService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserCsvImportJobHandler;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserCsvImportJobAccessRule;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserDirectoryService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserImportCsvParser;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserImportDraftService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserRoleService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserSubmissionPreferenceService;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAccountErasureJobAccessRule;
use CodingPanda\OjPlatform\Modules\Auth\Application\UserAccountErasureJobHandler;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AgreementDocumentType;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ActionResponse;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthorizedPasswordResetter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\ManagedUserNameUpdater;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AttemptLimiter;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LoginThrottleControl;
use CodingPanda\OjPlatform\Modules\Auth\Application\LoginThrottleService;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthAccountErasureStore;
use CodingPanda\OjPlatform\Modules\Auth\Contract\AuthTransaction;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentSessionTerminator;
use CodingPanda\OjPlatform\Modules\Auth\Contract\DeletedUserRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\GuardianVerificationRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\GuardianConsentNotifier;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyJudgeUserReader;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LegacyTransitionChallengeStore;
use CodingPanda\OjPlatform\Modules\Auth\Contract\LearnerAccountProvisioner;
use CodingPanda\OjPlatform\Modules\Auth\Contract\PasswordResetTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\RegistrationPolicyProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceBatchProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferenceProvider;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserDirectory;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserLoginHistoryRepository;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserIdentityAnonymizer;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserRoleAssignmentPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserManagementTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressAdminActionAuditLog;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressAttemptLimiter;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressAgreementRepository;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressAuthAccountErasureStore;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressAuthTransaction;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressDeletedUserRepository;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressGuardianVerificationRepository;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressGuardianConsentNotifier;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressLegacyTransitionChallengeStore;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressPasswordResetTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserManagementTargetPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressCurrentSessionTerminator;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserApiGateway;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressManagedUserNameUpdater;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserPreferenceRepository;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserProfileRepository;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserLoginHistoryRepository;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserIdentityAnonymizer;
use CodingPanda\OjPlatform\Modules\Auth\Infrastructure\WordPress\WordPressUserRoleAssignmentPolicy;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\AcceptUserAgreementsActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\ApproveUserRegistrationActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\ChangePasswordActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\CreateUserActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\DeleteAccountActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\ExecuteUserImportActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\GuardianConsentActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\GuardianConsentCheckActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\GuardianConsentRequestActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\LoginActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\LogoutActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\LostPasswordActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\PasswordResetActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\PreviewUserImportActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\RegisterUserActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\SaveAgreementDocumentActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\SaveRegistrationSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\SaveUserSettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\SaveAddonCapabilityAssignmentsActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\SyncRolePresetsActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\ToggleUserAccessActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\UpdateUserActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Action\UpdateUserRolesActionHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionGuard;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\ActionResponder;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\AgreementAdminPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\DeletedUserListPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\RegistrationSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\RoleListPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\UserBulkImportPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\UserDetailPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\UserEditPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\UserLoginHistoryPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\UserListPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\UserNewPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Console\UserRolesPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\GuardianConsentPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\LegalDocumentPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\LoginPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\RegistrationPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\UserAgreementsPageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\UserProfilePageHandler;
use CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\UserSettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionInstaller;
use CodingPanda\OjPlatform\Modules\Permission\Application\PermissionService;
use CodingPanda\OjPlatform\Modules\Permission\Contract\AddonCapabilityAssignmentGateway;
use CodingPanda\OjPlatform\Modules\Permission\Contract\RoleDefinition;
use CodingPanda\OjPlatform\Modules\Permission\Infrastructure\WordPress\WordPressAddonCapabilityAssignmentGateway;
use CodingPanda\OjPlatform\Modules\Jobs\Application\JobManager;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\FormDraftStore;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Registry\JobHandlerRegistry;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\PublicUi\Access\ConsoleRouteAccessRuleRegistry;
use CodingPanda\OjPlatform\Modules\PublicUi\Access\PlatformRouteAccessPolicy;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\HomeSettingsService;
use CodingPanda\OjPlatform\Modules\PublicUi\Application\PublicMenuSettingsService;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\AccountLinkProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder as PublicPageResponder;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\HomePageHandler;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\HomeBlockRegistry;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\HomeNoticeProviderRegistry;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\HomePageLoader;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\HomePageView;
use CodingPanda\OjPlatform\Modules\PublicUi\Home\NoticeHomeBlockProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Infrastructure\WordPress\WordPressAccountLinkProvider;
use CodingPanda\OjPlatform\Modules\PublicUi\Navigation\PublicMenuResolver;
use CodingPanda\OjPlatform\Modules\PublicUi\Presentation\PageResponder;
use CodingPanda\OjPlatform\Modules\PublicUi\Presentation\PublicRouteFailureResponder;
use CodingPanda\OjPlatform\Modules\PublicUi\Shell\PublicShell;
use CodingPanda\OjPlatform\Modules\Settings\Application\FeatureService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SiteSettingsService;
use CodingPanda\OjPlatform\Modules\Settings\Application\SystemSettingsService;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SettingsSubmissionLanguageAvailability;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Contract\SubmissionLanguageAvailability;
use CodingPanda\OjPlatform\Shared\Clock\Clock;
use CodingPanda\OjPlatform\Shared\Judge\JudgeLanguageCatalog;
use CodingPanda\OjPlatform\Ui\Asset\UiAssetLocator;
use CodingPanda\OjPlatform\Ui\Component\PaginationView;
use CodingPanda\OjPlatform\Ui\Component\SiteDateFormatter;
use CodingPanda\OjPlatform\Ui\Icon\IconRenderer;
use CodingPanda\OjPlatform\Ui\Infrastructure\WordPress\WordPressFlashStore;
use CodingPanda\OjPlatform\Ui\Infrastructure\WordPress\WordPressUiAssetLocator;
use CodingPanda\OjPlatform\Ui\Message\FlashStore;
use CodingPanda\OjPlatform\Ui\View\HtmlDocumentRenderer;

final class AccountShellComposition
{
    /**
     * @param DefinitionRegistry<SchemaTableDefinition> $schemas
     * @param DefinitionRegistry<RoleDefinition> $roles
     * @param DefinitionRegistry<ActionDefinition> $actions
     * @param DefinitionRegistry<RouteDefinition> $routeDefinitions
     */
    public function assemble(
        PluginEnvironment $environment,
        HookRegistrar $hooks,
        ErrorReporter $errorReporter,
        Clock $storageClock,
        Clock $siteClock,
        PhpConfigLoader $loader,
        DefinitionRegistry $schemas,
        DefinitionRegistry $roles,
        DefinitionRegistry $actions,
        DefinitionRegistry $routeDefinitions,
        RouteMatcher $routeMatcher,
        RouteUrlGenerator $routeUrls,
        WordPressRouteTransport $routeTransport,
        WordPressRewriteManager $rewriteManager,
        MenuTree $menuTree,
        SettingsService $settings,
        SiteSettingsService $siteSettings,
        SystemSettingsService $systemSettings,
        FeatureService $features,
        PermissionService $permissions,
        PermissionInstaller $permissionInstaller,
        ApplicationDatabase $applicationDatabase,
        FormDraftStore $formDrafts,
        JobManager $jobManager,
        JobHandlerRegistry $jobHandlers,
        JobStepJournal $jobSteps,
        LegacyJudgeUserReader $legacyUsers,
        AddonPublicMenuContributions $addonPublicMenus,
        DefinitionRegistry $capabilities,
        AddonDocumentExtensions $addonDocuments,
        AddonPrivacyInventory $addonPrivacy
    ): AccountShellBundle {
        $addonCapabilityAssignments = new WordPressAddonCapabilityAssignmentGateway();
        $authModule = new \CodingPanda\OjPlatform\Modules\Auth\Module(
            $schemas,
            $loader->load('auth-schema')
        );
        $publicUiModule = new \CodingPanda\OjPlatform\Modules\PublicUi\Module();

        $userGateway = new WordPressUserApiGateway();
        $sessionTerminator = new WordPressCurrentSessionTerminator();
        $profiles = WordPressUserProfileRepository::fromGlobals();
        $preferences = WordPressUserPreferenceRepository::fromGlobals();
        $agreements = WordPressAgreementRepository::fromGlobals();
        $guardians = WordPressGuardianVerificationRepository::fromGlobals();
        $deletedUsers = WordPressDeletedUserRepository::fromGlobals();
        $loginHistory = WordPressUserLoginHistoryRepository::fromGlobals();
        $erasureParticipants = new AccountErasureParticipantRegistry();
        $authErasureStore = new WordPressAuthAccountErasureStore($applicationDatabase);
        $identityAnonymizer = WordPressUserIdentityAnonymizer::fromGlobals();
        $guardianNotifier = new WordPressGuardianConsentNotifier();
        $transaction = WordPressAuthTransaction::fromGlobals();
        $transitionChallenges = new WordPressLegacyTransitionChallengeStore();
        $registrationPolicy = new SettingsRegistrationPolicyProvider($settings);
        $accountInputs = new AccountInputPolicy($siteClock);
        $guardianConsent = new GuardianConsentVerifier($guardians, $siteClock);
        $submissionLanguages = new SettingsSubmissionLanguageAvailability(
            new SubmissionSettingsProvider($settings),
            new SubmissionLanguageCatalog(new JudgeLanguageCatalog())
        );

        $currentAccounts = new CurrentAccountService($userGateway, $profiles);
        $login = new LoginService($userGateway, $profiles, $legacyUsers, $loginHistory);
        $registration = new RegistrationService(
            $userGateway,
            $profiles,
            $preferences,
            $agreements,
            $guardianConsent,
            $registrationPolicy,
            $accountInputs,
            $transaction
        );
        $guardianRequests = new GuardianConsentRequestService(
            $guardians,
            $guardianNotifier,
            $registrationPolicy,
            $agreements
        );
        $transition = new LegacyAccountTransitionService(
            $legacyUsers,
            $transitionChallenges,
            $userGateway,
            $profiles,
            $preferences,
            $accountInputs,
            $transaction
        );
        $directory = new UserDirectoryService($profiles, $userGateway, $userGateway);
        $submissionPreferences = new UserSubmissionPreferenceService($preferences);
        $userManagementTargets = new WordPressUserManagementTargetPolicy();
        $accountService = new UserAccountService(
            $userGateway,
            $userGateway,
            $profiles,
            $preferences,
            $registrationPolicy,
            $guardianConsent,
            $accountInputs,
            $transaction,
            $userManagementTargets
        );
        $administration = new UserAdministrationService(
            $profiles,
            $agreements,
            $guardians,
            $userGateway,
            $userManagementTargets
        );
        $creation = new AdminUserCreationService(
            $userGateway,
            $profiles,
            $preferences,
            $accountInputs,
            $transaction
        );
        $roleAssignmentPolicy = new WordPressUserRoleAssignmentPolicy();
        $roleService = new UserRoleService(
            $userGateway,
            $roles,
            $roleAssignmentPolicy
        );
        $passwordTargetPolicy = new WordPressPasswordResetTargetPolicy();
        $passwords = new PasswordResetService(
            $userGateway,
            $profiles,
            $passwordTargetPolicy,
            new WordPressAdminActionAuditLog($storageClock)
        );
        $importParser = new UserImportCsvParser(
            $accountInputs,
            $userGateway,
            $roles
        );
        $importDrafts = new UserImportDraftService(
            $importParser,
            $formDrafts
        );
        $bulkImport = new UserBulkImportService(
            $importDrafts,
            $jobManager,
            $permissions,
            $errorReporter
        );
        $userImportJobHandler = new UserCsvImportJobHandler(
            $creation,
            $permissions,
            $roles
        );
        $jobHandlers->register($userImportJobHandler);
        $userImportJobAccess = new UserCsvImportJobAccessRule($permissions);
        $erasures = new AccountErasureService(
            $erasureParticipants,
            $userGateway,
            $jobManager
        );
        $erasureJobHandler = new UserAccountErasureJobHandler(
            $erasureParticipants,
            $userGateway,
            $authErasureStore,
            $identityAnonymizer,
            $deletedUsers,
            $jobSteps,
            $errorReporter
        );
        $jobHandlers->register($erasureJobHandler);
        $erasureJobAccess = new UserAccountErasureJobAccessRule($permissions);

        $routeHandlers = new RouteHandlerRegistry();
        $actionHandlers = new ActionHandlerRegistry();
        $actionSecurity = new WordPressActionSecurity();
        $actionUrls = new WordPressActionUrlProvider();
        $attempts = new LoginThrottleService(new WordPressAttemptLimiter(), $settings);
        $flash = new WordPressFlashStore();
        $accountLinks = new WordPressAccountLinkProvider(
            $actionUrls,
            $actionSecurity
        );
        $assetLocator = new WordPressUiAssetLocator($environment);
        $documents = new HtmlDocumentRenderer(
            $assetLocator,
            static fn (): string => $siteSettings->serviceName(),
            $addonDocuments
        );
        $publicShell = new PublicShell(
            $documents,
            $accountLinks,
            $registrationPolicy,
            $siteSettings,
            $siteSettings
        );
        $publicMenuSettings = new PublicMenuSettingsService(
            $settings,
            $routeDefinitions,
            $routeHandlers,
            $routeUrls,
            $features,
            $permissions,
            $addonPublicMenus
        );
        $homeSettings = new HomeSettingsService($settings);
        $publicMenus = new PublicMenuResolver($publicMenuSettings);
        $publicPages = new PageResponder(
            $currentAccounts,
            $publicMenus,
            $permissions,
            $publicShell,
            $flash
        );
        $consoleMenuRules = new ConsoleMenuAccessRuleRegistry();
        $consoleMenus = new ConsoleMenuResolver(
            $menuTree,
            $routeHandlers,
            $routeUrls,
            $features,
            $permissions,
            $consoleMenuRules,
            $loader->load('console-local-nav')
        );
        $consoleShell = new ConsoleShell(
            $documents,
            $accountLinks,
            $siteSettings
        );
        $consolePages = new ConsolePageResponder(
            $currentAccounts,
            $consoleMenus,
            $consoleShell,
            $flash
        );
        $actionGuard = new ActionGuard(
            $actionSecurity,
            $currentAccounts,
            $permissions,
            $attempts
        );
        $actionResponder = new ActionResponder($flash);
        $safeRedirects = new SafeRedirectResolver(
            $routeMatcher,
            $routeHandlers
        );
        $homeNotices = new HomeNoticeProviderRegistry();
        $icons = new IconRenderer($assetLocator);
        $homeBlocks = new HomeBlockRegistry($errorReporter);
        $homeBlocks->register(new NoticeHomeBlockProvider($homeNotices));
        $homeLoader = new HomePageLoader(
            $currentAccounts,
            $homeSettings,
            $homeBlocks,
            $siteClock
        );
        $homeView = new HomePageView($routeUrls, $icons);
        $dashboardBlocks = new DashboardBlockRegistry($errorReporter);
        $dashboardBlocks->register(new PendingApprovalDashboardProvider(
            $directory,
            $permissions
        ));
        $dashboardBlocks->register(new CapabilityShortcutDashboardProvider(
            $permissions
        ));
        $dashboardLoader = new DashboardPageLoader(
            $currentAccounts,
            $permissions,
            $dashboardBlocks,
            $siteClock
        );
        $dashboardView = new DashboardPageView($routeUrls, $icons);
        $profileSections = new PublicProfileSectionRegistry($errorReporter);

        $this->registerRoutes(
            $routeHandlers,
            $publicPages,
            $consolePages,
            $currentAccounts,
            $erasures,
            $homeLoader,
            $homeView,
            $dashboardLoader,
            $dashboardView,
            $features,
            $directory,
            $preferences,
            $profileSections,
            $agreements,
            $guardianRequests,
            $deletedUsers,
            $loginHistory,
            $registrationPolicy,
            $submissionLanguages,
            $routeUrls,
            $actionUrls,
            $actionSecurity,
            $permissions,
            $roles,
            $roleAssignmentPolicy,
            $capabilities,
            $addonCapabilityAssignments,
            new WordPressAceCoreAssetLocator($environment),
            $addonPrivacy,
            $userManagementTargets
        );
        $this->registerActions(
            $actionHandlers,
            $login,
            $transition,
            $safeRedirects,
            $registration,
            $guardianRequests,
            $currentAccounts,
            $accountService,
            $submissionLanguages,
            $erasures,
            $administration,
            $creation,
            $roleService,
            $passwords,
            $importDrafts,
            $bulkImport,
            $agreements,
            $settings,
            $userGateway,
            $sessionTerminator,
            $permissions,
            $actionUrls,
            $actionSecurity,
            $actionGuard,
            $actionResponder,
            $consolePages,
            $permissionInstaller,
            $errorReporter,
            $capabilities,
            $roles,
            $addonCapabilityAssignments
        );

        $consoleRouteRules = new ConsoleRouteAccessRuleRegistry();
        $accessPolicy = new PlatformRouteAccessPolicy(
            $currentAccounts,
            $registrationPolicy,
            $features,
            $permissions,
            $publicShell,
            $consoleRouteRules,
            $loader->load('route-access'),
            $systemSettings
        );
        $routeFailures = new PublicRouteFailureResponder($publicPages, $currentAccounts);
        $routeDispatcher = new RouteDispatcher(
            $routeMatcher,
            $routeHandlers,
            $accessPolicy,
            $errorReporter,
            $routeFailures
        );
        $actionDispatcher = new ActionDispatcher(
            $actionHandlers,
            $actions,
            $systemSettings,
            $errorReporter
        );
        $responseEmitter = new WordPressResponseEmitter($routeTransport);
        $webRuntime = new WordPressWebRuntime(
            $hooks,
            $routeDispatcher,
            $actionDispatcher,
            $actionHandlers,
            $actions,
            $responseEmitter,
            $routeTransport,
            $rewriteManager,
            $routeFailures,
            new WordPressLoginGuard($responseEmitter, $features, $safeRedirects, $errorReporter)
        );

        return new AccountShellBundle(
            $authModule,
            $publicUiModule,
            $webRuntime,
            $routeHandlers,
            $actionHandlers,
            [
                RouteHandlerRegistry::class => $routeHandlers,
                RouteHandlerCollection::class => $routeHandlers,
                ActionHandlerRegistry::class => $actionHandlers,
                RouteDispatcher::class => $routeDispatcher,
                RouteFailureResponder::class => $routeFailures,
                PublicRouteFailureResponder::class => $routeFailures,
                ActionDispatcher::class => $actionDispatcher,
                SafeRedirectResolver::class => $safeRedirects,
                SafeRedirect::class => $safeRedirects,
                ResponseEmitter::class => $responseEmitter,
                WordPressWebRuntime::class => $webRuntime,
                WordPressRouteTransport::class => $routeTransport,
                WordPressRewriteManager::class => $rewriteManager,
                WordPressUserGateway::class => $userGateway,
                CurrentSessionTerminator::class => $sessionTerminator,
                WordPressCurrentSessionTerminator::class => $sessionTerminator,
                UserProfileRepository::class => $profiles,
                UserPreferenceRepository::class => $preferences,
                UserPreferenceBatchProvider::class => $preferences,
                UserSubmissionPreferenceProvider::class => $submissionPreferences,
                UserSubmissionPreferenceService::class => $submissionPreferences,
                UserRoleAssignmentPolicy::class => $roleAssignmentPolicy,
                AgreementRepository::class => $agreements,
                GuardianVerificationRepository::class => $guardians,
                DeletedUserRepository::class => $deletedUsers,
                UserLoginHistoryRepository::class => $loginHistory,
                AccountErasureParticipantRegistry::class => $erasureParticipants,
                AuthAccountErasureStore::class => $authErasureStore,
                WordPressAuthAccountErasureStore::class => $authErasureStore,
                UserIdentityAnonymizer::class => $identityAnonymizer,
                WordPressUserIdentityAnonymizer::class => $identityAnonymizer,
                AccountErasureService::class => $erasures,
                UserAccountErasureJobHandler::class => $erasureJobHandler,
                UserAccountErasureJobAccessRule::class => $erasureJobAccess,
                GuardianConsentNotifier::class => $guardianNotifier,
                AuthTransaction::class => $transaction,
                LegacyJudgeUserReader::class => $legacyUsers,
                LegacyTransitionChallengeStore::class => $transitionChallenges,
                RegistrationPolicyProvider::class => $registrationPolicy,
                AccountInputPolicy::class => $accountInputs,
                CurrentAccountService::class => $currentAccounts,
                CurrentAccountProvider::class => $currentAccounts,
                LoginService::class => $login,
                RegistrationService::class => $registration,
                GuardianConsentRequestService::class => $guardianRequests,
                LegacyAccountTransitionService::class => $transition,
                UserDirectoryService::class => $directory,
                UserDirectory::class => $directory,
                UserAccountService::class => $accountService,
                UserAdministrationService::class => $administration,
                AdminUserCreationService::class => $creation,
                LearnerAccountProvisioner::class => $creation,
                UserRoleService::class => $roleService,
                PasswordResetTargetPolicy::class => $passwordTargetPolicy,
                PasswordResetService::class => $passwords,
                AuthorizedPasswordResetter::class => $passwords,
                ManagedUserNameUpdater::class => new WordPressManagedUserNameUpdater($userGateway, $accountInputs),
                UserImportCsvParser::class => $importParser,
                UserImportDraftService::class => $importDrafts,
                UserBulkImportService::class => $bulkImport,
                UserCsvImportJobHandler::class => $userImportJobHandler,
                UserCsvImportJobAccessRule::class => $userImportJobAccess,
                ActionSecurity::class => $actionSecurity,
                ActionUrlProvider::class => $actionUrls,
                AttemptLimiter::class => $attempts,
                LoginThrottleControl::class => $attempts,
                FlashStore::class => $flash,
                AccountLinkProvider::class => $accountLinks,
                UiAssetLocator::class => $assetLocator,
                HtmlDocumentRenderer::class => $documents,
                PublicShell::class => $publicShell,
                PublicMenuResolver::class => $publicMenus,
                PublicMenuSettingsService::class => $publicMenuSettings,
                HomeSettingsService::class => $homeSettings,
                HomeBlockRegistry::class => $homeBlocks,
                PublicProfileSectionRegistry::class => $profileSections,
                HomePageLoader::class => $homeLoader,
                HomePageView::class => $homeView,
                SiteSettingsService::class => $siteSettings,
                SystemSettingsService::class => $systemSettings,
                HomeNoticeProviderRegistry::class => $homeNotices,
                IconRenderer::class => $icons,
                DashboardBlockRegistry::class => $dashboardBlocks,
                DashboardPageLoader::class => $dashboardLoader,
                DashboardPageView::class => $dashboardView,
                PageResponder::class => $publicPages,
                PublicPageResponder::class => $publicPages,
                ConsoleMenuResolver::class => $consoleMenus,
                ConsoleMenuAccessRuleRegistry::class => $consoleMenuRules,
                ConsoleRouteAccessRuleRegistry::class => $consoleRouteRules,
                ConsoleShell::class => $consoleShell,
                ConsolePageResponder::class => $consolePages,
                ConsoleResponder::class => $consolePages,
                ActionGuard::class => $actionGuard,
                ActionAccessGuard::class => $actionGuard,
                ActionResponder::class => $actionResponder,
                ActionResponse::class => $actionResponder,
            ]
        );
    }

    private function registerRoutes(
        RouteHandlerRegistry $routes,
        PageResponder $publicPages,
        ConsolePageResponder $consolePages,
        CurrentAccountService $accounts,
        AccountErasureService $erasures,
        HomePageLoader $homeLoader,
        HomePageView $homeView,
        DashboardPageLoader $dashboardLoader,
        DashboardPageView $dashboardView,
        FeatureService $features,
        UserDirectoryService $directory,
        UserPreferenceRepository $preferences,
        PublicProfileSectionRegistry $profileSections,
        AgreementRepository $agreements,
        GuardianConsentRequestService $guardianRequests,
        DeletedUserRepository $deletedUsers,
        UserLoginHistoryRepository $loginHistory,
        RegistrationPolicyProvider $registrationPolicy,
        SubmissionLanguageAvailability $submissionLanguages,
        RouteUrlProvider $routeUrls,
        ActionUrlProvider $actionUrls,
        ActionSecurity $actionSecurity,
        PermissionService $permissions,
        DefinitionRegistry $roles,
        UserRoleAssignmentPolicy $roleAssignmentPolicy,
        DefinitionRegistry $capabilities,
        AddonCapabilityAssignmentGateway $addonCapabilityAssignments,
        AceCoreAssetLocator $aceAssets,
        AddonPrivacyInventory $addonPrivacy,
        UserManagementTargetPolicy $userManagementTargets
    ): void {
        $this->route(
            $routes,
            'home',
            new HomePageHandler(
                $publicPages, $homeLoader, $homeView
            )
        );
        $this->route($routes, 'auth.login', new LoginPageHandler(
            $publicPages,
            $actionUrls,
            $actionSecurity,
            $registrationPolicy
        ));
        $this->route($routes, 'auth.register', new RegistrationPageHandler(
            $publicPages,
            $agreements,
            $registrationPolicy,
            $actionUrls,
            $actionSecurity
        ));
        $dateFormatter = new SiteDateFormatter();
        $this->route($routes, 'auth.profile', new UserProfilePageHandler(
            $publicPages,
            $accounts,
            $directory,
            $preferences,
            $profileSections,
            $routeUrls,
            $permissions,
            $features,
            $dateFormatter
        ));
        $this->route($routes, 'auth.settings', new UserSettingsPageHandler(
            $publicPages,
            $accounts,
            $erasures,
            $preferences,
            $registrationPolicy,
            $submissionLanguages,
            $routeUrls,
            $actionUrls,
            $actionSecurity,
            $aceAssets
        ));
        $this->route($routes, 'auth.agreements', new UserAgreementsPageHandler(
            $publicPages,
            $accounts,
            $agreements,
            $actionUrls,
            $actionSecurity,
            $addonPrivacy
        ));
        $this->route($routes, 'auth.guardian_consent', new GuardianConsentPageHandler(
            $publicPages,
            $actionUrls,
            $actionSecurity,
            $guardianRequests
        ));
        foreach (
            [
                'legal.terms' => AgreementDocumentType::Terms,
                'legal.privacy' => AgreementDocumentType::Privacy,
                'legal.copyright' => AgreementDocumentType::Copyright,
                'legal.email_collection_refusal' => AgreementDocumentType::EmailCollectionRefusal,
                'legal.guardian_policy' => AgreementDocumentType::GuardianConsent,
            ] as $routeName => $type
        ) {
            $this->route(
                $routes,
                $routeName,
                new LegalDocumentPageHandler(
                    $type->value,
                    $type->label(),
                    $publicPages,
                    $agreements,
                    $accounts
                )
            );
        }

        $this->route($routes, 'legal.open_source', new \CodingPanda\OjPlatform\Modules\Auth\Presentation\Route\OpenSourcePageHandler($publicPages, $accounts));

        $this->route($routes, 'console.dashboard', new DashboardPageHandler(
            $consolePages, $dashboardLoader, $dashboardView
        ));
        $this->route($routes, 'console.community.index', new CommunityHubPageHandler(
            $accounts,
            $features,
            $permissions
        ));
        $this->route($routes, 'console.users.index', new UserListPageHandler(
            $consolePages,
            $accounts,
            $directory,
            $permissions,
            $actionUrls,
            $actionSecurity,
            new PaginationView(),
            $dateFormatter
        ));
        $this->route($routes, 'console.users.new', new UserNewPageHandler(
            $consolePages,
            $actionUrls,
            $actionSecurity
        ));
        $this->route($routes, 'console.users.detail', new UserDetailPageHandler(
            $consolePages,
            $directory,
            $accounts,
            $permissions,
            $roles,
            $dateFormatter
        ));
        $this->route($routes, 'console.users.edit', new UserEditPageHandler(
            $consolePages,
            $directory,
            $actionUrls,
            $actionSecurity,
            $accounts,
            $permissions,
            $userManagementTargets
        ));
        $this->route($routes, 'console.users.roles', new UserRolesPageHandler(
            $consolePages,
            $directory,
            $roles,
            $actionUrls,
            $actionSecurity,
            $accounts,
            $roleAssignmentPolicy
        ));
        $this->route(
            $routes,
            'console.users.login_history',
            new UserLoginHistoryPageHandler(
                $consolePages,
                $directory,
                $loginHistory,
                new PaginationView(),
                $dateFormatter
            )
        );
        $this->route($routes, 'console.users.bulk_import', new UserBulkImportPageHandler(
            $consolePages,
            $actionUrls,
            $actionSecurity
        ));
        $this->route($routes, 'console.users.deleted', new DeletedUserListPageHandler(
            $consolePages,
            $deletedUsers
        ));
        $this->route($routes, 'console.roles.index', new RoleListPageHandler(
            $consolePages,
            $roles,
            $capabilities,
            $addonCapabilityAssignments,
            $actionUrls,
            $actionSecurity
        ));
        $this->route($routes, 'console.agreements.index', new AgreementAdminPageHandler(
            $consolePages,
            $agreements,
            $actionUrls,
            $actionSecurity
        ));
        $this->route(
            $routes,
            'console.users.registration',
            new RegistrationSettingsPageHandler(
                $consolePages,
                $registrationPolicy,
                $actionUrls,
                $actionSecurity
            )
        );
    }

    private function registerActions(
        ActionHandlerRegistry $actions,
        LoginService $login,
        LegacyAccountTransitionService $transition,
        SafeRedirectResolver $redirects,
        RegistrationService $registration,
        GuardianConsentRequestService $guardianRequests,
        CurrentAccountService $currentAccounts,
        UserAccountService $accountService,
        SubmissionLanguageAvailability $submissionLanguages,
        AccountErasureService $erasures,
        UserAdministrationService $administration,
        AdminUserCreationService $creation,
        UserRoleService $roles,
        PasswordResetService $passwords,
        UserImportDraftService $importDrafts,
        UserBulkImportService $bulkImport,
        AgreementRepository $agreements,
        SettingsService $settings,
        WordPressUserGateway $users,
        CurrentSessionTerminator $sessionTerminator,
        PermissionService $permissions,
        ActionUrlProvider $actionUrls,
        ActionSecurity $actionSecurity,
        ActionGuard $guard,
        ActionResponder $responses,
        ConsolePageResponder $consolePages,
        PermissionInstaller $permissionInstaller,
        ErrorReporter $errorReporter,
        DefinitionRegistry $capabilities,
        DefinitionRegistry $roleDefinitions,
        AddonCapabilityAssignmentGateway $addonCapabilityAssignments
    ): void {
        $this->action($actions, 'oj_login', new LoginActionHandler(
            $login,
            $transition,
            $redirects,
            $guard,
            $responses,
            $errorReporter
        ));
        $this->action($actions, 'oj_logout', new LogoutActionHandler(
            $sessionTerminator,
            $guard,
            $responses,
            $errorReporter
        ));
        $this->action($actions, 'oj_lost_password', new LostPasswordActionHandler(
            $users,
            $guard,
            $responses
        ));
        $this->action($actions, 'oj_register_user', new RegisterUserActionHandler(
            $registration,
            $guard,
            $responses
        ));
        $this->action(
            $actions,
            'oj_send_guardian_consent_request',
            new GuardianConsentRequestActionHandler(
                $guardianRequests,
                $guard,
                $responses
            )
        );
        $this->action(
            $actions,
            'oj_check_guardian_consent_request',
            new GuardianConsentCheckActionHandler(
                $guardianRequests,
                $guard,
                $responses
            )
        );
        $this->action($actions, 'oj_save_user_settings', new SaveUserSettingsActionHandler(
            $currentAccounts,
            $accountService,
            $submissionLanguages,
            $guard,
            $responses
        ));
        $this->action($actions, 'oj_change_password', new ChangePasswordActionHandler(
            $currentAccounts,
            $accountService,
            $guard,
            $responses,
            $errorReporter
        ));
        $this->action($actions, 'oj_delete_account', new DeleteAccountActionHandler(
            $currentAccounts,
            $erasures,
            $guard,
            $responses,
            $errorReporter
        ));
        $this->action(
            $actions,
            'oj_console_approve_user_registration',
            new ApproveUserRegistrationActionHandler(
                $administration,
                $guard,
                $responses
            )
        );
        $this->action($actions, 'oj_console_toggle_user_active', new ToggleUserAccessActionHandler(
            $administration,
            $guard,
            $responses
        ));
        $this->action($actions, 'oj_console_create_user', new CreateUserActionHandler(
            $creation,
            $guard,
            $responses,
            $errorReporter
        ));
        $this->action(
            $actions,
            'oj_console_bulk_preview_users',
            new PreviewUserImportActionHandler(
                $importDrafts,
                $guard,
                $permissions,
                $actionUrls,
                $actionSecurity,
                $consolePages,
                $errorReporter
            )
        );
        $this->action(
            $actions,
            'oj_console_bulk_execute_users',
            new ExecuteUserImportActionHandler(
                $bulkImport,
                $guard,
                $responses,
                $errorReporter
            )
        );
        $this->action($actions, 'oj_console_update_user', new UpdateUserActionHandler(
            $accountService,
            $guard,
            $responses
        ));
        $this->action($actions, 'oj_console_update_user_roles', new UpdateUserRolesActionHandler(
            $roles,
            $guard,
            $responses
        ));
        $this->action(
            $actions,
            'oj_console_sync_role_presets',
            new SyncRolePresetsActionHandler(
                $permissionInstaller,
                $guard,
                $responses
            )
        );
        $this->action(
            $actions,
            'oj_save_addon_capability_assignments',
            new SaveAddonCapabilityAssignmentsActionHandler(
                $capabilities,
                $roleDefinitions,
                $addonCapabilityAssignments,
                $guard,
                $responses
            )
        );
        $this->action($actions, 'oj_user_password_reset', new PasswordResetActionHandler(
            $passwords,
            $guard,
            $consolePages,
            $errorReporter
        ));
        $this->action($actions, 'oj_save_agreement_document', new SaveAgreementDocumentActionHandler(
            $agreements,
            $guard,
            $responses
        ));
        $this->action(
            $actions,
            'oj_save_registration_settings',
            new SaveRegistrationSettingsActionHandler(
                $settings,
                $guard,
                $responses
            )
        );
        $this->action($actions, 'oj_guardian_consent', new GuardianConsentActionHandler(
            $guardianRequests,
            $guard,
            $responses
        ));
        $this->action($actions, 'oj_accept_user_agreements', new AcceptUserAgreementsActionHandler(
            $agreements,
            $users,
            $guard,
            $responses
        ));
    }

    private function route(
        RouteHandlerRegistry $routes,
        string $routeName,
        RouteHandler $handler
    ): void {
        $routes->register($routeName, $handler);
    }

    private function action(
        ActionHandlerRegistry $actions,
        string $actionKey,
        ActionHandler $handler
    ): void {
        $actions->register($actionKey, $handler);
    }
}
