<?php
declare(strict_types=1);
namespace OjNetworkLab\Storage;
final class Projects
{
    public function __construct(private readonly object $db) {}
    public function list(int $owner): array
    {
        return $this->db->get_results($this->db->prepare('SELECT id,client_id,title,revision,updated_at FROM ' . Installer::table() . ' WHERE user_id=%d ORDER BY updated_at DESC,id DESC LIMIT 20', $owner), ARRAY_A) ?? [];
    }
    public function read(int $owner, int $id): array
    {
        $row = $this->db->get_row($this->db->prepare('SELECT * FROM ' . Installer::table() . ' WHERE id=%d AND user_id=%d', $id, $owner), ARRAY_A);
        if (!is_array($row)) { throw new \OutOfBoundsException('프로젝트가 없거나 접근할 수 없습니다.'); }
        return $row;
    }
    public static function response(array $row): array
    {
        return ['id' => (int) $row['id'], 'clientId' => $row['client_id'], 'title' => $row['title'], 'revision' => (int) $row['revision'], 'updatedAt' => $row['updated_at'], 'project' => json_decode($row['project_json'], true, 32, JSON_THROW_ON_ERROR)];
    }
    public function save(int $owner, int $id, array $input): array
    {
        $project = ProjectValidator::validate($input['project'] ?? null);
        $json = (string) wp_json_encode($project, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); $hash = hash('sha256', $json);
        $title = sanitize_text_field($project['title']);
        $lock = 'ojnl_' . substr(hash('sha256', Installer::table() . ':' . $owner), 0, 40);
        if ((string) $this->db->get_var($this->db->prepare('SELECT GET_LOCK(%s,0)', $lock)) !== '1') { throw new \RuntimeException('저장 중입니다. 잠시 후 다시 시도하세요.', 429); }
        try {
            if ($id > 0) {
                $old = $this->read($owner, $id);
                if (!is_int($input['revision'] ?? null) || $input['revision'] !== (int) $old['revision']) { if (hash_equals($old['project_hash'], $hash)) { return self::response($old); } throw new \RuntimeException('다른 창에서 저장한 버전이 있습니다. 서버본을 불러오거나 새 사본으로 저장하세요.', 409); }
                if (hash_equals($old['project_hash'], $hash)) { return self::response($old); }
                if (strtotime($old['updated_at'] . ' UTC') > time() - 2) { throw new \RuntimeException('저장 간격이 너무 짧습니다. 잠시 후 다시 시도하세요.', 429); }
                $result = $this->db->query($this->db->prepare('UPDATE ' . Installer::table() . ' SET title=%s,project_json=%s,project_hash=%s,revision=revision+1,updated_at=%s WHERE id=%d AND user_id=%d AND revision=%d', $title, $json, $hash, gmdate('Y-m-d H:i:s'), $id, $owner, $input['revision']));
                if ($result !== 1) { throw new \RuntimeException('저장 상태가 바뀌었습니다. 다시 불러와 주세요.', 409); }
            } else {
                $client = $input['clientId'] ?? ''; if (!is_string($client) || !preg_match('/^[a-f0-9-]{36}$/D', $client)) { throw new \InvalidArgumentException('프로젝트 요청 식별자를 확인하세요.'); }
                $old = $this->db->get_row($this->db->prepare('SELECT * FROM ' . Installer::table() . ' WHERE user_id=%d AND client_id=%s', $owner, $client), ARRAY_A);
                if (is_array($old)) { if (!hash_equals($old['project_hash'], $hash)) { throw new \RuntimeException('이 요청은 이미 저장되었습니다. 서버 목록에서 불러오세요.', 409); } return self::response($old); }
                $count = $this->db->get_var($this->db->prepare('SELECT COUNT(*) FROM ' . Installer::table() . ' WHERE user_id=%d', $owner));
                if ($count === null) { throw new \RuntimeException('저장소를 조회하지 못했습니다.', 503); }
                if ((int) $count >= 20) { throw new \RuntimeException('서버 프로젝트는 계정당 최대 20개입니다. 기존 프로젝트를 정리하세요.', 429); }
                if ($this->db->insert(Installer::table(), ['user_id' => $owner, 'client_id' => $client, 'title' => $title, 'project_json' => $json, 'project_hash' => $hash, 'revision' => 1, 'updated_at' => gmdate('Y-m-d H:i:s')], ['%d', '%s', '%s', '%s', '%s', '%d', '%s']) !== 1) { throw new \RuntimeException('프로젝트를 저장하지 못했습니다.', 503); }
                $id = (int) $this->db->insert_id;
            }
            return self::response($this->read($owner, $id));
        } finally { $this->db->get_var($this->db->prepare('SELECT RELEASE_LOCK(%s)', $lock)); }
    }
    public function delete(int $owner, int $id, int $revision): void
    {
        $this->read($owner, $id);
        $deleted = $this->db->query($this->db->prepare('DELETE FROM ' . Installer::table() . ' WHERE id=%d AND user_id=%d AND revision=%d', $id, $owner, $revision));
        if ($deleted !== 1) { throw new \RuntimeException('다른 창에서 변경되었습니다. 목록을 새로 불러오세요.', 409); }
    }
    public function erase(int $owner): void { if ($this->db->delete(Installer::table(), ['user_id' => $owner], ['%d']) === false) { throw new \RuntimeException('개인 프로젝트를 삭제하지 못했습니다.'); } }
}
