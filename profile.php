<?php
require_once __DIR__.'/includes/portal.php';$db=portalDb();$u=getCurrentUser();if(!$u)portalRedirect('index.php');$u=portalUser($db,$u['role']);$error='';
require_once __DIR__.'/includes/admin_actions.php';
if($_SERVER['REQUEST_METHOD']==='POST')try{
    portalCsrf();
    if(($_POST['action']??'')==='save_profile'){
        if($u['role']!=='admin')throw new RuntimeException('Only administrators can update their own institutional identity here.');
        $username=is_string($_POST['username']??null)?trim($_POST['username']):'';
        if($username!==$u['username']){
            $currentHash=cbQuery($db,'SELECT password_hash FROM classbook_admins WHERE id=?',[$u['id']])->fetchColumn();
            if(!password_verify(is_string($_POST['identity_password']??null)?$_POST['identity_password']:'',$currentHash))throw new RuntimeException('Enter your current password to change your sign-in username.');
        }
        cbAdminAccount($db,$u,'edit_account',[
            'role'=>'admin','account_id'=>$u['id'],'account_revision'=>$_POST['account_revision']??0,
            'full_name'=>is_string($_POST['full_name']??null)?$_POST['full_name']:'','username'=>$username,
            'reason'=>'Administrator updated their own account settings.',
        ]);
        portalRedirect('profile.php','Account settings saved.');
    }
    $table=match($u['role']){'professor'=>'professors','student'=>'students','admin'=>'classbook_admins'};
    $db->beginTransaction();
    $hash=cbQuery($db,'SELECT password_hash FROM '.$table.' WHERE id=? FOR UPDATE',[$u['id']])->fetchColumn();$password=(string)($_POST['password']??'');
    cbPassword($password,(string)($_POST['confirm_password']??''));
    if(!password_verify((string)($_POST['current_password']??''),$hash))throw new RuntimeException('Check your current password and use matching new passwords of at least 12 characters.');
    $newHash=password_hash($password,PASSWORD_DEFAULT);cbQuery($db,'UPDATE '.$table.' SET password_hash=? WHERE id=?',[$newHash,$u['id']]);
    cbQuery($db,'UPDATE classbook_tokens SET used_at=NOW() WHERE role=? AND account_id=? AND used_at IS NULL',[$u['role'],$u['id']]);
    cbAudit($db,$u,null,'password_changed',[]);$db->commit();$_SESSION['auth_signature']=hash('sha256',$newHash);session_regenerate_id(true);portalRedirect('profile.php','Password changed.');
}catch(Throwable $e){if($db->inTransaction())$db->rollBack();$error=portalError($e);}
$profileTable=match($u['role']){'professor'=>'professors','student'=>'students','admin'=>'classbook_admins'};
$profileColumns=match($u['role']){'professor'=>'email,department','student'=>'email,course,year_level',default=>'full_name'};
$profile=cbQuery($db,'SELECT '.$profileColumns.' FROM '.$profileTable.' WHERE id=?',[$u['id']])->fetch()?:[];
portalStart('Account settings',$u,'profile');
?><div class="profile-page"><div class="profile-heading"><span class="eyebrow"><?=h(strtoupper(portalRoleLabel($u['role'])))?> / ACCOUNT SETTINGS</span><h1>Account settings</h1><p><?=h($u['role']==='admin'?'Update your own profile, sign-in username and password.':'Review your school profile and manage your password.')?></p></div><?php
if($error)portalNotice($error,'danger');?><div class="profile-grid"><section class="card profile-panel"><div class="profile-section-heading"><span class="profile-feature-icon"><?=portalIcon('user-round')?></span><div><h2><?=h($u['full_name'])?></h2><p><?=h(portalRoleLabel($u['role']))?></p></div></div><dl class="profile-facts"><div><dt>Account ID</dt><dd><?=h($u['public_id'])?></dd></div><div><dt>Username</dt><dd><?=h($u['username']??'')?></dd></div><div><dt>Assigned role</dt><dd><?=h(portalRoleLabel($u['role']))?></dd></div></dl><div class="profile-divider"></div><h3>Profile details</h3>
<?php if($u['role']==='admin'):?><form method="post"><?=csrfField()?><input type="hidden" name="action" value="save_profile"><input type="hidden" name="account_revision" value="<?=cbAccountRevision($db,'admin',(int)$u['id'])?>"><?php portalField('Full name','full_name',$u['full_name'],'text','required maxlength="190" autocomplete="name"');portalField('Sign-in username','username',$u['username'],'text','required maxlength="80" autocomplete="username"');?><p class="profile-help">If you change your username, use the new one the next time you sign in.</p><?php portalField('Current password to change username','identity_password','','password','autocomplete="current-password"');?><p class="profile-help">Required only when changing your username.</p><button><?=portalIcon('circle-check')?>Save account settings</button></form>
<?php else:?><div class="profile-readonly-fields"><?php portalField('Full name','profile_name',$u['full_name'],'text','readonly');portalField('Sign-in username','profile_username',$u['username'],'text','readonly');if(!empty($profile['email']))portalField('Institutional email','profile_email',$profile['email'],'text','readonly');foreach(['department'=>'Department','course'=>'Course','year_level'=>'Year level'] as $key=>$label)if(!empty($profile[$key]))portalField($label,'profile_'.$key,$profile[$key],'text','readonly');?></div><p class="profile-help">Institutional identity corrections are handled by the Administrator.</p><?php endif;?></section>
<section class="card profile-panel"><div class="profile-section-heading"><span class="profile-feature-icon"><?=portalIcon('key-round')?></span><div><h2>Change password</h2><p>Choose a unique password with at least 12 characters.</p></div></div><form method="post"><?=csrfField()?><?php portalField('Current password','current_password','','password','required autocomplete="current-password"');portalField('New password','password','','password','required minlength="12" autocomplete="new-password"');portalField('Confirm password','confirm_password','','password','required minlength="12" autocomplete="new-password"');?><button><?=portalIcon('key-round')?>Change password</button></form></section></div></div><?php portalEnd();
