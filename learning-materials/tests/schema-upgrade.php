<?php
declare(strict_types=1);
// Exercise the installer against an in-memory SQL database. dbDelta's additive
// column behavior is emulated; actual WordPress/MySQL deployment remains separate.
require_once dirname(__DIR__) . '/src/Database/Installer.php';
require_once dirname(__DIR__) . '/src/Materials/Material.php';
define('LEARNING_MATERIALS_VERSION', '1.0.0');
$root = dirname(__DIR__, 2) . '/_notes/tmp/lmt-schema-' . bin2hex(random_bytes(8));
mkdir($root . '/wp-admin/includes', 0750, true);
define('ABSPATH', $root . '/');
file_put_contents($root . '/wp-admin/includes/upgrade.php', '<?php function dbDelta(string $sql): void { TestMigration::apply($sql); }');
function get_option(string $key): mixed { return $GLOBALS['options'][$key] ?? false; }
function update_option(string $key, mixed $value): void { $GLOBALS['options'][$key] = $value; }
final class TestMigration {
    public static SQLite3 $db;
    public static int $calls = 0;
    public static function apply(string $sql): void {
        self::$calls++;
        preg_match('/CREATE TABLE (\w+)/', $sql, $match);
        $table = $match[1];
        $exists = self::$db->querySingle("SELECT count(*) FROM sqlite_master WHERE type='table' AND name='$table'") > 0;
        preg_match_all('/^\s+([a-z_]+) (.+),?$/m', $sql, $matches, PREG_SET_ORDER);
        $columns = array();
        foreach ($matches as $column) {
            $definition = rtrim($column[2], ',');
            $definition = str_replace(array(' unsigned', ' AUTO_INCREMENT'), '', $definition);
            $columns[$column[1]] = $column[1] === 'id' ? 'id INTEGER PRIMARY KEY' : $column[1] . ' ' . $definition;
        }
        if (! $exists) {
            self::$db->exec('CREATE TABLE ' . $table . ' (' . implode(', ', $columns) . ')');
            return;
        }
        $current = array();
        $rows = self::$db->query('PRAGMA table_info(' . $table . ')');
        while ($row = $rows->fetchArray(SQLITE3_ASSOC)) $current[] = $row['name'];
        foreach ($columns as $name => $definition) if (! in_array($name, $current, true)) self::$db->exec('ALTER TABLE ' . $table . ' ADD COLUMN ' . $definition);
    }
}
$wpdb = new class {
    public string $prefix = 'wp_';
    public function get_charset_collate(): string { return ''; }
    public function prepare(string $sql, string $value): string { return $value; }
    public function get_var(string $table): mixed { return TestMigration::$db->querySingle("SELECT name FROM sqlite_master WHERE name='$table'"); }
};
try {
    foreach (array(false, true) as $legacy) {
        TestMigration::$db = new SQLite3(':memory:');
        TestMigration::$db->enableExceptions(true);
        TestMigration::$calls = 0;
        $GLOBALS['options'] = array('learning_materials_db_version' => $legacy ? '0.1.5' : '');
        if ($legacy) {
            TestMigration::$db->exec("CREATE TABLE wp_learning_materials (id INTEGER PRIMARY KEY, title TEXT, description TEXT, storage_filename TEXT,
                original_filename TEXT, extension TEXT, mime_type TEXT, file_size INTEGER, uploaded_by_user_id INTEGER, created_at TEXT, updated_at TEXT)");
            TestMigration::$db->exec("INSERT INTO wp_learning_materials (id,title,storage_filename,extension) VALUES (7, '기존 자료', 'old.pdf', 'pdf')");
            TestMigration::$db->exec('CREATE TABLE wp_learning_material_groups (id INTEGER PRIMARY KEY, material_id INTEGER, group_id TEXT)');
            TestMigration::$db->exec("INSERT INTO wp_learning_material_groups VALUES (1, 7, 'own')");
        }
        LearningMaterials\Database\Installer::maybe_install();
        if (get_option('learning_materials_db_version') !== '1.0.0' || TestMigration::$calls !== 2) throw new RuntimeException('Upgrade did not run');
        if ($legacy) {
            $row = TestMigration::$db->querySingle('SELECT * FROM wp_learning_materials WHERE id=7', true);
            $material = LearningMaterials\Materials\Material::from_row($row);
            if ($material->title !== '기존 자료' || ! $material->is_available() || $material->category !== '' || $material->is_pinned
                || TestMigration::$db->querySingle('SELECT count(*) FROM wp_learning_material_groups') !== 1) throw new RuntimeException('Existing data changed');
            TestMigration::$db->exec("UPDATE wp_learning_materials SET publication_status='archived', category='정보' WHERE id=7");
        }
        LearningMaterials\Database\Installer::maybe_install();
        if (TestMigration::$calls !== 2) throw new RuntimeException('Migration repeated at current version');
        LearningMaterials\Database\Installer::install();
        if ($legacy && TestMigration::$db->querySingle('SELECT publication_status FROM wp_learning_materials WHERE id=7') !== 'archived') throw new RuntimeException('Repeated install reset settings');
        TestMigration::$db->close();
    }
    echo "Schema: fresh install, legacy upgrade, group/data preservation and idempotence passed (SQLite dbDelta emulation).\n";
} finally {
    unlink($root . '/wp-admin/includes/upgrade.php');
    rmdir($root . '/wp-admin/includes');
    rmdir($root . '/wp-admin');
    rmdir($root);
}
