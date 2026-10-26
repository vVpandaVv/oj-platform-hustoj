<?php

declare(strict_types=1);

namespace OJML\Playgrounds\Ops;

use OJML\Playgrounds\Database\Installer;
use OJML\Playgrounds\Database\Repository;
use OJML\Playgrounds\Database\SchemaUpgrade;
use OJML\Playgrounds\Storage\PrivateArtifactStore;
use Throwable;

final class Privacy
{
    public function register(): void
    {
        add_action('admin_init', static function (): void {
            if (function_exists('wp_add_privacy_policy_content')) {
                wp_add_privacy_policy_content('OJ ML Playgrounds',
                    '<p>ML 실습은 계정 ID, 과제 배정 명단, 제출 시도, MLPX 원본, 검사 결과, 점수와 피드백을 저장합니다. 학습은 학생 브라우저에서 실행되고 원본 제출물은 서버 비공개 저장소에 보관됩니다. 공용 PC에는 계정별 암호화 저장소를 사용하며 사용 후 삭제할 수 있습니다.</p>'
                    . '<p>교사의 개인 양식 보관함과 지정 CSV 자료·배포 연결·변경 감사 기록도 저장합니다. CSV 라이브러리 숨김은 원본 삭제가 아닙니다. 기관은 보존기간과 기록 보존 유예 정책을 지정해야 합니다. 기본적으로 제출 기록은 보존되며 개인정보 삭제는 운영자가 명시적으로 허용해야 합니다. 내려받은 MLPX·CSV·양식 파일과 별도 백업은 기관이 따로 관리합니다. 이미지 실습의 외부 모델 요청 여부는 설치 안내를 확인하세요.</p>');
            }
        });
        add_filter('wp_privacy_personal_data_exporters', function (array $exporters): array {
            $exporters['oj-ml-playgrounds'] = ['exporter_friendly_name' => 'ML 실습 제출 기록', 'callback' => [$this, 'export']];
            return $exporters;
        });
        add_filter('wp_privacy_personal_data_erasers', function (array $erasers): array {
            $erasers['oj-ml-playgrounds'] = ['eraser_friendly_name' => 'ML 실습 제출 기록', 'callback' => [$this, 'erase']];
            return $erasers;
        });
    }

