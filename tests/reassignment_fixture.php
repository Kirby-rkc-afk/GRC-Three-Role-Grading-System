<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
session_save_path(sys_get_temp_dir());
// Reuse the guarded disposable-database fixture and its cleanup behavior.
if(($argv[1]??'')==='verify'){
    require_once __DIR__.'/../includes/portal.php';
    $name=(string)getenv('GRC_DB_NAME');if(!preg_match('/^grc_revision_http_[a-f0-9]{10}$/',$name))throw new RuntimeException('Test database required.');
    $db=getDbConnection();
    if((int)cbQuery($db,'SELECT professor_id FROM subjects WHERE id=1')->fetchColumn()!==2)throw new RuntimeException('Selected target was not used.');
    if((int)cbQuery($db,'SELECT professor_id FROM enrollments WHERE id=1')->fetchColumn()!==2)throw new RuntimeException('Enrollment owner not synchronized.');
    if((float)cbQuery($db,'SELECT earned FROM classbook_scores LIMIT 1')->fetchColumn()!==8.0)throw new RuntimeException('Evidence changed.');
    if((int)cbQuery($db,"SELECT COUNT(*) FROM classbook_audit WHERE action='reassign' AND subject_id=1")->fetchColumn()!==1)throw new RuntimeException('Expected exactly one reassignment.');
    echo "Reassignment persisted once; enrollment ownership, evidence and audit verified.\n";exit;
}
require __DIR__.'/http_fixture.php';
$hash=password_hash('Test-only-password-2026',PASSWORD_DEFAULT);$deps=array_keys(CB_DEPARTMENTS);
for($n=1;$n<=35;$n++)cbQuery($db,'INSERT INTO professors(employee_id,username,full_name,email,password_hash,department) VALUES(?,?,?,?,?,?)',['FAC-'.$n,'candidate.'.$n,'Candidate '.sprintf('%02d',$n),'candidate'.$n.'@example.invalid',$hash,$deps[$n%4]]);
foreach(['pending','inactive'] as $status){cbQuery($db,'INSERT INTO professors(employee_id,username,full_name,email,password_hash) VALUES(?,?,?,?,?)',[$status,'candidate.'.$status,'Unavailable '.$status,$status.'@example.invalid',$hash]);cbQuery($db,"INSERT INTO classbook_access(role,account_id,status) VALUES('professor',?,?)",[(int)$db->lastInsertId(),$status]);}
cbBackfillAccountIds($db);
cbQuery($db,'INSERT INTO subjects(professor_id,subject_code,subject_title,section,school_year,semester) VALUES(?,?,?,?,?,?)',[1,'IT101','Test Computing','A','2026-2027','1st Semester']);cbInitialize($db,1);
cbQuery($db,'INSERT INTO enrollments(professor_id,subject_id,student_id) VALUES(1,1,1)');cbQuery($db,'INSERT INTO classbook_members(enrollment_id,starts_on) VALUES(1,?)',[date('Y-m-d')]);
$criterion=(int)cbQuery($db,"SELECT id FROM classbook_criteria WHERE subject_id=1 AND kind='activity' LIMIT 1")->fetchColumn();cbQuery($db,'INSERT INTO classbook_activities(criterion_id,title,maximum) VALUES(?,?,10)',[$criterion,'Retained evidence']);cbQuery($db,"INSERT INTO classbook_scores(activity_id,enrollment_id,status,earned) VALUES(?,1,'scored',8)",[(int)$db->lastInsertId()]);
cbQuery($db,'INSERT INTO subjects(professor_id,subject_code,subject_title,section,school_year,semester) VALUES(?,?,?,?,?,?)',[3,'IT101','Test Computing','A','2026-2027','1st Semester']);cbInitialize($db,2);
cbQuery($db,'INSERT INTO subjects(professor_id,subject_code,subject_title,section,school_year,semester) VALUES(?,?,?,?,?,?)',[1,'IT102','Submitted class','B','2026-2027','1st Semester']);cbInitialize($db,3);cbQuery($db,"UPDATE classbook_settings SET state='submitted' WHERE subject_id=3");
