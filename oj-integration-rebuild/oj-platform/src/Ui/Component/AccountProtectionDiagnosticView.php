<?php

declare(strict_types=1);

namespace CodingPanda\OjPlatform\Ui\Component;

use CodingPanda\OjPlatform\Modules\Auth\Contract\AccountProtectionDiagnostic;

final class AccountProtectionDiagnosticView
{
    public function render(AccountProtectionDiagnostic $diagnostic, bool $compact = false): string
    {
        $code = $diagnostic->fault() ?? $diagnostic->reason();
        $summary = $this->summary($code);
        $details = '<dl class="ojp-detail-list"><div><dt>판정 코드</dt><dd><code>'
            . $this->escape('auth.account_protection.' . $code) . '</code></dd></div>'
            . '<div><dt>보호 판정</dt><dd>'
            . ($diagnostic->isProtected() ? '초기화 차단' : '보호 정책상 차단 없음') . '</dd></div>'
            . '<div><dt>인정된 애드온 학습자 권한</dt><dd>'
            . $diagnostic->learnerCapabilityCount() . '개</dd></div>';
        if ($diagnostic->exceptionType() !== null) {
            $details .= '<div><dt>오류 종류</dt><dd><code>'
                . $this->escape($diagnostic->exceptionType()) . '</code></dd></div>';
        }
        if ($diagnostic->sourceLocation() !== null) {
            $details .= '<div><dt>오류 위치</dt><dd><code>'
                . $this->escape($diagnostic->sourceLocation()) . '</code></dd></div>';
        }
        $details .= '</dl>';
        if ($diagnostic->blockingCapabilities() !== []) {
            $details .= '<p><strong>차단된 권한</strong></p><ul>';
            foreach ($diagnostic->blockingCapabilities() as $capability) {
                $details .= '<li><code>' . $this->escape($capability) . '</code></li>';
            }
            $details .= '</ul>';
        }
        $details .= '<p>' . $this->escape($this->nextAction($code)) . '</p>';

        return '<div class="ojp-account-protection-diagnostic"><p><strong>' . $this->escape($summary) . '</strong></p>'
            . ($compact ? '<details><summary>계정 보호 진단 상세</summary>' . $details . '</details>' : $details) . '</div>';
    }

    private function summary(string $code): string
    {
        return match ($code) {
            'administrator_role' => '관리자 역할이 있는 보호 계정입니다.',
            'super_admin' => 'WordPress가 superadmin으로 판정한 보호 계정입니다.',
            'capability_blocked' => '학습자 권한으로 분류되지 않은 권한 때문에 초기화를 차단했습니다.',
            'lookup_unavailable' => 'WordPress 계정 조회 기능을 사용할 수 없어 초기화를 차단했습니다.',
            'account_missing' => '권한 검사에서 대상 WordPress 계정을 찾지 못했습니다.',
            'identity_mismatch' => '조회한 계정 번호가 대상 계정과 일치하지 않습니다.',
            'capability_data_invalid' => '계정의 권한 정보가 올바른 배열이 아닙니다.',
            'super_admin_check_failed' => 'superadmin 여부를 확인하는 중 오류가 발생했습니다.',
            'user_lookup_failed' => 'WordPress 계정을 조회하는 중 오류가 발생했습니다.',
            'capability_data_failed', 'capability_scan_failed' => '계정의 권한 정보를 검사하는 중 오류가 발생했습니다.',
            'addon_filter_failed' => '애드온 학습자 권한 필터 실행에 실패해 초기화를 차단했습니다.',
            'addon_filter_invalid' => '애드온 학습자 권한 필터의 반환 형식이 올바르지 않습니다.',
            'addon_catalog_failed' => '승인된 애드온의 학습자 권한 선언을 읽지 못했습니다.',
            'addon_catalog_invalid' => '애드온 학습자 권한 선언의 반환 형식이 올바르지 않습니다.',
            'not_protected' => '계정 보호 정책에 따른 차단 사유가 없습니다.',
            default => '계정 보호 검사 중 오류가 발생해 초기화를 차단했습니다.',
        };
    }

    private function nextAction(string $code): string
    {
        return match ($code) {
            'administrator_role', 'super_admin' => '보호 계정은 학생 비밀번호 초기화 대상에서 제외하세요.',
            'capability_blocked' => '표시된 권한의 역할 배정과 개별 부여 내역을 확인하세요. 학습자 권한인지 확인하기 전에 권한을 일괄 제거하지 마세요.',
            'addon_filter_failed', 'addon_filter_invalid' => 'oj_platform_unprivileged_addon_capabilities 필터를 등록한 애드온의 실행과 반환값을 확인하세요.',
            'addon_catalog_failed', 'addon_catalog_invalid' => '시스템 상태의 애드온 등록 결과와 해당 애드온의 learner 권한 선언을 확인하세요.',
            'not_protected' => '가입·이용 상태, 본인 계정 여부와 작업자의 초기화 권한은 별도로 확인하세요.',
            default => 'WordPress 계정과 권한 조회 상태를 확인한 뒤 사용자 상세 화면을 새로 고침하세요. 보호 정책을 해제하지 마세요.',
        };
    }

    private function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
