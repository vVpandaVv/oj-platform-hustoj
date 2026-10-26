<?php
declare(strict_types=1);
namespace CodingPanda\OjPlatform\AddonApi\V1\Research;

final class ResearchHostV1
{
    public function __construct(
        public readonly \CodingPanda\OjPlatform\Modules\PlatformDb\Contract\ApplicationDatabase $database,
        public readonly \CodingPanda\OjPlatform\Modules\Settings\Contract\FeatureFlags $features,
        public readonly \CodingPanda\OjPlatform\Modules\Permission\Contract\Authorization $permissions,
        public readonly \CodingPanda\OjPlatform\Shared\Ai\AiTaskRouter $ai,
        public readonly \CodingPanda\OjPlatform\Shared\Clock\Clock $clock,
        public readonly \CodingPanda\OjPlatform\Kernel\Error\ErrorReporter $errors,
        public readonly \CodingPanda\OjPlatform\Kernel\Registry\DefinitionRegistry $schemas,
        public readonly \CodingPanda\OjPlatform\Modules\Groups\Contract\GroupContextAccess $groups,
        public readonly \CodingPanda\OjPlatform\Modules\Auth\Contract\WordPressUserGateway $users,
        public readonly \CodingPanda\OjPlatform\AddonApi\V1\Research\ResearchLearningDataV1 $learning,
        public readonly \CodingPanda\OjPlatform\Modules\Auth\Contract\CurrentAccountProvider $accounts,
        public readonly \CodingPanda\OjPlatform\Modules\AdminConsole\Contract\ConsoleResponder $console,
        public readonly \CodingPanda\OjPlatform\Modules\PublicUi\Contract\PageResponder $pages,
        public readonly \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionUrlProvider $urls,
        public readonly \CodingPanda\OjPlatform\Modules\AccessRouter\Contract\ActionSecurity $security,
        public readonly \CodingPanda\OjPlatform\Modules\Auth\Contract\ActionAccessGuard $guard
    ) {}
}
