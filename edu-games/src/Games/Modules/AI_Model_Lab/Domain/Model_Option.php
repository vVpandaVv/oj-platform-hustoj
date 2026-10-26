<?php
declare(strict_types=1);

namespace EduGames\Games\Modules\AI_Model_Lab\Domain;

final class Model_Option {
    /**
     * @param array<string, int> $stats
     */
    private function __construct(
        private readonly string $key,
        private readonly string $label,
        private readonly string $description,
        private readonly array $stats
    ) {
    }

    /**
     * @param array<string, mixed> $config
     */
    public static function fromArray(array $config): self {
        $stats = (array) ($config['stats'] ?? array());

        return new self(
            (string) ($config['key'] ?? ''),
            (string) ($config['label'] ?? ''),
            (string) ($config['description'] ?? ''),
            array(
                'speed' => (int) ($stats['speed'] ?? 0),
                'interpretability' => (int) ($stats['interpretability'] ?? 0),
                'data_need' => (int) ($stats['data_need'] ?? 0),
            )
        );
    }

    public function key(): string {
        return $this->key;
    }

    public function label(): string {
        return $this->label;
    }

    public function description(): string {
        return $this->description;
    }

    /**
     * @return array<string, int>
     */
    public function stats(): array {
        return $this->stats;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array {
        return array(
            'key' => $this->key,
            'label' => $this->label,
            'description' => $this->description,
            'stats' => $this->stats,
        );
    }
}
