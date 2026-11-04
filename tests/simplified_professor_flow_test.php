<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
$dbName='grc_revision_test_'.bin2hex(random_bytes(5));
putenv('GRC_DB_NAME='.$dbName);
session_save_path(sys_get_temp_dir());
require_once __DIR__.'/../includes/portal.php';
require_once __DIR__.'/../includes/classbook_actions.php';
require_once __DIR__.'/../includes/admin_actions.php';

$server=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$created=false;$checks=0;
$check=function(bool $ok,string $msg)use(&$checks){if(!$ok)throw new RuntimeException($msg);$checks++;};

try{
    $server->exec('CREATE DATABASE `'.$dbName.'` CHARACTER SET utf8mb4');$created=true;
    $db=getDbConnection();cbInstall($db);
    $hash=password_hash('Test-only-password-2026',PASSWORD_DEFAULT);

    // 1. Setup accounts
    cbQuery($db,'INSERT INTO classbook_admins(id,username,full_name,password_hash) VALUES(1,?,?,?)',['admin.user','Admin User',$hash]);
    $admin=['id'=>1,'role'=>'admin','username'=>'admin.user','full_name'=>'Admin User'];

    cbQuery($db,'INSERT INTO professors(id,employee_id,username,full_name,email,department,password_hash) VALUES(1,?,?,?,?,?,?)',['PROF-01','prof.test','Prof Test','prof@grc.edu.ph','College of Computer Studies',$hash]);
    cbQuery($db,"INSERT INTO classbook_access(role,account_id,status) VALUES('professor',1,'active')");
    $prof=['id'=>1,'role'=>'professor','username'=>'prof.test','full_name'=>'Prof Test'];

    for($i=1;$i<=5;$i++){
        $sno='STUD-2026-0000'.$i;
        cbQuery($db,'INSERT INTO students(id,student_no,username,first_name,last_name,course,year_level,password_hash) VALUES(?,?,?,?,?,?,?,?)',[$i,$sno,'stud'.$i,'Student'.$i,'LastName'.$i,'BSIT','1st Year',$hash]);
        cbQuery($db,"INSERT INTO classbook_access(role,account_id,status) VALUES('student',?,'active')",[$i]);
    }

    // 2. Admin creates a class offering and assigns to prof (cbCreateOffering)
    $_POST=['subject_code'=>'CS101','subject_title'=>'Intro to Computing','section'=>'BSIT-1A','units'=>3,'school_year'=>'2026-2027','semester'=>'1st Semester'];
    $classId=cbCreateOffering($db,$admin,$prof['id'],$_POST);
    $check($classId>0,'Class offering created by admin');

    // Verify default criteria & policy are initialized
    $criteria=cbClassCriteria($db,$classId);
    $check(count($criteria)>0,'Class criteria auto-initialized with GRC defaults');
    $policy=cbPolicy($db,$classId);
    $check($policy['passing_percent']==75.0,'Default passing rate is 75%');
    $check(!empty($policy['conversion_json']),'Default GRC conversion table is pre-populated');

    // 3. Prof adds students via enroll_many (paste student numbers)
    $s=cbClass($db,$classId,$prof);
    $_POST=[
        'action'=>'enroll_many',
        'subject_id'=>$classId,
        'revision'=>$s['revision'],
        'student_numbers'=>"STUD-2026-00001\nSTUD-2026-00002,STUD-2026-00003\tSTUD-2026-00004\r\nSTUD-2026-00005",
        'starts_on'=>'2026-08-01'
    ];
    cbProfessorAction($db,$prof,'enroll_many');
    $members=cbMembers($db,$classId);
    $check(count($members)===5,'All 5 students enrolled via bulk paste');

    // 4. Prof creates an activity
    $s=cbClass($db,$classId,$prof);
    $quizCriterion=(int)cbQuery($db,"SELECT id FROM classbook_criteria WHERE subject_id=? AND group_key='mid_lec' AND kind='activity' AND exam_key IS NULL LIMIT 1",[$classId])->fetchColumn();
    $_POST=[
        'action'=>'create_activity',
        'subject_id'=>$classId,
        'revision'=>$s['revision'],
        'criterion_id'=>$quizCriterion,
        'title'=>'Quiz 1 - Logic Gates',
        'maximum'=>20,
        'due_on'=>'2026-08-15'
    ];
    cbProfessorAction($db,$prof,'create_activity');
    $activityId = $_SESSION["cb_created"][1] ?? (int)cbQuery($db,"SELECT MAX(id) FROM classbook_activities")->fetchColumn();
    $check($activityId>0,'Activity created');
    $check(isset($_SESSION['cb_created'])&&$_SESSION['cb_created']===['activity_id',$activityId],'Auto-nav to score sheet set in session');

    // 5. Prof encodes scores with smart score inputs (numeric, M, E, blank)
    $s=cbClass($db,$classId,$prof);
    $mList=cbMembers($db,$classId);
    $_POST=[
        'action'=>'save_scores',
        'subject_id'=>$classId,
        'revision'=>$s['revision'],
        'activity_id'=>$activityId,
        'scores'=>[
            $mList[0]['enrollment_id']=>['status'=>'scored','earned'=>'18'],
            $mList[1]['enrollment_id']=>['status'=>'scored','earned'=>'0'], // scored zero
            $mList[2]['enrollment_id']=>['status'=>'missing','earned'=>''], // missing
            $mList[3]['enrollment_id']=>['status'=>'excused','earned'=>''], // excused
            $mList[4]['enrollment_id']=>['status'=>'not_graded','earned'=>''], // not graded
        ]
    ];
    cbProfessorAction($db,$prof,'save_scores');
    $scores=cbQuery($db,'SELECT enrollment_id,status,earned FROM classbook_scores WHERE activity_id=?',[$activityId])->fetchAll(PDO::FETCH_ASSOC);
    $scoresByEnroll=array_column($scores,null,'enrollment_id');
    $check($scoresByEnroll[$mList[0]['enrollment_id']]['earned']==18.0,'Scored points recorded');
    $check($scoresByEnroll[$mList[1]['enrollment_id']]['earned']==0.0,'Zero score recorded distinctly');
    $check($scoresByEnroll[$mList[2]['enrollment_id']]['status']==='missing','Missing recorded distinctly');
    $check($scoresByEnroll[$mList[3]['enrollment_id']]['status']==='excused','Excused recorded distinctly');
    $check($scoresByEnroll[$mList[4]['enrollment_id']]['status']==='not_graded','Not graded recorded distinctly');

    // 6. Prof creates meeting & saves attendance
    $s=cbClass($db,$classId,$prof);
    $attCriterion=(int)cbQuery($db,"SELECT id FROM classbook_criteria WHERE subject_id=? AND group_key='mid_lec' AND kind='attendance' LIMIT 1",[$classId])->fetchColumn();
    $_POST=[
        'action'=>'create_meeting',
        'subject_id'=>$classId,
        'revision'=>$s['revision'],
        'criterion_id'=>$attCriterion,
        'meeting_date'=>'2026-08-10',
        'title'=>'Lecture 1'
    ];
    cbProfessorAction($db,$prof,'create_meeting');
    $meetingId = $_SESSION["cb_created"][1] ?? (int)cbQuery($db,"SELECT MAX(id) FROM classbook_meetings")->fetchColumn();
    $check($meetingId>0,'Meeting created');

    $s=cbClass($db,$classId,$prof);
    $_POST=[
        'action'=>'save_attendance',
        'subject_id'=>$classId,
        'revision'=>$s['revision'],
        'meeting_id'=>$meetingId,
        'attendance'=>[
            $mList[0]['enrollment_id']=>'present',
            $mList[1]['enrollment_id']=>'present',
            $mList[2]['enrollment_id']=>'late',
            $mList[3]['enrollment_id']=>'absent',
            $mList[4]['enrollment_id']=>'excused',
        ]
    ];
    cbProfessorAction($db,$prof,'save_attendance');
    $att=cbQuery($db,'SELECT status FROM classbook_attendance WHERE meeting_id=? AND enrollment_id=?',[$meetingId,$mList[0]['enrollment_id']])->fetchColumn();
    $check($att==='present','Present status saved');

    // 7. Single-input score encoding (value vs initial_value) and 1-click take_attendance_today
    $s=cbClass($db,$classId,$prof);
    $_POST=[
        'action'=>'save_scores',
        'subject_id'=>$classId,
        'revision'=>$s['revision'],
        'activity_id'=>$activityId,
        'scores'=>[
            $mList[0]['enrollment_id']=>['status'=>'scored','earned'=>'18','initial_value'=>'18','value'=>'20'],
            $mList[1]['enrollment_id']=>['status'=>'scored','earned'=>'0','initial_value'=>'0','value'=>'M'],
            $mList[2]['enrollment_id']=>['status'=>'missing','earned'=>'','initial_value'=>'M','value'=>'E'],
            $mList[3]['enrollment_id']=>['status'=>'excused','earned'=>'','initial_value'=>'E','value'=>'0'],
            $mList[4]['enrollment_id']=>['status'=>'not_graded','earned'=>'','initial_value'=>'','value'=>''],
        ]
    ];
    cbProfessorAction($db,$prof,'save_scores');
    $scores2=array_column(cbQuery($db,'SELECT enrollment_id,status,earned FROM classbook_scores WHERE activity_id=?',[$activityId])->fetchAll(PDO::FETCH_ASSOC),null,'enrollment_id');
    $check($scores2[$mList[0]['enrollment_id']]['earned']==20.0,'Single-input value overrides initial_value');
    $check($scores2[$mList[1]['enrollment_id']]['status']==='missing','Single-input M sets missing');
    $check($scores2[$mList[2]['enrollment_id']]['status']==='excused','Single-input E sets excused');
    $check($scores2[$mList[3]['enrollment_id']]['status']==='scored'&&$scores2[$mList[3]['enrollment_id']]['earned']==0.0,'Single-input 0 sets scored zero');

    $s=cbClass($db,$classId,$prof);
    $_POST=[
        'action'=>'take_attendance_today',
        'subject_id'=>$classId,
        'revision'=>$s['revision'],
        'criterion_id'=>$attCriterion,
    ];
    cbProfessorAction($db,$prof,'take_attendance_today');
    $todayMeetingId=(int)($_SESSION['cb_created'][1]??0);
    $check($todayMeetingId>0,'take_attendance_today created today meeting');
    $presentCount=(int)cbQuery($db,"SELECT COUNT(*) FROM classbook_attendance WHERE meeting_id=? AND status='present'",[$todayMeetingId])->fetchColumn();
    $check($presentCount===5,'take_attendance_today marked all 5 students present automatically');

    // 8. Copy setup to a second section
    $_POST=['subject_code'=>'CS101','subject_title'=>'Intro to Computing','section'=>'BSIT-1B','units'=>3,'school_year'=>'2026-2027','semester'=>'1st Semester'];
    $section2=cbCreateOffering($db,$admin,$prof['id'],$_POST);

    $s2=cbClass($db,$section2,$prof);
    $_POST=[
        'action'=>'copy_setup',
        'subject_id'=>$section2,
        'revision'=>$s2['revision'],
        'source_id'=>$classId
    ];
    cbProfessorAction($db,$prof,'copy_setup');
    $policy2=cbPolicy($db,$section2);
    $check($policy2['passing_percent']==$policy['passing_percent'],'Policy and criteria setup copied to Section 2');

    // 9. Prevent duplicate subject enrollment across sections in the same term
    $s2=cbClass($db,$section2,$prof);
    $dupBlocked=false;
    try{
        $_POST=['action'=>'enroll','subject_id'=>$section2,'revision'=>$s2['revision'],'student_no'=>'STUD-2026-00001','starts_on'=>'2026-10-01'];
        cbProfessorAction($db,$prof,'enroll');
    }catch(RuntimeException $e){$dupBlocked=str_contains($e->getMessage(),'already enrolled in CS101');}
    $check($dupBlocked,'Single enroll rejects student already active in another section of the same subject');

    $s2=cbClass($db,$section2,$prof);
    $_POST=['action'=>'enroll_many','subject_id'=>$section2,'revision'=>$s2['revision'],'student_numbers'=>"STUD-2026-00001\nSTUD-2026-00002",'starts_on'=>'2026-10-01'];
    cbProfessorAction($db,$prof,'enroll_many');
    $check(count(cbMembers($db,$section2))===0&&($_SESSION['cb_enrolled']??[])===[0,2],'Bulk enroll_many skips students already active in another section of the same subject');

    // Dropping from BSIT-1A allows shifting to BSIT-1B, and blocks restoring BSIT-1A while BSIT-1B is active
    $s=cbClass($db,$classId,$prof);
    $_POST=['action'=>'drop_enrollment','subject_id'=>$classId,'revision'=>$s['revision'],'enrollment_id'=>$mList[0]['enrollment_id'],'reason'=>'Shifted to BSIT-1B'];
    cbProfessorAction($db,$prof,'drop_enrollment');

    $s2=cbClass($db,$section2,$prof);
    $_POST=['action'=>'enroll','subject_id'=>$section2,'revision'=>$s2['revision'],'student_no'=>'STUD-2026-00001','starts_on'=>'2026-10-01'];
    cbProfessorAction($db,$prof,'enroll');
    $check(count(cbMembers($db,$section2))===1,'Dropped student can enroll in another section of the subject');

    $restoreBlocked=false;
    try{
        $s=cbClass($db,$classId,$prof);
        $_POST=['action'=>'restore_enrollment','subject_id'=>$classId,'revision'=>$s['revision'],'enrollment_id'=>$mList[0]['enrollment_id'],'reason'=>'Attempt duplicate restore'];
        cbProfessorAction($db,$prof,'restore_enrollment');
    }catch(RuntimeException $e){$restoreBlocked=str_contains($e->getMessage(),'already actively enrolled in CS101');}
    $check($restoreBlocked,'Restoring dropped enrollment is blocked while student is active in another section of the subject');

    echo "PASS: ".$checks." automated checks in simplified professor flow.\n";
}finally{
    if($created)$server->exec('DROP DATABASE IF EXISTS `'.$dbName.'`');
}
