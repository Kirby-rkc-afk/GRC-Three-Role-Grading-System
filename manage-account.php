<?php
declare(strict_types=1);
require_once __DIR__.'/includes/portal.php';
require_once __DIR__.'/includes/admin_views.php';
$db=portalDb();$u=portalUser($db,'admin');$error='';
$role=is_string($_GET['role']??null)?$_GET['role']:'';
$id=filter_var($_GET['account_id']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
$context=[];
if(is_string($_GET['q']??null)&&trim($_GET['q'])!=='')$context['q']=substr(trim($_GET['q']),0,100);
if(in_array($_GET['status']??'',['pending','active','inactive'],true))$context['status']=$_GET['status'];
if(($page=filter_var($_GET['page']??null,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]])))$context['page']=$page;
if(!in_array($role,['professor','student','admin'],true)||!$id){
    http_response_code(404);portalStart('Account not found',$u,'accounts');portalHeading('Account not found','Open an account from the Accounts list.');portalEnd();exit;
}
if($role==='admin'&&$id===(int)$u['id'])portalRedirect('profile.php');
$backUrl='registrar.php?'.http_build_query(['view'=>'accounts','role'=>$role]+$context);
$manageUrl='manage-account.php?'.http_build_query(['role'=>$role,'account_id'=>$id]+$context);
$loadAccount=static function()use($db,$role,$id): array {
    $a=cbQuery($db,'SELECT * FROM '.cbAccountTable($role).' WHERE id=?',[$id])->fetch();
    if(!$a)throw new RuntimeException('Account not found.');
    $a['public_id']=cbAccountPublicId($db,$role,$id);$a['access_status']=cbStatus($db,$role,$id);return $a;
};
try{$a=$loadAccount();}catch(Throwable $e){
    http_response_code(404);portalStart('Account not found',$u,'accounts');portalHeading('Account not found','The requested account is unavailable.');?><a class="button secondary" href="<?=h($backUrl)?>">Back to accounts</a><?php portalEnd();exit;
}
if($_SERVER['REQUEST_METHOD']==='POST')try{
    portalCsrf();$action=is_string($_POST['action']??null)?$_POST['action']:'';
    if(!in_array($action,['edit_account','approve','deactivate','issue_code'],true))throw new RuntimeException('Choose a valid account action.');
    // The URL identifies the verified target. Posted role/account IDs cannot change it.
    $payload=array_replace($_POST,['role'=>$role,'account_id'=>$id]);
    $issued=cbAdminAccount($db,$u,$action,$payload);
    portalRedirect($manageUrl,$issued?'One-time activation code: '.$issued.' — share privately with the verified owner. Expires in 24 hours. Shown only now.':'Account updated.');
}catch(Throwable $e){$error=portalError($e);$a=$loadAccount();}
$revision=cbAccountRevision($db,$role,$id);
$fullName=$a['full_name']??$a['first_name'].' '.$a['last_name'];
$postFields=static function(string $action)use($role,$id,$revision): void {
    portalPost($action);?><input type="hidden" name="role" value="<?=h($role)?>"><input type="hidden" name="account_id" value="<?=$id?>"><input type="hidden" name="account_revision" value="<?=$revision?>"><?php
};
portalStart('Manage account',$u,'accounts');
?>
<a class="text-link account-back-link" href="<?=h($backUrl)?>"><?=portalIcon('arrow-right')?>Back to accounts</a>
<div class="admin-page-top"><div><span class="admin-kicker">ADMINISTRATOR / ACCOUNTS</span><h1>Manage account</h1><p>Review this user's identity and manage their access in one place.</p></div></div>
<?php if($error)portalNotice($error,'danger');?>
<section class="admin-panel managed-account-summary"><div class="managed-account-person"><span class="account-avatar"><?=portalIcon($role==='student'?'graduation-cap':'user-round')?></span><div><h2><?=h($fullName)?></h2><p><?=h($a['public_id'])?> <span aria-hidden="true">·</span> <?=h(portalRoleLabel($role))?></p></div></div><?=cbAdminStatus($a['access_status'])?></section>
<div class="account-manage-grid">
    <section class="admin-panel"><div class="panel-heading"><div><h2>Account details</h2><p>Keep the school's verified details accurate. The generated account ID stays the same.</p></div></div>
        <form method="post"><?php $postFields('edit_account');cbAccountFields($role,$a);portalField('Reason for correction','reason','','text','required maxlength="500"');?><button><?=portalIcon('circle-check')?>Save account details</button></form>
    </section>
    <div class="account-manage-actions">
        <section class="admin-panel"><div class="feature-icon"><?=portalIcon('shield-check')?></div><h2>Account access</h2>
        <?php if($a['access_status']==='active'):?><p>This user can sign in. Deactivation blocks access and retains their academic records.</p><form method="post" data-confirm="Deactivate this account and block its sign-in access?"><?php $postFields('deactivate');portalField('Reason for deactivation','reason','','text','required maxlength="500"');?><button class="quiet"><?=portalIcon('shield-check')?>Deactivate account</button></form>
        <?php else:$pending=$a['access_status']==='pending';?><p><?=h($pending?'Verify this user before approving access.':'This account is deactivated. Verify the owner before restoring access.')?></p><form method="post"><?php $postFields('approve');portalField('Verification / reason','reason','','text','required maxlength="500"');?><button><?=portalIcon('shield-check')?><?=$pending?'Approve account':'Reactivate account'?></button></form><?php endif;?></section>
        <section class="admin-panel"><div class="feature-icon"><?=portalIcon('key-round')?></div><h2>Activation &amp; password recovery</h2><p>After verifying the owner, issue a private one-time code so they can set their own password. The code expires after 24 hours and replaces any unused code.</p>
        <?php if($a['access_status']==='inactive'):?><p class="settings-help">Reactivate this account before issuing a recovery code.</p><?php else:?><form method="post"><?php $postFields('issue_code');?><button class="quiet"><?=portalIcon('key-round')?>Issue one-time code</button></form><?php endif;?></section>
    </div>
</div>
<?php portalEnd();
