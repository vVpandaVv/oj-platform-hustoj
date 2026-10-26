<?php
declare(strict_types=1);

namespace TeachingTools\Admin;

use TeachingTools\Sessions\Tool_Session_Repository;

final class Session_Report {
    public static function register(): void {
        add_action('admin_post_teaching_tools_session_report', array(__CLASS__, 'download'));
    }

    public static function download(): void {
        check_admin_referer('teaching_tools_session_report');
        $code = strtoupper(sanitize_text_field(wp_unslash((string) ($_GET['code'] ?? ''))));
        $user_id = get_current_user_id();
        $session = Tool_Session_Repository::find_by_code($code);
        $role = null === $session ? null : Tool_Session_Repository::host_role((int) $session['id'], $user_id);
        if (null === $session || null === $role) {
            status_header(404);
            nocache_headers();
            exit;
        }

        nocache_headers();
        header('Content-Type: text/csv; charset=UTF-8');
        header('Content-Disposition: attachment; filename="teaching-tools-' . strtolower($code) . '.csv"');
        header('X-Content-Type-Options: nosniff');
        $output = fopen('php://output', 'wb');
        if (false === $output) {
            status_header(500);
            exit;
        }
        fwrite($output, "\xEF\xBB\xBF");
        fputcsv($output, array('record_type', 'id', 'display_name', 'management_name', 'value', 'status', 'recorded_at'));

        $participants = Tool_Session_Repository::participants((int) $session['id']);
        $identities = array();
        foreach ($participants as $participant) {
            $id = (int) ($participant['user_id'] ?? 0);
            $data = json_decode((string) ($participant['data'] ?? '{}'), true);
            $data = is_array($data) ? $data : array();
            $display_name = sanitize_text_field((string) ($data['displayName'] ?? ''));
            $management_name = sanitize_text_field((string) ($data['managementName'] ?? ''));
            $identities[$id] = array($display_name, $management_name);
            self::row($output, array(
                'participant',
                $id,
                $display_name,
                $management_name,
                sanitize_key((string) ($data['signal'] ?? '')),
                max(0, (int) ($data['lastSeenAt'] ?? 0)) > 0 ? 'seen' : 'registered',
                (string) ($participant['joined_at'] ?? ''),
            ));
        }

        $state = json_decode((string) ($session['state'] ?? '{}'), true);
        $state = is_array($state) ? $state : array();
        self::write_tool_rows($output, (string) $session['tool_type'], $state, $identities);
        fclose($output);
        exit;
    }

    /** @param resource $output @param array<int,array{0:string,1:string}> $identities */
    private static function write_tool_rows($output, string $tool_type, array $state, array $identities): void {
        if ('quiz' === $tool_type) {
            foreach (is_array($state['responses'] ?? null) ? $state['responses'] : array() as $response) {
                if (! is_array($response)) {
                    continue;
                }
                $id = (int) ($response['student']['id'] ?? $response['userId'] ?? 0);
                $identity = $identities[$id] ?? array('', '');
                $value = isset($response['text'])
                    ? (string) $response['text']
                    : (isset($response['value']) ? (string) $response['value'] : (string) ($response['optionIndex'] ?? ''));
                self::row($output, array(
                    'response', $id, $identity[0], $identity[1], $value,
                    (string) ($response['status'] ?? 'submitted'),
                    self::timestamp((int) ($response['submittedAt'] ?? 0)),
                ));
            }
        } elseif ('seating' === $tool_type) {
            foreach (is_array($state['seats'] ?? null) ? $state['seats'] : array() as $seat) {
                if (! is_array($seat) || ! is_array($seat['student'] ?? null)) {
                    continue;
                }
                $id = (int) ($seat['student']['id'] ?? 0);
                $identity = $identities[$id] ?? array((string) ($seat['student']['name'] ?? ''), '');
                self::row($output, array(
                    'seat', $id,
                    $identity[0], $identity[1],
                    ((int) ($seat['row'] ?? 0) + 1) . '-' . ((int) ($seat['col'] ?? 0) + 1),
                    'assigned', '',
                ));
            }
        } elseif ('grouping' === $tool_type) {
            foreach (is_array($state['groups'] ?? null) ? $state['groups'] : array() as $group) {
                if (! is_array($group)) {
                    continue;
                }
                foreach (is_array($group['members'] ?? null) ? $group['members'] : array() as $member) {
                    if (is_array($member)) {
                        $id = (int) ($member['id'] ?? 0);
                        $identity = $identities[$id] ?? array((string) ($member['name'] ?? ''), '');
                        self::row($output, array(
                            'group', $id, $identity[0], $identity[1],
                            (string) ($group['name'] ?? ''), 'assigned', '',
                        ));
                    }
                }
            }
        } elseif ('classroom' === $tool_type) {
            foreach (is_array($state['activities'] ?? null) ? $state['activities'] : array() as $activity) {
                if (is_array($activity)) {
                    self::row($output, array(
                        'activity', (string) ($activity['id'] ?? ''), '', '',
                        (string) ($activity['title'] ?? ''),
                        (string) ($activity['target']['label'] ?? 'all'),
                        self::timestamp((int) ($activity['sentAt'] ?? 0)),
                    ));
                }
            }
        }
    }

    /** @param resource $output @param list<string|int> $values */
    private static function row($output, array $values): void {
        fputcsv($output, array_map(array(__CLASS__, 'safe_cell'), $values));
    }

    private static function safe_cell(string|int $value): string {
        $value = str_replace(array("\0", "\r", "\n"), ' ', (string) $value);
        if (preg_match('/\A[\t\x20]*[=+\-@]/', $value)) {
            $value = "'" . $value;
        }

        return $value;
    }

    private static function timestamp(int $timestamp): string {
        return $timestamp > 0 ? gmdate('Y-m-d H:i:s', $timestamp) : '';
    }
}
