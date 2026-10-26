<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Ops;

use OJML\Playgrounds\Domain\Problem;

/** Connection-scoped MySQL advisory locks are released even if PHP terminates. */
final class Lease
{
    private function __construct(private readonly object $database, private ?string $name) {}

    public static function acquire(string $purpose, int $slots = 1, int $wait = 0): self
    {
        global $wpdb;
        $prefix = 'ojml:' . substr(hash('sha256', (string) $wpdb->dbname . ':' . $purpose), 0, 40);
        for ($slot = 0; $slot < max(1, min(8, $slots)); $slot++) {
            $name = $prefix . ':' . $slot;
            $result = $wpdb->get_var($wpdb->prepare('SELECT GET_LOCK(%s, %d)', $name, $wait));
            if ((string) $result === '1') { return new self($wpdb, $name); }
            if ($result === null || (string) ($wpdb->last_error ?? '') !== '') {
                throw new Problem('OJ_ML_LEASE_UNAVAILABLE', '서버 작업 잠금을 사용할 수 없습니다.', 503);
            }
        }
        throw new Problem('OJ_ML_SERVER_BUSY', '서버에서 다른 제출을 처리 중입니다. 잠시 후 다시 시도하세요.', 429);
    }

    public function release(): void
    {
        if ($this->name !== null) {
            $name = $this->name;
            $this->name = null;
            $this->database->get_var($this->database->prepare('SELECT RELEASE_LOCK(%s)', $name));
        }
    }

    public function __destruct() { $this->release(); }
}
