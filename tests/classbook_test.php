<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
session_save_path(sys_get_temp_dir());
require_once __DIR__.'/../includes/portal.php';
require_once __DIR__.'/../includes/classbook_actions.php';
require_once __DIR__.'/../includes/admin_actions.php';
require_once __DIR__.'/../includes/backup.php';
$checks=0;
function check(bool $ok,string $name): void {global $checks;if(!$ok)throw new RuntimeException('FAILED: '.$name);$checks++;}
function rejects(callable $fn,string $name): void {try{$fn();}catch(Throwable $e){check(true,$name);return;}check(false,$name);}
function action(PDO $db,array $u,int $id,string $a,array $data=[]): int {$_POST=$data+['subject_id'=>$id,'revision'=>cbQuery($db,'SELECT revision FROM classbook_settings WHERE subject_id=?',[$id])->fetchColumn()];return cbProfessorAction($db,$u,$a);}
$row=fn($earned,$maximum,$status='scored')=>compact('earned','maximum','status');
check(cbAggregate([$row(8,10),$row(20,50)],'pooled','exclude')===46.67,'pooled');
check(cbAggregate([$row(8,10),$row(20,50)],'equal','exclude')===60.0,'equal');
check(cbAggregate([$row(0,10)],'pooled','makeup')===0.0,'zero');
check(cbAggregate([$row(null,10,'missing')],'pooled','makeup')===null,'missing unresolved');
check(cbAggregate([$row(5,10),$row(null,10,'excused')],'pooled','exclude')===50.0,'exclude excused');
check(cbAggregate([$row(5,10),$row(null,10,'excused')],'pooled','makeup')===null,'makeup pending');
rejects(fn()=>cbNumber(INF),'infinite');rejects(fn()=>cbAggregate([$row(11,10)],'pooled','exclude'),'above max');
$csv=cbReadReport("student_no,duration_minutes\nS-TEST,12.5\n",'report.csv');check($csv['rows'][0]['seconds']===750.0,'CSV minutes');
rejects(fn()=>cbReadReport("name,duration,name\nA,1,B",'x.csv'),'ambiguous CSV');
$pdf='C:/Users/Administrator/OneDrive/Documents/Attendance Report - zbu-quii-msd (10-Oct-2026).pdf';
if(is_file($pdf)){$report=cbReadReport(file_get_contents($pdf),'report.pdf');check(count($report['rows'])===16,'PDF complete rows');check($report['duration_seconds']===891.0,'PDF duration');check($report['date']==='2026-10-10','PDF date');check($report['rows'][5]['seconds']===454.0,'PDF row duration');}
$name='grc_revision_test_'.bin2hex(random_bytes(5));$server=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try{
$server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$db=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$name.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);cbInstall($db);
check((int)$db->query('SELECT COUNT(*) FROM professors')->fetchColumn()===0,'no demo data');cbInstall($db);check(cbInstalled($db),'idempotent schema');
cbQuery($db,'INSERT INTO professors(employee_id,username,full_name,email,password_hash) VALUES(?,?,?,?,?)',['TEST','test','Test Faculty','test@example.invalid',password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);$u=['id'=>(int)$db->lastInsertId(),'role'=>'professor'];
$_POST=['subject_code'=>'TEST101','subject_title'=>'Test class','section'=>'A','units'=>3,'school_year'=>'2026-2027','semester'=>'1st Semester'];$id=cbProfessorAction($db,$u,'create_class');
// This suite retains legacy component fixtures; period_grading_test covers new two-period classes.
cbQuery($db,'DELETE FROM classbook_criteria WHERE subject_id=?',[$id]);cbQuery($db,"UPDATE classbook_settings SET grading_model='legacy_components' WHERE subject_id=?",[$id]);cbInitialize($db,$id);cbQuery($db,'UPDATE classbook_policies SET lecture_percent=65 WHERE subject_id=?',[$id]);cbValidateWeights($db,$id);check(count(cbCompute($db,$id)['criteria'])===22,'default criteria');
rejects(fn()=>cbClass($db,$id,['id'=>999,'role'=>'professor']),'class ownership');
action($db,$u,$id,'enroll',['student_no'=>'S-TEST','first_name'=>'Test','last_name'=>'Student','starts_on'=>'2026-01-01']);$m=cbMembers($db,$id)[0];$e=$m['enrollment_id'];
rejects(fn()=>action($db,$u,$id,'submit'),'incomplete blocked');
$criteria=cbCompute($db,$id)['criteria'];foreach($criteria as $c){if($c['kind']==='attendance'){action($db,$u,$id,'create_meeting',['criterion_id'=>$c['id'],'meeting_date'=>'2026-10-10','title'=>'Test meeting']);$meeting=(int)$db->lastInsertId();$meeting=(int)cbQuery($db,'SELECT MAX(id) FROM classbook_meetings')->fetchColumn();action($db,$u,$id,'save_attendance',['meeting_id'=>$meeting,'attendance'=>[$e=>'late']]);}else{if(!empty($c['exam_key'])&&count(array_filter(cbActivities($db,$id),fn($a)=>$a['exam_key']===$c['exam_key'])))continue;action($db,$u,$id,'create_activity',['criterion_id'=>$c['id'],'title'=>'Test work','maximum'=>10]);$a=(int)cbQuery($db,'SELECT MAX(id) FROM classbook_activities')->fetchColumn();action($db,$u,$id,'save_scores',['activity_id'=>$a,'scores'=>[$e=>['status'=>'scored','earned'=>10]]]);}}
$data=cbCompute($db,$id);check($data['students'][$e]['course']===94.0,'strict attendance weighted course');
$examActivities=array_values(array_filter(cbActivities($db,$id),fn($a)=>$a['exam_key']!==null));
check(count($examActivities)===3,'one activity each for Prelim Midterm and Final');
$labPrelim=(int)cbQuery($db,"SELECT id FROM classbook_criteria WHERE subject_id=? AND group_key='mid_lab' AND exam_key='prelim'",[$id])->fetchColumn();
rejects(fn()=>action($db,$u,$id,'create_activity',['criterion_id'=>$labPrelim,'title'=>'Duplicate exam','maximum'=>100]),'second exam rejected through laboratory criterion');
$examEvidence=array_values(array_filter($data['students'][$e]['evidence'],fn($ev)=>$ev['exam_key']==='prelim'));
check(count($examEvidence)===2&&$examEvidence[0]['rows']===$examEvidence[1]['rows'],'lecture and laboratory reuse identical exam evidence');
check(count(array_filter(cbReviewEvidence($data['students'][$e]),fn($ev)=>$ev['exam_key']!==null))===3,'review shows exactly three shared exams');
$canonical=(int)array_values(array_filter($examActivities,fn($a)=>$a['exam_key']==='prelim'))[0]['id'];
action($db,$u,$id,'save_scores',['activity_id'=>$canonical,'scores'=>[$e=>['status'=>'scored','earned'=>8]]]);
$examEvidence=array_values(array_filter(cbCompute($db,$id)['students'][$e]['evidence'],fn($ev)=>$ev['exam_key']==='prelim'));
check($examEvidence[0]['percentage']===80.0&&$examEvidence[1]['percentage']===80.0,'single score edit updates both components');
action($db,$u,$id,'save_scores',['activity_id'=>$canonical,'scores'=>[$e=>['status'=>'scored','earned'=>10]]]);
cbQuery($db,'INSERT INTO classbook_activities(criterion_id,title,maximum) VALUES(?,?,10)',[$labPrelim,'Retained old duplicate']);$duplicate=(int)$db->lastInsertId();
cbQuery($db,"INSERT INTO classbook_scores(activity_id,enrollment_id,status,earned) VALUES(?,?,'scored',0)",[$duplicate,$e]);
check(cbCompute($db,$id)['students'][$e]['course']===94.0,'retained duplicate has no grade contribution');
rejects(fn()=>action($db,$u,$id,'save_scores',['activity_id'=>$duplicate,'scores'=>[$e=>['status'=>'scored','earned'=>1]]]),'retained duplicate cannot be edited as a current exam');
rejects(fn()=>action($db,$u,$id,'add_criterion',['group_key'=>'fnl_lab','kind'=>'prelim_exam','name'=>'Wrong period exam']),'exam criteria must match period');
action($db,$u,$id,'save_rules',['late_points'=>5,'absent_points'=>0,'excused_mode'=>'makeup','online_full_minutes'=>10,'online_partial_minutes'=>5]);check(cbCompute($db,$id)['students'][$e]['course']===100.0,'lenient late');
$revision=(int)cbQuery($db,'SELECT revision FROM classbook_settings WHERE subject_id=?',[$id])->fetchColumn();rejects(fn()=>cbWrite($db,$id,$u,$revision-1,fn()=>null),'stale form');
$before=cbQuery($db,'SELECT earned FROM classbook_scores WHERE activity_id=? AND enrollment_id=?',[$a,$e])->fetchColumn();rejects(fn()=>action($db,$u,$id,'save_scores',['activity_id'=>$a,'scores'=>[$e=>['status'=>'scored','earned'=>11]]]),'invalid score rollback');check(cbQuery($db,'SELECT earned FROM classbook_scores WHERE activity_id=? AND enrollment_id=?',[$a,$e])->fetchColumn()===$before,'score unchanged');
// Confirm import persists evidence and rejects duplicate reports atomically.
$criterion=(int)cbQuery($db,"SELECT id FROM classbook_criteria WHERE subject_id=? AND kind='attendance' LIMIT 1",[$id])->fetchColumn();
$draft=['subject'=>$id,'actor'=>$u['id'],'revision'=>cbQuery($db,'SELECT revision FROM classbook_settings WHERE subject_id=?',[$id])->fetchColumn(),'criterion'=>$criterion,'date'=>'2026-10-11','filename'=>'fixture.csv','fingerprint'=>hash('sha256','test report'),'report'=>$csv,'expires'=>time()+1200];$_SESSION['classbook_import']=$draft;action($db,$u,$id,'commit_import',['matches'=>[0=>$e],'statuses'=>[0=>'present']]);check((int)$db->query('SELECT COUNT(*) FROM classbook_imports')->fetchColumn()===1,'import persisted');$draft['revision']=cbQuery($db,'SELECT revision FROM classbook_settings WHERE subject_id=?',[$id])->fetchColumn();$_SESSION['classbook_import']=$draft;rejects(fn()=>action($db,$u,$id,'commit_import',['matches'=>[0=>$e],'statuses'=>[0=>'present']]),'duplicate blocked');
action($db,$u,$id,'submit');rejects(fn()=>action($db,$u,$id,'save_rules',['late_points'=>3]),'submitted locked');$revision=(int)cbQuery($db,'SELECT revision FROM classbook_settings WHERE subject_id=?',[$id])->fetchColumn();rejects(fn()=>cbRelease($db,$id,$u,$revision),'faculty cannot release');$admin=['id'=>1,'role'=>'admin'];cbRelease($db,$id,$admin,$revision);$released=cbLatestRelease($db,$id);check($released['snapshot']['students'][$e]['course']===100,'released snapshot');
$revision=(int)cbQuery($db,'SELECT revision FROM classbook_settings WHERE subject_id=?',[$id])->fetchColumn();cbWrite($db,$id,$admin,$revision,function()use($db,$id){cbQuery($db,"UPDATE classbook_settings SET state='draft' WHERE subject_id=?",[$id]);},true);action($db,$u,$id,'save_scores',['activity_id'=>$a,'scores'=>[$e=>['status'=>'scored','earned'=>0]]]);check(cbCompute($db,$id)['students'][$e]['course']<100,'correction changes draft');check(cbLatestRelease($db,$id)['snapshot']['students'][$e]['course']===100,'old publication immutable');
check(cbBlend(80,null,100)===80.0,'lecture only without lab');
check(cbBlend(null,90,0)===90.0,'final only without midterm');
rejects(fn()=>cbConversion("60 = Pass"),'conversion requires zero band');
rejects(fn()=>cbConversion("0 = F\n0 = Fail"),'duplicate conversion band');
check(cbConverted(85,['conversion_json'=>json_encode(cbConversion("0 = F\n60 = D\n80 = B\n90 = A"))])==='B','conversion threshold');
cbQuery($db,'INSERT INTO classbook_admins(id,username,full_name,password_hash) VALUES(1,?,?,?)',['registrar','Test Registrar',password_hash(bin2hex(random_bytes(16)),PASSWORD_DEFAULT)]);
rejects(fn()=>cbAdminAccount($db,$u,'create_account',['role'=>'admin']),'faculty cannot manage accounts');
rejects(fn()=>cbAdminAccount($db,$admin,'create_account',['role'=>'professor','username'=>'invalid.department','full_name'=>'Invalid Department Test','email'=>'invalid-dept@example.invalid','department'=>'Unlisted department']),'unlisted department is rejected on the server');
cbAdminAccount($db,$admin,'create_account',['role'=>'professor','employee_id'=>'TEST2','username'=>'test2','full_name'=>'Second Faculty','email'=>'second@example.invalid','department'=>'College of Computer Studies']);
$next=(int)cbQuery($db,'SELECT id FROM professors WHERE username=?',['test2'])->fetchColumn();check(cbStatus($db,'professor',$next)==='pending','new account pending');
$accountData=['role'=>'professor','account_id'=>$next,'account_revision'=>1];
$code=cbAdminAccount($db,$admin,'issue_code',$accountData);check(strlen($code)===32,'one time code issued');
check(cbQuery($db,'SELECT token_hash FROM classbook_tokens WHERE account_id=?',[$next])->fetchColumn()===hash('sha256',$code),'only token hash stored');
rejects(fn()=>cbAdminAccount($db,$admin,'approve',$accountData+['reason'=>'Verified']),'account stale form blocked');
$accountData['account_revision']=cbAccountRevision($db,'professor',$next);cbAdminAccount($db,$admin,'approve',$accountData+['reason'=>'Verified faculty']);check(cbStatus($db,'professor',$next)==='active','faculty approved');
rejects(fn()=>cbAdminAccount($db,$admin,'deactivate',['role'=>'admin','account_id'=>1,'account_revision'=>1,'reason'=>'Test']),'cannot deactivate self');
$rev=fn()=>(int)cbQuery($db,'SELECT revision FROM classbook_settings WHERE subject_id=?',[$id])->fetchColumn();
cbAdminClass($db,$admin,'reassign',['subject_id'=>$id,'revision'=>$rev(),'professor_id'=>$next,'reason'=>'Teaching assignment changed']);rejects(fn()=>cbClass($db,$id,$u),'previous owner loses class');
check((int)cbQuery($db,'SELECT professor_id FROM enrollments WHERE id=?',[$e])->fetchColumn()===$next,'enrollment owner updated');
cbAdminClass($db,$admin,'reassign',['subject_id'=>$id,'revision'=>$rev(),'professor_id'=>$u['id'],'reason'=>'Return test assignment']);
action($db,$u,$id,'drop_enrollment',['enrollment_id'=>$e,'reason'=>'Test drop']);check(count(cbMembers($db,$id))===0,'drop retained outside active roster');
check(cbStudentRelease($db,$id,(int)$e)!==null,'dropped student retains published history');
action($db,$u,$id,'restore_enrollment',['enrollment_id'=>$e,'reason'=>'Approved restoration']);check(count(cbMembers($db,$id))===1,'restore retained enrollment');
$scoreActivity=(int)cbQuery($db,"SELECT a.id FROM classbook_activities a JOIN classbook_criteria c ON c.id=a.criterion_id WHERE c.subject_id=? AND c.group_key='fnl_lec' AND c.name='Recitation' LIMIT 1",[$id])->fetchColumn();
rejects(fn()=>action($db,$u,$id,'edit_activity',['activity_id'=>$scoreActivity,'title'=>'Corrected','maximum'=>5,'reason'=>'Typo']),'maximum cannot invalidate existing scores');
action($db,$u,$id,'edit_activity',['activity_id'=>$scoreActivity,'title'=>'Corrected title','maximum'=>10,'reason'=>'Typo']);check(cbQuery($db,'SELECT title FROM classbook_activities WHERE id=?',[$scoreActivity])->fetchColumn()==='Corrected title','activity corrected');
rejects(fn()=>action($db,$u,$id,'create_meeting',['criterion_id'=>$criterion,'meeting_date'=>'2026-10-10','title'=>'Test meeting']),'manual duplicate meeting blocked');
action($db,$u,$id,'save_policy',['lecture_percent'=>100,'midterm_percent'=>0,'passing_percent'=>90,'policy_label'=>'Test policy','conversion'=>"0 = F\n60 = D\n80 = B\n90 = A"]);
cbQuery($db,"UPDATE classbook_scores sc JOIN classbook_activities a ON a.id=sc.activity_id JOIN classbook_criteria c ON c.id=a.criterion_id SET sc.status='not_graded',sc.earned=NULL WHERE c.subject_id=? AND (c.group_key LIKE '%lab' OR c.group_key LIKE 'mid%')",[$id]);
check(cbCompute($db,$id)['students'][$e]['course']===100.0,'unused groups do not block completion');
action($db,$u,$id,'save_scores',['activity_id'=>$scoreActivity,'scores'=>[$e=>['status'=>'scored','earned'=>0]]]);$result=cbCompute($db,$id)['students'][$e];check($result['course']===85.0&&$result['converted']==='B'&&$result['remarks']==='Failed','custom passing threshold and conversion independent');
cbAdminClass($db,$admin,'archive_class',['subject_id'=>$id,'revision'=>$rev(),'reason'=>'Term finished']);rejects(fn()=>action($db,$u,$id,'save_policy',[]),'archive locks writes');
cbAdminClass($db,$admin,'unarchive_class',['subject_id'=>$id,'revision'=>$rev(),'reason'=>'Approved review']);check((int)cbClass($db,$id,$u)['archived']===0,'unarchive');
$backup=cbBackup($db);check(!isset($backup['tables']['classbook_tokens']),'backup excludes temporary codes');
$restoreName=$name.'_restore';
try{
 $server->exec('CREATE DATABASE '.chr(96).$restoreName.chr(96).' CHARACTER SET utf8mb4');
 $restored=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$restoreName.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);cbInstall($restored);cbRestore($restored,$backup);
 check(cbCompute($restored,$id)['students'][$e]['course']===85.0,'restored records recompute identically');
 check(cbLatestRelease($restored,$id)['snapshot']['students'][$e]['course']===100,'restored release snapshot intact');
 rejects(fn()=>cbRestore($restored,$backup),'restore refuses populated database');
}finally{$server->exec('DROP DATABASE IF EXISTS '.chr(96).$restoreName.chr(96));}
rejects(fn()=>action($db,$u,$id,'save_scores',['activity_id'=>$scoreActivity,'scores'=>[]]),'incomplete bulk form rejected');
cbQuery($db,'INSERT INTO subjects(professor_id,subject_code,subject_title,section,units,school_year,semester) VALUES(?,?,?,?,?,?,?)',[$u['id'],'OLD101','Legacy class','B',3,'2025-2026','1st Semester']);$legacyId=(int)$db->lastInsertId();
cbQuery($db,'INSERT INTO enrollments(professor_id,subject_id,student_id) VALUES(?,?,?)',[$u['id'],$legacyId,$m['student_id']]);$legacyEnrollment=(int)$db->lastInsertId();
cbQuery($db,'INSERT INTO grades(enrollment_id,mid_lec_attendance) VALUES(?,88)',[$legacyEnrollment]);
cbAdminClass($db,$admin,'initialize_legacy',['subject_id'=>$legacyId,'reason'=>'Verified preserved record']);
check((float)cbQuery($db,'SELECT mid_lec_attendance FROM grades WHERE enrollment_id=?',[$legacyEnrollment])->fetchColumn()===88.0,'legacy aggregate preserved on initialization');
check(cbCompute($db,$legacyId)['students'][$legacyEnrollment]['course']===null,'legacy aggregate is not fabricated activity evidence');
$sharedHash=password_hash('Shared-test-password-2026',PASSWORD_DEFAULT);
cbQuery($db,'UPDATE professors SET password_hash=? WHERE id=?',[$sharedHash,$u['id']]);
cbQuery($db,'UPDATE students SET password_hash=? WHERE id=?',[$sharedHash,$m['student_id']]);
cbQuery($db,'UPDATE classbook_admins SET password_hash=? WHERE id=1',[$sharedHash]);
check(cbResolveLogin($db,'registrar','Shared-test-password-2026')['role']==='admin','shared login resolves Registrar');
check(cbResolveLogin($db,'test@example.invalid','Shared-test-password-2026')['role']==='professor','shared login resolves faculty email');
check(cbResolveLogin($db,'TEST','Shared-test-password-2026')['role']==='professor','shared login resolves employee ID');
check(cbResolveLogin($db,'S-TEST','Shared-test-password-2026')['role']==='student','shared login resolves student ID');
check(cbResolveLogin($db,'S-TEST','incorrect password')===null,'shared login rejects wrong password');
rejects(fn()=>cbUniqueIdentifiers($db,'student',['username'=>'registrar']),'cross-role identifier collision rejected');
cbQuery($db,'UPDATE students SET username=? WHERE id=?',['test',$m['student_id']]);
rejects(fn()=>cbResolveLogin($db,'test','Shared-test-password-2026'),'ambiguous legacy credentials fail closed');
for($i=0;$i<10;$i++)cbLoginAttempt($db,'student','attempt-test','unit-test-ip');
rejects(fn()=>cbLoginAttempt($db,'student','attempt-test','unit-test-ip'),'server-side throttle enforced');
echo "PASS: $checks checks, calculations, edits, ownership, imports, release history, administration, throttling and backup/restore.\n";
}finally{if(preg_match('/^grc_revision_test_[a-f0-9]{10}$/',$name))$server->exec('DROP DATABASE IF EXISTS `'.$name.'`');}
