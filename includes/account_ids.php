<?php
declare(strict_types=1);

function cbIdentityTable(string $role): string {
    return match($role){'admin'=>'classbook_admins','professor'=>'professors','student'=>'students',default=>throw new InvalidArgumentException('Invalid account role.')};
}
function cbLockIdentifiers(PDO $db): void {
    if(!$db->inTransaction())throw new RuntimeException('Account ID allocation requires a transaction.');
    // One retained migration row serializes allocation and identifier changes across roles.
    cbQuery($db,'SELECT version FROM classbook_migrations WHERE version=2 FOR UPDATE')->fetchColumn();
}
function cbNextAccountId(PDO $db,string $role,?string $month=null): string {
    cbIdentityTable($role);cbLockIdentifiers($db);$month=$month??date('Y-m');
    if(!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D',$month))throw new InvalidArgumentException('Invalid account ID month.');
    $prefix=match($role){'professor'=>'PROF','student'=>'STUD','admin'=>'ADMIN'}.'-'.$month.'-';
    $last=cbQuery($db,'SELECT public_id FROM classbook_account_meta WHERE public_id LIKE ? ORDER BY public_id DESC LIMIT 1 FOR UPDATE',[$prefix.'%'])->fetchColumn();
    $next=$last?(int)substr($last,-5)+1:1;
    for(;$next<=99999;$next++){
        $candidate=$prefix.str_pad((string)$next,5,'0',STR_PAD_LEFT);$used=false;
        foreach(['admin','professor','student'] as $other){
            $where=match($other){'admin'=>'username=?','professor'=>'username=? OR employee_id=? OR email=?','student'=>'username=? OR student_no=? OR email=?'};
            if(cbQuery($db,'SELECT id FROM '.cbIdentityTable($other).' WHERE '.$where.' LIMIT 1 FOR UPDATE',array_fill(0,$other==='admin'?1:3,$candidate))->fetchColumn()){$used=true;break;}
        }
        if(!$used)return $candidate;
    }
    throw new RuntimeException('The monthly account ID range is full.');
}
function cbStoreAccountId(PDO $db,string $role,int $id,string $publicId): void {
    cbLockIdentifiers($db);
    cbQuery($db,'INSERT IGNORE INTO classbook_account_meta(role,account_id) VALUES(?,?)',[$role,$id]);
    cbQuery($db,'UPDATE classbook_account_meta SET public_id=? WHERE role=? AND account_id=? AND public_id IS NULL',[$publicId,$role,$id]);
}
function cbAccountPublicId(PDO $db,string $role,int $id): string {
    $existing=cbQuery($db,'SELECT public_id FROM classbook_account_meta WHERE role=? AND account_id=?',[$role,$id])->fetchColumn();
    if($existing)return $existing;
    $ownTransaction=!$db->inTransaction();if($ownTransaction)$db->beginTransaction();
    try{
        cbLockIdentifiers($db);
        $existing=cbQuery($db,'SELECT public_id FROM classbook_account_meta WHERE role=? AND account_id=? FOR UPDATE',[$role,$id])->fetchColumn();
        if(!$existing){
            $created=cbQuery($db,'SELECT created_at FROM '.cbIdentityTable($role).' WHERE id=?',[$id])->fetchColumn();
            if(!$created)throw new RuntimeException('Account not found.');
            $existing=cbNextAccountId($db,$role,substr($created,0,7));cbStoreAccountId($db,$role,$id,$existing);
        }
        if($ownTransaction)$db->commit();return $existing;
    }catch(Throwable $e){if($ownTransaction&&$db->inTransaction())$db->rollBack();throw $e;}
}
function cbBackfillAccountIds(PDO $db): void {
    foreach(['admin','professor','student'] as $role){
        $rows=cbQuery($db,'SELECT a.id FROM '.cbIdentityTable($role).' a LEFT JOIN classbook_account_meta m ON m.role=? AND m.account_id=a.id WHERE m.public_id IS NULL ORDER BY a.created_at,a.id',[$role])->fetchAll();
        foreach($rows as $row)cbAccountPublicId($db,$role,(int)$row['id']);
    }
}
