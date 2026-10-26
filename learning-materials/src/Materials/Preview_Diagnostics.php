<?php
declare(strict_types=1);

namespace LearningMaterials\Materials;

use Throwable;

/** Only server-generated URLs are fetched; signed links and paths stay out of results. */
final class Preview_Diagnostics {
    public static function run(Material $material, array $groups, ?int $previous_expiry = null): array {
        $checks = array(array('label' => __('공개 상태', 'learning-materials'), 'status' => $material->is_available() ? 'ok' : 'warning',
            'message' => $material->is_available() ? __('현재 공개 중입니다.', 'learning-materials') : __('현재 학생에게 공개되지 않습니다. 관리자는 미리볼 수 있습니다.', 'learning-materials')));
        if ($previous_expiry !== null) {
            $expired = $previous_expiry <= time();
            $checks[] = array('label' => __('기존 임시 링크', 'learning-materials'), 'status' => $expired ? 'error' : 'ok',
                'message' => $expired ? __('링크가 만료됐습니다. 다시 시도하면 갱신됩니다.', 'learning-materials') : __('유효시간이 남아 있습니다.', 'learning-materials'));
        }
        if (! $material->is_google_link()) {
            try {
                $info = (new Material_Storage())->info($material->storage_filename);
                $same = $info['size'] === $material->file_size && $info['mime'] === $material->mime_type;
                $checks[] = array('label' => __('원본 파일', 'learning-materials'), 'status' => $same ? 'ok' : 'error',
                    'message' => $same ? __('파일을 읽을 수 있으며 크기와 형식이 일치합니다.', 'learning-materials') : __('저장된 파일의 크기 또는 형식이 등록 정보와 다릅니다.', 'learning-materials'));
                if (! $same) return $checks;
            } catch (Throwable $error) {
                $checks[] = array('label' => __('원본 파일', 'learning-materials'), 'status' => 'error',
                    'message' => __('파일이 없거나 읽기·MIME 검사에 실패했습니다.', 'learning-materials'));
                return $checks;
            }
            if (! $material->uses_google_viewer()) {
                $checks[] = array('label' => __('뷰어', 'learning-materials'), 'status' => 'ok',
                    'message' => __('사이트 내장 뷰어를 사용합니다. Google 연결이 필요하지 않습니다.', 'learning-materials'));
                return $checks;
            }
        }
        try {
            $data = Preview_Link::data($material, $groups, true);
        } catch (Throwable $error) {
            $checks[] = array('label' => __('미리보기 주소', 'learning-materials'), 'status' => 'error',
                'message' => __('HTTPS 설정 또는 Google 공유 링크를 확인해 주세요.', 'learning-materials'));
            return $checks;
        }
        if (! $material->is_google_link()) {
            parse_str((string) parse_url($data['viewer_url'], PHP_URL_QUERY), $query);
            $response = wp_safe_remote_get($query['url'], array('timeout' => 8, 'redirection' => 0, 'limit_response_size' => 64,
                'headers' => array('Range' => 'bytes=0-63'), 'cookies' => array()));
            $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
            $mime = is_wp_error($response) ? '' : (string) wp_remote_retrieve_header($response, 'content-type');
            $ok = in_array($code, array(200, 206), true) && strtolower(trim(explode(';', $mime)[0])) === strtolower($material->content_type());
            $checks[] = array('label' => __('로그인 없는 파일 접근', 'learning-materials'), 'status' => $ok ? 'ok' : 'error',
                'message' => $ok ? __('임시 링크로 파일에 접근할 수 있습니다.', 'learning-materials')
                    : sprintf(__('파일 접근 실패 (HTTP %s). HTTPS·방화벽·로그인 리디렉션을 확인해 주세요.', 'learning-materials'), $code ?: __('연결 실패', 'learning-materials')));
            if (! $ok) return $checks;
        }
        $response = wp_safe_remote_get($data['viewer_url'], array('timeout' => 8, 'redirection' => 0, 'limit_response_size' => 16384, 'cookies' => array()));
        $code = is_wp_error($response) ? 0 : (int) wp_remote_retrieve_response_code($response);
        $frame = is_wp_error($response) ? '' : strtolower((string) wp_remote_retrieve_header($response, 'x-frame-options'));
        $csp = is_wp_error($response) ? '' : (string) wp_remote_retrieve_header($response, 'content-security-policy');
        $mime = is_wp_error($response) ? '' : strtolower((string) wp_remote_retrieve_header($response, 'content-type'));
        $status = 'error';
        if ($code === 204) {
            $status = 'warning';
            $message = __('Google이 빈 응답(204)을 반환했습니다. 자동 재시도 또는 새 창 보기를 이용해 주세요.', 'learning-materials');
        } elseif ($frame !== '' || preg_match("/frame-ancestors\\s+'(?:none|self)'/i", $csp)) {
            $message = __('Google 응답이 외부 iframe 표시를 제한합니다. 새 창에서 열어 주세요.', 'learning-materials');
        } elseif ($code === 200 && str_contains($mime, 'text/html')) {
            $status = $material->is_google_link() ? 'warning' : 'ok';
            $message = $material->is_google_link()
                ? __('Google 응답을 받았습니다. 원본 공유 권한과 브라우저의 Google 로그인은 별도로 확인해 주세요.', 'learning-materials')
                : __('Google 뷰어 HTML 응답은 정상입니다. 브라우저의 실제 슬라이드 표시까지 보장하지는 않습니다.', 'learning-materials');
        } else {
            $message = sprintf(__('Google 뷰어 응답 오류 (HTTP %s). 새 창에서 열거나 원본을 이용해 주세요.', 'learning-materials'), $code ?: __('연결 실패', 'learning-materials'));
        }
        $checks[] = array('label' => __('Google 뷰어', 'learning-materials'), 'status' => $status, 'message' => $message);
        return $checks;
    }
}
