<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Kernel\Integration\Operations;

use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusItem;
use CodingPanda\OjPlatform\Modules\AdminConsole\Contract\SystemStatusProvider;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionProbe;
use CodingPanda\OjPlatform\Modules\JudgeDb\Contract\JudgeConnectionSettingsProvider;

final class JudgeSystemStatusProvider implements SystemStatusProvider
{
    public function __construct(
        private readonly JudgeConnectionSettingsProvider $settings,
        private readonly JudgeConnectionProbe $probe
    ) {
    }

    public function key(): string
    {
        return 'judge_database';
    }

    public function current(): SystemStatusItem
    {
        $settings = $this->settings->current();
        if ($settings === null) {
            return new SystemStatusItem(
                $this->key(),
                'Judge 데이터베이스',
                'warning',
                '적용된 Judge DB 연결이 없습니다.',
                '저지 데이터베이스 화면에서 후보 검사를 완료하세요.',
                [],
                'oj_manage_settings'
            );
        }
        if (! $settings->utcStorageConfirmed()) {
            return new SystemStatusItem(
                $this->key(),
                'Judge 데이터베이스',
                'warning',
                '기존 제출 기록의 시간 기준을 확인해야 합니다.',
                '저지 데이터베이스 설정에서 UTC 저장 여부를 확인하고 후보를 적용하세요.',
                ['시간 저장 계약' => '미확인', '데이터 변경' => '없음'],
                'oj_manage_settings'
            );
        }
        $report = $this->probe->inspect($settings);
        $ready = $report->isReady();

        return new SystemStatusItem(
            $this->key(),
            'Judge 데이터베이스',
            $ready ? 'success' : 'danger',
            $ready
                ? 'HUSTOJ 필수 채점 테이블 8개와 연결 권한이 정상입니다.'
                : 'Judge DB 연결 또는 필수 테이블 상태를 확인해야 합니다.',
            $ready
                ? '정기 연결 검사만 유지하세요.'
                : '기존 운영값을 유지한 채 새 후보 연결을 검사하세요.',
            [
                '호스트' => $settings->host(),
                '데이터베이스' => $settings->database(),
                '계정 구성' => $settings->usesSingleAccount()
                    ? '읽기·쓰기 단일 계정'
                    : '읽기·쓰기 분리 계정',
                '누락 테이블' => implode(', ', $report->missingRequiredTables()) ?: '없음',
            ],
            'oj_manage_settings'
        );
    }
}
