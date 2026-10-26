<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Error;

use Throwable;

/** Only curated descriptions enter stored diagnostics; never arbitrary exception text. */
final class ExceptionDiagnostics
{
    /** @return array<string, string> */
    public function describe(Throwable $error): array
    {
        $operation = $this->operation($error);
        $detail = null;
        $root = $error;
        for ($cause = $error, $depth = 0; $cause !== null && $depth < 4; $cause = $cause->getPrevious(), ++$depth) {
            $root = $cause;
            $detail = $this->knownCause($cause) ?? $detail;
        }
        $detail ??= ['unclassified_exception', '직접 원인을 확정할 수 없습니다.', '발생 위치와 추적 ID에 해당하는 서버 로그를 확인하세요.'];
        return [
            'code' => $detail[0],
            'operation' => $operation,
            'summary' => $detail[1],
            'next_action' => $detail[2],
            'classification' => $detail[0] === 'unclassified_exception' ? 'unknown' : 'identified',
            'error_class' => $this->symbol($error::class),
            'root_cause_class' => $this->symbol($root::class),
            'source' => $this->source($error),
            'cause_source' => $this->source($root),
        ];
    }

    /** @return array{string,string,string}|null */
    private function knownCause(Throwable $error): ?array
    {
        $messages = [
            '요청이 너무 많습니다. 잠시 뒤 다시 시도해 주세요.' => ['request_rate_limited', '요청 횟수 제한에 도달했습니다.', '해당 작업의 허용 횟수와 만료 시간을 확인하세요. 로그인·제출·쪽지는 서로 다른 제한입니다.'],
            '요청 횟수 제한 상태를 저장하지 못했습니다.' => ['rate_limit_storage_failed', '요청 제한 카운터를 저장하지 못했습니다.', 'WordPress DB와 외부 오브젝트 캐시의 쓰기 상태를 확인하세요. 제한을 끄기 전에 저장 장애를 점검하세요.'],
            '요청 확인 정보가 만료되었습니다.' => ['request_nonce_expired', '요청 확인 정보가 만료되었거나 일치하지 않습니다.', '페이지를 새로 연 뒤 다시 시도하세요. 반복되면 로그인 세션과 캐시 설정을 확인하세요.'],
            '이 작업을 수행할 권한이 없습니다.' => ['request_permission_denied', '현재 계정에 작업 권한이 없습니다.', '로그인 계정의 활성 상태와 해당 작업 권한을 확인하세요.'],
            'Judge DB에 연결하지 못했습니다.' => ['judge_database_connection_failed', '채점 DB에 연결하지 못했습니다.', '채점 DB 설정에서 호스트·포트·계정과 DB 서비스 상태를 확인하고 연결 시험을 실행하세요.'],
        ];
        if (isset($messages[$error->getMessage()])) { return $messages[$error->getMessage()]; }
        if (preg_match('/^쪽지 발송 한도\(10분에 ([1-9]|[12][0-9]|30)건\)에 도달했습니다\./u', $error->getMessage()) === 1) {
            return ['messaging_send_rate_limited', '사용자별 쪽지 발송 한도에 도달했습니다.', '쪽지 설정의 10분당 발송 건수를 확인하세요. 알림 조회는 발송 횟수에 포함되지 않습니다.'];
        }
        if ($error instanceof \mysqli_sql_exception || $error instanceof \PDOException) {
            $database = [
                '1045' => ['database_authentication_failed', 'DB 계정 인증이 거부되었습니다.', 'DB 접속 계정·비밀번호와 접속 호스트별 권한을 확인하세요.'],
                '1049' => ['database_not_found', '설정된 데이터베이스가 존재하지 않습니다.', 'DB 이름과 서버 연결 대상을 확인하세요.'],
                '1054' => ['database_column_missing', '조회에 필요한 DB 열이 없습니다.', '플랫폼 설치·스키마 갱신 상태와 사용 중인 DB 스키마 버전을 확인하세요.'],
                '1146' => ['database_table_missing', '조회에 필요한 DB 테이블이 없습니다.', '플랫폼 설치·스키마 갱신과 채점 DB 연결 대상을 확인하세요.'],
                '42S02' => ['database_table_missing', '조회에 필요한 DB 테이블이 없습니다.', '플랫폼 설치·스키마 갱신과 채점 DB 연결 대상을 확인하세요.'],
                '1205' => ['database_lock_timeout', 'DB 잠금을 기다리다 시간 제한에 도달했습니다.', '장시간 트랜잭션과 DB 잠금 대기 상태를 확인한 뒤 다시 시도하세요.'],
                '1213' => ['database_deadlock', 'DB 트랜잭션 간 교착 상태가 발생했습니다.', '동시에 실행한 변경 작업과 트랜잭션 순서를 확인하고 다시 시도하세요.'],
                '2002' => ['database_connection_failed', 'DB 서버 연결에 실패했습니다.', 'DB 서비스·호스트·포트·소켓과 방화벽 설정을 확인하세요.'],
                '2006' => ['database_connection_lost', 'DB 연결이 중간에 종료되었습니다.', 'DB 재시작 기록·연결 시간 제한과 서버 상태를 확인하세요.'],
            ];
            $code = $error instanceof \PDOException && isset($error->errorInfo[1])
                ? (string) $error->errorInfo[1]
                : (string) $error->getCode();
            if (isset($database[$code])) { return $database[$code]; }
        }
        if ($error instanceof \CodingPanda\OjPlatform\Modules\Settings\Contract\AiProviderException) {
            $ai = [
                'authentication_failed' => ['ai_authentication_failed', 'AI Provider 인증이 거부되었습니다.', 'AI 연결 프로필의 API 인증 방식과 키 권한을 확인하세요.'],
                'rate_limited' => ['ai_rate_limited', 'AI Provider의 요청 한도에 도달했습니다.', 'AI 서버의 요청·토큰 한도와 동시 처리량을 확인한 뒤 재시도하세요.'],
                'provider_unavailable' => ['ai_provider_unavailable', 'AI Provider에 연결하지 못했거나 서비스를 사용할 수 없습니다.', 'AI 서버·역방향 프록시·네트워크와 연결 시험 결과를 확인하세요.'],
                'invalid_response' => ['ai_invalid_response', 'AI 응답 형식이 요청 계약과 일치하지 않습니다.', '모델의 JSON 출력 지원과 작업별 응답 스키마·출력 토큰 설정을 확인하세요.'],
                'context_too_small' => ['ai_context_too_small', 'AI 모델의 문맥 한도가 입력보다 작습니다.', '작업 배정의 문맥 토큰과 모델이 지원하는 문맥 크기를 확인하세요.'],
                'model_not_found' => ['ai_model_not_found', 'AI 모델 또는 API 경로를 찾지 못했습니다.', '연결 프로필의 모델 이름과 API 경로를 확인하세요.'],
                'transport_unavailable' => ['ai_transport_unavailable', '안전한 AI 연결에 필요한 전송 환경이 준비되지 않았습니다.', 'PHP cURL 확장과 WordPress HTTP 연동 상태를 확인하세요.'],
            ];
            if (isset($ai[$error->safeCode()])) { return $ai[$error->safeCode()]; }
        }
        if ($error instanceof \TypeError) {
            return ['php_type_error', 'PHP 함수의 인자 또는 반환값 형식이 맞지 않습니다.', '발생 위치의 호출 코드와 플러그인·애드온 버전 호환성을 확인하세요.'];
        }
        return null;
    }

