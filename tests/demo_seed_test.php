<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$name='grc_revision_demo_'.bin2hex(random_bytes(5));putenv('GRC_DB_NAME='.$name);
require_once __DIR__.'/../database/seed_demo.php';
require_once __DIR__.'/../includes/backup.php';
$server=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$checks=0;
function verifyDemo(bool $ok,string $message):void{global $checks;if(!$ok)throw new RuntimeException($message);$checks++;}
try{
    $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$db=getDbConnection();cbInstall($db);
    cbQuery($db,'INSERT INTO classbook_admins(username,full_name,password_hash) VALUES(?,?,?)',['existing.owner','Existing Owner',password_hash('Existing-password-2026',PASSWORD_DEFAULT)]);
    $before=cbQuery($db,'SELECT * FROM classbook_admins WHERE id=1')->fetch();
    $m=seedDemo($db,'GrcDemo!2026');
    verifyDemo(count($m['accounts'])===39&&count($m['classes'])===5,'Dataset counts');
    verifyDemo($before===cbQuery($db,'SELECT * FROM classbook_admins WHERE id=1')->fetch(),'Existing account untouched');
    foreach($m['accounts'] as $a){
        verifyDemo(cbStatus($db,$a['role'],$a['id'])===$a['status'],'Account access status');
        verifyDemo(cbAccountPublicId($db,$a['role'],$a['id'])===$a['public'],'Generated account ID');
    }
    foreach(['demo.admin'=>'admin','demo.faculty.ccs'=>'professor','demo.student17'=>'student'] as $login=>$role)verifyDemo((cbResolveLogin($db,$login,'GrcDemo!2026')['role']??null)===$role,'Demo sign-in');
    foreach($m['classes'] as $class){
        $s=cbClass($db,$class['id'],['role'=>'admin','id'=>1]);verifyDemo($s['state']===$class['state']&&(bool)$s['archived']===$class['archived'],'Class state');
        $computed=cbCompute($db,$class['id']);
        if($class['state']!=='draft')foreach($computed['students'] as $r)verifyDemo($r['course']!==null,'Submitted/released grades complete');
        if($class['state']==='released'){
            $snapshot=json_decode(cbQuery($db,'SELECT snapshot_json FROM classbook_releases WHERE subject_id=?',[$class['id']])->fetchColumn(),true);
            // JSON round-trip normalizes whole-number floats to integers.
            $normalized=json_decode(json_encode($computed['students'],JSON_THROW_ON_ERROR),true);
            verifyDemo($snapshot['students']===$normalized,'Released snapshot matches computed evidence');
        }
        if($class['code']==='DEMO-IT101'){
            verifyDemo(count(cbMembers($db,$class['id'],false))===8&&count($computed['students'])===7,'Dropped membership retained');
            verifyDemo(count(array_filter($computed['students'],fn($r)=>$r['course']===null))===2,'Missing and not graded remain pending');
            verifyDemo((int)cbQuery($db,"SELECT COUNT(*) FROM classbook_scores sc JOIN classbook_activities a ON a.id=sc.activity_id JOIN classbook_criteria c ON c.id=a.criterion_id WHERE c.subject_id=? AND sc.status='scored' AND sc.earned=0",[$class['id']])->fetchColumn()>0,'Zero preserved');
        }
    }
    cbQuery($db,"UPDATE classbook_admins SET full_name='Demo edited name' WHERE username='demo.admin'");
    $first=cbBackup($db);$again=seedDemo($db,'Different-password-2026');$second=cbBackup($db);
    verifyDemo($m===$again,'Stable manifest');verifyDemo($first['tables']===$second['tables'],'Rerun preserves edits and all rows');
    // Collision must roll back the whole seed, including its first inserted admin.
    cbQuery($db,"DELETE FROM classbook_audit WHERE action='demo_seed_loaded'");
    cbQuery($db,"UPDATE classbook_admins SET username='demo.admin.renamed' WHERE username='demo.admin'");
    $beforeCollision=cbBackup($db);$rejected=false;
    try{seedDemo($db,'GrcDemo!2026');}catch(RuntimeException $e){$rejected=true;}
    verifyDemo($rejected,'Existing demo identifier collision refused');
    verifyDemo($beforeCollision['tables']===cbBackup($db)['tables'],'Collision rolled back without partial data');
    echo $checks." demo seed checks passed.\n";
}finally{$server->exec('DROP DATABASE IF EXISTS `'.$name.'`');}
