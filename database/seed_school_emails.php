<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/seed_demo.php';

/** Enrollment's seed-domain convention; these do not provision real mailboxes. */
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
            // An edited/non-demo address belongs to its owner and must stay unchanged.
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
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){
    if(!in_array('--apply',$argv,true)){echo "Preview: replace recorded demo placeholder emails with unique name-based @grc.edu.ph sample addresses. Existing custom emails and login usernames stay unchanged. This does not provision mailboxes. Back up privately before --apply.\n";exit;}
    $m=cbSeedSchoolEmails(getDbConnection());echo count($m['changed'])." recorded sample addresses assigned; ".$m['skipped']." custom or unavailable accounts preserved.\n";
}
