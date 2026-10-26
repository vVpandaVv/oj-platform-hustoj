<?php
declare(strict_types=1);

namespace TeachingTools\Rest\Controllers;

use CodingPanda\OjPlatform\AddonApi\V1\AddonAcceptedSubmissionQueryV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonApiProblemV1;
use CodingPanda\OjPlatform\AddonApi\V1\AddonProblemQueryV1;
use InvalidArgumentException;
use TeachingTools\Addon\Seating\Runtime_Bridge;
use TeachingTools\Sessions\Tool_Session_Repository;
use TeachingTools\Support\Capabilities;
use WP_Error;
use WP_REST_Request;
use WP_REST_Server;

/** Platform-authorized problem and accepted-submission projections. */
final class Judge_Controller {
    private const CODE_PATTERN = '(?P<code>[A-Za-z0-9]{6})';

    public static function register_routes(): void {
        register_rest_route('teaching-tools/v1', '/oj/problems', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'search_problems'),
            'permission_callback' => array(Capabilities::class, 'can_host'),
        ));
        register_rest_route('teaching-tools/v1', '/oj/accepted-counts', array(
            'methods' => WP_REST_Server::CREATABLE,
            'callback' => array(__CLASS__, 'accepted_counts'),
            'permission_callback' => array(Capabilities::class, 'can_host'),
        ));
        register_rest_route('teaching-tools/v1', '/sessions/' . self::CODE_PATTERN . '/timeattack-status', array(
            'methods' => WP_REST_Server::READABLE,
            'callback' => array(__CLASS__, 'timeattack_status'),
            'permission_callback' => array(Capabilities::class, 'can_host'),
        ));
    }

    public static function search_problems(WP_REST_Request $request) {
        $gateway = Runtime_Bridge::integrations();
        if (null === $gateway) {
            return self::integration_unavailable_error();
        }

        try {
            $page = $gateway->searchProblems(new AddonProblemQueryV1(
                get_current_user_id(),
                sanitize_text_field((string) $request->get_param('search')),
                30
            ));
        } catch (InvalidArgumentException) {
            return new WP_Error('teaching_tools_invalid_problem_search', __('검색어를 확인해 주세요.', 'teaching-tools'), array('status' => 400));
        }
        if (! $page->isSuccessful()) {
            return self::platform_error($page->problem());
        }

        return rest_ensure_response(array('items' => array_map(
            static fn ($problem): array => array(
                'problemId' => $problem->problemId(),
                'title' => $problem->title(),
                'submitted' => $problem->submitted(),
                'accepted' => $problem->accepted(),
            ),
            $page->items()
        )));
    }

    public static function accepted_counts(WP_REST_Request $request) {
        $gateway = Runtime_Bridge::integrations();
        if (null === $gateway) {
            return self::integration_unavailable_error();
        }

        $params = $request->get_json_params();
        $params = is_array($params) ? $params : array();
        $ids = self::positive_ids($params['ids'] ?? array(), 500);
        $context = self::context_from($params['context'] ?? null);
        if (is_wp_error($context)) {
            return $context;
        }
        if ([] === $ids) {
            return rest_ensure_response(array('counts' => array()));
        }

        // The contract intentionally keeps reads bounded. A broad public
        // problem sample provides a stable balancing signal without exposing
        // arbitrary judge-table access to addons.
        $problems = $gateway->searchProblems(new AddonProblemQueryV1(get_current_user_id(), '', 100));
        if (! $problems->isSuccessful()) {
            return self::platform_error($problems->problem());
        }
        $problem_ids = array_map(static fn ($problem): int => $problem->problemId(), $problems->items());
        if ([] === $problem_ids) {
            return rest_ensure_response(array('counts' => array_fill_keys($ids, 0)));
        }

        try {
            $page = $gateway->acceptedSubmissions(new AddonAcceptedSubmissionQueryV1(
                get_current_user_id(),
                $context['type'],
                $context['id'],
                $context['revision'],
                $ids,
                $problem_ids
            ));
        } catch (InvalidArgumentException) {
            return self::invalid_context_error();
        }
        if (! $page->isSuccessful()) {
            return self::platform_error($page->problem());
        }

        $solved = array_fill_keys($ids, array());
        foreach ($page->items() as $submission) {
            $solved[$submission->learnerUserId()][$submission->problemId()] = true;
        }
        $counts = array_map('count', $solved);

        return rest_ensure_response(array('counts' => $counts));
    }

    public static function timeattack_status(WP_REST_Request $request) {
        $actor_id = get_current_user_id();
        $session = Tool_Session_Repository::find_active_by_code((string) $request['code']);
        $host_role = null === $session ? null : Tool_Session_Repository::host_role((int) $session['id'], $actor_id);
        if (
            null === $session
            || 'timeattack' !== (string) $session['tool_type']
            || ! in_array($host_role, array('owner', 'cohost', 'observer'), true)
        ) {
            return new WP_Error('teaching_tools_session_not_found', __('참여 코드를 확인해 주세요.', 'teaching-tools'), array('status' => 404));
        }
        $gateway = Runtime_Bridge::integrations();
        if (null === $gateway) {
            return self::integration_unavailable_error();
        }

        $state = json_decode((string) $session['state'], true);
        $state = is_array($state) ? $state : array();
        $problems = is_array($state['problems'] ?? null) ? array_values(array_filter($state['problems'], 'is_array')) : array();
        $problem_ids = self::positive_ids(array_map(static fn (array $problem): int => (int) ($problem['problemId'] ?? 0), $problems), 100);
        $roster = is_array($state['roster'] ?? null) ? array_values(array_filter($state['roster'], 'is_array')) : array();
        $ids = self::positive_ids(array_map(static fn (array $entry): int => (int) ($entry['id'] ?? 0), $roster), 500);
        $context = self::context_from($state['context'] ?? null);
        if (is_wp_error($context) || [] === $problem_ids || [] === $ids) {
            return self::invalid_context_error();
        }

        try {
            $page = $gateway->acceptedSubmissions(new AddonAcceptedSubmissionQueryV1(
                $actor_id,
                $context['type'],
                $context['id'],
                $context['revision'],
                $ids,
                $problem_ids,
                (string) $session['created_at']
            ));
        } catch (InvalidArgumentException) {
            return self::invalid_context_error();
        }
        if (! $page->isSuccessful()) {
            return self::platform_error($page->problem());
        }

        $solved_by_id = array_fill_keys($ids, array());
        foreach ($page->items() as $submission) {
            $id = $submission->learnerUserId();
            $solved_by_id[$id][$submission->problemId()] = $submission->acceptedAt();
        }

        $total_count = count($problem_ids);
        $results = array_map(static function (array $entry) use ($solved_by_id, $total_count): array {
            $id = (int) ($entry['id'] ?? 0);
            $solved = $solved_by_id[$id] ?? array();
            $finished = $total_count > 0 && count($solved) === $total_count;

            return array(
                'id' => $id,
                'name' => sanitize_text_field((string) ($entry['name'] ?? '')),
                'solvedCount' => count($solved),
                'totalCount' => $total_count,
                'solvedProblemIds' => array_map('intval', array_keys($solved)),
                'finished' => $finished,
                'finishedAt' => $finished ? max($solved) : null,
            );
        }, $roster);

        usort($results, static function (array $left, array $right): int {
            if ($left['finished'] !== $right['finished']) {
                return $left['finished'] ? -1 : 1;
            }
            if ($left['finished'] && $right['finished']) {
                return strcmp((string) $left['finishedAt'], (string) $right['finishedAt']);
            }
            return $right['solvedCount'] <=> $left['solvedCount'];
        });

        $rank = 0;
        foreach ($results as &$result) {
            $result['rank'] = $result['finished'] ? ++$rank : null;
        }
        unset($result);

        return rest_ensure_response(array(
            'problems' => $problems,
            'results' => $results,
            'finishedCount' => count(array_filter($results, static fn (array $result): bool => $result['finished'])),
        ));
    }

    /** @return list<int> */
    private static function positive_ids($values, int $maximum): array {
        if (! is_array($values)) {
            return array();
        }

        return array_slice(array_values(array_unique(array_filter(
            array_map('intval', $values),
            static fn (int $id): bool => $id > 0
        ))), 0, $maximum);
    }

    /** @return array{type: string, id: string, revision: string}|WP_Error */
    private static function context_from($value) {
        if (! is_array($value)) {
            return self::invalid_context_error();
        }
        $type = sanitize_key((string) ($value['type'] ?? ''));
        $id = sanitize_text_field((string) ($value['id'] ?? ''));
        $revision = sanitize_text_field((string) ($value['revision'] ?? ''));
        if (! in_array($type, array('class', 'group'), true) || '' === $id || '' === $revision) {
            return self::invalid_context_error();
        }

        return compact('type', 'id', 'revision');
    }

    private static function invalid_context_error(): WP_Error {
        return new WP_Error(
            'teaching_tools_context_required',
            __('학급 또는 그룹 명단을 새로 불러온 뒤 다시 시도해 주세요.', 'teaching-tools'),
            array('status' => 400)
        );
    }

    private static function platform_error(?AddonApiProblemV1 $problem): WP_Error {
        return new WP_Error(
            'teaching_tools_platform_integration_failed',
            $problem instanceof AddonApiProblemV1 ? $problem->message() : __('플랫폼 통합 기능을 사용할 수 없습니다.', 'teaching-tools'),
            array(
                'status' => 502,
                'platformCode' => $problem instanceof AddonApiProblemV1 ? $problem->code() : '',
            )
        );
    }

    private static function integration_unavailable_error(): WP_Error {
        return new WP_Error(
            'teaching_tools_platform_integration_unavailable',
            __('OJ Platform을 업데이트한 뒤 이 기능을 사용할 수 있습니다.', 'teaching-tools'),
            array('status' => 503)
        );
    }
}
