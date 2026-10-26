<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\AI_Model_Lab;

use EduGames\Games\Modules\AI_Model_Lab\Domain\Activity_Definition;

/**
 * Lazily builds and caches an Activity_Definition per raw config array
 * handed in at construction time. Callers (the REST controllers) build a
 * fresh catalog per request from the two activities/*.php files — there is
 * no persistent registry singleton for this game.
 */
final class Activity_Catalog {
    /** @var array<string, array<string, mixed>> */
    private array $configs;

    /** @var array<string, Activity_Definition> */
    private array $cache = array();

    /**
     * @param array<string, array<string, mixed>> $configs key => raw config array
     */
    public function __construct(array $configs) {
        $this->configs = $configs;
    }

    /**
     * @return array<string, Activity_Definition>
     */
    public function all(): array {
        $definitions = array();

        foreach (array_keys($this->configs) as $key) {
            $definition = $this->find((string) $key);
            if (null !== $definition) {
                $definitions[$key] = $definition;
            }
        }

        return $definitions;
    }

    public function find(string $key): ?Activity_Definition {
        if (isset($this->cache[$key])) {
            return $this->cache[$key];
        }

        if (! isset($this->configs[$key])) {
            return null;
        }

        $definition = Activity_Definition::fromArray($this->configs[$key]);
        $this->cache[$key] = $definition;

        return $definition;
    }
}
