<?php
declare(strict_types=1);

namespace LearningMaterials\Materials;

use LearningMaterials\Database\Installer;
use RuntimeException;
use Throwable;

final class Material_Repository {
    public function find(int $material_id): ?Material {
        global $wpdb;

        $table = Installer::materials_table();
        $row = $wpdb->get_row(
            $wpdb->prepare("SELECT * FROM {$table} WHERE id = %d LIMIT 1", $material_id),
            ARRAY_A
        );

        return is_array($row) ? Material::from_row($row) : null;
    }

    /** @return list<Material> */
    public function all(): array {
        global $wpdb;

        $table = Installer::materials_table();
        $rows = $wpdb->get_results("SELECT * FROM {$table} ORDER BY id DESC", ARRAY_A);

        return array_map(array(Material::class, 'from_row'), is_array($rows) ? $rows : array());
    }

    /** @return list<string> */
    public function group_ids_for(int $material_id): array {
        global $wpdb;

        $table = Installer::material_groups_table();
        $rows = $wpdb->get_col(
            $wpdb->prepare("SELECT group_id FROM {$table} WHERE material_id = %d ORDER BY id", $material_id)
        );

        return array_values(array_map('strval', is_array($rows) ? $rows : array()));
    }

    /** @return array<int, list<string>> */
    public function group_ids_map(): array {
        global $wpdb;
        $table = Installer::material_groups_table();
        $rows = $wpdb->get_results("SELECT material_id, group_id FROM {$table} ORDER BY id", ARRAY_A);
        $map = array();
        foreach (is_array($rows) ? $rows : array() as $row) $map[(int) $row['material_id']][] = (string) $row['group_id'];
        return $map;
    }

    /**
     * @param array{storage_filename:string,original_filename:string,extension:string,mime_type:string,file_size:int} $file
     * @param list<string> $group_ids
     */
    public function create(string $title, string $description, array $file, int $actor_user_id, array $group_ids, ?Material_Settings $settings = null): int {
        return $this->transaction(function () use ($title, $description, $file, $actor_user_id, $group_ids, $settings): int {
            global $wpdb;

            $now = current_time('mysql', true);
            $inserted = $wpdb->insert(
                Installer::materials_table(),
                array_merge(array(
                    'title' => $title,
                    'description' => $description,
                    'source_url' => $file['source_url'] ?? '',
                    'storage_filename' => $file['storage_filename'],
                    'original_filename' => $file['original_filename'],
                    'extension' => $file['extension'],
                    'mime_type' => $file['mime_type'],
                    'file_size' => $file['file_size'],
                    'uploaded_by_user_id' => $actor_user_id,
                    'created_at' => $now,
                    'updated_at' => $now,
                ), ($settings ?? new Material_Settings())->row()),
                array('%s', '%s', '%s', '%s', '%s', '%s', '%s', '%d', '%d', '%s', '%s', '%s', '%s', '%d', '%s', '%s', '%s')
            );
            if ($inserted === false) {
                throw new RuntimeException(__('학습자료를 저장하지 못했습니다.', 'learning-materials'));
            }
            $material_id = (int) $wpdb->insert_id;
            $this->replace_group_access($material_id, $group_ids);

            return $material_id;
        });
    }

    /**
     * @param array{storage_filename:string,original_filename:string,extension:string,mime_type:string,file_size:int}|null $file
     * @param list<string> $group_ids
     */
    public function update(int $material_id, string $title, string $description, ?array $file, array $group_ids, ?Material_Settings $settings = null): void {
        $this->transaction(function () use ($material_id, $title, $description, $file, $group_ids, $settings): void {
            global $wpdb;

            $data = array(
                'title' => $title,
                'description' => $description,
                'updated_at' => current_time('mysql', true),
            );
            $formats = array('%s', '%s', '%s');

            if (null !== $file) {
                $data['source_url'] = $file['source_url'] ?? '';
                $data['storage_filename'] = $file['storage_filename'];
                $data['original_filename'] = $file['original_filename'];
                $data['extension'] = $file['extension'];
                $data['mime_type'] = $file['mime_type'];
                $data['file_size'] = $file['file_size'];
                $formats = array_merge($formats, array('%s', '%s', '%s', '%s', '%s', '%d'));
            }

            if ($settings !== null) {
                $data = array_merge($data, $settings->row());
                $formats = array_merge($formats, array('%s', '%s', '%d', '%s', '%s', '%s'));
            }
            if ($wpdb->update(Installer::materials_table(), $data, array('id' => $material_id), $formats, array('%d')) === false) {
                throw new RuntimeException(__('학습자료를 저장하지 못했습니다.', 'learning-materials'));
            }
            $this->replace_group_access($material_id, $group_ids);
        });
    }

    public function delete(int $material_id): void {
        $this->transaction(function () use ($material_id): void {
            global $wpdb;

            if ($wpdb->delete(Installer::material_groups_table(), array('material_id' => $material_id), array('%d')) === false
                || $wpdb->delete(Installer::materials_table(), array('id' => $material_id), array('%d')) === false) {
                throw new RuntimeException(__('학습자료를 삭제하지 못했습니다.', 'learning-materials'));
            }
        });
    }

    /** @param list<string> $group_ids */
    private function replace_group_access(int $material_id, array $group_ids): void {
        global $wpdb;

        $table = Installer::material_groups_table();
        if ($wpdb->delete($table, array('material_id' => $material_id), array('%d')) === false) {
            throw new RuntimeException(__('자료의 그룹 설정을 저장하지 못했습니다.', 'learning-materials'));
        }
        foreach (array_values(array_unique($group_ids)) as $group_id) {
            if ($wpdb->insert($table, array('material_id' => $material_id, 'group_id' => $group_id), array('%d', '%s')) === false) {
                throw new RuntimeException(__('자료의 그룹 설정을 저장하지 못했습니다.', 'learning-materials'));
            }
        }
    }

    private function transaction(callable $operation): mixed {
        global $wpdb;
        if ($wpdb->query('START TRANSACTION') === false) throw new RuntimeException(__('자료 저장을 시작하지 못했습니다.', 'learning-materials'));
        try {
            $result = $operation();
            if ($wpdb->query('COMMIT') === false) throw new RuntimeException(__('자료 저장을 완료하지 못했습니다.', 'learning-materials'));
            return $result;
        } catch (Throwable $error) {
            $wpdb->query('ROLLBACK');
            throw $error;
        }
    }
}
