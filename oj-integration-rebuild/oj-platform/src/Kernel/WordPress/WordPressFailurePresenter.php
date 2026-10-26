<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\WordPress;

use CodingPanda\OjPlatform\Kernel\Error\FailurePresenter;
use CodingPanda\OjPlatform\Kernel\Hook\HookRegistrar;

final class WordPressFailurePresenter implements FailurePresenter
{
    /**
     * @var list<string>
     */
    private array $messages = [];

    private bool $registered = false;

    public function __construct(private readonly HookRegistrar $hooks)
    {
    }

    public function present(array $messages): void
    {
        foreach ($messages as $message) {
            $message = trim($message);
            if ($message !== '' && ! in_array($message, $this->messages, true)) {
                $this->messages[] = $message;
            }
        }

        if ($this->messages === [] || $this->registered) {
            return;
        }

        $this->registered = true;
        $this->hooks->addAction('admin_notices', function (): void {
            if (function_exists('current_user_can') && ! current_user_can('manage_options')) {
                return;
            }

            foreach ($this->messages as $message) {
                $escaped = function_exists('esc_html')
                    ? esc_html($message)
                    : htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

                echo '<div class="notice notice-error"><p>' . $escaped . '</p></div>';
            }
        });
    }
}
