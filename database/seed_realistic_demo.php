<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/seed_masterlist_demo.php';

/** Fictional classroom dataset; only records named in earlier demo manifests are revised. */
function seedRealisticDemo(PDO $db):array{
    $db->beginTransaction();
    try{
        cbLockIdentifiers($db);
        $prior=cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='realistic_demo_seeded' ORDER BY id DESC LIMIT 1")->fetchColumn();
        if($prior){$db->commit();return json_decode($prior,true,512,JSON_THROW_ON_ERROR);}
        $base=json_decode((string)cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='demo_seed_loaded' ORDER BY id DESC LIMIT 1")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $extra=json_decode((string)cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='demo_masterlist_seeded' ORDER BY id DESC LIMIT 1")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $first=['Miguel Angelo','Isabella Mae','Gabriel Luis','Sofia Nicole','Juan Carlo','Andrea Louise','Joshua Daniel','Bianca Marie','Rafael James','Camille Anne','Paolo Vincent','Patricia Faith','Nathaniel Joseph','Alyssa Joy','Mark Anthony','Jasmine Claire'];
        $last=['Reyes','Santos','Dela Cruz','Garcia','Mendoza','Ramos','Torres','Flores','Navarro','Bautista','Villanueva','Aquino','Castillo','Fernandez','Mercado','Gonzales','Soriano','Domingo','Castro','Valdez','Pascual','Rivera','Lim','Salazar'];
        $names=fn(int $n)=>[$first[($n+intdiv($n,count($last)))%count($first)],$last[$n%count($last)]];
        $manifest=['dataset'=>'grc-realistic-v1','accounts'=>[],'classes'=>[],'fictional'=>true];$hash=password_hash('GrcDemo!2026',PASSWORD_DEFAULT);
        foreach($base['accounts'] as $a){
            $name=match($a['username']){'demo.admin'=>'Regina Mae Castillo','demo.admin.pending'=>'Arvin Jose Santiago','demo.admin.inactive'=>'Leah Patricia Ramos','demo.faculty.pending'=>'Prof. Kristine Joy Fernandez','demo.faculty.inactive'=>'Prof. Mark Anthony Ramos',default=>null};
            if($name!==null){$table=cbIdentityTable($a['role']);$old=cbQuery($db,'SELECT full_name FROM '.$table.' WHERE id=? AND username=?',[$a['id'],$a['username']])->fetchColumn();if(!is_string($old)||!str_starts_with($old,'Demo '))throw new RuntimeException('A demo profile was edited; preserve it.');cbQuery($db,'UPDATE '.$table.' SET full_name=? WHERE id=?',[$name,$a['id']]);cbQuery($db,'UPDATE classbook_account_meta SET revision=revision+1 WHERE role=? AND account_id=?',[$a['role'],$a['id']]);}
        }
        $studentIds=[];
        foreach($base['accounts'] as $a)if($a['role']==='student')$studentIds[]=(int)$a['id'];
        foreach($extra['students_added'] as $a)$studentIds[]=(int)$a['id'];
        foreach($studentIds as $n=>$student){
            $old=cbQuery($db,'SELECT * FROM students WHERE id=?',[$student])->fetch();
            if(!$old||$old['first_name']!=='Demo'||!str_starts_with($old['username'],'demo.student'))throw new RuntimeException('A demo student was edited. Preserve the edit and review before reseeding.');
            [$given,$surname]=$names($n);
            cbQuery($db,'UPDATE students SET first_name=?,last_name=? WHERE id=?',[$given,$surname,$student]);
            cbQuery($db,"UPDATE classbook_account_meta SET revision=revision+1 WHERE role='student' AND account_id=?",[$student]);
        }
        $primary=[];$secondary=[];$departments=array_keys(CB_DEPARTMENTS);
        $facultyNames=['Dr. Elena Patricia Reyes','Prof. Rafael Antonio Mendoza','Prof. Catherine Louise Santos','Prof. Adrian Miguel Dela Cruz'];
        $otherNames=['Prof. Victor Emmanuel Garcia','Dr. Marissa Anne Bautista','Prof. Daniel Jose Navarro','Prof. Angela Marie Villanueva'];
        foreach(['coa','coe','cbae','ccs'] as $i=>$abbr){
            $source=null;foreach($base['accounts'] as $a)if($a['username']==='demo.faculty.'.$abbr)$source=$a;
            if(!$source)throw new RuntimeException('Demo faculty manifest missing.');$id=(int)$source['id'];
            $old=cbQuery($db,'SELECT * FROM professors WHERE id=?',[$id])->fetch();
            if(!$old||!str_starts_with($old['full_name'],'Demo ')||$old['username']!==$source['username'])throw new RuntimeException('A demo professor was edited; preserve it.');
            cbQuery($db,'UPDATE professors SET full_name=? WHERE id=?',[$facultyNames[$i],$id]);
            cbQuery($db,"UPDATE classbook_account_meta SET revision=revision+1 WHERE role='professor' AND account_id=?",[$id]);$primary[$abbr]=$id;
            $username='demo.faculty.'.$abbr.'2';$public=cbNextAccountId($db,'professor');$email=$username.'@example.invalid';
            cbUniqueIdentifiers($db,'professor',['username'=>$username,'employee_id'=>$public,'email'=>$email]);
            cbQuery($db,'INSERT INTO professors(employee_id,username,full_name,email,password_hash,department) VALUES(?,?,?,?,?,?)',[$public,$username,$otherNames[$i],$email,$hash,$departments[$i]]);$id=(int)$db->lastInsertId();
            cbStoreAccountId($db,'professor',$id,$public);cbQuery($db,"INSERT INTO classbook_access(role,account_id,status) VALUES('professor',?,'active')",[$id]);$secondary[$abbr]=$id;
            $manifest['accounts'][]=['role'=>'professor','id'=>$id,'username'=>$username,'public'=>$public];
        }
        // Each block section (-1A and -1B) gets its own distinct 40-student cohort so no student has duplicate subjects.
        $cohorts=[];$n=count($studentIds);$serial=1;
        foreach(['ccs'=>'BS Information Technology','coa'=>'BS Accountancy','coe'=>'BS Education','cbae'=>'BS Business Administration'] as $abbr=>$course){
            $idsA=[];foreach($studentIds as $student){$row=cbQuery($db,'SELECT course FROM students WHERE id=?',[$student])->fetch();if($row['course']===$course&&cbStatus($db,'student',$student)==='active')$idsA[]=$student;}
            while(count($idsA)<40){
                $username='sample.student'.sprintf('%03d',$serial++);$public=cbNextAccountId($db,'student');$email=$username.'@example.invalid';cbUniqueIdentifiers($db,'student',['username'=>$username,'student_no'=>$public,'email'=>$email]);[$given,$surname]=$names($n++);
                cbQuery($db,'INSERT INTO students(student_no,username,first_name,last_name,email,course,year_level,password_hash) VALUES(?,?,?,?,?,?,?,?)',[$public,$username,$given,$surname,$email,$course,'1st Year',$hash]);$id=(int)$db->lastInsertId();cbStoreAccountId($db,'student',$id,$public);cbQuery($db,"INSERT INTO classbook_access(role,account_id,status) VALUES('student',?,'active')",[$id]);$idsA[]=$id;
                $manifest['accounts'][]=['role'=>'student','id'=>$id,'username'=>$username,'public'=>$public];
            }
            $idsB=[];
            while(count($idsB)<40){
                $username='sample.student'.sprintf('%03d',$serial++);$public=cbNextAccountId($db,'student');$email=$username.'@example.invalid';cbUniqueIdentifiers($db,'student',['username'=>$username,'student_no'=>$public,'email'=>$email]);[$given,$surname]=$names($n++);
                cbQuery($db,'INSERT INTO students(student_no,username,first_name,last_name,email,course,year_level,password_hash) VALUES(?,?,?,?,?,?,?,?)',[$public,$username,$given,$surname,$email,$course,'1st Year',$hash]);$id=(int)$db->lastInsertId();cbStoreAccountId($db,'student',$id,$public);cbQuery($db,"INSERT INTO classbook_access(role,account_id,status) VALUES('student',?,'active')",[$id]);$idsB[]=$id;
                $manifest['accounts'][]=['role'=>'student','id'=>$id,'username'=>$username,'public'=>$public];
            }
            $cohorts[$abbr]=[array_slice($idsA,0,40),$idsB];
        }
        $specs=[['DEMO-IT101','IT101','Introduction to Computing','ccs'],['DEMO-ACC101','ACC101','Financial Accounting and Reporting','coa'],['DEMO-ED101','ED101','The Teaching Profession','coe'],['DEMO-BA101','BA101','Principles of Management','cbae'],['DEMO-IT202','IT202','Object-Oriented Programming','ccs'],['DEMO-IT102','IT102','Web Development Fundamentals','ccs']];
        $allowed=array_column($base['classes'],'id');$allowed=array_merge($allowed,$extra['classes']);
        foreach($specs as [$oldCode,$code,$title,$abbr]){
            $source=null;foreach(array_unique($allowed) as $id){$row=cbQuery($db,'SELECT s.*,c.state,c.archived,c.revision FROM subjects s JOIN classbook_settings c ON c.subject_id=s.id WHERE s.id=?',[$id])->fetch();if($row&&$row['subject_code']===$oldCode)$source=$row;}
            if(!$source||!str_starts_with($source['subject_title'],'Demo ')||(int)$source['professor_id']!==$primary[$abbr])throw new RuntimeException('A demo class was edited; preserve it.');
            $prefix=match($abbr){'ccs'=>'BSIT','coa'=>'BSA','coe'=>'BSED',default=>'BSBA'};
            cbQuery($db,'UPDATE subjects SET subject_code=?,subject_title=?,section=? WHERE id=?',[$code,$title,$prefix.'-1A',$source['id']]);
            $offerings=[(int)$source['id']];
            cbQuery($db,'INSERT INTO subjects(professor_id,subject_code,subject_title,section,units,school_year,semester) VALUES(?,?,?,?,?,?,?)',[$secondary[$abbr],$code,$title,$prefix.'-1B',$source['units'],$source['school_year'],$source['semester']]);$offerings[]=(int)$db->lastInsertId();
            foreach($offerings as $oi=>$subject){
                $owner=$oi?$secondary[$abbr]:$primary[$abbr];
                foreach($cohorts[$abbr][$oi] as $student){
                    if(!cbQuery($db,'SELECT id FROM enrollments WHERE subject_id=? AND student_id=?',[$subject,$student])->fetchColumn()){
                        cbQuery($db,'INSERT INTO enrollments(professor_id,subject_id,student_id,enrolled_at) VALUES(?,?,?,?)',[$owner,$subject,$student,date('Y-m-d',strtotime('-30 days')).' 08:00:00']);$eid=(int)$db->lastInsertId();cbQuery($db,'INSERT INTO classbook_members(enrollment_id,starts_on) VALUES(?,?)',[$eid,date('Y-m-d',strtotime('-30 days'))]);
                    }
                }
                cbInitialize($db,$subject);cbQuery($db,"UPDATE classbook_settings SET excused_mode='exclude',archived=?,online_full_minutes=40,online_partial_minutes=20 WHERE subject_id=?",[$source['archived'],$subject]);
                cbQuery($db,"INSERT INTO classbook_policies(subject_id,policy_label) VALUES(?,'Fictional classroom demonstration') ON DUPLICATE KEY UPDATE policy_label=VALUES(policy_label)",[$subject]);
                $members=cbMembers($db,$subject,false);
                $seededExams=[];foreach(cbQuery($db,'SELECT * FROM classbook_criteria WHERE subject_id=?',[$subject])->fetchAll() as $ci=>$criterion){
                    if($criterion['exam_key']!==null){if(isset($seededExams[$criterion['exam_key']]))continue;$seededExams[$criterion['exam_key']]=true;}
                    cbQuery($db,"UPDATE classbook_criteria SET excused_mode='exclude' WHERE id=?",[$criterion['id']]);
                    if($criterion['kind']==='attendance'){
                        $items=cbQuery($db,'SELECT * FROM classbook_meetings WHERE criterion_id=?',[$criterion['id']])->fetchAll();
                        if(!$items){for($day=0;$day<3;$day++)cbQuery($db,'INSERT INTO classbook_meetings(criterion_id,meeting_date,title) VALUES(?,?,?)',[$criterion['id'],date('Y-m-d',strtotime('-'.(14-$day).' days')),'Class meeting '.($day+1)]);$items=cbQuery($db,'SELECT * FROM classbook_meetings WHERE criterion_id=?',[$criterion['id']])->fetchAll();}
                        foreach($items as $day=>$item){cbQuery($db,'UPDATE classbook_meetings SET title=? WHERE id=?',[$day===2?'Online discussion — sample duration':'Class discussion '.($day+1),$item['id']]);foreach($members as $m){$r=((int)$m['student_id']+$day+$oi)%13;$status=$r===0?'absent':($r===1?'late':'present');cbQuery($db,'INSERT IGNORE INTO classbook_attendance(meeting_id,enrollment_id,status,duration_seconds) VALUES(?,?,?,?)',[$item['id'],$m['enrollment_id'],$status,$day===2?($status==='present'?2700:($status==='late'?1500:300)):null]);}}
                    }else{
                        $items=cbQuery($db,'SELECT * FROM classbook_activities WHERE criterion_id=?',[$criterion['id']])->fetchAll();
                        if(!$items){$max=str_contains(strtolower($criterion['name']),'recitation')?10:(str_contains(strtolower($criterion['name']),'quiz')?20:100);for($i=1;$i<=($criterion['exam_key']!==null?1:2);$i++)cbQuery($db,'INSERT INTO classbook_activities(criterion_id,title,maximum,due_on) VALUES(?,?,?,?)',[$criterion['id'],$criterion['name'].' '.$i,$max,date('Y-m-d',strtotime('-7 days'))]);$items=cbQuery($db,'SELECT * FROM classbook_activities WHERE criterion_id=?',[$criterion['id']])->fetchAll();}
                        foreach($items as $i=>$item){cbQuery($db,'UPDATE classbook_activities SET title=? WHERE id=?',[$criterion['exam_key']!==null?CB_EXAMS[$criterion['exam_key']]:$criterion['name'].' '.($i+1),$item['id']]);foreach($members as $m){$ratio=(58+(((int)$m['student_id']*7+$ci*3+$i*11+$oi*5)%43))/100;cbQuery($db,"INSERT IGNORE INTO classbook_scores(activity_id,enrollment_id,status,earned) VALUES(?,?,'scored',?)",[$item['id'],$m['enrollment_id'],round((float)$item['maximum']*$ratio,2)]);}}
                    }
                }
                cbValidateWeights($db,$subject);$state=$source['state'];
                cbQuery($db,'UPDATE classbook_settings SET state=?,revision=revision+1 WHERE subject_id=?',[$state==='released'?'submitted':$state,$subject]);
                if($state!=='draft')foreach(cbCompute($db,$subject)['students'] as $r)if($r['course']===null)throw new RuntimeException('Seeded submitted grade is incomplete.');
                if($state==='released'){
                    $snapshot=cbCompute($db,$subject);$snapshot['class']=cbClass($db,$subject,['role'=>'admin','id'=>0]);cbQuery($db,'INSERT INTO classbook_releases(subject_id,revision,snapshot_json,actor_id) VALUES(?,?,?,?)',[$subject,$snapshot['revision'],json_encode($snapshot,JSON_THROW_ON_ERROR),0]);cbQuery($db,"UPDATE classbook_settings SET state='released',revision=revision+1 WHERE subject_id=?",[$subject]);
                }
                $manifest['classes'][]=['id'=>$subject,'code'=>$code,'professor_id'=>$owner,'section'=>$prefix.($oi?'-1B':'-1A')];
            }
        }
        cbAudit($db,['role'=>'system','id'=>0],null,'realistic_demo_seeded',$manifest);$db->commit();return $manifest;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){if(!in_array('--apply',$argv,true)){echo "Preview: revise only the recorded demo dataset into fictional names, coherent cohorts, two professors per subject and varied score evidence. Back up privately before --apply.\n";exit;}echo json_encode(seedRealisticDemo(getDbConnection()),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;}
