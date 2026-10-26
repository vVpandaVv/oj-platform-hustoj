<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionProbe;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobDispatcher;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobStepJournal;
use CodingPanda\OjPlatform\Modules\Jobs\Registry\JobHandlerRegistry;
use CodingPanda\OjPlatform\Modules\Migration\Application\AiFeedbackLegacyTransformer;
use CodingPanda\OjPlatform\Modules\Migration\Application\AuthSubmissionLegacyTransformer;
use CodingPanda\OjPlatform\Modules\Migration\Application\LearningSpacesLegacyTransformer;
use CodingPanda\OjPlatform\Modules\Migration\Application\LegacyRowTransformer;
use CodingPanda\OjPlatform\Modules\Migration\Application\LegacyTransformValues;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationCatalog;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationCommandService;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationJobAccessRule;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationPlanService;
use CodingPanda\OjPlatform\Modules\Migration\Application\MigrationStateService;
use CodingPanda\OjPlatform\Modules\Migration\Application\PlatformMigrationJobHandler;
use CodingPanda\OjPlatform\Modules\Migration\Application\ProblemsLegacyTransformer;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyMigrationGateway;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyInstallationPresence;
use CodingPanda\OjPlatform\Modules\Migration\Contract\LegacyUserResolver;
use CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress\LegacySourceLocator;
use CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress\MigrationDataCopier;
use CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress\MigrationSchemaManager;
use CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress\WordPressLegacyMigrationGateway;
use CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress\WordPressLegacyInstallationPresence;
use CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress\WordPressLegacyUserResolver;
use CodingPanda\OjPlatform\Modules\Migration\Infrastructure\WordPress\WordPressMigrationDatabase;
use CodingPanda\OjPlatform\Modules\Migration\Module;
use CodingPanda\OjPlatform\Modules\Migration\Presentation\Action\MigrationActionHandler;
use CodingPanda\OjPlatform\Modules\Migration\Presentation\Console\MigrationPlanningPageHandler;
use CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\SchemaOwnershipMarker;
use CodingPanda\OjPlatform\Modules\PlatformDb\Application\SchemaSqlCompiler;
use CodingPanda\OjPlatform\Modules\PlatformDb\Contract\SchemaTableDefinition;
use CodingPanda\OjPlatform\Modules\Problems\Contract\ProblemTopicDictionary;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\ModuleWriteFreezeManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SystemOperationPolicy;
use CodingPanda\OjPlatform\Shared\Clock\Clock;

