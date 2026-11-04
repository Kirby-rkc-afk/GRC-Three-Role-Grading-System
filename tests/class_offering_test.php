<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$worker=($argv[1]??'')==='worker';
if(!$worker)putenv('GRC_DB_NAME=grc_revision_offerings_'.bin2hex(random_bytes(5)));
if(!preg_match('/^grc_revision_offerings_[a-f0-9]{10}$/',getenv('GRC_DB_NAME')?:''))throw new RuntimeException('Disposable database required.');
session_save_path(sys_get_temp_dir());
require_once __DIR__.'/../includes/portal.php';
require_once __DIR__.'/../includes/classbook_actions.php';
require_once __DIR__.'/../includes/admin_actions.php';
if($worker){
    $db=getDbConnection();$mode=$argv[2]??'create';$id=(int)($argv[3]??0);
    $_POST=['subject_code'=>'SAME-101','subject_title'=>'Same offering','section'=>'A','units'=>3,'school_year'=>'2026-2027','semester'=>'1st Semester'];
    try{
        if($mode==='reassign')cbAdminClass($db,['role'=>'admin','id'=>1],'reassign',['subject_id'=>$id,'revision'=>1,'professor_id'=>1,'reason'=>'Concurrent offering test']);
        else{
            if($mode==='edit')$_POST+=['subject_id'=>$id,'revision'=>1,'reason'=>'Concurrent offering test'];
            cbProfessorAction($db,['role'=>'professor','id'=>1],$mode==='edit'?'edit_class':'create_class');
        }
        echo 'saved';
    }catch(RuntimeException $e){
        if(!in_array($e->getMessage(),['This class offering already exists.','Another class already uses this offering.','That professor already has this offering.'],true))throw $e;
        echo 'duplicate rejected';
    }
    exit;
}
$server=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$created=false;$checks=0;$processes=[];
$check=function(bool $ok,string $message)use(&$checks){if(!$ok)throw new RuntimeException($message);$checks++;};
$race=function(array $workers)use(&$processes,$check){
    $processes=[];$outputs=[];
    foreach($workers as [$mode,$id]){
        $process=proc_open([PHP_BINARY,__FILE__,'worker',$mode,(string)$id],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes);
        if(!is_resource($process))throw new RuntimeException('Could not start worker.');
        fclose($pipes[0]);$processes[]=[$process,$pipes];
    }
    foreach($processes as [$process,$pipes]){
        $outputs[]=stream_get_contents($pipes[1]);$error=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
        if(proc_close($process)!==0)throw new RuntimeException('Worker failed: '.$error);
    }
    $processes=[];sort($outputs);$check($outputs===['duplicate rejected','saved'],'Exactly one competing request must save.');
};
try{
    $server->exec('CREATE DATABASE `'.DB_NAME.'` CHARACTER SET utf8mb4');$created=true;$db=getDbConnection();cbInstall($db);
    $hash=password_hash('Test-only-password-2026',PASSWORD_DEFAULT);
    cbQuery($db,'INSERT INTO classbook_admins(id,username,full_name,password_hash) VALUES(1,?,?,?)',['offering.admin','Offering Admin',$hash]);
    foreach([1,2,3] as $id)cbQuery($db,'INSERT INTO professors(id,employee_id,username,full_name,email,password_hash) VALUES(?,?,?,?,?,?)',[$id,'P'.$id,'offering.faculty'.$id,'Offering Faculty '.$id,'offering'.$id.'@example.invalid',$hash]);
    // Delayed writes make overlapping existence checks reproducible in this disposable database.
    $db->exec('CREATE TRIGGER slow_offering_insert BEFORE INSERT ON subjects FOR EACH ROW DO SLEEP(1)');
    $race([['create',0],['create',0]]);
    $check((int)$db->query("SELECT COUNT(*) FROM subjects WHERE subject_code='SAME-101'")->fetchColumn()===1,'Concurrent creation must leave one offering.');
    $db->exec('DROP TRIGGER slow_offering_insert');
    // Remove only the disposable target from the first scenario; retain its related record history.
    $db->exec("UPDATE subjects SET subject_code='FIRST-CREATE' WHERE subject_code='SAME-101'");
    $ids=[];
    foreach(['EDIT-A','EDIT-B'] as $code){$_POST=['subject_code'=>$code,'subject_title'=>'Editable offering','section'=>'A','units'=>3,'school_year'=>'2026-2027','semester'=>'1st Semester'];$ids[]=cbProfessorAction($db,['role'=>'professor','id'=>1],'create_class');}
    $db->exec('CREATE TRIGGER slow_offering_update BEFORE UPDATE ON subjects FOR EACH ROW DO SLEEP(1)');
    $race([['edit',$ids[0]],['edit',$ids[1]]]);
    $check((int)$db->query("SELECT COUNT(*) FROM subjects WHERE subject_code='SAME-101' AND professor_id=1")->fetchColumn()===1,'Concurrent edits must leave one target offering.');
    $db->exec('DROP TRIGGER slow_offering_update');$db->exec("UPDATE subjects SET subject_code=CONCAT('EDITED-',id) WHERE subject_code='SAME-101'");
    $ids=[];
    foreach([2,3] as $prof){$_POST=['subject_code'=>'SAME-101','subject_title'=>'Reassignable offering','section'=>'A','units'=>3,'school_year'=>'2026-2027','semester'=>'1st Semester'];$ids[]=cbProfessorAction($db,['role'=>'professor','id'=>$prof],'create_class');}
    $before=(int)$db->query('SELECT COUNT(*) FROM subjects')->fetchColumn();
    $db->exec('CREATE TRIGGER slow_offering_update BEFORE UPDATE ON subjects FOR EACH ROW DO SLEEP(1)');
    $race([['reassign',$ids[0]],['reassign',$ids[1]]]);
    $check((int)$db->query("SELECT COUNT(*) FROM subjects WHERE subject_code='SAME-101' AND professor_id=1")->fetchColumn()===1,'Concurrent reassignment must leave one target offering.');
    $check((int)$db->query('SELECT COUNT(*) FROM subjects')->fetchColumn()===$before,'Reassignment retains all original classes.');
    echo 'PASS: '.$checks." offering concurrency checks across creation, editing and reassignment.\n";
}finally{
    foreach($processes as [$process,$pipes]){if(is_resource($process))proc_terminate($process);}
    if($created)$server->exec('DROP DATABASE `'.DB_NAME.'`');
}
