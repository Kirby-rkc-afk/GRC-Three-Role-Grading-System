<?php
declare(strict_types=1);
require_once __DIR__.'/attendance_report.php';

/** Students cannot be enrolled in more than one active section of the same subject in the same academic term. */
function cbDuplicateStudentSubject(PDO $db,int $studentId,array $subject,int $excludeSubjectId=0): ?array {
    $exclude=$excludeSubjectId?:(int)($subject['id']??0);
    $row=cbQuery($db,"SELECT s.id,s.subject_code,s.section,s.school_year,s.semester FROM enrollments e JOIN subjects s ON s.id=e.subject_id LEFT JOIN classbook_members m ON m.enrollment_id=e.id LEFT JOIN classbook_settings c ON c.subject_id=s.id WHERE e.student_id=? AND s.id<>? AND s.subject_code=? AND s.school_year=? AND s.semester=? AND COALESCE(m.status,'active')='active' AND COALESCE(c.archived,0)=0 LIMIT 1",[$studentId,$exclude,$subject['subject_code'],$subject['school_year'],$subject['semester']])->fetch();
    return $row?:null;
}

/** Create a class offering for a professor. Only administrators assign offerings. */
function cbCreateOffering(PDO $db,array $actor,int $professorId,array $p): int {
    if($actor['role']!=='admin')throw new RuntimeException('Class offerings are assigned by the administrator.');
    $code=cbText($p['subject_code']??'',50);$title=cbText($p['subject_title']??'');$section=cbText($p['section']??'',50);$units=cbNumber($p['units']??'',12);if($units<1||floor($units)!=$units)throw new InvalidArgumentException('Units must be a whole number from 1 to 12.');$year=cbText($p['school_year']??'',20);$semester=(string)($p['semester']??'');if(!in_array($semester,['1st Semester','2nd Semester','Summer'],true))throw new InvalidArgumentException('Invalid semester.');
    $db->beginTransaction();try{
        // Serialize offering changes with the retained transaction mutex.
        cbLockIdentifiers($db);
        if(!cbQuery($db,'SELECT id FROM professors WHERE id=? FOR UPDATE',[$professorId])->fetchColumn()||cbStatus($db,'professor',$professorId)==='inactive')throw new RuntimeException('Choose an active professor.');
        if(cbQuery($db,'SELECT id FROM subjects WHERE professor_id=? AND subject_code=? AND section=? AND school_year=? AND semester=? FOR UPDATE',[$professorId,$code,$section,$year,$semester])->fetchColumn())throw new RuntimeException('This class offering already exists.');
        cbQuery($db,'INSERT INTO subjects(professor_id,subject_code,subject_title,section,units,school_year,semester) VALUES(?,?,?,?,?,?,?)',[$professorId,$code,$title,$section,$units,$year,$semester]);$id=(int)$db->lastInsertId();cbInitialize($db,$id);cbAudit($db,$actor,$id,'class_created',['professor_id'=>$professorId]);$db->commit();return $id;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
/** One score cell: a number is Scored (including 0), M = Missing, E = Excused, blank = Not graded. */
function cbParseScoreCell(mixed $value,float $maximum): array {
    $v=strtolower(trim(is_scalar($value)?(string)$value:''));
    if($v==='')return ['not_graded',null];
    if(in_array($v,['m','missing'],true))return ['missing',null];
    if(in_array($v,['e','ex','excused'],true))return ['excused',null];
    return ['scored',cbNumber($v,$maximum)];
}
function cbProfessorAction(PDO $db,array $u,string $action): int {
    if($u['role']!=='professor')throw new RuntimeException('Faculty access required.');
    unset($_SESSION['cb_created']);
    if($action==='create_class'){
        $code=cbText($_POST['subject_code']??'',50);$title=cbText($_POST['subject_title']??'');$section=cbText($_POST['section']??'',50);$units=cbNumber($_POST['units']??'',12);if($units<1||floor($units)!=$units)throw new InvalidArgumentException('Units must be a whole number from 1 to 12.');$year=cbText($_POST['school_year']??'',20);$semester=(string)($_POST['semester']??'');if(!in_array($semester,['1st Semester','2nd Semester','Summer'],true))throw new InvalidArgumentException('Invalid semester.');
        $db->beginTransaction();try{
            cbLockIdentifiers($db);
            if(cbQuery($db,'SELECT id FROM subjects WHERE professor_id=? AND subject_code=? AND section=? AND school_year=? AND semester=? FOR UPDATE',[$u['id'],$code,$section,$year,$semester])->fetchColumn())throw new RuntimeException('This class offering already exists.');
            cbQuery($db,'INSERT INTO subjects(professor_id,subject_code,subject_title,section,units,school_year,semester) VALUES(?,?,?,?,?,?,?)',[$u['id'],$code,$title,$section,$units,$year,$semester]);$id=(int)$db->lastInsertId();cbInitialize($db,$id);cbAudit($db,$u,$id,'class_created',[]);$db->commit();return $id;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    $id=(int)($_POST['subject_id']??0);
    if($action==='initialize_class'){
        $db->beginTransaction();try{$owner=cbQuery($db,'SELECT professor_id FROM subjects WHERE id=? FOR UPDATE',[$id])->fetchColumn();if((int)$owner!==(int)$u['id'])throw new RuntimeException('Class not available.');cbInitialize($db,$id);cbAudit($db,$u,$id,'class_record_initialized',['legacy_totals_preserved'=>true]);$db->commit();return $id;}catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    $s=cbClass($db,$id,$u);$validCriteria=array_map('intval',array_column(cbClassCriteria($db,$id),'id'));if(in_array($action,['preview_import','create_activity','create_meeting','take_attendance_today'],true)&&!in_array((int)($_POST['criterion_id']??0),$validCriteria,true))throw new RuntimeException('Choose an active period criterion.');$revision=(int)($_POST['revision']??0);
    if($action==='preview_import'){
        if($s['state']!=='draft'||$s['archived'])throw new RuntimeException('This class is locked.');
        $criterion=cbQuery($db,"SELECT * FROM classbook_criteria WHERE id=? AND subject_id=? AND kind='attendance'",[(int)($_POST['criterion_id']??0),$id])->fetch();if(!$criterion)throw new RuntimeException('Choose an attendance criterion.');
        $f=$_FILES['report']??[];if(($f['error']??UPLOAD_ERR_NO_FILE)!==UPLOAD_ERR_OK||($f['size']??0)>4*1024*1024||!is_uploaded_file($f['tmp_name']??''))throw new RuntimeException('Choose an attendance file up to 4 MB (CSV up to 1 MB).');
        $bytes=file_get_contents($f['tmp_name']);$report=cbReadReport($bytes,basename($f['name']));$date=cbDate((string)($_POST['meeting_date']??''));if(isset($report['date'])&&$report['date']!==$date)throw new RuntimeException('The selected meeting date differs from the PDF. Choose '.$report['date'].' and preview again.');
        $_SESSION['classbook_import']=['subject'=>$id,'actor'=>$u['id'],'revision'=>$s['revision'],'criterion'=>$criterion['id'],'date'=>$date,'filename'=>substr(basename($f['name']),0,180),'fingerprint'=>hash('sha256',$bytes),'report'=>$report,'expires'=>time()+1200];return $id;
    }
    cbWrite($db,$id,$u,$revision,function($s)use($db,$u,$action,$id,$validCriteria){
        $detail=[];
        switch($action){
            case 'save_policy':
                $lecture=cbPeriodOnly($s)?100:cbNumber($_POST['lecture_percent']??'');$midterm=cbNumber($_POST['midterm_percent']??'');$passing=cbNumber($_POST['passing_percent']??'');
                $label=cbText($_POST['policy_label']??'');$bands=cbConversion((string)($_POST['conversion']??''));
                $before=cbPolicy($db,$id);$json=$bands?json_encode($bands,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE):null;
                cbQuery($db,'INSERT INTO classbook_policies(subject_id,lecture_percent,midterm_percent,passing_percent,policy_label,conversion_json) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE lecture_percent=VALUES(lecture_percent),midterm_percent=VALUES(midterm_percent),passing_percent=VALUES(passing_percent),policy_label=VALUES(policy_label),conversion_json=VALUES(conversion_json)',[$id,$lecture,$midterm,$passing,$label,$json]);$detail=['before'=>$before,'after'=>cbPolicy($db,$id)];break;
            case 'edit_class':
                $code=cbText($_POST['subject_code']??'',50);$title=cbText($_POST['subject_title']??'');$section=cbText($_POST['section']??'',50);$year=cbText($_POST['school_year']??'',20);$semester=(string)($_POST['semester']??'');$units=cbNumber($_POST['units']??'',12);
                if($units<1||floor($units)!=$units||!in_array($semester,['1st Semester','2nd Semester','Summer'],true))throw new RuntimeException('Choose a valid semester and whole-number units from 1 to 12.');
                // Current reads are needed after waiting: cbWrite may already have a read snapshot.
                cbLockIdentifiers($db);
                if(cbQuery($db,'SELECT id FROM subjects WHERE id<>? AND professor_id=? AND subject_code=? AND section=? AND school_year=? AND semester=? FOR UPDATE',[$id,$u['id'],$code,$section,$year,$semester])->fetchColumn())throw new RuntimeException('Another class already uses this offering.');
                $targetSubject=['id'=>$id,'subject_code'=>$code,'school_year'=>$year,'semester'=>$semester];
                foreach(cbMembers($db,$id) as $activeMember){
                    if($dup=cbDuplicateStudentSubject($db,(int)$activeMember['student_id'],$targetSubject,$id))throw new RuntimeException('Cannot change subject or term: an enrolled student already takes '.$code.' ('.$dup['section'].') in '.$year.' · '.$semester.'.');
                }
                cbQuery($db,'UPDATE subjects SET subject_code=?,subject_title=?,section=?,units=?,school_year=?,semester=? WHERE id=?',[$code,$title,$section,$units,$year,$semester,$id]);$detail=['before'=>$s,'reason'=>cbText($_POST['reason']??'',500)];break;
            case 'restore_enrollment':
                $enrollment=(int)($_POST['enrollment_id']??0);
                $old=cbQuery($db,'SELECT m.*,e.student_id FROM classbook_members m JOIN enrollments e ON e.id=m.enrollment_id WHERE e.subject_id=? AND m.enrollment_id=?',[$id,$enrollment])->fetch();
                if(!$old||$old['status']!=='dropped')throw new RuntimeException('Only a dropped enrollment in this class can be restored.');
                if($dup=cbDuplicateStudentSubject($db,(int)$old['student_id'],$s,$id))throw new RuntimeException('This student is already actively enrolled in '.$s['subject_code'].' ('.$dup['section'].') for '.$s['school_year'].' · '.$s['semester'].'. Drop that section first before restoring this enrollment.');
                $reason=cbText($_POST['reason']??'',500);cbQuery($db,"UPDATE classbook_members SET status='active',reason=? WHERE enrollment_id=?",[$reason,$enrollment]);$detail=['before'=>$old,'reason'=>$reason];break;
            case 'edit_activity':
                $activity=(int)($_POST['activity_id']??0);
                $old=cbActiveActivity($db,$id,$activity);
                $maximum=cbNumber($_POST['maximum']??'',1000000);if($maximum<=0)throw new RuntimeException('Maximum must be positive.');
                if((float)cbQuery($db,'SELECT MAX(earned) FROM classbook_scores WHERE activity_id=?',[$activity])->fetchColumn()>$maximum)throw new RuntimeException('The maximum cannot be lower than an existing earned score. Correct those scores first.');
                cbQuery($db,'UPDATE classbook_activities SET title=?,maximum=?,due_on=? WHERE id=?',[cbText($_POST['title']??''),$maximum,empty($_POST['due_on'])?null:cbDate($_POST['due_on']),$activity]);$detail=['before'=>$old,'reason'=>cbText($_POST['reason']??'',500)];break;
            case 'edit_meeting':
                $meeting=(int)($_POST['meeting_id']??0);$old=cbQuery($db,'SELECT m.* FROM classbook_meetings m JOIN classbook_criteria c ON c.id=m.criterion_id WHERE m.id=? AND c.subject_id=?',[$meeting,$id])->fetch();if(!$old||!in_array((int)$old['criterion_id'],$validCriteria,true))throw new RuntimeException('Meeting not available.');
                $date=cbDate((string)($_POST['meeting_date']??''));$title=cbText($_POST['title']??'');
                if($old['source_key']&&$date!==$old['meeting_date'])throw new RuntimeException('Imported meeting dates must stay consistent with the source report.');
                if(cbQuery($db,'SELECT id FROM classbook_meetings WHERE criterion_id=? AND meeting_date=? AND title=? AND id<>?',[$old['criterion_id'],$date,$title,$meeting])->fetchColumn())throw new RuntimeException('An identical meeting already exists.');
                cbQuery($db,'UPDATE classbook_meetings SET meeting_date=?,title=? WHERE id=?',[$date,$title,$meeting]);$detail=['before'=>$old,'reason'=>cbText($_POST['reason']??'',500)];break;
            case 'save_criteria':
                $criteria=cbClassCriteria($db,$id);$posted=$_POST['criteria']??[];
                if(!is_array($posted)||count($posted)!==count($criteria))throw new RuntimeException('The criteria form changed. Reload before saving.');
                foreach($criteria as $c){$p=$posted[$c['id']]??null;if(!is_array($p))throw new RuntimeException('Missing criterion.');$weight=cbNumber($p['weight']??'');$method=(string)($p['aggregation']??'');$excused=(string)($p['excused_mode']??'');if(!in_array($method,['pooled','equal'],true)||!in_array($excused,['exclude','makeup'],true))throw new RuntimeException('Invalid criterion rule.');
                    $name=isset($p['name'])?cbText($p['name'],100):$c['name'];
                    cbQuery($db,'UPDATE classbook_criteria SET name=?,weight=?,aggregation=?,excused_mode=? WHERE id=?',[$name,$weight,$method,$excused,$c['id']]);$detail[]=['id'=>$c['id'],'before'=>$c,'after'=>[$name,$weight,$method,$excused]];
                }cbValidateWeights($db,$id);break;
            case 'add_criterion':
                $group=(string)($_POST['group_key']??'');if(!isset(cbClassGroups($db,$id)[$group]))throw new RuntimeException('Invalid grading group.');$kind=(string)($_POST['kind']??'');$exam=match($kind){'prelim_exam'=>'prelim','midterm_exam'=>'midterm','final_exam'=>'final',default=>null};
                if($exam!==null){if(!str_starts_with($group,$exam==='final'?'fnl':'mid'))throw new RuntimeException('Choose a grading group in the correct exam period.');$kind='activity';}
                if(!in_array($kind,['activity','attendance'],true))throw new RuntimeException('Invalid criterion type.');
                cbQuery($db,'INSERT INTO classbook_criteria(subject_id,group_key,name,kind,weight,exam_key) VALUES(?,?,?,?,0,?)',[$id,$group,cbText($_POST['name']??'',100),$kind,$exam]);$detail=['criterion_id'=>(int)$db->lastInsertId()];break;
            case 'save_rules':
                $late=cbNumber($_POST['late_points']??'',5);$absent=cbNumber($_POST['absent_points']??'',5);$excused=(string)($_POST['excused_mode']??'');if(!in_array($excused,['exclude','makeup'],true))throw new RuntimeException('Choose an Excused rule.');
                $full=($_POST['online_full_minutes']??'')===''?null:cbNumber($_POST['online_full_minutes'],100000);$partial=($_POST['online_partial_minutes']??'')===''?null:cbNumber($_POST['online_partial_minutes'],100000);
                if(($full===null)!==($partial===null)||($full!==null&&($full<=0||$partial>$full)))throw new RuntimeException('Configure both time thresholds, with full > 0 and partial ≤ full, or leave both blank for manual review.');
                cbQuery($db,'UPDATE classbook_settings SET late_points=?,absent_points=?,excused_mode=?,online_full_minutes=?,online_partial_minutes=? WHERE subject_id=?',[$late,$absent,$excused,$full,$partial,$id]);$detail=['before'=>[$s['late_points'],$s['absent_points'],$s['excused_mode'],$s['online_full_minutes'],$s['online_partial_minutes']],'after'=>[$late,$absent,$excused,$full,$partial]];break;
            case 'enroll':
                $number=cbText($_POST['student_no']??'',50);$student=cbQuery($db,"SELECT id FROM students WHERE student_no=? OR id IN (SELECT account_id FROM classbook_account_meta WHERE role='student' AND public_id=?)",[$number,$number])->fetchColumn();
                if(!$student){$first=cbText($_POST['first_name']??'',100);$last=cbText($_POST['last_name']??'',100);$email=trim((string)($_POST['email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid student email.');$username='s.'.bin2hex(random_bytes(10));
                    cbUniqueIdentifiers($db,'student',['username'=>$username,'student_no'=>$number,'email'=>$email]);cbQuery($db,'INSERT INTO students(student_no,username,first_name,last_name,email,password_hash) VALUES(?,?,?,?,?,?)',[$number,$username,$first,$last,$email,password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT)]);$student=(int)$db->lastInsertId();cbQuery($db,"INSERT INTO classbook_access(role,account_id,status) VALUES('student',?,'pending')",[$student]);cbAccountPublicId($db,'student',(int)$student);}
                $enrollment=cbQuery($db,'SELECT id FROM enrollments WHERE subject_id=? AND student_id=?',[$id,$student])->fetchColumn();if($enrollment)throw new RuntimeException('This student already has an enrollment in the class. Use the retained record rather than creating another.');
                if($dup=cbDuplicateStudentSubject($db,(int)$student,$s,$id))throw new RuntimeException('This student is already enrolled in '.$s['subject_code'].' ('.$dup['section'].') for '.$s['school_year'].' · '.$s['semester'].'. A student cannot be enrolled in duplicate sections of the same subject.');
                cbQuery($db,'INSERT INTO enrollments(subject_id,student_id,professor_id) VALUES(?,?,?)',[$id,$student,$u['id']]);$enrollment=(int)$db->lastInsertId();cbQuery($db,'INSERT INTO classbook_members(enrollment_id,starts_on) VALUES(?,?)',[$enrollment,cbDate((string)($_POST['starts_on']??''))]);$detail=['enrollment_id'=>$enrollment,'student_id'=>$student];break;
            case 'drop_enrollment':
                $enrollment=(int)($_POST['enrollment_id']??0);if(!cbQuery($db,'SELECT id FROM enrollments WHERE id=? AND subject_id=?',[$enrollment,$id])->fetchColumn())throw new RuntimeException('Enrollment not available.');$reason=trim((string)($_POST['reason']??''))===''?'Dropped by professor':cbText($_POST['reason'],500);
                cbQuery($db,"UPDATE classbook_members SET status='dropped',reason=? WHERE enrollment_id=?",[$reason,$enrollment]);$detail=['enrollment_id'=>$enrollment,'reason'=>$reason];break;
            case 'create_activity':
                $c=cbQuery($db,"SELECT id FROM classbook_criteria WHERE id=? AND subject_id=? AND kind='activity'",[(int)($_POST['criterion_id']??0),$id])->fetchColumn();if(!$c)throw new RuntimeException('Choose an activity criterion.');$maximum=cbNumber($_POST['maximum']??'',1000000);if($maximum<=0)throw new RuntimeException('Maximum points must be greater than zero.');
                $exam=cbQuery($db,'SELECT exam_key FROM classbook_criteria WHERE id=?',[$c])->fetchColumn();
                if($exam){foreach(cbActivities($db,$id) as $existing)if($existing['exam_key']===$exam)throw new RuntimeException('This subject already has its '.CB_EXAMS[$exam].'. Edit that shared exam instead of creating another.');$c=(int)cbQuery($db,'SELECT MIN(id) FROM classbook_criteria WHERE subject_id=? AND exam_key=?',[$id,$exam])->fetchColumn();}
                cbQuery($db,'INSERT INTO classbook_activities(criterion_id,title,maximum,due_on) VALUES(?,?,?,?)',[$c,cbText($_POST['title']??''),$maximum,($_POST['due_on']??'')===''?null:cbDate($_POST['due_on'])]);$detail=['activity_id'=>(int)$db->lastInsertId(),'maximum'=>$maximum];$_SESSION['cb_created']=['activity_id',$detail['activity_id']];break;
            case 'save_scores':
                $a=cbActiveActivity($db,$id,(int)($_POST['activity_id']??0));
                $members=cbMembers($db,$id);$valid=array_column($members,'enrollment_id');$posted=$_POST['scores']??[];if(!is_array($posted)||count($posted)!==count($valid)||array_diff(array_keys($posted),$valid))throw new RuntimeException('The score form is incomplete or contains a student outside this class. No partial scores were saved.');
                foreach($posted as $enrollment=>$score){
                    $hasValue=is_array($score)&&array_key_exists('value',$score);
                    $valueChanged=$hasValue&&(!array_key_exists('initial_value',$score)||(string)$score['value']!==(string)$score['initial_value']);
                    if($valueChanged||($hasValue&&empty($score['status']))){
                        try{[$status,$earned]=cbParseScoreCell($score['value'],(float)$a['maximum']);}catch(InvalidArgumentException){throw new RuntimeException('Enter a score from 0 to '.$a['maximum'].', M for Missing, E for Excused, or leave blank. No scores were saved.');}
                    }else{
                        if(!is_array($score)||!in_array($score['status']??'',CB_WORK,true))throw new RuntimeException('Choose a valid work status.');
                        $status=$score['status'];$earned=$status==='scored'?cbNumber($score['earned']??'',(float)$a['maximum']):null;
                    }
                    $old=cbQuery($db,'SELECT status,earned FROM classbook_scores WHERE activity_id=? AND enrollment_id=?',[$a['id'],$enrollment])->fetch();cbQuery($db,'INSERT INTO classbook_scores(activity_id,enrollment_id,status,earned) VALUES(?,?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status),earned=VALUES(earned)',[$a['id'],$enrollment,$status,$earned]);$detail[]=['enrollment_id'=>$enrollment,'before'=>$old,'after'=>[$status,$earned]];
                }break;
            case 'take_attendance_today':
                $chosen=(int)($_POST['criterion_id']??0);$date=date('Y-m-d');
                $c=cbQuery($db,"SELECT id FROM classbook_criteria WHERE id=? AND subject_id=? AND kind='attendance'",[$chosen,$id])->fetchColumn();if(!$c)throw new RuntimeException('Choose an attendance criterion.');
                $title='Meeting '.date('M j, Y',strtotime($date));
                $meeting=(int)cbQuery($db,'SELECT id FROM classbook_meetings WHERE criterion_id=? AND meeting_date=? ORDER BY id DESC LIMIT 1',[$c,$date])->fetchColumn();
                if(!$meeting){cbQuery($db,'INSERT INTO classbook_meetings(criterion_id,meeting_date,title) VALUES(?,?,?)',[$c,$date,$title]);$meeting=(int)$db->lastInsertId();}
                $eligible=cbMembers($db,$id);
                foreach($eligible as $m)cbQuery($db,"INSERT IGNORE INTO classbook_attendance(meeting_id,enrollment_id,status) VALUES(?,?,'present')",[$meeting,$m['enrollment_id']]);
                $detail=['meeting_id'=>$meeting,'date'=>$date,'present_defaults'=>count($eligible)];$_SESSION['cb_created']=['meeting_id',$meeting];break;
            case 'create_meeting':
                $chosen=(int)($_POST['criterion_id']??0);$date=cbDate((string)($_POST['meeting_date']??''));
                $c=cbQuery($db,"SELECT id FROM classbook_criteria WHERE id=? AND subject_id=? AND kind='attendance'",[$chosen,$id])->fetchColumn();if(!$c)throw new RuntimeException('Choose an attendance criterion.');
                if(trim((string)($_POST['title']??''))===''){
                    // Untitled meetings are named automatically; a second meeting on the same day gets a number.
                    $base='Meeting '.date('M j, Y',strtotime($date));$title=$base;$n=1;
                    while(cbQuery($db,'SELECT id FROM classbook_meetings WHERE criterion_id=? AND meeting_date=? AND title=?',[$c,$date,$title])->fetchColumn())$title=$base.' ('.(++$n).')';
                }else $title=cbText($_POST['title']);
                if(cbQuery($db,'SELECT id FROM classbook_meetings WHERE criterion_id=? AND meeting_date=? AND title=?',[$c,$date,$title])->fetchColumn())throw new RuntimeException('An identical meeting already exists.');
                cbQuery($db,'INSERT INTO classbook_meetings(criterion_id,meeting_date,title) VALUES(?,?,?)',[$c,$date,$title]);$detail=['meeting_id'=>(int)$db->lastInsertId()];$_SESSION['cb_created']=['meeting_id',$detail['meeting_id']];break;
            case 'copy_setup':
                // Copy criteria weights, grade formula and attendance points from another class of the same professor.
                $source=(int)($_POST['source_id']??0);if($source===$id)throw new RuntimeException('Choose a different class to copy from.');
                $src=cbQuery($db,'SELECT s.id,c.grading_model,c.late_points,c.absent_points,c.excused_mode,c.online_full_minutes,c.online_partial_minutes FROM subjects s JOIN classbook_settings c ON c.subject_id=s.id WHERE s.id=? AND s.professor_id=?',[$source,$u['id']])->fetch();
                if(!$src||$src['grading_model']!==($s['grading_model']??'periods'))throw new RuntimeException('Choose one of your classes that uses the same grading periods.');
                $same=fn(array $a,array $b):bool=>$a['group_key']===$b['group_key']&&$a['kind']===$b['kind']&&$a['exam_key']===$b['exam_key']&&strcasecmp($a['name'],$b['name'])===0;
                $sourceCriteria=cbClassCriteria($db,$source);$target=cbClassCriteria($db,$id);
                foreach($sourceCriteria as $from){
                    $match=null;foreach($target as $to)if($same($to,$from)){$match=$to;break;}
                    if($match)cbQuery($db,'UPDATE classbook_criteria SET weight=?,aggregation=?,excused_mode=? WHERE id=?',[$from['weight'],$from['aggregation'],$from['excused_mode'],$match['id']]);
                    else cbQuery($db,'INSERT INTO classbook_criteria(subject_id,group_key,name,kind,weight,exam_key,aggregation,excused_mode) VALUES(?,?,?,?,?,?,?,?)',[$id,$from['group_key'],$from['name'],$from['kind'],$from['weight'],$from['exam_key'],$from['aggregation'],$from['excused_mode']]);
                }
                // Criteria found only in this class stop counting; their records are retained.
                foreach($target as $to){$found=false;foreach($sourceCriteria as $from)if($same($to,$from))$found=true;if(!$found)cbQuery($db,'UPDATE classbook_criteria SET weight=0 WHERE id=?',[$to['id']]);}
                cbValidateWeights($db,$id);
                $policy=cbQuery($db,'SELECT * FROM classbook_policies WHERE subject_id=?',[$source])->fetch();
                if($policy)cbQuery($db,'INSERT INTO classbook_policies(subject_id,lecture_percent,midterm_percent,passing_percent,policy_label,conversion_json) VALUES(?,?,?,?,?,?) ON DUPLICATE KEY UPDATE lecture_percent=VALUES(lecture_percent),midterm_percent=VALUES(midterm_percent),passing_percent=VALUES(passing_percent),policy_label=VALUES(policy_label),conversion_json=VALUES(conversion_json)',[$id,$policy['lecture_percent'],$policy['midterm_percent'],$policy['passing_percent'],$policy['policy_label'],$policy['conversion_json']]);
                cbQuery($db,'UPDATE classbook_settings SET late_points=?,absent_points=?,excused_mode=?,online_full_minutes=?,online_partial_minutes=? WHERE subject_id=?',[$src['late_points'],$src['absent_points'],$src['excused_mode'],$src['online_full_minutes'],$src['online_partial_minutes'],$id]);
                $detail=['source_id'=>$source,'criteria'=>count($sourceCriteria),'policy_copied'=>(bool)$policy];break;
            case 'enroll_many':
                // Existing student accounts only; new students are created by the administrator.
                $numbers=array_values(array_unique(array_filter(array_map('trim',preg_split('/[\r\n,;\t]+/',(string)($_POST['student_numbers']??''))),fn($n)=>$n!=='')));
                if(!$numbers)throw new RuntimeException('Paste at least one student number.');if(count($numbers)>200)throw new RuntimeException('Enroll up to 200 students at a time.');
                $start=cbDate((string)($_POST['starts_on']??''));$added=[];$skipped=[];$unknown=[];
                foreach($numbers as $number){
                    $number=mb_substr($number,0,50);
                    $student=cbQuery($db,"SELECT id FROM students WHERE student_no=? OR id IN (SELECT account_id FROM classbook_account_meta WHERE role='student' AND public_id=?)",[$number,$number])->fetchColumn();
                    if(!$student){$unknown[]=$number;continue;}
                    if(cbQuery($db,'SELECT id FROM enrollments WHERE subject_id=? AND student_id=?',[$id,$student])->fetchColumn()||cbDuplicateStudentSubject($db,(int)$student,$s,$id)){$skipped[]=$number;continue;}
                    cbQuery($db,'INSERT INTO enrollments(subject_id,student_id,professor_id) VALUES(?,?,?)',[$id,$student,$u['id']]);$enrollment=(int)$db->lastInsertId();cbQuery($db,'INSERT INTO classbook_members(enrollment_id,starts_on) VALUES(?,?)',[$enrollment,$start]);$added[]=$number;
                }
                if($unknown)throw new RuntimeException('No account found for: '.implode(', ',array_slice($unknown,0,10)).(count($unknown)>10?' …':'').'. Nothing was enrolled. Remove these numbers or ask the administrator to create the accounts.');
                $detail=['added'=>$added,'already_enrolled'=>$skipped];$_SESSION['cb_enrolled']=[count($added),count($skipped)];break;
            case 'save_attendance':
                $m=cbQuery($db,'SELECT m.* FROM classbook_meetings m JOIN classbook_criteria c ON c.id=m.criterion_id WHERE m.id=? AND c.subject_id=?',[(int)($_POST['meeting_id']??0),$id])->fetch();if(!$m||!in_array((int)$m['criterion_id'],$validCriteria,true))throw new RuntimeException('Meeting not available.');$members=cbMembers($db,$id);$valid=array_column($members,'enrollment_id');$posted=$_POST['attendance']??[];if(!is_array($posted)||count($posted)!==count($valid)||array_diff(array_keys($posted),$valid))throw new RuntimeException('The attendance form is incomplete or contains an ineligible student. No partial attendance was saved.');
                foreach($posted as $enrollment=>$status){if(!in_array($status,CB_ATTENDANCE,true))throw new RuntimeException('Invalid attendance status.');$old=cbQuery($db,'SELECT status FROM classbook_attendance WHERE meeting_id=? AND enrollment_id=?',[$m['id'],$enrollment])->fetchColumn();cbQuery($db,'INSERT INTO classbook_attendance(meeting_id,enrollment_id,status) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)',[$m['id'],$enrollment,$status]);$detail[]=['enrollment_id'=>$enrollment,'before'=>$old,'after'=>$status];}break;
            case 'commit_import':
                $draft=$_SESSION['classbook_import']??null;if(!$draft||$draft['subject']!==$id||(int)$draft['actor']!==(int)$u['id']||$draft['expires']<time()||(int)$draft['revision']!==(int)$s['revision'])throw new RuntimeException('This import preview expired or the class changed. Upload and review again.');
                if($draft['report']['format']==='tracker_pdf'&&empty($_POST['quality_reviewed']))throw new RuntimeException('Review the report-provided accuracy and identity matching first.');
                $members=cbMembers($db,$id);$byId=array_column($members,null,'enrollment_id');$matches=$_POST['matches']??[];$statuses=$_POST['statuses']??[];if(!is_array($matches)||!is_array($statuses))throw new RuntimeException('Invalid import form.');$selected=[];$seen=[];
                foreach($draft['report']['rows'] as $i=>$r){$match=(string)($matches[$i]??'');if($match==='skip')continue;$e=(int)$match;$status=$statuses[$i]??'';if(!isset($byId[$e])||isset($seen[$e])||!in_array($status,['present','late','absent','excused'],true))throw new RuntimeException('Match each report row once to an eligible student, choose its status, or explicitly skip it.');$seen[$e]=true;$selected[]=[$e,$status,$r];}
                if(!$selected)throw new RuntimeException('No attendance rows selected.');
                $fingerprint=hash('sha256',$draft['fingerprint'].'|'.$draft['criterion']);$sourceKey=hash('sha256',$id.'|'.$draft['criterion'].'|'.($draft['report']['meeting']??$draft['fingerprint']).'|'.$draft['date']);
                if(cbQuery($db,'SELECT id FROM classbook_imports WHERE subject_id=? AND fingerprint=?',[$id,$fingerprint])->fetchColumn()||cbQuery($db,'SELECT id FROM classbook_meetings WHERE source_key=?',[$sourceKey])->fetchColumn())throw new RuntimeException('This report or meeting has already been imported for this grading group.');
                $meta=$draft['report'];unset($meta['rows']);$meta['filename']=$draft['filename'];cbQuery($db,'INSERT INTO classbook_meetings(criterion_id,meeting_date,title,source_key,source_json) VALUES(?,?,?,?,?)',[$draft['criterion'],$draft['date'],$draft['report']['meeting']??'Online attendance',$sourceKey,json_encode($meta,JSON_THROW_ON_ERROR)]);$meeting=(int)$db->lastInsertId();
                foreach($selected as [$e,$status,$r])cbQuery($db,'INSERT INTO classbook_attendance(meeting_id,enrollment_id,status,duration_seconds,source_identity) VALUES(?,?,?,?,?)',[$meeting,$e,$status,$r['seconds'],$r['identity']]);
                cbQuery($db,'INSERT INTO classbook_imports(subject_id,fingerprint,meeting_id,actor_id) VALUES(?,?,?,?)',[$id,$fingerprint,$meeting,$u['id']]);$detail=['rows'=>count($selected),'fingerprint'=>$fingerprint,'meeting_id'=>$meeting];break;
            case 'submit':
                cbValidateWeights($db,$id);$computed=cbCompute($db,$id);if(!$computed['students'])throw new RuntimeException('Enroll students before submission.');foreach($computed['students'] as $r)if($r['course']===null)throw new RuntimeException('Complete all positively weighted criteria for every active student before submitting.');cbQuery($db,"UPDATE classbook_settings SET state='submitted' WHERE subject_id=?",[$id]);$detail=['revision'=>$s['revision']];break;
            case 'archive':
                cbQuery($db,'UPDATE classbook_settings SET archived=1 WHERE subject_id=?',[$id]);$detail=['academic_records_preserved'=>true];break;
            default:throw new RuntimeException('Unknown classroom action.');
        }
        cbAudit($db,$u,$id,$action,$detail);
    });
    if($action==='commit_import')unset($_SESSION['classbook_import']);return $id;
}
