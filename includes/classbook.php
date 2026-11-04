<?php
declare(strict_types=1);
require_once __DIR__.'/account_ids.php';
require_once __DIR__.'/shared_exams.php';
require_once __DIR__.'/grading_periods.php';

const CB_GROUPS = ['mid_lec'=>'Midterm Lecture','mid_lab'=>'Midterm Laboratory','fnl_lec'=>'Final Lecture','fnl_lab'=>'Final Laboratory'];
const CB_ATTENDANCE = ['unrecorded','present','late','absent','excused'];
const CB_WORK = ['not_graded','missing','excused','scored'];
/** Default GRC grade conversion for new classes; professors may edit it in Class settings. */
const CB_DEFAULT_CONVERSION = [['minimum'=>0,'label'=>'5.00'],['minimum'=>75,'label'=>'3.00'],['minimum'=>76,'label'=>'2.75'],['minimum'=>79,'label'=>'2.50'],['minimum'=>82,'label'=>'2.25'],['minimum'=>85,'label'=>'2.00'],['minimum'=>88,'label'=>'1.75'],['minimum'=>91,'label'=>'1.50'],['minimum'=>94,'label'=>'1.25'],['minimum'=>97,'label'=>'1.00']];

function cbQuery(PDO $db, string $sql, array $args=[]): PDOStatement {
    $q=$db->prepare($sql); $q->execute($args); return $q;
}
function cbInstalled(PDO $db): bool {
    try { $db->query('SELECT id FROM classbook_admins LIMIT 1'); return true; } catch (PDOException $e) { return false; }
}
function cbInstall(PDO $db): void {
    $base=file_get_contents(__DIR__.'/../database/grc_grading.sql');
    $base=explode('-- Seed Data:', $base)[0];
    $base=preg_replace('/CREATE DATABASE.*?;|USE `grc_grading`;/s','',$base);
    $db->exec($base);
    cbUpgrade($db);
}
function cbUpgrade(PDO $db): void {
    try {if(cbQuery($db,'SELECT version FROM classbook_migrations WHERE version=5')->fetchColumn())return;}catch(PDOException $e){}
    $db->exec("CREATE TABLE IF NOT EXISTS classbook_policies (subject_id INT UNSIGNED PRIMARY KEY, lecture_percent DECIMAL(5,2) NOT NULL DEFAULT 65, midterm_percent DECIMAL(5,2) NOT NULL DEFAULT 50, passing_percent DECIMAL(5,2) NOT NULL DEFAULT 75, policy_label VARCHAR(150) NOT NULL DEFAULT 'Provisional defaults', conversion_json TEXT NULL, FOREIGN KEY(subject_id) REFERENCES subjects(id) ON DELETE RESTRICT) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS classbook_account_meta (role VARCHAR(20) NOT NULL, account_id INT UNSIGNED NOT NULL, revision INT UNSIGNED NOT NULL DEFAULT 1, PRIMARY KEY(role,account_id)) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS classbook_login_limits (bucket CHAR(64) PRIMARY KEY, attempts INT UNSIGNED NOT NULL DEFAULT 0, window_started DATETIME NOT NULL) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS classbook_migrations (version INT PRIMARY KEY, applied_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
INSERT IGNORE INTO classbook_migrations(version) VALUES(2);
ALTER TABLE classbook_account_meta ADD COLUMN IF NOT EXISTS public_id VARCHAR(32) NULL;
ALTER TABLE classbook_account_meta ADD UNIQUE INDEX IF NOT EXISTS unique_public_id (public_id);");
    cbBackfillAccountIds($db);
    cbQuery($db,'INSERT IGNORE INTO classbook_migrations(version) VALUES(3)');
    $db->exec("ALTER TABLE classbook_criteria ADD COLUMN IF NOT EXISTS exam_key VARCHAR(20) NULL;");
    cbQuery($db,'INSERT IGNORE INTO classbook_migrations(version) VALUES(4)');
    $db->exec("ALTER TABLE classbook_settings ADD COLUMN IF NOT EXISTS grading_model VARCHAR(30) NOT NULL DEFAULT 'legacy_components';");
    cbQuery($db,'INSERT IGNORE INTO classbook_migrations(version) VALUES(5)');
}
function cbPolicy(PDO $db,int $subject): array {
    return cbQuery($db,'SELECT * FROM classbook_policies WHERE subject_id=?',[$subject])->fetch() ?: ['lecture_percent'=>65,'midterm_percent'=>50,'passing_percent'=>75,'policy_label'=>'Provisional defaults','conversion_json'=>null];
}
function cbBlend(?float $first,?float $second,float $firstPercent): ?float {
    if($firstPercent<0||$firstPercent>100)throw new InvalidArgumentException('Invalid period weight.');
    if(($firstPercent>0&&$first===null)||($firstPercent<100&&$second===null))return null;
    return round(($first??0)*$firstPercent/100+($second??0)*(100-$firstPercent)/100,2);
}
function cbConversion(string $text): array {
    $rows=[];
    foreach(preg_split('/\r?\n/',$text) as $line){if(trim($line)==='')continue;
        $parts=explode('=',trim($line),2);if(count($parts)!==2)throw new InvalidArgumentException('Use one minimum percentage = grade label per line.');
        $min=cbNumber(trim($parts[0]));$label=cbText(trim($parts[1]),30);
        if(isset($rows[(string)$min]))throw new InvalidArgumentException('Conversion thresholds must be unique.');
        $rows[(string)$min]=['minimum'=>$min,'label'=>$label];
    }
    if(count($rows)>30)throw new InvalidArgumentException('Use at most 30 conversion bands.');
    $rows=array_values($rows);usort($rows,fn($a,$b)=>$a['minimum']<=>$b['minimum']);
    if($rows&&$rows[0]['minimum']!=0)throw new InvalidArgumentException('The conversion table must start at 0% to cover every score.');
    return $rows;
}
function cbConverted(?float $score,array $policy): ?string {
    if($score===null)return null;$result=null;
    foreach(json_decode($policy['conversion_json']??'[]',true,512,JSON_THROW_ON_ERROR)??[] as $band)if($score>=(float)$band['minimum'])$result=(string)$band['label'];
    return $result;
}
function cbStatus(PDO $db, string $role, int $id): string {
    return (string)(cbQuery($db,'SELECT status FROM classbook_access WHERE role=? AND account_id=?',[$role,$id])->fetchColumn() ?: 'active');
}
function cbNumber(mixed $value, float $max=100): float {
    if (!is_scalar($value)||!is_numeric($value)||!is_finite((float)$value)||(float)$value<0||(float)$value>$max) throw new InvalidArgumentException('Enter a valid number from 0 to '.$max.'.');
    return round((float)$value,2);
}
function cbText(mixed $v, int $max=150): string {
    if(!is_string($v)||trim($v)===''||mb_strlen(trim($v))>$max) throw new InvalidArgumentException('A required text field is empty or too long.');
    return trim($v);
}
function cbDate(string $date): string {
    $d=DateTimeImmutable::createFromFormat('!Y-m-d',$date);
    if(!$d||$d->format('Y-m-d')!==$date) throw new InvalidArgumentException('Choose a valid meeting or due date.'); return $date;
}
function cbAudit(PDO $db, array $actor, ?int $subject, string $action, array $detail): void {
    cbQuery($db,'INSERT INTO classbook_audit(actor_role,actor_id,subject_id,action,detail_json) VALUES(?,?,?,?,?)',[$actor['role'],$actor['id'],$subject,$action,json_encode($detail,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE)]);
}
function cbClass(PDO $db,int $id,array $actor): array {
    $q=cbQuery($db,'SELECT s.*, c.revision,c.state,c.archived,c.grading_model,c.late_points,c.absent_points,c.excused_mode,c.online_full_minutes,c.online_partial_minutes FROM subjects s JOIN classbook_settings c ON c.subject_id=s.id WHERE s.id=?',[$id]);
    $s=$q->fetch();
    if(!$s||($actor['role']!=='admin'&&($actor['role']!=='professor'||(int)$s['professor_id']!==(int)$actor['id']))) throw new RuntimeException('This class is not available to your account.');
    return $s;
}
function cbWrite(PDO $db,int $id,array $actor,int $revision,callable $work,bool $stateChange=false): mixed {
    $db->beginTransaction();
    try {
        $row=cbQuery($db,'SELECT * FROM classbook_settings WHERE subject_id=? FOR UPDATE',[$id])->fetch();
        $s=cbClass($db,$id,$actor);
        if(!$row||(int)$row['revision']!==$revision) throw new RuntimeException('This class changed in another session. Reload and review before saving.');
        if(!$stateChange&&($actor['role']!=='professor'||$s['archived']||$s['state']!=='draft')) throw new RuntimeException('This record is locked or archived. Ask the Registrar to return it for correction.');
        $result=$work($s);
        cbQuery($db,'UPDATE classbook_settings SET revision=revision+1 WHERE subject_id=?',[$id]);
        $db->commit(); return $result;
    } catch(Throwable $e) { if($db->inTransaction())$db->rollBack(); throw $e; }
}
function cbInitialize(PDO $db,int $subject): void {
    cbQuery($db,"INSERT IGNORE INTO classbook_settings(subject_id,grading_model) VALUES(?,'periods')",[$subject]);
    if(cbQuery($db,'SELECT COUNT(*) FROM classbook_criteria WHERE subject_id=?',[$subject])->fetchColumn())return;
    foreach(cbClassGroups($db,$subject) as $g=>$label){
        $weights=str_starts_with($g,'mid')?MIDTERM_WEIGHTS:FINAL_WEIGHTS;
        foreach($weights as $key=>$weight)cbQuery($db,'INSERT INTO classbook_criteria(subject_id,group_key,name,kind,weight) VALUES(?,?,?,?,?)',[$subject,$g,ucwords(str_replace('_',' ',$key)),$key==='attendance'?'attendance':'activity',$weight*100]);
    }
    cbTagDefaultExams($db,$subject);
    if(cbPeriodOnly(cbQuery($db,'SELECT grading_model FROM classbook_settings WHERE subject_id=?',[$subject])->fetch()))cbQuery($db,'INSERT IGNORE INTO classbook_policies(subject_id,lecture_percent,policy_label,conversion_json) VALUES(?,100,?,?)',[$subject,'GRC standard grading',json_encode(CB_DEFAULT_CONVERSION,JSON_THROW_ON_ERROR)]);
    cbQuery($db,"INSERT IGNORE INTO classbook_members(enrollment_id,starts_on) SELECT id,DATE(enrolled_at) FROM enrollments WHERE subject_id=?",[$subject]);
}
function cbMembers(PDO $db,int $subject,bool $active=true): array {
    return cbQuery($db,"SELECT e.id AS enrollment_id,e.student_id,s.student_no,s.first_name,s.last_name,s.email,s.course,s.year_level,i.public_id,COALESCE(m.status,'active') AS enrollment_status,COALESCE(m.starts_on,DATE(e.enrolled_at)) AS starts_on FROM enrollments e JOIN students s ON s.id=e.student_id LEFT JOIN classbook_members m ON m.enrollment_id=e.id LEFT JOIN classbook_account_meta i ON i.role='student' AND i.account_id=e.student_id WHERE e.subject_id=?".($active?" AND COALESCE(m.status,'active')='active'":'').' ORDER BY s.last_name,s.first_name',[$subject])->fetchAll();
}
/** Each row represents a real activity or meeting; missing facts never become zero. */
function cbAggregate(array $rows,string $method,string $excused): ?float {
    if(!in_array($method,['pooled','equal'],true)||!in_array($excused,['exclude','makeup'],true))throw new InvalidArgumentException('Invalid calculation rule.');
    $eligible=[];
    foreach($rows as $r){
        if(($r['status']??'not_graded')==='excused'&&$excused==='exclude')continue;
        if(($r['status']??'not_graded')!=='scored'||$r['earned']===null||$r['earned']==='')return null;
        $maximum=cbNumber($r['maximum'],1000000); if($maximum<=0)throw new InvalidArgumentException('Maximum points must be greater than zero.');
        $eligible[]=['earned'=>cbNumber($r['earned'],$maximum),'maximum'=>$maximum];
    }
    if(!$eligible)return null;
    if($method==='equal')return round(array_sum(array_map(fn($r)=>$r['earned']/$r['maximum']*100,$eligible))/count($eligible),2);
    return round(array_sum(array_column($eligible,'earned'))/array_sum(array_column($eligible,'maximum'))*100,2);
}
function cbCompute(PDO $db,int $subject): array {
    $settings=cbQuery($db,'SELECT * FROM classbook_settings WHERE subject_id=?',[$subject])->fetch();
    if(!$settings)throw new RuntimeException('Initialize this class record first.');
    $criteria=cbClassCriteria($db,$subject);$groupLabels=cbClassGroups($db,$subject);
    $members=cbMembers($db,$subject);$results=[];$policy=cbPolicy($db,$subject);
    $activities=cbActivities($db,$subject);
    $scores=cbQuery($db,'SELECT sc.* FROM classbook_scores sc JOIN classbook_activities a ON a.id=sc.activity_id JOIN classbook_criteria c ON c.id=a.criterion_id WHERE c.subject_id=?',[$subject])->fetchAll();
    $scoreMap=[];foreach($scores as $r)$scoreMap[$r['activity_id']][$r['enrollment_id']]=$r;
    $meetings=cbQuery($db,'SELECT m.* FROM classbook_meetings m JOIN classbook_criteria c ON c.id=m.criterion_id WHERE c.subject_id=?',[$subject])->fetchAll();
    $attendance=cbQuery($db,'SELECT a.* FROM classbook_attendance a JOIN classbook_meetings m ON m.id=a.meeting_id JOIN classbook_criteria c ON c.id=m.criterion_id WHERE c.subject_id=?',[$subject])->fetchAll();
    $attMap=[];foreach($attendance as $r)$attMap[$r['meeting_id']][$r['enrollment_id']]=$r;
    foreach($members as $student){
        $groups=array_fill_keys(array_keys($groupLabels),0.0);$totals=array_fill_keys(array_keys($groupLabels),0.0);$evidence=[];
        foreach($criteria as $c){
            $rows=[];
            if($c['kind']==='activity')foreach($activities as $a){
                if($c['exam_key']!==null ? $a['exam_key']!==$c['exam_key'] : (int)$a['criterion_id']!==(int)$c['id'])continue;
                $score=$scoreMap[$a['id']][$student['enrollment_id']]??[];
                $rows[]=['title'=>$a['title'],'maximum'=>$a['maximum'],'earned'=>$score['earned']??null,'status'=>$score['status']??'not_graded'];
            }
            else foreach($meetings as $m){
                if((int)$m['criterion_id']!==(int)$c['id'])continue;
                $a=$attMap[$m['id']][$student['enrollment_id']]??[];$status=$a['status']??'unrecorded';
                $points=match($status){'present'=>5,'late'=>(float)$settings['late_points'],'absent'=>(float)$settings['absent_points'],default=>null};
                $rows[]=['title'=>$m['meeting_date'].' · '.$m['title'],'maximum'=>5,'earned'=>$points,'status'=>$status==='excused'?'excused':($points===null?'not_graded':'scored'),'attendance_status'=>$status,'duration_seconds'=>$a['duration_seconds']??null];
            }
            $percentage=cbAggregate($rows,$c['kind']==='attendance'?'pooled':$c['aggregation'],$c['kind']==='attendance'?$settings['excused_mode']:$c['excused_mode']);
            $weight=(float)$c['weight'];$totals[$c['group_key']]+=$weight;
            if($weight>0){if($percentage===null)$groups[$c['group_key']]=null;elseif($groups[$c['group_key']]!==null)$groups[$c['group_key']]+=$percentage*$weight/100;}
            $evidence[]=['criterion_id'=>(int)$c['id'],'criterion'=>$c['name'],'group'=>$c['group_key'],'group_label'=>$groupLabels[$c['group_key']],'exam_key'=>$c['exam_key'],'weight'=>$weight,'percentage'=>$percentage,'aggregation'=>$c['kind']==='attendance'?'pooled':$c['aggregation'],'excused_mode'=>$c['kind']==='attendance'?$settings['excused_mode']:$c['excused_mode'],'rows'=>$rows];
        }
        foreach($groups as $g=>$value){if(abs($totals[$g]-100)>0.001)$groups[$g]=null;elseif($value!==null)$groups[$g]=round($value,2);}
        $mid=cbPeriodOnly($settings)?$groups['mid_lec']:cbBlend($groups['mid_lec'],$groups['mid_lab'],(float)$policy['lecture_percent']);$final=cbPeriodOnly($settings)?$groups['fnl_lec']:cbBlend($groups['fnl_lec'],$groups['fnl_lab'],(float)$policy['lecture_percent']);$course=cbBlend($mid,$final,(float)$policy['midterm_percent']);
        $results[$student['enrollment_id']]=['student'=>$student,'groups'=>$groups,'midterm'=>$mid,'final'=>$final,'course'=>$course,'converted'=>cbConverted($course,$policy),'remarks'=>$course===null?'Pending':($course>=(float)$policy['passing_percent']?'Passed':'Failed'),'evidence'=>$evidence];
    }
    return ['subject_id'=>$subject,'revision'=>(int)$settings['revision'],'settings'=>$settings,'policy'=>$policy,'criteria'=>$criteria,'students'=>$results];
}
function cbValidateWeights(PDO $db,int $subject): void {
    $rows=cbQuery($db,'SELECT group_key,SUM(weight) AS total FROM classbook_criteria WHERE subject_id=? GROUP BY group_key',[$subject])->fetchAll();$totals=array_column($rows,'total','group_key');
    foreach(cbClassGroups($db,$subject) as $g=>$l)if(!isset($totals[$g])||abs((float)$totals[$g]-100)>0.001)throw new InvalidArgumentException($l.' weights must total 100%.');
}
function cbRelease(PDO $db,int $subject,array $admin,int $revision): void {
    if($admin['role']!=='admin')throw new RuntimeException('Registrar access required.');
    cbWrite($db,$subject,$admin,$revision,function($s)use($db,$subject,$admin,$revision){
        if($s['state']!=='submitted')throw new RuntimeException('Only submitted grade sheets can be released.');
        cbValidateWeights($db,$subject);$snapshot=cbCompute($db,$subject);
        if(!$snapshot['students'])throw new RuntimeException('This class has no active enrolled students.');
        foreach($snapshot['students'] as $r)if($r['course']===null)throw new RuntimeException('Every active student must have a complete grade before release.');
        $snapshot['class']=$s;
        cbQuery($db,'INSERT INTO classbook_releases(subject_id,revision,snapshot_json,actor_id) VALUES(?,?,?,?)',[$subject,$revision,json_encode($snapshot,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE),$admin['id']]);
        cbQuery($db,"UPDATE classbook_settings SET state='released' WHERE subject_id=?",[$subject]);cbAudit($db,$admin,$subject,'grades_released',['revision'=>$revision]);
    },true);
}
function cbLatestRelease(PDO $db,int $subject): ?array {
    $row=cbQuery($db,'SELECT * FROM classbook_releases WHERE subject_id=? ORDER BY id DESC LIMIT 1',[$subject])->fetch();
    if(!$row)return null;return ['record'=>$row,'snapshot'=>json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR)];
}
function cbStudentRelease(PDO $db,int $subject,int $enrollment): ?array {
    foreach(cbQuery($db,'SELECT * FROM classbook_releases WHERE subject_id=? ORDER BY id DESC',[$subject])->fetchAll() as $row){
        $snapshot=json_decode($row['snapshot_json'],true,512,JSON_THROW_ON_ERROR);
        if(isset($snapshot['students'][$enrollment]))return ['record'=>$row,'snapshot'=>$snapshot];
    }
    return null;
}