    public function export(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) { return ['data' => [], 'done' => true]; }
        Installer::maybeUpgrade();
        global $wpdb;
        $history = $wpdb->get_results($wpdb->prepare('SELECT uuid FROM oj_ml_attempts WHERE user_id = %d ORDER BY id LIMIT 50 OFFSET %d',
            (int) $user->ID, (max(1, $page) - 1) * 50), ARRAY_A);
        SchemaUpgrade::assertDatabase($wpdb);
        $data = [];
        $repository = new Repository($wpdb);
        foreach ($history as $row) {
            $attempt = $repository->getAttemptResult($row['uuid'], (int) $user->ID);
            if ($attempt === null) { continue; }
            $data[] = ['group_id' => 'oj-ml-submissions', 'group_label' => 'ML 실습 제출',
                'item_id' => 'oj-ml-' . $row['uuid'], 'data' => [
                    ['name' => '과제 ID', 'value' => (string) $attempt['assignment_id']],
                    ['name' => '시도', 'value' => (string) $attempt['attempt_no']],
                    ['name' => '상태', 'value' => $attempt['status']],
                    ['name' => '제출 시각 (UTC)', 'value' => $attempt['received_at'] ?? '미접수'],
                    ['name' => '제출 파일', 'value' => $attempt['artifact']['original_name'] ?? '없음 또는 보존기간 만료'],
                    ['name' => '공개 평가', 'value' => (string) wp_json_encode($attempt['review'] ?? [], JSON_UNESCAPED_UNICODE)],
                    ['name' => '원본 파일 사본', 'value' => '기관 운영자가 wp oj-ml export-user 명령으로 비공개 원본 사본을 제공할 수 있습니다.'],
                ]];
        }
        $roster = $wpdb->get_results($wpdb->prepare('SELECT l.assignment_id,l.enrolled_at,l.removed_at,l.max_attempts_override,l.due_at_override,a.title
            FROM oj_ml_assignment_learners l JOIN oj_ml_assignments a ON a.id = l.assignment_id
            WHERE l.user_id = %d ORDER BY l.id LIMIT 50 OFFSET %d', (int) $user->ID, (max(1, $page) - 1) * 50), ARRAY_A);
        SchemaUpgrade::assertDatabase($wpdb);
        foreach ($roster as $row) {
            $data[] = ['group_id' => 'oj-ml-assignments', 'group_label' => 'ML 실습 배정',
                'item_id' => 'oj-ml-assignment-' . $row['assignment_id'], 'data' => [
                    ['name' => '과제', 'value' => $row['title']],
                    ['name' => '배정 정보 (UTC)', 'value' => (string) wp_json_encode($row, JSON_UNESCAPED_UNICODE)],
                ]];
        }
        if ($page === 1) {
            $teachingData = \OJML\Playgrounds\Application\TeachingDatasetService::privacyForUser((int) $user->ID);
            foreach ($teachingData['items'] as $dataset) {
                $data[] = ['group_id' => 'oj-ml-teaching-datasets', 'group_label' => '교사 지정 CSV 자료',
                    'item_id' => 'oj-ml-dataset-' . $dataset['id'], 'data' => [
                        ['name' => '자료 정보', 'value' => (string) wp_json_encode($dataset, JSON_UNESCAPED_UNICODE)],
                        ['name' => '보관 및 원본 사본', 'value' => $teachingData['message'] . ' 원본 사본은 기관 운영자에게 요청하세요.'],
                    ]];
            }
            $templates = get_option(\OJML\Playgrounds\Rest\TemplateLibraryRoute::key((int) $user->ID), []);
            foreach (is_array($templates) ? $templates : [] as $template) {
                $data[] = ['group_id' => 'oj-ml-template-library', 'group_label' => '내 포트폴리오 양식',
                    'item_id' => 'oj-ml-template-' . $template['id'], 'data' => [
                        ['name' => '보관한 양식', 'value' => (string) wp_json_encode($template, JSON_UNESCAPED_UNICODE)],
                    ]];
            }
        }
        return ['data' => $data, 'done' => count($history) < 50 && count($roster) < 50];
    }

    public function erase(string $email, int $page = 1): array
    {
        $user = get_user_by('email', $email);
        if (!$user) { return ['items_removed' => false, 'items_retained' => false, 'messages' => [], 'done' => true]; }
        $settingsLease = null;
        try { $settingsLease = Lease::acquire('settings'); }
        catch (Throwable) { return ['items_removed' => false, 'items_retained' => true, 'messages' => ['운영 설정 변경 중입니다. 삭제 요청을 다시 실행하세요.'], 'done' => true]; }
        $settings = Options::all();
        if ($settings['maintenanceMode'] || $settings['retentionHold'] || !$settings['allowPersonalErasure']) {
            return ['items_removed' => false, 'items_retained' => true,
                'messages' => ['기관의 학습 기록 보존 정책에 따라 ML 제출물을 보존했습니다. 운영자가 보존 유예와 개인정보 삭제 허용 설정을 확인해야 합니다.'], 'done' => true];
        }
        $removed = false;
        try {
            Installer::maybeUpgrade();
            global $wpdb;
            $lease = Lease::acquire('learner:' . (int) $user->ID);
            try {
                $rows = $wpdb->get_results($wpdb->prepare('SELECT id,uuid FROM oj_ml_attempts WHERE user_id = %d ORDER BY id LIMIT 20', (int) $user->ID), ARRAY_A);
                SchemaUpgrade::assertDatabase($wpdb);
                $maintenance = new StorageMaintenance($wpdb, new PrivateArtifactStore());
                foreach ($rows as $row) {
                    $id = (int) $row['id'];
                    $attemptLease = Lease::acquire('attempt:' . $row['uuid']);
                    $artifact = $wpdb->get_row($wpdb->prepare('SELECT * FROM oj_ml_artifacts WHERE attempt_id = %d', $id), ARRAY_A);
                    SchemaUpgrade::assertDatabase($wpdb);
                    if (is_array($artifact)) { $maintenance->deleteArtifact($artifact, 'privacy_request'); }
                    if ($wpdb->query('START TRANSACTION') === false) { throw new \RuntimeException('transaction'); }
                    try {
                        $wpdb->get_var($wpdb->prepare('SELECT id FROM oj_ml_attempts WHERE id = %d FOR UPDATE', $id));
                        SchemaUpgrade::assertDatabase($wpdb);
                        foreach (['oj_ml_evaluations', 'oj_ml_reviews', 'oj_ml_artifacts'] as $table) {
                            if ($wpdb->delete($table, ['attempt_id' => $id], ['%d']) === false) { throw new \RuntimeException('privacy delete'); }
                        }
                        if ($wpdb->update('oj_ml_events', ['actor_user_id' => null, 'payload_json' => '{"redacted":true}'], ['attempt_id' => $id]) === false
                            || $wpdb->delete('oj_ml_attempts', ['id' => $id], ['%d']) === false) { throw new \RuntimeException('privacy delete'); }
                        if ($wpdb->query('COMMIT') === false) { throw new \RuntimeException('commit'); }
                        $removed = true;
                    } catch (Throwable $error) { $wpdb->query('ROLLBACK'); throw $error; }
                    $attemptLease->release();
                }
                $remaining = (int) $wpdb->get_var($wpdb->prepare('SELECT COUNT(*) FROM oj_ml_attempts WHERE user_id = %d', (int) $user->ID));
                SchemaUpgrade::assertDatabase($wpdb);
                if ($remaining === 0) {
                    $libraryKey = \OJML\Playgrounds\Rest\TemplateLibraryRoute::key((int) $user->ID);
                    $libraryLease = Lease::acquire($libraryKey);
                    if (get_option($libraryKey, false) !== false) {
                        if (!delete_option($libraryKey)) { throw new \RuntimeException('privacy template library'); }
                        $removed = true;
                    }
                    $libraryLease->release();
                    $rosterDeleted = $wpdb->delete('oj_ml_assignment_learners', ['user_id' => (int) $user->ID], ['%d']);
                    if ($rosterDeleted === false) { throw new \RuntimeException('privacy roster'); }
                    $removed = $removed || $rosterDeleted > 0;
                    $wpdb->query($wpdb->prepare("UPDATE oj_ml_events SET actor_user_id = NULL,payload_json = %s
                        WHERE actor_user_id = %d OR (JSON_VALID(payload_json) AND JSON_UNQUOTE(JSON_EXTRACT(payload_json, '$.userId')) = %s)",
                        '{"redacted":true}', (int) $user->ID, (string) $user->ID));
                    SchemaUpgrade::assertDatabase($wpdb);
                }
                // Teacher authorship belongs to retained institutional records.
                $authorship = (int) $wpdb->get_var($wpdb->prepare('SELECT (SELECT COUNT(*) FROM oj_ml_assignments WHERE created_by = %d) + (SELECT COUNT(*) FROM oj_ml_reviews WHERE reviewer_id = %d)', (int) $user->ID, (int) $user->ID));
                SchemaUpgrade::assertDatabase($wpdb);
                $maintenance->event(null, null, 'privacy.erased', ['removedAttempts' => count($rows)]);
                $teachingData = \OJML\Playgrounds\Application\TeachingDatasetService::privacyForUser((int) $user->ID);
                $messages = $authorship > 0 ? ['교사 작성 과제와 다른 학생 평가의 작성자 기록은 기관 기록으로 보존했습니다.'] : [];
                if ($teachingData['retained']) { $messages[] = $teachingData['message']; }
                return ['items_removed' => $removed, 'items_retained' => $authorship > 0 || $teachingData['retained'],
                    'messages' => $messages,
                    'done' => $remaining === 0];
            } finally { $lease->release(); }
        } catch (Throwable $error) {
            Audit::log($error, wp_generate_uuid4());
            return ['items_removed' => $removed, 'items_retained' => true,
                'messages' => ['일부 자료를 삭제하지 못했습니다. 운영자가 저장소와 오류 로그를 확인한 뒤 삭제 요청을 다시 실행하세요.'], 'done' => true];
        }
    }
}
