<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Composition;

use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklyProfileCatalog;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklyPythonGenerator;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklySettingsProvider;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklySettingsService;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklySourceMode;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklyStoredSourceCodec;
use CodingPanda\OjPlatform\Modules\Blockly\Application\BlocklyWorkspaceValidator;
use CodingPanda\OjPlatform\Modules\Blockly\Contract\BlocklyAssets;
use CodingPanda\OjPlatform\Modules\Blockly\Infrastructure\WordPress\WordPressBlocklyAssets;
use CodingPanda\OjPlatform\Modules\Blockly\Module;
use CodingPanda\OjPlatform\Modules\Blockly\Presentation\Action\SaveBlocklySettingsActionHandler;
use CodingPanda\OjPlatform\Modules\Blockly\Presentation\BlocklyEditorExtension;
use CodingPanda\OjPlatform\Modules\Blockly\Presentation\Console\BlocklySettingsPageHandler;
use CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;

final class BlocklyComposition
{
    public function assemble(
        PluginEnvironment $environment,
        ErrorReporter $errors,
        SettingsManager $settings,
        FeatureFlags $features,
        AccountShellBundle $accountShell,
        SubmissionsBundle $submissions
    ): BlocklyBundle {
        $profiles = new BlocklyProfileCatalog();
        $settingProvider = new BlocklySettingsProvider(
            $settings,
            $features
        );
        $settingService = new BlocklySettingsService(
            $settings,
            $profiles
        );
        $assets = new WordPressBlocklyAssets($environment);
        $validator = new BlocklyWorkspaceValidator(
            $profiles,
            $settingProvider
        );
        $generator = new BlocklyPythonGenerator();
        $codec = new BlocklyStoredSourceCodec();
        $sourceMode = new BlocklySourceMode(
            $validator,
            $generator,
            $codec
        );
        $editor = new BlocklyEditorExtension(
            $settingProvider,
            $profiles,
            $assets
        );
        $submissions->sourceModes()->register($sourceMode);
        $submissions->editorExtensions()->register($editor);
        $submissions->storedSourceDecoders()->register($codec);

        $accountShell->routeHandlers()->register(
            'console.submissions.block_coding',
            new BlocklySettingsPageHandler(
                $accountShell->consolePages(),
                $settings,
                $settingProvider,
                $profiles,
                $assets,
                $accountShell->actionUrls(),
                $accountShell->actionSecurity()
            )
        );
        $accountShell->actionHandlers()->register(
            'oj_save_blockly_settings',
            new SaveBlocklySettingsActionHandler(
                $settingService,
                $accountShell->actionGuard(),
                $accountShell->actionResponder(),
                $errors
            )
        );

        return new BlocklyBundle(new Module(), [
            BlocklyProfileCatalog::class => $profiles,
            BlocklySettingsProvider::class => $settingProvider,
            BlocklySettingsService::class => $settingService,
            BlocklyAssets::class => $assets,
            WordPressBlocklyAssets::class => $assets,
            BlocklyWorkspaceValidator::class => $validator,
            BlocklyPythonGenerator::class => $generator,
            BlocklyStoredSourceCodec::class => $codec,
            BlocklySourceMode::class => $sourceMode,
            BlocklyEditorExtension::class => $editor,
        ]);
    }
}
