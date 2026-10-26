<?php

declare(strict_types=1);

use CodingPanda\OjPlatform\Kernel\Autoloader;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider;
use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\HttpRequest;
use CodingPanda\OjPlatform\Modules\Auth\Contract\UserSubmissionPreferences;
use CodingPanda\OjPlatform\Modules\Problems\Contract\SubmissionCodePolicy;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsManager;
use CodingPanda\OjPlatform\Modules\Settings\Contract\SettingsSnapshot;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionFormRenderer;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionLanguageCatalog;
use CodingPanda\OjPlatform\Modules\Submissions\Application\SubmissionSettingsProvider;
use CodingPanda\OjPlatform\Modules\Submissions\Presentation\SubmissionHtml;
use CodingPanda\OjPlatform\Shared\CodeOrdering\CodeOrderingPuzzle;

require_once dirname(__DIR__, 2) . '/src/Kernel/Autoloader.php';
Autoloader::register(dirname(__DIR__, 2));

/** @return array{html:string,ids:list<string>,hash:string} */
function codeOrderingUiFixture(?int $actorUserId = null): array
{
    $schema = CodeOrderingPuzzle::create(
        "total = 0\nfor number in range(3):\n    total += number\nprint(total)\nprint('<script>unsafe()</script>')\n",
        'python'
    );
    $encoded = CodeOrderingPuzzle::encode($schema);
    $hash = CodeOrderingPuzzle::hash($encoded);
    $policy = new SubmissionCodePolicy(
        4096, '합계 배열', 'code_ordering', [],
        'private_front(); #hidden', 'PRIVATE_STARTER_ORDER', '', null, null, null,
        'problem-code-policy-v1:' . str_repeat('c', 64), $encoded, $hash
    );
    $reflection = new ReflectionClass(SubmissionFormRenderer::class);
    $renderer = $reflection->newInstanceWithoutConstructor();
    $properties = [
        'settings' => new SubmissionSettingsProvider(new class implements SettingsManager {
            public function read(string $key, mixed $default = null): SettingsSnapshot
            {
                return new SettingsSnapshot(1, $default);
            }
            public function save(string $key, mixed $value, int $expectedRevision): SettingsSnapshot
            {
                return new SettingsSnapshot($expectedRevision + 1, $value);
            }
        }),
        'languages' => new SubmissionLanguageCatalog(),
        'html' => (new ReflectionClass(SubmissionHtml::class))->newInstanceWithoutConstructor(),
        'actionUrls' => new class implements ActionUrlProvider {
            public function actionUrl(): string { return '/submit-action'; }
            public function ajaxUrl(): string { return '/ajax'; }
        },
        'security' => new class implements ActionSecurity {
            public function hiddenFields(string $actionKey): string
            {
                return '<input type="hidden" name="action" value="' . $actionKey
                    . '"><input type="hidden" name="nonce" value="fixture-token">';
            }
            public function verify(string $actionKey, HttpRequest $request): bool { return true; }
        },
    ];
    foreach ($properties as $name => $value) {
        $reflection->getProperty($name)->setValue($renderer, $value);
    }
    return [
        'html' => $renderer->render(4096, $policy, null, new UserSubmissionPreferences(), [
            'contest_id' => 0, 'contest_problem_number' => -1, 'class_id' => 77, 'group_id' => null,
        ], $actorUserId),
        'ids' => array_column($schema['blocks'], 'id'),
        'hash' => $hash,
    ];
}

if (realpath((string) ($_SERVER['SCRIPT_FILENAME'] ?? '')) === __FILE__) {
    echo json_encode(codeOrderingUiFixture(), JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE);
}
