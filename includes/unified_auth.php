<?php
declare(strict_types=1);
/** Resolve verified credentials across roles; reject ambiguous credentials. */
function cbResolveLogin(PDO $db,string $identifier,string $password): ?array {
    $matches=[];
    foreach(['admin'=>'classbook_admins','professor'=>'professors','student'=>'students'] as $role=>$table){
        $where=match($role){'admin'=>'username=?','professor'=>'username=? OR employee_id=? OR email=?','student'=>'username=? OR student_no=? OR email=?'};
        $accounts=cbQuery($db,'SELECT * FROM '.$table.' WHERE ('.$where.') OR id IN (SELECT account_id FROM classbook_account_meta WHERE role=? AND public_id=?)',array_merge(array_fill(0,$role==='admin'?1:3,$identifier),[$role,$identifier]))->fetchAll();
        if(!$accounts)password_verify($password,'$2y$10$92IXUNpkjO0rOQ5byMi.Ye4oKoEa3Ro9llC/.og/at2uheWG/igi');
        foreach($accounts as $account){
            if(password_verify($password,$account['password_hash']))$matches[]=['role'=>$role,'account'=>$account];
        }
    }
    if(count($matches)>1)throw new RuntimeException('This sign-in matches more than one account. Contact the Registrar for a unique username.');
    return $matches[0]??null;
}
function cbUniqueIdentifiers(PDO $db,string $role,array $fields,int $ownId=0): void {
    if($db->inTransaction())cbLockIdentifiers($db);
    $lock=$db->inTransaction()?' FOR UPDATE':'';
    $keys=match($role){'admin'=>['username'],'professor'=>['username','employee_id','email'],'student'=>['username','student_no','email'],default=>throw new RuntimeException('Invalid account role.')};
    foreach($keys as $key){$raw=$fields[$key]??'';if(!is_string($raw))throw new InvalidArgumentException('Enter a valid account identifier.');$value=trim($raw);if($value==='')continue;
        if(($error=cbIdentifierError($value))!==null)throw new InvalidArgumentException($error);
        if(cbQuery($db,'SELECT account_id FROM classbook_account_meta WHERE public_id=? AND NOT(role=? AND account_id=?)'.$lock,[$value,$role,$ownId])->fetchColumn())throw new RuntimeException('That account ID is already assigned to another user.');
        foreach(['admin'=>'classbook_admins','professor'=>'professors','student'=>'students'] as $other=>$table){
            $where=match($other){'admin'=>'username=?','professor'=>'username=? OR employee_id=? OR email=?','student'=>'username=? OR student_no=? OR email=?'};
            $args=array_fill(0,$other==='admin'?1:3,$value);$exclude=$other===$role?' AND id<>?':'';if($exclude)$args[]=$ownId;
            if(cbQuery($db,'SELECT id FROM '.$table.' WHERE ('.$where.')'.$exclude.' LIMIT 1'.$lock,$args)->fetchColumn())throw new RuntimeException('That username, school ID or email is already assigned to another account. Use a unique sign-in identifier.');
        }
    }
}
