<?php
declare(strict_types=1);
require __DIR__.'/v1.php';
require __DIR__.'/hands-solutions.php';
use EduGames\Play\Catalog as Play_Catalog;
use EduGames\Rest\Controllers\Play_Controller as Play_API;
$wpdb->db->exec('ALTER TABLE wp_edu_games_learning_runs ADD COLUMN token TEXT');
$wpdb->db->exec('ALTER TABLE wp_edu_games_learning_runs ADD COLUMN revision INTEGER DEFAULT 0');
$wpdb->db->exec('ALTER TABLE wp_edu_games_learning_runs ADD COLUMN expires_at TEXT');
$base=$checks;
check(Play_API::start(new WP_REST_Request('outlier-detection',array('version'=>'stale-cached-page')))->data['status']===409,'stale daily preview cannot submit different data');
foreach(Play_Catalog::GAMES as $game=>$class){
    $request=fn($p=array())=>new WP_REST_Request($game,$p);
    check(Play_API::allowed($request()),'known game permitted');
    $start=Play_API::start($request(array('level'=>'board-01')));
    check(!is_wp_error($start)&&strlen($start['token'])===48,'opaque game session');
    $wpdb->db->prepare('UPDATE wp_edu_games_learning_runs SET content_version=? WHERE token=?')->execute(array(Play_Catalog::base_version($game).'-'.gmdate('Y-m-d',time()-86400),$start['token']));
    check(!is_wp_error(Play_API::resume($request(array('token'=>$start['token'])))),'in-progress daily session survives midnight');
    $wpdb->db->prepare('UPDATE wp_edu_games_learning_runs SET content_version=? WHERE token=?')->execute(array(Play_Catalog::version($game),$start['token']));
    $moves=array(hands_move($game,$class::create('board-01')),array('type'=>'finish'));
    $input=array('token'=>$start['token'],'revision'=>0,'requestId'=>'play-request-0001','move'=>$moves[0]);
    $saved=Play_API::update($request($input));
    check(!is_wp_error($saved)&&$saved['revision']===1,'CAS save');
    $replayed=Play_API::update($request($input));
    check($saved['revision']===$replayed['revision']&&$saved['score']===$replayed['score'],'idempotent update');
    check(Play_API::update($request(array_replace($input,array('requestId'=>'play-request-0002'))))->data['status']===409,'stale revision rejected');
    foreach(array('user_id'=>8,'mode'=>'strategy','game_slug'=>'unknown','content_version'=>'old','expires_at'=>'2000-01-01') as $key=>$value){
        $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM wp_edu_games_learning_runs WHERE token=%s',$start['token']),ARRAY_A);
        $wpdb->db->prepare("UPDATE wp_edu_games_learning_runs SET {$key}=? WHERE token=?")->execute(array($value,$start['token']));
        check(Play_API::resume($request($input))->data['status']===404,'session binding '.$key);
        $wpdb->db->prepare("UPDATE wp_edu_games_learning_runs SET {$key}=? WHERE token=?")->execute(array($row[$key],$start['token']));
    }
    foreach(array_slice($moves,1) as $i=>$m)$saved=Play_API::update($request(array('token'=>$start['token'],'revision'=>$saved['revision'],'requestId'=>'play-action-next-'.$i,'move'=>$m)));
    check($saved['status']==='won','finished through API');
    $row=$wpdb->get_row($wpdb->prepare('SELECT * FROM wp_edu_games_learning_runs WHERE token=%s',$start['token']),ARRAY_A);
    check((int)$row['score']===$saved['score']&&$row['completed_at']!==null,'server-computed result stored');
    $summary=Play_API::summary($request(array('page'=>1,'level'=>'board-01')));
    check($summary['levels']['board-01']['score']===$saved['score']&&$summary['levels']['board-01']['unlocked'],'daily scores and open challenge');
    $wpdb->db->prepare('UPDATE wp_edu_games_learning_runs SET started_at=? WHERE token=?')->execute(array('2000-01-01',$start['token']));
}
for($id=1;$id<=31;$id++)if($id!==7)$wpdb->insert('wp_edu_games_learning_runs',array('user_id'=>$id,'game_slug'=>'outlier-detection','content_version'=>Play_Catalog::version('outlier-detection'),'mode'=>'play','level'=>'board-01','score'=>700));
$p=Play_API::summary(new WP_REST_Request('outlier-detection',array('page'=>2,'perPage'=>20,'level'=>'board-01')));
check($p['pagination']['total']===31&&count($p['leaderboard'])===11,'all participants paginated, old season excluded');
$archive=Play_API::archive(new WP_REST_Request('outlier-detection'));
check(!empty($archive['records'])&&!in_array(Play_Catalog::version('outlier-detection'),array_column($archive['records'],'version'),true),'old records remain readable');
$report=EduGames\Reports\Report::read(array(7),'outlier-detection','play','','',1,20);
check($report['students'][0]['completed']===1&&$report['students'][0]['best']===65.0,'teacher report includes new game result');
$wpdb->fail=true;check(Play_API::summary(new WP_REST_Request('outlier-detection'))->data['status']===500,'query outage visible');$wpdb->fail=false;
$GLOBALS['caps']=array();check(!Play_API::allowed(new WP_REST_Request('outlier-detection')),'permission denied');unset($GLOBALS['caps']);
check(!Play_API::allowed(new WP_REST_Request('unknown')),'unknown game denied');
echo ($checks-$base)." play API/query checks passed\n";
