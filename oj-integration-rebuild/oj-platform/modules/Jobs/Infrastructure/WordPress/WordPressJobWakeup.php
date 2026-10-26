<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Modules\Jobs\Infrastructure\WordPress;

use CodingPanda\OjPlatform\Kernel\Error\ErrorReporter;
use CodingPanda\OjPlatform\Modules\Jobs\Contract\JobWakeup;
use RuntimeException;
use Throwable;

/** Internal, single-use loopback signals. No job payload travels over HTTP. */
final class WordPressJobWakeup implements JobWakeup
{
    public const ACTION = 'oj_platform_run_jobs';
    private const OPTION = 'oj_platform_jobs_wakeup';
    private const TTL = 60;

    public function __construct(
        private readonly object $database,
        private readonly ErrorReporter $errors
    ) {
    }

    public static function fromGlobals(ErrorReporter $errors): self
    {
        return new self($GLOBALS['wpdb'] ?? new \stdClass(), $errors);
    }

    public function wake(): void
    {
        $ticket = null;
        try {
            if (! function_exists('wp_remote_post') || ! function_exists('admin_url')) {
                return;
            }
            if ((string) $this->scalar('SELECT IS_FREE_LOCK(%s)', $this->lockName('run')) !== '1') {
                return;
            }
            $ticket = $this->issue();
            if ($ticket === null) {
                return;
            }
            $response = wp_remote_post(admin_url('admin-post.php'), [
                'blocking' => false,
                'timeout' => 1,
                'redirection' => 0,
                'sslverify' => true,
                'body' => ['action' => self::ACTION, 'ticket' => $ticket],
            ]);
            if (function_exists('is_wp_error') && is_wp_error($response)) {
                throw new RuntimeException('Jobs 즉시 실행 연결에 실패했습니다. 예약 실행으로 복구합니다.');
            }
        } catch (Throwable $error) {
            if ($ticket !== null) {
                try {
                    $this->consume($ticket);
                } catch (Throwable) {
                    // The ticket expires; durable queued items are unaffected.
                }
            }
            $this->errors->report($error);
        }
    }

    public function acquire(): bool
    {
        $result = $this->scalar('SELECT GET_LOCK(%s, 0)', $this->lockName('run'));
        if ($result === null) {
            throw new RuntimeException('Jobs 실행 잠금을 확인하지 못했습니다.');
        }
        return (string) $result === '1';
    }

    public function release(): void
    {
        $this->scalar('SELECT RELEASE_LOCK(%s)', $this->lockName('run'));
    }

    public function consume(string $ticket): bool
    {
        if (preg_match('/^[a-f0-9]{64}$/D', $ticket) !== 1) {
            return false;
        }
        $value = $this->stored();
        $state = is_string($value) ? json_decode($value, true) : null;
        if (! is_array($state)
            || (int) ($state['expires'] ?? 0) < time()
            || ! is_string($state['hash'] ?? null)
            || ! hash_equals($state['hash'], hash('sha256', $ticket))) {
            return false;
        }
        return $this->database->query($this->database->prepare(
            "DELETE FROM {$this->optionsTable()} WHERE option_name = %s AND option_value = %s",
            self::OPTION,
            $value
        )) === 1;
    }

    private function issue(): ?string
    {
        $name = $this->lockName('signal');
        if ((string) $this->scalar('SELECT GET_LOCK(%s, 0)', $name) !== '1') {
            return null;
        }
        try {
            $state = json_decode((string) $this->stored(), true);
            if (is_array($state) && (int) ($state['expires'] ?? 0) >= time()) {
                return null;
            }
            $ticket = bin2hex(random_bytes(32));
            $value = json_encode(['hash' => hash('sha256', $ticket), 'expires' => time() + self::TTL], JSON_THROW_ON_ERROR);
            $written = $this->database->query($this->database->prepare(
                "INSERT INTO {$this->optionsTable()} (option_name, option_value, autoload)
                VALUES (%s, %s, 'no') ON DUPLICATE KEY UPDATE option_value = VALUES(option_value), autoload = 'no'",
                self::OPTION,
                $value
            ));
            if ($written === false) {
                throw new RuntimeException('Jobs 실행 신호를 저장하지 못했습니다.');
            }
            return $ticket;
        } finally {
            $this->scalar('SELECT RELEASE_LOCK(%s)', $name);
        }
    }

    private function stored(): mixed
    {
        return $this->scalar("SELECT option_value FROM {$this->optionsTable()} WHERE option_name = %s", self::OPTION);
    }

    private function scalar(string $sql, string $argument): mixed
    {
        $value = $this->database->get_var($this->database->prepare($sql, $argument));
        if (($this->database->last_error ?? '') !== '') {
            throw new RuntimeException('Jobs 실행 상태를 확인하지 못했습니다.');
        }
        return $value;
    }

    private function optionsTable(): string
    {
        $table = (string) ($this->database->options ?? '');
        if (preg_match('/^[a-zA-Z0-9_]+$/D', $table) !== 1) {
            throw new RuntimeException('WordPress 설정 테이블을 확인하지 못했습니다.');
        }
        return $table;
    }

    private function lockName(string $purpose): string
    {
        return 'oj_jobs_' . $purpose . '_' . substr(hash('sha256',
            (defined('DB_NAME') ? DB_NAME : '') . ':' . $this->optionsTable()
        ), 0, 40);
    }
}
