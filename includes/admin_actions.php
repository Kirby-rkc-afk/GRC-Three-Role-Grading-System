<?php
declare(strict_types=1);
function cbAccountTable(string $role): string {
    return cbIdentityTable($role);
}
function cbAccountRevision(PDO $db,string $role,int $id): int {
    return (int)(cbQuery($db,'SELECT revision FROM classbook_account_meta WHERE role=? AND account_id=?',[$role,$id])->fetchColumn()?:1);
}
function cbAdminAccount(PDO $db,array $u,string $action,array $p): ?string {
    if($u['role']!=='admin')throw new RuntimeException('Registrar access required.');
    $role=(string)($p['role']??'');$table=cbAccountTable($role);$id=(int)($p['account_id']??0);$issued=null;$db->beginTransaction();
    try{
        cbLockIdentifiers($db);
        // Serialize administrator access changes so two requests cannot remove the last active Registrar.
        cbQuery($db,'SELECT id FROM classbook_admins ORDER BY id FOR UPDATE')->fetchAll();
        if($action==='create_account'){
            $publicId=cbNextAccountId($db,$role);
            if($role==='professor')$p['employee_id']=$publicId;
            if($role==='student')$p['student_no']=$publicId;
            if(!isset($p['username'])||$p['username']==='')$p['username']=$publicId;
            cbUniqueIdentifiers($db,$role,$p);
            $hash=password_hash(bin2hex(random_bytes(32)),PASSWORD_DEFAULT);
            if($role==='student'){
                $email=trim((string)($p['email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid email.');
                cbQuery($db,'INSERT INTO students(student_no,username,first_name,last_name,email,course,year_level,password_hash) VALUES(?,?,?,?,?,?,?,?)',[cbText($p['student_no']??'',50),cbText($p['username']??'',50),cbText($p['first_name']??'',100),cbText($p['last_name']??'',100),$email,cbText($p['course']??'',100),cbText($p['year_level']??'',30),$hash]);
            }elseif($role==='professor'){
                $email=cbText($p['email']??'',190);if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('A valid faculty email is required.');
                cbQuery($db,'INSERT INTO professors(employee_id,username,full_name,email,department,password_hash) VALUES(?,?,?,?,?,?)',[cbText($p['employee_id']??'',50),cbText($p['username']??'',50),cbText($p['full_name']??''),$email,cbDepartment($p['department']??''),$hash]);
            }else cbQuery($db,'INSERT INTO classbook_admins(username,full_name,password_hash) VALUES(?,?,?)',[cbText($p['username']??'',80),cbText($p['full_name']??''),$hash]);
            $id=(int)$db->lastInsertId();cbQuery($db,"INSERT INTO classbook_access(role,account_id,status) VALUES(?,?,'pending')",[$role,$id]);
            cbStoreAccountId($db,$role,$id,$publicId);
        }else{
            $old=cbQuery($db,"SELECT * FROM $table WHERE id=? FOR UPDATE",[$id])->fetch();if(!$old)throw new RuntimeException('Account not found.');
            cbQuery($db,'INSERT IGNORE INTO classbook_account_meta(role,account_id) VALUES(?,?)',[$role,$id]);
            if((int)($p['account_revision']??0)!==cbAccountRevision($db,$role,$id))throw new RuntimeException('This account changed. Reload before saving.');
            if($action==='edit_account'){
                $publicId=cbAccountPublicId($db,$role,$id);
                $schoolKey=$role==='student'?'student_no':($role==='professor'?'employee_id':null);
                if($schoolKey&&$old[$schoolKey]===$publicId)$p[$schoolKey]=$old[$schoolKey];
                cbUniqueIdentifiers($db,$role,$p,$id);
                $reason=cbText($p['reason']??'',500);
                if($role==='student'){
                    $email=trim((string)($p['email']??''));if($email!==''&&!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid email.');
                    cbQuery($db,'UPDATE students SET student_no=?,username=?,first_name=?,last_name=?,email=?,course=?,year_level=? WHERE id=?',[cbText($p['student_no']??'',50),cbText($p['username']??'',50),cbText($p['first_name']??'',100),cbText($p['last_name']??'',100),$email,cbText($p['course']??'',100),cbText($p['year_level']??'',30),$id]);
                }elseif($role==='professor'){
                    $email=cbText($p['email']??'',190);if(!filter_var($email,FILTER_VALIDATE_EMAIL))throw new RuntimeException('Invalid email.');
                    cbQuery($db,'UPDATE professors SET employee_id=?,username=?,full_name=?,email=?,department=? WHERE id=?',[cbText($p['employee_id']??'',50),cbText($p['username']??'',50),cbText($p['full_name']??''),$email,cbDepartment($p['department']??''),$id]);
                }else cbQuery($db,'UPDATE classbook_admins SET username=?,full_name=? WHERE id=?',[cbText($p['username']??'',80),cbText($p['full_name']??''),$id]);
                unset($old['password_hash']);$detail=['before'=>$old,'reason'=>$reason];
            }elseif($action==='issue_code'){
                if($role==='admin'&&$id===(int)$u['id'])throw new RuntimeException('Use Profile to change your own password. Another Registrar can issue recovery codes.');
                $issued=bin2hex(random_bytes(16));cbQuery($db,'UPDATE classbook_tokens SET used_at=NOW() WHERE role=? AND account_id=? AND used_at IS NULL',[$role,$id]);cbQuery($db,'INSERT INTO classbook_tokens(role,account_id,token_hash,expires_at) VALUES(?,?,?,DATE_ADD(NOW(),INTERVAL 24 HOUR))',[$role,$id,hash('sha256',$issued)]);
            }elseif(in_array($action,['approve','activate','deactivate'],true)){
                if($action==='deactivate'&&$role==='admin'){
                    if($id===(int)$u['id'])throw new RuntimeException('You cannot deactivate your own Registrar account.');
                    $active=cbQuery($db,"SELECT a.id FROM classbook_admins a LEFT JOIN classbook_access x ON x.role='admin' AND x.account_id=a.id WHERE COALESCE(x.status,'active')='active'")->fetchAll();
                    if(count($active)<=1&&cbStatus($db,$role,$id)==='active')throw new RuntimeException('At least one active Registrar must remain.');
                }
                $reason=cbText($p['reason']??'',500);$status=$action==='deactivate'?'inactive':'active';$oldStatus=cbStatus($db,$role,$id);
                cbQuery($db,'INSERT INTO classbook_access(role,account_id,status) VALUES(?,?,?) ON DUPLICATE KEY UPDATE status=VALUES(status)',[$role,$id,$status]);$detail=['before_status'=>$oldStatus,'reason'=>$reason,'status'=>$status];
            }else throw new RuntimeException('Unknown account action.');
            cbQuery($db,'UPDATE classbook_account_meta SET revision=revision+1 WHERE role=? AND account_id=?',[$role,$id]);
        }
        cbAudit($db,$u,null,$action,['role'=>$role,'account_id'=>$id]+($detail??[]));$db->commit();return $issued;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
function cbAdminClass(PDO $db,array $u,string $action,array $p): void {
    if($u['role']!=='admin')throw new RuntimeException('Registrar access required.');$id=(int)($p['subject_id']??0);
    if($action==='release'){cbRelease($db,$id,$u,(int)($p['revision']??0));return;}
    $reason=cbText($p['reason']??'',500);
    if($action==='initialize_legacy'){
        $db->beginTransaction();try{
            if(!cbQuery($db,'SELECT id FROM subjects WHERE id=? FOR UPDATE',[$id])->fetchColumn())throw new RuntimeException('Class not found.');
            if(cbQuery($db,'SELECT subject_id FROM classbook_settings WHERE subject_id=?',[$id])->fetchColumn())throw new RuntimeException('This class is already initialized.');
            cbInitialize($db,$id);cbAudit($db,$u,$id,'legacy_initialized',['reason'=>$reason,'legacy_totals_preserved'=>true]);$db->commit();return;
        }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
    }
    cbWrite($db,$id,$u,(int)($p['revision']??0),function($s)use($db,$u,$action,$id,$p,$reason){
        if($action==='return'){
            if(!in_array($s['state'],['submitted','released'],true))throw new RuntimeException('Only submitted or released sheets can be returned.');
            cbQuery($db,"UPDATE classbook_settings SET state='draft' WHERE subject_id=?",[$id]);
        }elseif($action==='reassign'){
            if($s['state']!=='draft'||$s['archived'])throw new RuntimeException('Return and unarchive this class before reassigning it.');
            $next=(int)($p['professor_id']??0);if(!cbQuery($db,'SELECT id FROM professors WHERE id=?',[$next])->fetchColumn()||cbStatus($db,'professor',$next)!=='active')throw new RuntimeException('Choose an active professor.');
            // Use the same mutex as creation/editing, and inspect the latest committed offering.
            cbLockIdentifiers($db);
            if(cbQuery($db,'SELECT id FROM subjects WHERE id<>? AND professor_id=? AND subject_code=? AND section=? AND school_year=? AND semester=? FOR UPDATE',[$id,$next,$s['subject_code'],$s['section'],$s['school_year'],$s['semester']])->fetchColumn())throw new RuntimeException('That professor already has this offering.');
            cbQuery($db,'UPDATE subjects SET professor_id=? WHERE id=?',[$next,$id]);cbQuery($db,'UPDATE enrollments SET professor_id=? WHERE subject_id=?',[$next,$id]);
        }elseif(in_array($action,['archive_class','unarchive_class'],true)){
            if($s['state']==='submitted')throw new RuntimeException('Release or return the submitted sheet first.');
            cbQuery($db,'UPDATE classbook_settings SET archived=? WHERE subject_id=?',[$action==='archive_class'?1:0,$id]);
        }else throw new RuntimeException('Unknown class action.');
        cbAudit($db,$u,$id,$action,['reason'=>$reason,'previous_professor'=>$s['professor_id'],'new_professor'=>$p['professor_id']??null]);
    },true);
}