final class MigrationComposition
{
    /** @param DefinitionRegistry<SchemaTableDefinition> $tableSchemas */
    public function assemble(
        ErrorReporter $errors,
        DefinitionRegistry $tableSchemas,
        SchemaSqlCompiler $compiler,
        SchemaOwnershipMarker $ownership,
        SettingsManager $settings,
        SystemOperationPolicy $operations,
        ModuleWriteFreezeManager $freezes,
        JudgeConnectionSettingsProvider $judgeSettings,
        JudgeConnectionProbe $judgeProbe,
        Authorization $permissions,
        JobDispatcher $jobs,
        JobHandlerRegistry $jobHandlers,
        JobStepJournal $jobSteps,
        ProblemTopicDictionary $topics,
        Clock $clock,
        Clock $siteClock,
        AccountShellBundle $accountShell,
        array $extraTables = []
    ): MigrationBundle {
        $database = WordPressMigrationDatabase::fromGlobals();
        $sources = new LegacySourceLocator($database);
        $schemaManager = new MigrationSchemaManager(
            $tableSchemas,
            $compiler,
            $ownership,
            $settings,
            $database,
            $clock
        );
        $users = new WordPressLegacyUserResolver();
        $values = new LegacyTransformValues($users, $clock);
        $rowTransformer = new LegacyRowTransformer([
            new AuthSubmissionLegacyTransformer($values),
            new ProblemsLegacyTransformer($values, $topics),
            new LearningSpacesLegacyTransformer($values),
            new AiFeedbackLegacyTransformer($values),
        ]);
        $copier = new MigrationDataCopier(
            $database,
            $sources,
            $schemaManager,
            $rowTransformer
        );
        $gateway = new WordPressLegacyMigrationGateway(
            $database,
            $sources,
            $schemaManager,
            $copier,
            $operations,
            $judgeSettings,
            $judgeProbe
        );
        $catalog = new MigrationCatalog(array_merge((new MigrationCatalog())->all(), array_map(
            static fn (array $row) => new \CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationTable(
                $row['wave'] . '_' . $row['name'],
                \CodingPanda\OjPlatform\Modules\Migration\Domain\MigrationWave::from($row['wave']),
                null, $row['name'], 'id', 'none'
            ),
            $extraTables
        )));
        $legacyPresence = new WordPressLegacyInstallationPresence($catalog, $sources);
        $states = new MigrationStateService($settings);
        $plans = new MigrationPlanService($catalog);
        $commands = new MigrationCommandService(
            $states,
            $plans,
            $catalog,
            $gateway,
            $jobs,
            $freezes,
            $clock,
            $siteClock
        );
        $jobAccess = new MigrationJobAccessRule($permissions);
        $jobHandler = new PlatformMigrationJobHandler(
            $catalog,
            $gateway,
            $commands,
            $states,
            $jobSteps,
            $permissions,
            $errors
        );
        $jobHandlers->register($jobHandler);

        $page = new MigrationPlanningPageHandler(
            $accountShell->consolePages(),
            $states,
            $accountShell->actionUrls(),
            $accountShell->actionSecurity()
        );
        $accountShell->routeHandlers()->register(
            'console.settings.migration',
            $page
        );

        $actionDefinitions = [
            'oj_migration_scan' => 'scan',
            'oj_save_migration_settings' => 'save',
            'oj_migration_dry_run' => 'plan',
            'oj_migration_run' => 'run',
            'oj_migration_validate' => 'validate',
            'oj_migration_cleanup_report' => 'cleanup',
            'oj_migration_deactivate_legacy_plugins' => 'deactivate',
        ];
        $actionHandlers = [];
        foreach ($actionDefinitions as $actionKey => $operation) {
            $handler = new MigrationActionHandler(
                $actionKey,
                $operation,
                $commands,
                $states,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            );
            $accountShell->actionHandlers()->register($actionKey, $handler);
            $actionHandlers[$actionKey] = $handler;
        }

        return new MigrationBundle(
            new Module(),
            $jobAccess,
            $legacyPresence,
            [
                WordPressMigrationDatabase::class => $database,
                LegacySourceLocator::class => $sources,
                MigrationSchemaManager::class => $schemaManager,
                LegacyUserResolver::class => $users,
                WordPressLegacyUserResolver::class => $users,
                LegacyTransformValues::class => $values,
                LegacyRowTransformer::class => $rowTransformer,
                MigrationDataCopier::class => $copier,
                LegacyMigrationGateway::class => $gateway,
                WordPressLegacyMigrationGateway::class => $gateway,
                LegacyInstallationPresence::class => $legacyPresence,
                WordPressLegacyInstallationPresence::class => $legacyPresence,
                MigrationCatalog::class => $catalog,
                MigrationStateService::class => $states,
                MigrationPlanService::class => $plans,
                MigrationCommandService::class => $commands,
                MigrationJobAccessRule::class => $jobAccess,
                PlatformMigrationJobHandler::class => $jobHandler,
                MigrationPlanningPageHandler::class => $page,
            ] + $this->classMap($actionHandlers)
        );
    }

    /** @param array<string, object> $services
     *  @return array<class-string, object>
     */
    private function classMap(array $services): array
    {
        $indexed = [];
        foreach ($services as $service) {
            $indexed[$service::class] = $service;
        }

        return $indexed;
    }
}
