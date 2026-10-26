<?php
declare(strict_types=1);

namespace TeachingTools\Admin;

use TeachingTools\Addon\Whiteboard\Whiteboard_Image_Repository;
use TeachingTools\Database\Installer;

final class Privacy_Tools {
    public static function register(): void {
        add_filter('wp_privacy_personal_data_exporters', array(__CLASS__, 'exporters'));
        add_filter('wp_privacy_personal_data_erasers', array(__CLASS__, 'erasers'));
        add_action('delete_user', array(__CLASS__, 'delete_user_data'));
    }

    /** @param array<string, mixed> $exporters @return array<string, mixed> */
    public static function exporters(array $exporters): array {
        $exporters['teaching-tools'] = array(
            'exporter_friendly_name' => __('교실 도구', 'teaching-tools'),
            'callback' => array(__CLASS__, 'export_personal_data'),
        );

        return $exporters;
    }

    /** @param array<string, mixed> $erasers @return array<string, mixed> */
    public static function erasers(array $erasers): array {
        $erasers['teaching-tools'] = array(
            'eraser_friendly_name' => __('교실 도구', 'teaching-tools'),
            'callback' => array(__CLASS__, 'erase_personal_data'),
        );

        return $erasers;
    }

    /** @return array{data:list<array<string,mixed>>,done:bool} */
    public static function export_personal_data(string $email_address, int $page = 1): array {
        if ($page > 1) {
            return array('data' => array(), 'done' => true);
        }
        $user = get_user_by('email', $email_address);
        if (! $user) {
            return array('data' => array(), 'done' => true);
        }

        global $wpdb;
        $user_id = (int) $user->ID;
        $sessions = Installer::sessions_table();
        $participants = Installer::participants_table();
        $hosts = Installer::session_hosts_table();
        $data = array();

        $participant_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT p.id AS participant_id, p.joined_at, p.data, s.* FROM {$participants} p"
                . " INNER JOIN {$sessions} s ON s.id = p.session_id WHERE p.user_id = %d"
                . ' ORDER BY s.created_at DESC LIMIT 500',
            $user_id
        ), ARRAY_A);
        foreach (is_array($participant_rows) ? $participant_rows : array() as $row) {
            $state = json_decode((string) ($row['state'] ?? '{}'), true);
            $personal_state = self::personal_state(
                (string) ($row['tool_type'] ?? ''),
                is_array($state) ? $state : array(),
                $user_id
            );
            $data[] = self::export_item(
                'participation-' . (int) $row['participant_id'],
                array(
                    __('도구', 'teaching-tools') => (string) $row['tool_type'],
                    __('참여 코드', 'teaching-tools') => (string) $row['join_code'],
                    __('참여 시각', 'teaching-tools') => (string) $row['joined_at'],
                    __('세션 상태', 'teaching-tools') => (string) $row['status'],
                    __('개인 참여 데이터', 'teaching-tools') => (string) wp_json_encode(array(
                        'participant' => json_decode((string) ($row['data'] ?? '{}'), true),
                        'state' => $personal_state,
                    )),
                )
            );
        }

        $host_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT h.id AS host_id, h.role, h.created_at AS host_created_at, s.join_code, s.tool_type, s.status"
                . " FROM {$hosts} h INNER JOIN {$sessions} s ON s.id = h.session_id"
                . ' WHERE h.user_id = %d ORDER BY h.created_at DESC LIMIT 500',
            $user_id
        ), ARRAY_A);
        foreach (is_array($host_rows) ? $host_rows : array() as $row) {
            $data[] = self::export_item('host-' . (int) $row['host_id'], array(
                __('도구', 'teaching-tools') => (string) $row['tool_type'],
                __('참여 코드', 'teaching-tools') => (string) $row['join_code'],
                __('역할', 'teaching-tools') => (string) $row['role'],
                __('등록 시각', 'teaching-tools') => (string) $row['host_created_at'],
                __('세션 상태', 'teaching-tools') => (string) $row['status'],
            ));
        }

        foreach (self::owned_items($user_id) as $item) {
            $data[] = $item;
        }

        return array('data' => $data, 'done' => true);
    }

    /** @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    public static function erase_personal_data(string $email_address, int $page = 1): array {
        if ($page > 1) {
            return self::erase_result(false, array());
        }
        $user = get_user_by('email', $email_address);
        if (! $user) {
            return self::erase_result(false, array());
        }

        $removed = self::erase_user((int) $user->ID);

        return self::erase_result($removed, $removed ? array(__('교실 도구의 사용자 연결 데이터를 삭제했습니다.', 'teaching-tools')) : array());
    }

    public static function delete_user_data(int $user_id): void {
        self::erase_user($user_id);
    }

    private static function erase_user(int $user_id): bool {
        global $wpdb;
        $sessions = Installer::sessions_table();
        $participants = Installer::participants_table();
        $hosts = Installer::session_hosts_table();
        $owned_ids = array_map('intval', $wpdb->get_col($wpdb->prepare(
            "SELECT id FROM {$sessions} WHERE owner_user_id = %d",
            $user_id
        )));
        $joined_rows = $wpdb->get_results($wpdb->prepare(
            "SELECT s.id, s.tool_type, s.state FROM {$sessions} s"
                . " INNER JOIN {$participants} p ON p.session_id = s.id WHERE p.user_id = %d",
            $user_id
        ), ARRAY_A);

        if (false === $wpdb->query('START TRANSACTION')) {
            return false;
        }
        $removed = [] !== $owned_ids || [] !== $joined_rows;
        foreach (is_array($joined_rows) ? $joined_rows : array() as $row) {
            if (in_array((int) $row['id'], $owned_ids, true)) {
                continue;
            }
            $state = json_decode((string) ($row['state'] ?? '{}'), true);
            $state = self::scrub_state(
                (string) ($row['tool_type'] ?? ''),
                is_array($state) ? $state : array(),
                $user_id
            );
            $encoded = wp_json_encode($state);
            if (false === $encoded || false === $wpdb->update(
                $sessions,
                array('state' => $encoded, 'updated_at' => current_time('mysql', true)),
                array('id' => (int) $row['id']),
                array('%s', '%s'),
                array('%d')
            )) {
                $wpdb->query('ROLLBACK');

                return false;
            }
        }

        if ([] !== $owned_ids) {
            $placeholders = implode(',', array_fill(0, count($owned_ids), '%d'));
            if (
                false === $wpdb->query($wpdb->prepare("DELETE FROM {$participants} WHERE session_id IN ({$placeholders})", ...$owned_ids))
                || false === $wpdb->query($wpdb->prepare("DELETE FROM {$hosts} WHERE session_id IN ({$placeholders})", ...$owned_ids))
                || false === $wpdb->query($wpdb->prepare("DELETE FROM {$sessions} WHERE id IN ({$placeholders})", ...$owned_ids))
            ) {
                $wpdb->query('ROLLBACK');

                return false;
            }
        }
        foreach (array(
            array($participants, 'user_id'),
            array($hosts, 'user_id'),
            array(Installer::seating_layouts_table(), 'owner_user_id'),
            array(Installer::lesson_templates_table(), 'owner_user_id'),
        ) as [$table, $column]) {
            $deleted = $wpdb->delete($table, array($column => $user_id), array('%d'));
            if (false === $deleted) {
                $wpdb->query('ROLLBACK');

                return false;
            }
            $removed = $removed || $deleted > 0;
        }
        $committed = false !== $wpdb->query('COMMIT');
        if (! $committed) {
            $wpdb->query('ROLLBACK');

            return false;
        }

        foreach ($owned_ids as $session_id) {
            Whiteboard_Image_Repository::delete_for_session($session_id);
        }

        return $removed;
    }

    /** @return list<array<string,mixed>> */
    private static function owned_items(int $user_id): array {
        global $wpdb;
        $items = array();
        $layouts = $wpdb->get_results($wpdb->prepare(
            'SELECT id, name, rows_count, cols_count, created_at, updated_at FROM ' . Installer::seating_layouts_table()
                . ' WHERE owner_user_id = %d ORDER BY id ASC',
            $user_id
        ), ARRAY_A);
        foreach (is_array($layouts) ? $layouts : array() as $row) {
            $items[] = self::export_item('layout-' . (int) $row['id'], array(
                __('저장 항목', 'teaching-tools') => __('자리배치', 'teaching-tools'),
                __('이름', 'teaching-tools') => (string) $row['name'],
                __('크기', 'teaching-tools') => (int) $row['rows_count'] . 'x' . (int) $row['cols_count'],
                __('수정 시각', 'teaching-tools') => (string) $row['updated_at'],
            ));
        }
        $templates = $wpdb->get_results($wpdb->prepare(
            'SELECT id, name, created_at, updated_at FROM ' . Installer::lesson_templates_table()
                . ' WHERE owner_user_id = %d ORDER BY id ASC',
            $user_id
        ), ARRAY_A);
        foreach (is_array($templates) ? $templates : array() as $row) {
            $items[] = self::export_item('template-' . (int) $row['id'], array(
                __('저장 항목', 'teaching-tools') => __('수업 흐름 템플릿', 'teaching-tools'),
                __('이름', 'teaching-tools') => (string) $row['name'],
                __('수정 시각', 'teaching-tools') => (string) $row['updated_at'],
            ));
        }

        return $items;
    }

    /** @param array<string,string> $values @return array<string,mixed> */
    private static function export_item(string $item_id, array $values): array {
        return array(
            'group_id' => 'teaching-tools',
            'group_label' => __('교실 도구', 'teaching-tools'),
            'item_id' => $item_id,
            'data' => array_map(
                static fn (string $name, string $value): array => array('name' => $name, 'value' => $value),
                array_keys($values),
                array_values($values)
            ),
        );
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function personal_state(string $tool_type, array $state, int $user_id): array {
        return match ($tool_type) {
            'seating' => array('seats' => array_values(array_filter(
                is_array($state['seats'] ?? null) ? $state['seats'] : array(),
                static fn ($seat): bool => is_array($seat) && (int) ($seat['student']['id'] ?? 0) === $user_id
            ))),
            'picker' => array('history' => self::filter_identity_lists($state['history'] ?? array(), $user_id)),
            'ladder' => array('claims' => self::filter_identity_entries($state['claims'] ?? array(), $user_id)),
            'quiz' => array('responses' => self::filter_student_entries($state['responses'] ?? $state['votes'] ?? array(), $user_id)),
            'grouping' => array('groups' => self::personal_groups($state['groups'] ?? array(), $user_id)),
            'timeattack' => array('roster' => self::filter_identity_entries($state['roster'] ?? array(), $user_id)),
            default => array(),
        };
    }

    /** @param array<string,mixed> $state @return array<string,mixed> */
    private static function scrub_state(string $tool_type, array $state, int $user_id): array {
        if ('seating' === $tool_type) {
            $seats = is_array($state['seats'] ?? null) ? $state['seats'] : array();
            foreach ($seats as &$seat) {
                if (is_array($seat) && (int) ($seat['student']['id'] ?? 0) === $user_id) {
                    $seat['student'] = null;
                }
            }
            unset($seat);
            $state['seats'] = $seats;
        } elseif ('picker' === $tool_type) {
            foreach (array('pool', 'totalRoster', 'latest') as $key) {
                $state[$key] = self::reject_identity_entries($state[$key] ?? array(), $user_id);
            }
            $state['history'] = array_map(
                static fn ($entries): array => self::reject_identity_entries($entries, $user_id),
                is_array($state['history'] ?? null) ? $state['history'] : array()
            );
        } elseif ('ladder' === $tool_type) {
            $state['claims'] = self::reject_identity_entries($state['claims'] ?? array(), $user_id);
        } elseif ('quiz' === $tool_type) {
            $state['responses'] = self::reject_student_entries($state['responses'] ?? array(), $user_id);
            $state['votes'] = self::reject_student_entries($state['votes'] ?? array(), $user_id);
        } elseif ('grouping' === $tool_type) {
            $groups = is_array($state['groups'] ?? null) ? $state['groups'] : array();
            foreach ($groups as &$group) {
                if (is_array($group)) {
                    $group['members'] = self::reject_identity_entries($group['members'] ?? array(), $user_id);
                }
            }
            unset($group);
            $state['groups'] = $groups;
        } elseif ('timeattack' === $tool_type) {
            $state['roster'] = self::reject_identity_entries($state['roster'] ?? array(), $user_id);
        } elseif ('classroom' === $tool_type) {
            $activities = is_array($state['activities'] ?? null) ? $state['activities'] : array();
            foreach ($activities as &$activity) {
                if (is_array($activity) && is_array($activity['target']['ids'] ?? null)) {
                    $activity['target']['ids'] = array_values(array_filter(
                        array_map('absint', $activity['target']['ids']),
                        static fn (int $id): bool => $id !== $user_id
                    ));
                }
            }
            unset($activity);
            $state['activities'] = $activities;
            if (is_array($state['activity']['target']['ids'] ?? null)) {
                $state['activity']['target']['ids'] = array_values(array_filter(
                    array_map('absint', $state['activity']['target']['ids']),
                    static fn (int $id): bool => $id !== $user_id
                ));
            }
        }

        return $state;
    }

    private static function filter_identity_entries(mixed $entries, int $user_id): array {
        return array_values(array_filter(
            is_array($entries) ? $entries : array(),
            static fn ($entry): bool => is_array($entry) && (int) ($entry['id'] ?? 0) === $user_id
        ));
    }

    private static function reject_identity_entries(mixed $entries, int $user_id): array {
        return array_values(array_filter(
            is_array($entries) ? $entries : array(),
            static fn ($entry): bool => ! is_array($entry) || (int) ($entry['id'] ?? 0) !== $user_id
        ));
    }

    private static function filter_student_entries(mixed $entries, int $user_id): array {
        return array_values(array_filter(
            is_array($entries) ? $entries : array(),
            static fn ($entry): bool => is_array($entry)
                && (int) ($entry['student']['id'] ?? $entry['userId'] ?? 0) === $user_id
        ));
    }

    private static function reject_student_entries(mixed $entries, int $user_id): array {
        return array_values(array_filter(
            is_array($entries) ? $entries : array(),
            static fn ($entry): bool => ! is_array($entry)
                || (int) ($entry['student']['id'] ?? $entry['userId'] ?? 0) !== $user_id
        ));
    }

    private static function filter_identity_lists(mixed $lists, int $user_id): array {
        return array_values(array_filter(array_map(
            static fn ($entries): array => self::filter_identity_entries($entries, $user_id),
            is_array($lists) ? $lists : array()
        )));
    }

    private static function personal_groups(mixed $groups, int $user_id): array {
        return array_values(array_filter(array_map(
            static function ($group) use ($user_id): ?array {
                if (! is_array($group)) {
                    return null;
                }
                $members = self::filter_identity_entries($group['members'] ?? array(), $user_id);

                return [] === $members ? null : array('name' => (string) ($group['name'] ?? ''), 'members' => $members);
            },
            is_array($groups) ? $groups : array()
        )));
    }

    /** @param list<string> $messages @return array{items_removed:bool,items_retained:bool,messages:list<string>,done:bool} */
    private static function erase_result(bool $removed, array $messages): array {
        return array(
            'items_removed' => $removed,
            'items_retained' => false,
            'messages' => $messages,
            'done' => true,
        );
    }
}
