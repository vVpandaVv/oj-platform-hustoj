<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Learning;

use CodingPanda\OjPlatform\Modules\AccessRouter\Contract\RouteMatch;
use CodingPanda\OjPlatform\Modules\Classes\Application\ClassService;
use CodingPanda\OjPlatform\Modules\PublicUi\Contract\ConsoleRouteAccessRule;
use DomainException;

final class ClassConsoleRouteAccessRule implements ConsoleRouteAccessRule
{
    public function __construct(private readonly ClassService $classes)
    {
    }

    public function supports(RouteMatch $route): bool
    {
        $name = $route->route()->key();

        return $name === 'console.learning.index'
            || str_starts_with($name, 'console.classes.');
    }

    public function allows(int $userId, RouteMatch $route): bool
    {
        $name = $route->route()->key();
        if ($name === 'console.learning.index') {
            return $this->classes->managedBy($userId) !== [];
        }
        if ($name === 'console.classes.new') {
            return false;
        }
        if (in_array($name, ['console.classes.index', 'console.classes.deleted'], true)) {
            return $this->classes->managedBy($userId) !== [];
        }
        $classId = (int) ($route->parameters()['class_id'] ?? 0);
        try {
            $class = $this->classes->findManaged($classId, $userId);

            return $name !== 'console.classes.delete'
                || $this->classes->canArchive($userId, $class);
        } catch (DomainException) {
            return false;
        }
    }
}
