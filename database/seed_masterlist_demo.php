<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/seed_demo.php';
function seedMasterlistDemo(PDO $db):array{
    $db->beginTransaction();
    try{
        cbLockIdentifiers($db);
        $existing=cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='demo_masterlist_seeded' ORDER BY id DESC LIMIT 1")->fetchColumn();
        if($existing){$db->commit();return json_decode($existing,true,512,JSON_THROW_ON_ERROR);}
        $seed=cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='demo_seed_loaded' ORDER BY id DESC LIMIT 1")->fetchColumn();
        if(!$seed)throw new RuntimeException('Load the base demo dataset first.');
        $seed=json_decode($seed,true,512,JSON_THROW_ON_ERROR);$class=null;
        foreach($seed['classes'] as $row)if($row['code']==='DEMO-IT101')$class=$row;
        if(!$class)throw new RuntimeException('Base demo class missing.');
        $subject=(int)$class['id'];
        $s=cbQuery($db,"SELECT s.* FROM subjects s JOIN classbook_settings c ON c.subject_id=s.id JOIN professors p ON p.id=s.professor_id WHERE s.id=? AND s.subject_code='DEMO-IT101' AND p.username='demo.faculty.ccs' AND c.state='draft' AND c.archived=0 FOR UPDATE",[$subject])->fetch();
        if(!$s)throw new RuntimeException('Demo class has changed. Preserve it and review before extending.');
        $members=cbMembers($db,$subject);if(count($members)!==7)throw new RuntimeException('Demo roster has changed; do not overwrite it.');
        $hash=password_hash('GrcDemo!2026',PASSWORD_DEFAULT);$created=[];
        for($n=31;$n<=63;$n++){
            $username='demo.student'.$n;$public=cbNextAccountId($db,'student');$email=$username.'@example.invalid';
            cbUniqueIdentifiers($db,'student',['username'=>$username,'student_no'=>$public,'email'=>$email]);
            cbQuery($db,'INSERT INTO students(student_no,username,first_name,last_name,email,password_hash,course,year_level) VALUES(?,?,?,?,?,?,?,?)',[$public,$username,'Demo','Learner '.sprintf('%02d',$n),$email,$hash,'BS Information Technology','1st Year']);$student=(int)$db->lastInsertId();
            cbStoreAccountId($db,'student',$student,$public);cbQuery($db,"INSERT INTO classbook_access(role,account_id,status) VALUES('student',?,'active')",[$student]);
            cbQuery($db,'INSERT INTO enrollments(professor_id,subject_id,student_id) VALUES(?,?,?)',[$s['professor_id'],$subject,$student]);$enrollment=(int)$db->lastInsertId();
            cbQuery($db,'INSERT INTO classbook_members(enrollment_id,starts_on) VALUES(?,?)',[$enrollment,date('Y-m-d',strtotime('-30 days'))]);
            $created[]=['id'=>$student,'username'=>$username,'public'=>$public];
        }
        cbQuery($db,'UPDATE classbook_settings SET revision=revision+1 WHERE subject_id=?',[$subject]);
        cbQuery($db,'INSERT INTO subjects(professor_id,subject_code,subject_title,section,units,school_year,semester) VALUES(?,?,?,?,?,?,?)',[$s['professor_id'],'DEMO-IT102','Demo Web Development','DEMO-1B',3,$s['school_year'],$s['semester']]);$second=(int)$db->lastInsertId();
        foreach(cbMembers($db,$subject) as $m)cbQuery($db,'INSERT INTO enrollments(professor_id,subject_id,student_id) VALUES(?,?,?)',[$s['professor_id'],$second,$m['student_id']]);
        cbInitialize($db,$second);cbQuery($db,'INSERT INTO classbook_policies(subject_id,policy_label) VALUES(?,?) ON DUPLICATE KEY UPDATE policy_label=VALUES(policy_label)',[$second,'DEMO policy - sample only']);
        $manifest=['dataset'=>'grc-masterlist-demo-v1','classes'=>[$subject,$second],'students_added'=>$created,'note'=>'40 active students per class. Newly enrolled sample students have no invented scores.'];
        cbAudit($db,['role'=>'system','id'=>0],$subject,'demo_masterlist_seeded',$manifest);
        $db->commit();return $manifest;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    if(!in_array('--apply',$argv,true)){echo "Preview: extend the unchanged demo IT roster to 40 active students and add a second class for the same demo professor. Back up privately before --apply.\n";exit;}
    echo json_encode(seedMasterlistDemo(getDbConnection()),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}
