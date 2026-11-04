<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$worker=($argv[1]??'')==='worker';
if(!$worker)putenv('GRC_DB_NAME=grc_revision_ids_'.bin2hex(random_bytes(5)));
if(!preg_match('/^grc_revision_ids_[a-f0-9]{10}$/',getenv('GRC_DB_NAME')?:''))throw new RuntimeException('Isolated ID test database required.');
session_save_path(sys_get_temp_dir());
require_once __DIR__.'/../includes/portal.php';
require_once __DIR__.'/../includes/admin_actions.php';
require_once __DIR__.'/../includes/classbook_actions.php';
if($worker){
    $db=getDbConnection();$tag=(int)($argv[2]??0);
    cbAdminAccount($db,['role'=>'admin','id'=>1],'create_account',['role'=>'professor','username'=>'worker-'.$tag,'full_name'=>'Test worker','email'=>'worker'.$tag.'@example.invalid','department'=>'College of Computer Studies']);
    echo cbQuery($db,'SELECT employee_id FROM professors WHERE username=?',['worker-'.$tag])->fetchColumn();exit;
}
$server=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$created=false;$checks=0;
$check=function(bool $ok,string $label)use(&$checks){if(!$ok)throw new RuntimeException('FAILED: '.$label);$checks++;};
try{
    $server->exec('CREATE DATABASE `'.DB_NAME.'` CHARACTER SET utf8mb4');$created=true;$db=getDbConnection();cbInstall($db);
    $hash=password_hash('Test-only-password-2026',PASSWORD_DEFAULT);
    cbQuery($db,'INSERT INTO classbook_admins(id,username,full_name,password_hash) VALUES(1,?,?,?)',['test.admin','Test Admin',$hash]);$admin=['role'=>'admin','id'=>1];
    $adminId=cbAccountPublicId($db,'admin',1);$check($adminId==='ADMIN-'.date('Y-m').'-00001','administrator ID');
    cbAdminAccount($db,$admin,'create_account',['role'=>'student','student_no'=>'FORGED-ID','first_name'=>'Test','last_name'=>'Student','course'=>'BSIT','year_level'=>'1st Year']);
    $s=cbQuery($db,'SELECT * FROM students')->fetch();$studentId=cbAccountPublicId($db,'student',(int)$s['id']);
    $check($studentId==='STUD-'.date('Y-m').'-00001','student sequence independent of administrator');
    $check($s['student_no']===$studentId&&$s['username']===$studentId,'server ID overrides forged ID and supplies default username');
    cbQuery($db,'UPDATE students SET password_hash=? WHERE id=?',[$hash,$s['id']]);
    $check(cbResolveLogin($db,$studentId,'Test-only-password-2026')['role']==='student','automatic ID signs in');
    $check(cbAccountPublicId($db,'student',(int)$s['id'])===$studentId,'ID is stable');
    $db->beginTransaction();$unused=cbNextAccountId($db,'student');$db->rollBack();
    $db->beginTransaction();$check(cbNextAccountId($db,'student')===$unused,'rollback does not consume a number');$db->rollBack();
    cbAdminAccount($db,$admin,'edit_account',['role'=>'student','account_id'=>$s['id'],'account_revision'=>1,'student_no'=>'CHANGED-ID','username'=>$studentId,'first_name'=>'Renamed','last_name'=>'Student','email'=>'','course'=>'BSIT','year_level'=>'1st Year','reason'=>'Test correction']);
    $check(cbQuery($db,'SELECT student_no FROM students WHERE id=?',[$s['id']])->fetchColumn()===$studentId,'generated student number cannot be edited');
    $jobs=[];for($i=0;$i<5;$i++){
        $process=proc_open([PHP_BINARY,__FILE__,'worker',(string)$i],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes,null,null,['bypass_shell'=>true,'create_new_console'=>false]);
        if(!is_resource($process))throw new RuntimeException('Could not start allocation worker.');fclose($pipes[0]);$jobs[]=[$process,$pipes];
    }
    $ids=[];foreach($jobs as [$process,$pipes]){$out=trim(stream_get_contents($pipes[1]));$err=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);if(proc_close($process)!==0)throw new RuntimeException($err);$ids[]=$out;}
    $check(count(array_unique($ids))===5,'parallel allocations are unique');sort($ids);
    $check($ids[0]==='PROF-'.date('Y-m').'-00001'&&$ids[4]==='PROF-'.date('Y-m').'-00005','parallel allocations produce the correct sequence');
    $other=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.DB_NAME.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    $db->beginTransaction();$db->query('SELECT COUNT(*) FROM classbook_account_meta')->fetchColumn();
    cbAdminAccount($other,$admin,'create_account',['role'=>'professor','username'=>'snapshot-worker','full_name'=>'Snapshot Test','email'=>'snapshot@example.invalid','department'=>'College of Computer Studies']);
    $check(cbNextAccountId($db,'professor')==='PROF-'.date('Y-m').'-00007','allocation sees commits after an older transaction snapshot');$db->rollBack();
    foreach(['2025-01-10','2025-01-20','2025-02-01'] as $i=>$date){cbQuery($db,'INSERT INTO professors(employee_id,username,full_name,email,password_hash,created_at) VALUES(?,?,?,?,?,?)',['OLD-'.$i,'legacy-'.$i,'Legacy Test','legacy'.$i.'@example.invalid',$hash,$date]);$legacy[]=(int)$db->lastInsertId();}
    $check(cbAccountPublicId($db,'professor',$legacy[0])==='PROF-2025-01-00001','legacy ID uses creation month');
    $check(cbAccountPublicId($db,'professor',$legacy[1])==='PROF-2025-01-00002','same month increments');
    $check(cbAccountPublicId($db,'professor',$legacy[2])==='PROF-2025-02-00001','new month resets');
    $check(cbQuery($db,'SELECT employee_id FROM professors WHERE id=?',[$legacy[0]])->fetchColumn()==='OLD-0','legacy school ID preserved');
    $check(cbResolveLogin($db,'OLD-0','Test-only-password-2026')['role']==='professor','legacy sign-in preserved');
    $check(cbResolveLogin($db,'PROF-2025-01-00001','Test-only-password-2026')['role']==='professor','new ID signs into legacy account');
    cbUpgrade($db);$check(cbAccountPublicId($db,'student',(int)$s['id'])===$studentId,'upgrade does not reassign an ID');
    cbQuery($db,'INSERT INTO students(student_no,username,first_name,last_name,password_hash) VALUES(?,?,?,?,?)',['LEGACY-STUDENT','legacy.student','Legacy','Student',$hash]);$legacyStudent=(int)$db->lastInsertId();$legacyStudentId=cbAccountPublicId($db,'student',$legacyStudent);
    $professor=['role'=>'professor','id'=>(int)cbQuery($db,'SELECT id FROM professors WHERE username=?',['worker-0'])->fetchColumn()];
    $_POST=['subject_code'=>'ID-TEST','subject_title'=>'Account ID enrollment','section'=>'A','units'=>3,'school_year'=>'2026-2027','semester'=>'1st Semester'];$class=cbProfessorAction($db,$professor,'create_class');
    $_POST=['subject_id'=>$class,'revision'=>1,'student_no'=>$legacyStudentId,'starts_on'=>date('Y-m-d')];cbProfessorAction($db,$professor,'enroll');
    $member=cbMembers($db,$class)[0];$check((int)$member['student_id']===$legacyStudent&&$member['public_id']===$legacyStudentId,'enrollment accepts an automatic ID for a legacy student');
    echo 'PASS: '.$checks." automatic ID checks, including five simultaneous allocations.\n";
}finally{if($created)$server->exec('DROP DATABASE `'.DB_NAME.'`');}
