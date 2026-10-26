<?php
declare(strict_types=1);
namespace CodingPanda\OjResearch\Core;

use CodingPanda\OjPlatform\AddonApi\V1\Research\{ResearchExtensionV1, ResearchHostV1, ResearchRuntimeV1};
use CodingPanda\OjPlatform\Modules\PublicUi\Domain\PublicMenuEntry;
use CodingPanda\OjResearch\Application\{ResearchService, PilotService, ResearchFeatureDisableGuard, ResearchAccountErasureParticipant};
use CodingPanda\OjResearch\Infrastructure\{WordPressResearchRepository, PlatformLearningGateway};
use CodingPanda\OjResearch\Presentation\{ResearchPageHandler, ResearchActionHandler, PilotPages, PilotActions, PilotProblemSection};
use CodingPanda\OjResearch\Module;

final class ResearchExtension implements ResearchExtensionV1
{
    public function __construct(private readonly string $file, private readonly ?\Closure $onReady = null) {}
    public function configuration(): array { return require dirname($this->file) . '/config/platform.php'; }

    public function build(ResearchHostV1 $host): ResearchRuntimeV1
    {
        $repository = new WordPressResearchRepository($host->database);
        $learning = new PlatformLearningGateway($host->learning);
        $service = new ResearchService($repository, $host->groups, $learning, $host->users, $host->permissions, $host->features, $host->ai, $host->clock);
        $pilot = new PilotService($service, $repository, $learning, $learning, $host->features, $host->ai, $host->clock);
        $service->attachPilot($pilot);
        $pilotPages = new PilotPages($service, $pilot, $host->urls, $host->security);
        $pilotActions = new PilotActions($service, $pilot);
        $page = new ResearchPageHandler($service, $host->accounts, $host->console, $host->pages, $host->urls, $host->security, $pilotPages, assets: $this->assets());
        $routes = [];
        foreach ($this->configuration()['routes'] as $route) { $routes[$route['name']] = $page; }
        $guard = new ResearchFeatureDisableGuard($repository);
        if ($this->onReady !== null) {
            ($this->onReady)($guard, static function () use ($host): bool {
                $count = $host->database->value('SELECT COUNT(*) FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = %s', ['research_studies']);
                if (! is_numeric($count)) { throw new \DomainException('연구 설치 상태를 확인할 수 없습니다.'); }
                return (int) $count > 0;
            });
        }
        return new ResearchRuntimeV1(
            new Module($host->schemas, require dirname($this->file) . '/config/schema.php'),
            $service, $service, $pilot, $pilot, $guard, new ResearchAccountErasureParticipant($host->database),
            new PilotProblemSection($pilot), $routes,
            ['oj_research_command' => new ResearchActionHandler($service, $host->guard, $host->errors, $pilotActions)],
            [new PublicMenuEntry('research.index', '연구 활동', 2, 'learning_support', 'research.index', null, 'all', 30, 'primary', true)]
        );
    }

    private function assets(): string
    {
        if (! function_exists('plugins_url')) { return ''; }
        $version = defined('OJ_RESEARCH_VERSION') ? OJ_RESEARCH_VERSION : '1.0.0';
        $escape = static fn(string $s): string => htmlspecialchars($s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
        return '<link rel="stylesheet" href="' . $escape(plugins_url('assets/research.css', $this->file) . '?ver=' . $version) . '">'
            . '<script defer src="' . $escape(plugins_url('assets/research.js', $this->file) . '?ver=' . $version) . '"></script>';
    }
}
