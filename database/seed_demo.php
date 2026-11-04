<?php
declare(strict_types=1);
// Local preview data only. Never expose account creation through an HTTP endpoint.
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Manila');
foreach(['db','functions','departments','classbook','security','unified_auth'] as $dependency)require_once __DIR__.'/../includes/'.$dependency.'.php';

/** Append once; later runs preserve all edits, credentials and existing records. */
function seedDemo(PDO $db, string $password): array {
    $lock='grc-demo-'.substr(hash('sha256',(string)$db->query('SELECT DATABASE()')->fetchColumn()),0,40);
    if ((int)cbQuery($db,'SELECT GET_LOCK(?,10)',[$lock])->fetchColumn()!==1) throw new RuntimeException('Another demo seed is running.');
    try {
        $previous=cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='demo_seed_loaded' ORDER BY id")->fetchAll(PDO::FETCH_COLUMN);
        foreach($previous as $json){$saved=json_decode($json,true,512,JSON_THROW_ON_ERROR);if(($saved['dataset']??'')==='grc-demo-v1')return $saved;}
        $db->beginTransaction();
        try {
            cbLockIdentifiers($db);
            $manifest=['dataset'=>'grc-demo-v1','accounts'=>[],'classes'=>[]];
            $hash=password_hash($password,PASSWORD_DEFAULT);
            $departments=array_keys(CB_DEPARTMENTS);
            $add=function(string $role,string $username,string $name,string $status,int $dept=0,string $course='BS Information Technology')use($db,$hash,&$manifest,$departments):int {
                $public=cbNextAccountId($db,$role);
                $email=$username.'@example.invalid';
                $fields=['username'=>$username,'email'=>$email,'employee_id'=>$public,'student_no'=>$public];
                cbUniqueIdentifiers($db,$role,$fields);
                if($role==='admin')cbQuery($db,'INSERT INTO classbook_admins(username,full_name,password_hash) VALUES(?,?,?)',[$username,$name,$hash]);
                elseif($role==='professor')cbQuery($db,'INSERT INTO professors(employee_id,username,full_name,email,password_hash,department) VALUES(?,?,?,?,?,?)',[$public,$username,$name,$email,$hash,$departments[$dept]]);
                else cbQuery($db,'INSERT INTO students(student_no,username,first_name,last_name,email,course,year_level,password_hash) VALUES(?,?,?,?,?,?,?,?)',[$public,$username,'Demo',$name,$email,$course,'1st Year',$hash]);
                $id=(int)$db->lastInsertId();cbStoreAccountId($db,$role,$id,$public);
                cbQuery($db,'INSERT INTO classbook_access(role,account_id,status) VALUES(?,?,?)',[$role,$id,$status]);
                $manifest['accounts'][]=compact('role','id','username','status','public');return $id;
            };
            $admin=$add('admin','demo.admin','Demo Administrator','active');
            $add('admin','demo.admin.pending','Demo Pending Administrator','pending');
            $add('admin','demo.admin.inactive','Demo Inactive Administrator','inactive');
            $faculty=[];
            foreach(['coa','coe','cbae','ccs'] as $i=>$abbr)$faculty[]=$add('professor','demo.faculty.'.$abbr,'Demo '.strtoupper($abbr).' Professor','active',$i);
            $add('professor','demo.faculty.pending','Demo Pending Professor','pending',3);
            $add('professor','demo.faculty.inactive','Demo Inactive Professor','inactive',3);
            $students=[];$courses=['BS Information Technology','BS Accountancy','BS Education','BS Business Administration'];
            $names=['Aguilar','Bautista','Cruz','Diaz','Evangelista','Flores','Garcia','Herrera','Ignacio','Jimenez','Lim','Mendoza','Navarro','Ocampo','Perez','Reyes','Santos','Tan','Uy','Valdez','Villanueva','Yap','Zamora','Alvarez','Bernardo','Castro','Domingo','Estrada','Fernandez','Gonzales'];
            for($i=0;$i<30;$i++)$students[]=$add('student','demo.student'.sprintf('%02d',$i+1),$names[$i],$i<26?'active':($i<28?'pending':'inactive'),0,$courses[$i<8?0:($i<16?1:($i<23?2:3))]);
            $specs=[
                ['DEMO-IT101','Demo Programming Fundamentals',3,range(0,7),'draft',false],
                ['DEMO-ACC101','Demo Financial Accounting',0,range(8,15),'submitted',false],
                ['DEMO-ED101','Demo Foundations of Education',1,range(16,22),'released',false],
                ['DEMO-BA101','Demo Business Management',2,range(23,25),'released',false],
                ['DEMO-IT202','Demo Archived Programming',3,range(0,3),'draft',true],
            ];
            $start=date('Y-m-d',strtotime('-30 days'));
            foreach($specs as [$code,$title,$owner,$roster,$state,$archived]){
                cbQuery($db,'INSERT INTO subjects(professor_id,subject_code,subject_title,section,units,school_year,semester) VALUES(?,?,?,?,?,?,?)',[$faculty[$owner],$code,$title,'DEMO-1A',3,date('Y').'-'.(date('Y')+1),'1st Semester']);
                $subject=(int)$db->lastInsertId();$enrollments=[];
                foreach($roster as $index){cbQuery($db,'INSERT INTO enrollments(professor_id,subject_id,student_id,enrolled_at) VALUES(?,?,?,?)',[$faculty[$owner],$subject,$students[$index],$start.' 08:00:00']);$enrollments[]=(int)$db->lastInsertId();}
                cbInitialize($db,$subject);
                cbQuery($db,"UPDATE classbook_settings SET excused_mode='exclude',online_full_minutes=40,online_partial_minutes=20,archived=? WHERE subject_id=?",[(int)$archived,$subject]);
                cbQuery($db,'INSERT INTO classbook_policies(subject_id,policy_label) VALUES(?,?) ON DUPLICATE KEY UPDATE policy_label=VALUES(policy_label)',[$subject,'DEMO policy - sample only']);
                $criteria=cbQuery($db,'SELECT * FROM classbook_criteria WHERE subject_id=? ORDER BY id',[$subject])->fetchAll();
                $seededExams=[];foreach($criteria as $ci=>$criterion){
                    if($criterion['exam_key']!==null){if(isset($seededExams[$criterion['exam_key']]))continue;$seededExams[$criterion['exam_key']]=true;}
                    cbQuery($db,"UPDATE classbook_criteria SET excused_mode='exclude',aggregation=? WHERE id=?",[$ci%2?'equal':'pooled',$criterion['id']]);
                    if($criterion['kind']==='attendance'){
                        for($day=0;$day<3;$day++){
                            $online=$day===2;
                            cbQuery($db,'INSERT INTO classbook_meetings(criterion_id,meeting_date,title,source_key,source_json) VALUES(?,?,?,?,?)',[$criterion['id'],date('Y-m-d',strtotime('-'.(14-$day).' days')),$online?'Demo online attendance (sample)':'Demo face-to-face meeting '.($day+1),$online?hash('sha256','demo-'.$subject.'-'.$criterion['id']):null,$online?json_encode(['demo'=>true,'note'=>'Synthetic online durations; no live Google connection']):null]);
                            $meeting=(int)$db->lastInsertId();
                            foreach($enrollments as $n=>$enrollment){
                                $status=$day===0?'present':(['present','late','absent','excused'][$n%4]);
                                $duration=$online?match($status){'present'=>3000,'late'=>1500,'absent'=>300,default=>null}:null;
                                cbQuery($db,'INSERT INTO classbook_attendance(meeting_id,enrollment_id,status,duration_seconds,source_identity) VALUES(?,?,?,?,?)',[$meeting,$enrollment,$status,$duration,$online?'Demo attendance row '.($n+1):null]);
                            }
                        }
                    }else{
                        $max=str_contains(strtolower($criterion['name']),'recitation')?10:(str_contains(strtolower($criterion['name']),'quiz')?20:100);
                        for($item=0;$item<($criterion['exam_key']!==null?1:2);$item++){
                            cbQuery($db,'INSERT INTO classbook_activities(criterion_id,title,maximum,due_on) VALUES(?,?,?,?)',[$criterion['id'],$criterion['exam_key']!==null?CB_EXAMS[$criterion['exam_key']]:'Demo '.$criterion['name'].' '.($item+1),$max,date('Y-m-d',strtotime('-7 days'))]);$activity=(int)$db->lastInsertId();
                            foreach($enrollments as $n=>$enrollment){
                                $status='scored';$earned=round($max*[1,.88,.76,.64,.92,.48,.83,.70][$n%8],2);
                                if($item===1&&$n===3){$status='excused';$earned=null;}
                                if($code==='DEMO-IT101'&&$item===0){if($n===0){$status='not_graded';$earned=null;}elseif($n===1){$status='missing';$earned=null;}elseif($n===2)$earned=0;}
                                cbQuery($db,'INSERT INTO classbook_scores(activity_id,enrollment_id,status,earned) VALUES(?,?,?,?)',[$activity,$enrollment,$status,$earned]);
                            }
                        }
                    }
                }
                // One withdrawn student keeps their evidence for restore/history review.
                if($code==='DEMO-IT101')cbQuery($db,"UPDATE classbook_members SET status='dropped',reason='Demo withdrawal scenario' WHERE enrollment_id=?",[end($enrollments)]);
                cbValidateWeights($db,$subject);
                if($state!=='draft'){
                    cbQuery($db,"UPDATE classbook_settings SET state='submitted',revision=2 WHERE subject_id=?",[$subject]);
                    $snapshot=cbCompute($db,$subject);
                    foreach($snapshot['students'] as $result)if($result['course']===null)throw new RuntimeException('Incomplete demo release.');
                    if($state==='released'){
                        $snapshot['class']=cbClass($db,$subject,['role'=>'admin','id'=>$admin]);
                        cbQuery($db,'INSERT INTO classbook_releases(subject_id,revision,snapshot_json,actor_id) VALUES(?,?,?,?)',[$subject,2,json_encode($snapshot,JSON_THROW_ON_ERROR),$admin]);
                        cbQuery($db,"UPDATE classbook_settings SET state='released',revision=3 WHERE subject_id=?",[$subject]);
                    }
                }
                cbAudit($db,['role'=>'system','id'=>0],$subject,'demo_class_seeded',['demo'=>true,'state'=>$state,'archived'=>$archived]);
                $manifest['classes'][]=['id'=>$subject,'code'=>$code,'state'=>$state,'archived'=>$archived,'students'=>count($enrollments)];
            }
            cbAudit($db,['role'=>'system','id'=>0],null,'demo_seed_loaded',$manifest);
            $db->commit();return $manifest;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }finally{cbQuery($db,'SELECT RELEASE_LOCK(?)',[$lock]);}
}

if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    if(!in_array('--apply',$argv,true)){echo "Preview: append 3 demo administrators, 6 faculty, 30 students and 5 classes. Existing records stay unchanged. Use --apply after making a private backup.\n";exit;}
    $manifest=seedDemo(getDbConnection(),'GrcDemo!2026');
    echo json_encode($manifest,JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;
}
