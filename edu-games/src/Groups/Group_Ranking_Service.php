<?php
declare(strict_types=1);

namespace EduGames\Groups;

use EduGames\Rankings\Leaderboard_Service;

final class Group_Ranking_Service {
    public static function page(string $game, $page = 1, $size = 20): array|\WP_Error {
        global $wpdb;
        $scores = \EduGames\Database\Installer::scores_table();
        $teams = \EduGames\Database\Installer::teams_table();
        $members = \EduGames\Database\Installer::team_members_table();
        $best = $wpdb->prepare("SELECT user_id, MAX(score) AS score FROM {$scores} WHERE game_slug=%s GROUP BY user_id",sanitize_key($game));
        $grouped = "SELECT teams.id, teams.name, COUNT(*) AS member_count, ROUND(AVG(best.score),1) AS average_score, SUM(best.score) AS total_score FROM {$teams} teams INNER JOIN {$members} members ON members.team_id=teams.id INNER JOIN ({$best}) best ON best.user_id=members.user_id GROUP BY teams.id, teams.name";
        $total = $wpdb->get_var("SELECT COUNT(*) FROM ({$grouped}) standings");
        if ($total === null || !empty($wpdb->last_error)) return \EduGames\Rankings\Page::error();
        $meta = \EduGames\Rankings\Page::meta((int)$total,$page,$size);
        $rows = $wpdb->get_results($wpdb->prepare("SELECT * FROM ({$grouped}) standings ORDER BY average_score DESC, total_score DESC, name ASC, id ASC LIMIT %d OFFSET %d",$meta['perPage'],$meta['offset']),ARRAY_A);
        if (!is_array($rows) || !empty($wpdb->last_error)) return \EduGames\Rankings\Page::error();
        $items = array();
        foreach ($rows as $i=>$row) $items[] = array('rank'=>$meta['offset']+$i+1,'teamId'=>(int)$row['id'],'teamName'=>$row['name'],'memberCount'=>(int)$row['member_count'],'averageScore'=>(float)$row['average_score'],'totalScore'=>(int)$row['total_score']);
        return array('standings'=>$items,'pagination'=>$meta);
    }
    /**
     * Within-team member ranking for one game — who's ahead inside a single
     * team.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function leaderboard(int $team_id, string $game_slug, int $limit = 0): array {
        $member_ids = Team_Repository::member_user_ids($team_id);
        if ($member_ids === array()) {
            return array();
        }

        return Leaderboard_Service::for_game_among_users($game_slug, $member_ids, $limit);
    }

    /**
     * Team-vs-team standings for one game: each team's score is the average
     * of its members' own best score (teams with no scoring members yet are
     * left out, and averaging — not summing — keeps larger and smaller teams
     * on a level footing). Team membership is read live, so a team's
     * standing always reflects who is on it right now, not who was on it
     * when any given score was recorded.
     *
     * @return array<int, array<string, mixed>>
     */
    public static function standings(string $game_slug, int $limit = 0): array {
        $rows = array();

        foreach (Team_Repository::all() as $team) {
            $team_id = (int) ($team['id'] ?? 0);
            $member_ids = Team_Repository::member_user_ids($team_id);
            if ($member_ids === array()) {
                continue;
            }

            $members = Leaderboard_Service::for_game_among_users($game_slug, $member_ids);
            if ($members === array()) {
                continue;
            }

            $total_score = 0;
            foreach ($members as $member) {
                $total_score += (int) $member['score'];
            }

            $rows[] = array(
                'teamId' => $team_id,
                'teamName' => (string) ($team['name'] ?? ''),
                'memberCount' => count($members),
                'averageScore' => round($total_score / count($members), 1),
                'totalScore' => $total_score,
            );
        }

        usort($rows, static function (array $left, array $right): int {
            $by_average = $right['averageScore'] <=> $left['averageScore'];
            if (0 !== $by_average) {
                return $by_average;
            }

            $by_total = $right['totalScore'] <=> $left['totalScore'];
            if (0 !== $by_total) {
                return $by_total;
            }

            return strnatcasecmp($left['teamName'], $right['teamName']);
        });

        if ($limit > 0) {
            $rows = array_slice($rows, 0, $limit);
        }

        foreach ($rows as $index => $row) {
            $rows[$index]['rank'] = $index + 1;
        }

        return $rows;
    }
}
