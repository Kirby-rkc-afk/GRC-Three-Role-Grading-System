<?php
require_once __DIR__.'/includes/portal.php';$db=portalDb();$error='';
if($_SERVER['REQUEST_METHOD']==='POST')try{
    portalCsrf();$identifier=cbText($_POST['identifier']??'',190);$token=cbText($_POST['token']??'',100);$password=(string)($_POST['password']??'');cbPassword($password,(string)($_POST['confirm_password']??''));
    $db->beginTransaction();
    $row=cbQuery($db,'SELECT * FROM classbook_tokens WHERE token_hash=? AND used_at IS NULL AND expires_at>NOW() FOR UPDATE',[hash('sha256',$token)])->fetch();
    if(!$row)throw new RuntimeException('The one-time code is invalid, expired or already used. Contact the Registrar.');
    $role=$row['role'];$table=match($role){'student'=>'students','professor'=>'professors','admin'=>'classbook_admins',default=>throw new RuntimeException('Invalid activation code.')};
    $where=match($role){'admin'=>'username=?','professor'=>'username=? OR employee_id=? OR email=?','student'=>'username=? OR student_no=? OR email=?'};
    $args=array_merge([$row['account_id']],array_fill(0,$role==='admin'?1:3,$identifier),[$role,$identifier]);
    $account=cbQuery($db,'SELECT id FROM '.$table.' WHERE id=? AND (('.$where.') OR id IN (SELECT account_id FROM classbook_account_meta WHERE role=? AND public_id=?))',$args)->fetchColumn();
    if(!$account)throw new RuntimeException('The account identifier does not match this activation code.');
    if(cbStatus($db,$role,(int)$account)==='inactive')throw new RuntimeException('The account is deactivated. Contact the Registrar.');
    cbQuery($db,'UPDATE '.$table.' SET password_hash=? WHERE id=?',[password_hash($password,PASSWORD_DEFAULT),$account]);cbQuery($db,'UPDATE classbook_tokens SET used_at=NOW() WHERE id=?',[$row['id']]);
    cbQuery($db,"INSERT INTO classbook_access(role,account_id,status) VALUES(?,?,'active') ON DUPLICATE KEY UPDATE status='active'",[$role,$account]);cbAudit($db,['role'=>$role,'id'=>(int)$account],null,'one_time_password_set',[]);$db->commit();portalRedirect('index.php','Password set. Your one-time code has been consumed. Sign in with your own password.');
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=portalError($e);}
portalStart('Activate or recover account');portalHeading('Activate or recover your account','Obtain a one-time code from the Registrar after identity verification.');if($error)portalNotice($error,'danger');?><section class="card narrow"><form method="post"><?=csrfField()?><?php portalField('Username, school ID or email','identifier','','text','required');portalField('Registrar-issued one-time code','token','','text','required autocomplete="off"');portalField('New password','password','','password','required minlength="12" autocomplete="new-password"');portalField('Confirm password','confirm_password','','password','required minlength="12" autocomplete="new-password"');?><button>Set my password</button></form><a href="index.php">Back to sign in</a></section><?php portalEnd();
