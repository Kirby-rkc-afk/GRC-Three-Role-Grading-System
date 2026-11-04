<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
session_save_path(sys_get_temp_dir());
require_once __DIR__.'/../includes/portal.php';require_once __DIR__.'/../includes/classbook_actions.php';require_once __DIR__.'/../includes/backup.php';
$name='grc_revision_periods_'.bin2hex(random_bytes(5));$checks=0;
function assertPeriod(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
function periodAction(PDO $db,array $u,int $id,string $action,array $fields=[]):void{$_POST=$fields+['subject_id'=>$id,'revision'=>cbQuery($db,'SELECT revision FROM classbook_settings WHERE subject_id=?',[$id])->fetchColumn()];cbProfessorAction($db,$u,$action);}
$server=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try{
    $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$db=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$name.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);cbInstall($db);
    cbQuery($db,'INSERT INTO professors(employee_id,username,full_name,email,password_hash) VALUES(?,?,?,?,?)',['P1','period.faculty','Test Faculty','period@example.invalid',password_hash('Test-only-password-2026',PASSWORD_DEFAULT)]);$u=['role'=>'professor','id'=>1];
    $_POST=['subject_code'=>'ITFUNDL','subject_title'=>'IT Fundamentals Laboratory','section'=>'A','units'=>1,'school_year'=>'2026-2027','semester'=>'1st Semester'];$id=cbProfessorAction($db,$u,'create_class');
    assertPeriod(cbClassGroups($db,$id)===CB_PERIOD_GROUPS,'New classes have two periods');assertPeriod(count(cbClassCriteria($db,$id))===11,'Eleven unique period criteria');cbValidateWeights($db,$id);
    periodAction($db,$u,$id,'enroll',['student_no'=>'P-STUDENT','first_name'=>'Period','last_name'=>'Student','starts_on'=>'2026-01-01']);$enrollment=(int)cbMembers($db,$id)[0]['enrollment_id'];
    foreach(cbClassCriteria($db,$id) as $c){
        if($c['kind']==='attendance'){
            periodAction($db,$u,$id,'create_meeting',['criterion_id'=>$c['id'],'meeting_date'=>'2026-11-01','title'=>'Class meeting']);$meeting=(int)cbQuery($db,'SELECT MAX(id) FROM classbook_meetings')->fetchColumn();periodAction($db,$u,$id,'save_attendance',['meeting_id'=>$meeting,'attendance'=>[$enrollment=>'present']]);
        }else{
            periodAction($db,$u,$id,'create_activity',['criterion_id'=>$c['id'],'title'=>$c['name'],'maximum'=>100]);$activity=(int)cbQuery($db,'SELECT MAX(id) FROM classbook_activities')->fetchColumn();periodAction($db,$u,$id,'save_scores',['activity_id'=>$activity,'scores'=>[$enrollment=>['status'=>'scored','earned'=>$c['group_key']==='mid_lec'?80:90]]]);
        }
    }
    $result=cbCompute($db,$id)['students'][$enrollment];assertPeriod($result['midterm']===83.0,'Midterm directly weights its criteria');assertPeriod($result['final']===91.5,'Final directly weights its criteria');assertPeriod($result['course']===87.25,'Course combines only two periods');assertPeriod(count($result['evidence'])===11,'No hidden laboratory criteria in evidence');
    periodAction($db,$u,$id,'save_policy',['midterm_percent'=>40,'passing_percent'=>75,'policy_label'=>'Two-period test','conversion'=>'','lecture_percent'=>0]);assertPeriod(cbCompute($db,$id)['students'][$enrollment]['course']===88.1,'Configured period shares honored; component spoof ignored');
    $rejected=false;try{periodAction($db,$u,$id,'add_criterion',['group_key'=>'mid_lab','kind'=>'activity','name'=>'Duplicate component']);}catch(RuntimeException $e){$rejected=true;}assertPeriod($rejected,'Removed component cannot be posted');
    // Retained component records have no influence after explicit conversion/model selection.
    cbQuery($db,"INSERT INTO classbook_criteria(subject_id,group_key,name,kind,weight) VALUES(?,'mid_lab','Retained evidence','activity',100)",[$id]);$old=(int)$db->lastInsertId();cbQuery($db,'INSERT INTO classbook_activities(criterion_id,title,maximum) VALUES(?,?,100)',[$old,'Old component']);$oldActivity=(int)$db->lastInsertId();cbQuery($db,"INSERT INTO classbook_scores(activity_id,enrollment_id,status,earned) VALUES(?,?,'scored',0)",[$oldActivity,$enrollment]);
    assertPeriod(cbCompute($db,$id)['students'][$enrollment]['course']===88.1,'Retained component score excluded');$rejected=false;try{periodAction($db,$u,$id,'save_scores',['activity_id'=>$oldActivity,'scores'=>[$enrollment=>['status'=>'scored','earned'=>100]]]);}catch(RuntimeException $e){$rejected=true;}assertPeriod($rejected,'Retained component cannot be edited as current work');
    $criteria=[];foreach(cbClassCriteria($db,$id) as $c)$criteria[$c['id']]=['name'=>$c['name'],'weight'=>$c['weight'],'aggregation'=>$c['aggregation'],'excused_mode'=>$c['excused_mode']];periodAction($db,$u,$id,'save_criteria',['criteria'=>$criteria]);assertPeriod(count(cbClassCriteria($db,$id))===11,'Criteria save uses active periods only');
    // A professor may stop using Recitation without deleting its existing records.
    $criteria=[];$recitation=null;$quiz=null;
    foreach(cbClassCriteria($db,$id) as $c){$criteria[$c['id']]=['name'=>$c['name'],'weight'=>$c['weight'],'aggregation'=>$c['aggregation'],'excused_mode'=>$c['excused_mode']];if($c['group_key']==='mid_lec'&&$c['name']==='Recitation')$recitation=$c;if($c['group_key']==='mid_lec'&&$c['name']==='Quizzes')$quiz=$c;}
    $original=$criteria;$criteria[$recitation['id']]['weight']=0;
    $rejected=false;try{periodAction($db,$u,$id,'save_criteria',['criteria'=>$criteria]);}catch(InvalidArgumentException $e){$rejected=true;}
    assertPeriod($rejected,'Removing a weight without redistributing to 100% is rejected');
    assertPeriod((float)cbQuery($db,'SELECT weight FROM classbook_criteria WHERE id=?',[$recitation['id']])->fetchColumn()===(float)$recitation['weight'],'Invalid weight change rolls back');
    $retained=cbQuery($db,'SELECT sc.* FROM classbook_scores sc JOIN classbook_activities a ON a.id=sc.activity_id WHERE a.criterion_id=?',[$recitation['id']])->fetchAll();
    $criteria[$quiz['id']]['weight']=(float)$quiz['weight']+(float)$recitation['weight'];periodAction($db,$u,$id,'save_criteria',['criteria'=>$criteria]);
    assertPeriod(cbCompute($db,$id)['students'][$enrollment]['course']===88.1,'Redistributed zero-weight Recitation has no grade contribution');
    assertPeriod($retained===cbQuery($db,'SELECT sc.* FROM classbook_scores sc JOIN classbook_activities a ON a.id=sc.activity_id WHERE a.criterion_id=?',[$recitation['id']])->fetchAll(),'Existing Recitation score rows are retained unchanged');
    periodAction($db,$u,$id,'create_activity',['criterion_id'=>$recitation['id'],'title'=>'Unused recitation work','maximum'=>100]);$unused=(int)$db->lastInsertId();
    assertPeriod(cbCompute($db,$id)['students'][$enrollment]['course']===88.1,'Unrecorded work in a zero-weight criterion does not block the grade');
    $unused=(int)cbQuery($db,'SELECT id FROM classbook_activities WHERE criterion_id=? AND title=?',[$recitation['id'],'Unused recitation work'])->fetchColumn();
    periodAction($db,$u,$id,'save_scores',['activity_id'=>$unused,'scores'=>[$enrollment=>['status'=>'scored','earned'=>80]]]);periodAction($db,$u,$id,'save_criteria',['criteria'=>$original]);
    assertPeriod(cbCompute($db,$id)['students'][$enrollment]['course']===88.1,'Professor can restore the original Recitation weight');
    periodAction($db,$u,$id,'submit');assertPeriod(cbClass($db,$id,['role'=>'admin','id'=>0])['state']==='submitted','Complete two-period sheet submits');
    $backup=cbBackup($db);assertPeriod($backup['tables']['classbook_settings'][0]['grading_model']==='periods','Backup retains period model');
    require_once __DIR__.'/../includes/student_review.php';require_once __DIR__.'/../includes/admin_presentation.php';
    $finalExam=(int)cbQuery($db,"SELECT id FROM classbook_criteria WHERE subject_id=? AND group_key='fnl_lec' AND exam_key='final'",[$id])->fetchColumn();$_GET=['criterion'=>$finalExam];
    ob_start();cbStudentReviewPage($db,['role'=>'admin','id'=>1,'full_name'=>'Test Administrator'],cbClass($db,$id,['role'=>'admin','id'=>1])+['professor_name'=>'Test Faculty'],$enrollment,'class.php?subject_id='.$id,'');$html=ob_get_clean();
    assertPeriod(!str_contains($html,'<details')&&!str_contains($html,'<select'),'Review has no dropdown or accordion panels');
    assertPeriod(!str_contains($html,'Midterm Lecture')&&!str_contains($html,'Final Laboratory'),'Review has no duplicate component groups');
    assertPeriod(substr_count($html,'class="review-period"')===2,'Two period panels rendered');
    assertPeriod(substr_count($html,'class="review-criterion ')===11,'Unique criteria appear once per period');
    assertPeriod(str_contains($html,'Final · Final Exam')&&!str_contains($html,'Old component'),'Selected evidence is scoped to the period criterion');
    assertPeriod(substr_count($html,'id="criterion-evidence"')===1,'One evidence panel');
    echo $checks." period grading checks passed.\n";
}finally{$server->exec('DROP DATABASE IF EXISTS `'.$name.'`');}
