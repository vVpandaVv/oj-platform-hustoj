<?php
/**
 * Asset registration.
 *
 * @package ContentsEditor\Infrastructure
 */

declare(strict_types=1);

namespace ContentsEditor\Infrastructure;

final class Assets
{
    private const ADMIN_HANDLE = 'contents-editor-admin';

    private const PUBLIC_HANDLE = 'contents-editor-public';

    public function register(): void
    {
        add_action('init', [$this, 'registerAssets']);
    }

    public function registerAssets(): void
    {
        $admin_js = CONTENTS_EDITOR_PATH . 'assets/editor/admin.js';
        $admin_css = CONTENTS_EDITOR_PATH . 'assets/editor/admin.css';
        $public_js = CONTENTS_EDITOR_PATH . 'assets/editor/public.js';
        $public_css = CONTENTS_EDITOR_PATH . 'assets/editor/public.css';

        if (function_exists('wp_register_script_module')) {
            wp_register_script_module(
                self::ADMIN_HANDLE,
                CONTENTS_EDITOR_URL . 'assets/editor/admin.js',
                [],
                file_exists($admin_js) ? (string) filemtime($admin_js) : CONTENTS_EDITOR_VERSION
            );
        } else {
            wp_register_script(
                self::ADMIN_HANDLE,
                CONTENTS_EDITOR_URL . 'assets/editor/admin.js',
                [],
                file_exists($admin_js) ? (string) filemtime($admin_js) : CONTENTS_EDITOR_VERSION,
                true
            );
            wp_script_add_data(self::ADMIN_HANDLE, 'type', 'module');
        }

        wp_register_style(
            self::ADMIN_HANDLE,
            CONTENTS_EDITOR_URL . 'assets/editor/admin.css',
            [],
            file_exists($admin_css) ? (string) filemtime($admin_css) : CONTENTS_EDITOR_VERSION
        );

        if (function_exists('wp_register_script_module')) {
            wp_register_script_module(
                self::PUBLIC_HANDLE,
                CONTENTS_EDITOR_URL . 'assets/editor/public.js',
                [],
                file_exists($public_js) ? (string) filemtime($public_js) : CONTENTS_EDITOR_VERSION
            );
        } else {
            wp_register_script(
                self::PUBLIC_HANDLE,
                CONTENTS_EDITOR_URL . 'assets/editor/public.js',
                [],
                file_exists($public_js) ? (string) filemtime($public_js) : CONTENTS_EDITOR_VERSION,
                true
            );
            wp_script_add_data(self::PUBLIC_HANDLE, 'type', 'module');
        }

        wp_register_style(
            self::PUBLIC_HANDLE,
            CONTENTS_EDITOR_URL . 'assets/editor/public.css',
            [],
            file_exists($public_css) ? (string) filemtime($public_css) : CONTENTS_EDITOR_VERSION
        );
    }

    public function enqueueAdmin(): void
    {
        wp_enqueue_style(self::ADMIN_HANDLE);

        if (function_exists('wp_enqueue_script_module')) {
            wp_enqueue_script_module(self::ADMIN_HANDLE);
        } else {
            wp_enqueue_script(self::ADMIN_HANDLE);
            wp_add_inline_script(
                self::ADMIN_HANDLE,
                $this->adminBootDiagnosticsScript(),
                'before'
            );
        }
    }

    public function enqueuePublic(): void
    {
        if (file_exists(CONTENTS_EDITOR_PATH . 'assets/editor/public.css')) {
            wp_enqueue_style(self::PUBLIC_HANDLE);
        }

        if (! file_exists(CONTENTS_EDITOR_PATH . 'assets/editor/public.js')) {
            return;
        }

        if (function_exists('wp_enqueue_script_module')) {
            wp_enqueue_script_module(self::PUBLIC_HANDLE);
        } else {
            wp_enqueue_script(self::PUBLIC_HANDLE);
        }
    }

    public function adminBuildExists(): bool
    {
        return file_exists(CONTENTS_EDITOR_PATH . 'assets/editor/admin.js')
            && file_exists(CONTENTS_EDITOR_PATH . 'assets/editor/admin.css');
    }

    public function adminSettings(): array
    {
        $max_upload_size = function_exists('wp_max_upload_size') ? (int) wp_max_upload_size() : 0;

        return [
            'version' => CONTENTS_EDITOR_VERSION,
            'restUrl' => esc_url_raw(rest_url()),
            'restNamespace' => 'readcontents-library/v1',
            'nonce' => wp_create_nonce('wp_rest'),
            'maxUploadSize' => $max_upload_size,
            'maxUploadSizeLabel' => $max_upload_size > 0 && function_exists('size_format')
                ? size_format($max_upload_size)
                : '',
        ];
    }

    public function adminBootDiagnosticsScript(): string
    {
        return <<<'JS'
(function () {
  function showContentsEditorBootError(message) {
    var root = document.getElementById('contents-editor-admin-root');
    if (!root || root.dataset.contentsEditorMounted === '1') {
      return;
    }

    root.innerHTML = '<div class="notice notice-error inline"><p><strong>콘텐츠 에디터를 시작하지 못했습니다.</strong> ' +
      String(message).replace(/[&<>"']/g, function (character) {
        return {
          '&': '&amp;',
          '<': '&lt;',
          '>': '&gt;',
          '"': '&quot;',
          "'": '&#039;'
        }[character];
      }) +
      '</p></div>';
  }

  window.addEventListener('error', function (event) {
    showContentsEditorBootError(event.message || '관리자 스크립트 실행 중 오류가 발생했습니다.');
  });

  window.addEventListener('unhandledrejection', function (event) {
    var reason = event.reason;
    showContentsEditorBootError(reason && reason.message ? reason.message : '관리자 스크립트 비동기 실행 중 오류가 발생했습니다.');
  });

  window.setTimeout(function () {
    var root = document.getElementById('contents-editor-admin-root');
    if (root && root.dataset.contentsEditorMounted !== '1') {
      showContentsEditorBootError('관리자 스크립트가 실행되지 않았습니다. admin.js가 type="module"로 로드되는지 확인하세요.');
    }
  }, 5000);
})();
JS;
    }
}
