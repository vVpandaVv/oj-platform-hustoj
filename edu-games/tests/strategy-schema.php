<?php
declare(strict_types=1);
require __DIR__.'/bootstrap.php';
define('ABSPATH',__DIR__.'/fixtures/wp/');
function dbDelta($sql){$GLOBALS['schema_sql']=$sql;$GLOBALS['schema_calls']=($GLOBALS['schema_calls']??0)+1;}
$GLOBALS['wpdb']=new class {
    public string $prefix='wp_';public string $type='decimal(5,2)';
    public function get_charset_collate(){return 'DEFAULT CHARACTER SET utf8mb4';}
    public function get_row($sql,$format){return array('Type'=>$this->type);}
};
$GLOBALS['options']['edu_games_learning_schema']='1';
EduGames\Learning\Installer::maybe_install();
if(get_option('edu_games_learning_schema')!=='1')throw new RuntimeException('Failed migration marked complete');
if(!str_contains($GLOBALS['schema_sql'],'score decimal(7,2)'))throw new RuntimeException('Score column must fit 1000');
$GLOBALS['wpdb']->type='decimal(7,2)';EduGames\Learning\Installer::maybe_install();
if(get_option('edu_games_learning_schema')!=='2')throw new RuntimeException('Migration not marked after verification');
$calls=$GLOBALS['schema_calls'];EduGames\Learning\Installer::maybe_install();
if($GLOBALS['schema_calls']!==$calls)throw new RuntimeException('Repeated migration');
echo "PASS: 4 schema migration contract checks (dbDelta double; no live database).\n";
