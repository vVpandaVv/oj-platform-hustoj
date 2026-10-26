<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1 {
    // The constructor and accessors exported by supported platform releases before API 1.8.
    final class AddonLearningCheckContextQueryV1 {
        public function __construct(private int $actorUserId, private int $problemId, private ?int $submissionId = null,
            private string $contextType = 'general', private ?int $contextId = null) {}
        public function actorUserId(): int { return $this->actorUserId; }
        public function problemId(): int { return $this->problemId; }
        public function submissionId(): ?int { return $this->submissionId; }
        public function contextType(): string { return $this->contextType; }
        public function contextId(): ?int { return $this->contextId; }
    }
}
namespace {
    $pluginRoot = dirname(__DIR__);
    $platformRoot = dirname($pluginRoot) . '/oj-integration-rebuild/oj-platform';
    require_once $platformRoot . '/src/Kernel/Autoloader.php';
    \CodingPanda\OjPlatform\Kernel\Autoloader::register($platformRoot);
    require_once $pluginRoot . '/src/Core/Autoloader.php'; \OjPrimm\Core\Autoloader::register();
    function get_current_user_id(): int { return 7; }
    $learning = new class implements \CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1 {
        public array $queries = [];
        public function context(\CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextQueryV1 $query): \CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextResultV1 {
            $this->queries[] = $query;
            return new \CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckContextResultV1(true, [
                'problem' => ['id' => 100, 'title' => '문제', 'description' => '', 'input' => '', 'output' => '',
                    'sample_input' => '', 'sample_output' => '', 'content_hash' => hash('sha256', 'fixture')], 'submission' => null,
            ], null);
        }
    };
    $runtime = new class($learning) implements \CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckRuntimeContextV1 {
        public function __construct(private \CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1 $learning) {}
        public function snapshot(): \CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeSnapshotV1 {
            return new \CodingPanda\OjPlatform\AddonApi\V1\AddonRuntimeSnapshotV1('oj-primm', '1.0.5', '>=2.4.3 <3.0.0', '2.4.3', '^1.7', '1.7.0',
                'accepted', '', 'catalog:fallback', [], 'fallback:runtime');
        }
        public function contexts(): \CodingPanda\OjPlatform\AddonApi\V1\AddonContextGatewayV1 { throw new \LogicException('Unused'); }
        public function aiTasks(): \CodingPanda\OjPlatform\AddonApi\V1\AddonAiTaskGatewayV1 { throw new \LogicException('Unused'); }
        public function learningChecks(): \CodingPanda\OjPlatform\AddonApi\V1\AddonLearningCheckGatewayV1 { return $this->learning; }
    };
    $gateway = new \OjPrimm\Support\PlatformGateway(static fn () => $runtime);
    $gateway->problem(100, 'class', 'class:5'); $gateway->submission(100, 'class', 'class:5');
    if (count($learning->queries) !== 2 || $learning->queries[0]->actorUserId() !== 7 || $learning->queries[1]->contextId() !== 5
        || method_exists($learning->queries[0], 'projection')) { throw new \RuntimeException('Older supported platforms must retain the five-argument learning-check path.'); }
    echo "PRIMM legacy learning projection fallback passed\n";
}