    private function operation(Throwable $error): string
    {
        $actions = ['oj_message_send' => '쪽지 발송', 'oj_message_report' => '쪽지 신고', 'oj_message_delete' => '쪽지 삭제', 'oj_submit_submission' => '소스 코드 제출', 'oj_login' => '로그인', 'oj_save_submission_settings' => '제출 설정 저장'];
        $action = $_POST['action'] ?? null;
        if (is_string($action) && isset($actions[$action])) { return $actions[$action]; }
        $paths = str_replace('\\', '/', $error->getFile());
        foreach (array_slice($error->getTrace(), 0, 12) as $frame) { $paths .= ' ' . str_replace('\\', '/', (string) ($frame['file'] ?? '')); }
        foreach (['/Messaging/' => '쪽지 처리', '/Submissions/' => '제출 처리', '/Auth/' => '계정·인증 처리', '/JudgeDb/' => '채점 DB 처리', '/TestData/' => '테스트데이터 처리', '/Settings/' => '설정·외부 연결 처리'] as $path => $label) {
            if (str_contains($paths, $path)) { return $label; }
        }
        return '플랫폼 요청 처리';
    }

    private function symbol(string $symbol): string
    {
        return str_contains($symbol, '@anonymous') ? explode('@anonymous', $symbol, 2)[0] . '@anonymous' : $symbol;
    }

    private function source(Throwable $error): string
    {
        $file = str_replace('\\', '/', $error->getFile());
        $position = strrpos($file, '/plugins/');
        return ($position === false ? basename($file) : substr($file, $position + 9)) . ':' . $error->getLine();
    }
}
