<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel;

use CodingPanda\OjPlatform\Kernel\Composition\CompositionRoot;
use CodingPanda\OjPlatform\Kernel\Environment\PluginEnvironment;
use CodingPanda\OjPlatform\Kernel\Environment\RequirementChecker;
use CodingPanda\OjPlatform\Kernel\Error\ErrorBoundary;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressErrorReporter;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressFailurePresenter;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressHookRegistrar;
use CodingPanda\OjPlatform\Kernel\WordPress\WordPressRuntime;

final class Bootstrap
{
    public static function start(PluginEnvironment $environment): void
    {
        $hooks = new WordPressHookRegistrar();
        $presenter = new WordPressFailurePresenter($hooks);
        $runtime = new WordPressRuntime();
        $requirements = (new RequirementChecker())->check(
            $environment,
            $runtime->version(),
            PHP_VERSION,
            $runtime->isMultisite()
        );

        if ($requirements->isFailure()) {
            $presenter->present($requirements->errors());

            return;
        }

        $reporter = new WordPressErrorReporter();
        $boundary = new ErrorBoundary($reporter, $presenter);
        $compositionRoot = new CompositionRoot($environment, $hooks, $reporter);

        $hooks->addAction(
            'plugins_loaded',
            static function () use ($boundary, $compositionRoot): void {
                \CodingPanda\OjPlatform\Kernel\Setup\WordPress\ServerInstallScreen::register();
                $boundary->execute(
                    static fn () => $compositionRoot->boot()
                );
            },
            1
        );
    }
}
