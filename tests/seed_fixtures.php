<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
date_default_timezone_set('Asia/Manila');
foreach (['db', 'functions', 'departments', 'classbook', 'security', 'unified_auth'] as $dependency) {
    require_once __DIR__ . '/../includes/' . $dependency . '.php';
}

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

function cbDemoEmailLocal(string $name):string{
    $name=preg_replace('/^(?:(?:prof|dr|mr|mrs|ms)\.?\s+)+/i','',trim($name));
    $ascii=iconv('UTF-8','ASCII//TRANSLIT//IGNORE',$name);
    return rtrim(substr(trim(preg_replace('/[^a-z0-9]+/','.',strtolower($ascii===false?$name:$ascii)),'.'),0,50),'.')?:'school.account';
}

function cbSeedSchoolEmails(PDO $db):array{
    $db->beginTransaction();try{
        cbLockIdentifiers($db);
        $prior=cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='demo_school_emails_seeded' ORDER BY id DESC LIMIT 1")->fetchColumn();if($prior){$db->commit();return json_decode($prior,true,512,JSON_THROW_ON_ERROR);}
        $accounts=[];
        foreach(['demo_seed_loaded','demo_masterlist_seeded','realistic_demo_seeded'] as $action){
            $json=cbQuery($db,'SELECT detail_json FROM classbook_audit WHERE action=? ORDER BY id DESC LIMIT 1',[$action])->fetchColumn();if(!$json)continue;$m=json_decode($json,true,512,JSON_THROW_ON_ERROR);
            foreach($m['accounts']??[] as $a)if(in_array($a['role'],['professor','student'],true))$accounts[$a['role'].':'.$a['id']]=$a;
            foreach($m['students_added']??[] as $a){$a['role']='student';$accounts['student:'.$a['id']]=$a;}
        }
        if(!$accounts)throw new RuntimeException('Recorded demo accounts required.');
        $manifest=['domain'=>'grc.edu.ph','mailboxes_verified'=>false,'changed'=>[],'skipped'=>0];
        foreach($accounts as $a){
            $role=$a['role'];$table=cbIdentityTable($role);$id=(int)$a['id'];$row=cbQuery($db,'SELECT * FROM '.$table.' WHERE id=? FOR UPDATE',[$id])->fetch();
            if(!$row||$row['username']!==$a['username']||!preg_match('/^(?:demo|sample)\./',$row['username'])||!str_ends_with(strtolower((string)$row['email']),'@example.invalid')){$manifest['skipped']++;continue;}
            $name=$row['full_name']??($row['first_name'].' '.$row['last_name']);$local=cbDemoEmailLocal($name);$email=null;
            for($suffix=1;$suffix<=9999;$suffix++){
                $candidate=$local.($suffix===1?'':'.'.$suffix).'@grc.edu.ph';
                try{cbUniqueIdentifiers($db,$role,['email'=>$candidate],$id);$email=$candidate;break;}
                catch(RuntimeException $e){if(!str_contains($e->getMessage(),'already assigned'))throw $e;}
            }
            if($email===null)throw new RuntimeException('Could not allocate a unique sample email.');
            cbQuery($db,'UPDATE '.$table.' SET email=? WHERE id=?',[$email,$id]);cbQuery($db,'UPDATE classbook_account_meta SET revision=revision+1 WHERE role=? AND account_id=?',[$role,$id]);
            $manifest['changed'][]=['role'=>$role,'id'=>$id,'username'=>$row['username'],'name'=>$name,'before'=>$row['email'],'email'=>$email];
        }
        cbAudit($db,['role'=>'system','id'=>0],null,'demo_school_emails_seeded',$manifest);$db->commit();return $manifest;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function cbFixDemoExams(PDO $db):array{
    $db->beginTransaction();try{
        cbLockIdentifiers($db);
        $prior=cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='demo_shared_exams_fixed' ORDER BY id DESC LIMIT 1")->fetchColumn();if($prior){$db->commit();return json_decode($prior,true,512,JSON_THROW_ON_ERROR);}
        $seed=cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='realistic_demo_seeded' ORDER BY id DESC LIMIT 1")->fetchColumn();if(!$seed)throw new RuntimeException('Recorded realistic demo dataset required.');$seed=json_decode($seed,true,512,JSON_THROW_ON_ERROR);$manifest=['classes'=>[]];
        foreach($seed['classes'] as $record){
            $id=(int)$record['id'];$before=cbQuery($db,'SELECT * FROM classbook_settings WHERE subject_id=? FOR UPDATE',[$id])->fetch();if(!$before)throw new RuntimeException('Demo class missing.');
            cbTagDefaultExams($db,$id);$exams=[];
            foreach(cbActivities($db,$id) as $a)if($a['exam_key']!==null){$exams[$a['exam_key']]=(int)$a['id'];cbQuery($db,'UPDATE classbook_activities SET title=? WHERE id=?',[CB_EXAMS[$a['exam_key']],$a['id']]);}
            if(count($exams)!==3)throw new RuntimeException('Expected exactly three subject exams.');
            cbQuery($db,'UPDATE classbook_settings SET revision=revision+1 WHERE subject_id=?',[$id]);
            if($before['state']==='released'){
                cbQuery($db,"UPDATE classbook_settings SET state='submitted' WHERE subject_id=?",[$id]);$snapshot=cbCompute($db,$id);foreach($snapshot['students'] as $r)if($r['course']===null)throw new RuntimeException('Corrected demo publication is incomplete.');$snapshot['class']=cbClass($db,$id,['role'=>'admin','id'=>0]);
                cbQuery($db,'INSERT INTO classbook_releases(subject_id,revision,snapshot_json,actor_id) VALUES(?,?,?,0)',[$id,$snapshot['revision'],json_encode($snapshot,JSON_THROW_ON_ERROR)]);cbQuery($db,"UPDATE classbook_settings SET state='released',revision=revision+1 WHERE subject_id=?",[$id]);
            }
            cbAudit($db,['role'=>'system','id'=>0],$id,'demo_exam_corrected',['whole_subject_exams'=>$exams,'previous_revision'=>$before['revision'],'prior_rows_and_releases_retained'=>true]);$manifest['classes'][$id]=$exams;
        }
        cbAudit($db,['role'=>'system','id'=>0],null,'demo_shared_exams_fixed',$manifest);$db->commit();return $manifest;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}

function cbAlignPeriodDemo(PDO $db):array{
    $db->beginTransaction();try{
        cbLockIdentifiers($db);
        $prior=cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='demo_periods_aligned' ORDER BY id DESC LIMIT 1")->fetchColumn();if($prior){$db->commit();return json_decode($prior,true,512,JSON_THROW_ON_ERROR);}
        $seed=json_decode((string)cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='realistic_demo_seeded' ORDER BY id DESC LIMIT 1")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        $catalog=[
            'IT101'=>['ITFUND','IT Fundamentals',2],
            'IT102'=>['ITFUNDL','IT Fundamentals Laboratory',1],
            'IT202'=>['PROG2','Object-Oriented Programming',2],
            'ACC101'=>['ACTG101','Financial Accounting and Reporting 1',3],
            'BA101'=>['MGMT101','Principles of Management',3],
            'ED101'=>['EDUC101','The Child and Adolescent Learners and Learning Principles',3],
        ];$manifest=['classes'=>[],'source'=>'SMS_WebSys subject catalog; no enrollment-system data changed'];
        foreach($seed['classes']??[] as $record){
            $id=(int)$record['id'];$s=cbClass($db,$id,['role'=>'admin','id'=>0]);$old=$s;
            cbConvertToPeriods($db,$id);
            if(isset($catalog[$record['code']])&&$s['subject_code']===$record['code']){[$code,$title,$units]=$catalog[$record['code']];cbQuery($db,'UPDATE subjects SET subject_code=?,subject_title=?,units=? WHERE id=?',[$code,$title,$units,$id]);}
            if($old['state']==='released'){
                cbQuery($db,"UPDATE classbook_settings SET state='submitted' WHERE subject_id=?",[$id]);$snapshot=cbCompute($db,$id);foreach($snapshot['students'] as $r)if($r['course']===null)throw new RuntimeException('Demo publication requires complete period grades.');$snapshot['class']=cbClass($db,$id,['role'=>'admin','id'=>0]);
                cbQuery($db,'INSERT INTO classbook_releases(subject_id,revision,snapshot_json,actor_id) VALUES(?,?,?,0)',[$id,$snapshot['revision'],json_encode($snapshot,JSON_THROW_ON_ERROR)]);cbQuery($db,"UPDATE classbook_settings SET state='released',revision=revision+1 WHERE subject_id=?",[$id]);
            }
            cbAudit($db,['role'=>'system','id'=>0],$id,'demo_period_model_updated',['before_subject'=>$old,'retained_previous_component_evidence'=>true,'model'=>'periods']);$manifest['classes'][]=$id;
        }
        if(!$manifest['classes'])throw new RuntimeException('Recorded demo classes required.');
        cbAudit($db,['role'=>'system','id'=>0],null,'demo_periods_aligned',$manifest);$db->commit();return $manifest;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
