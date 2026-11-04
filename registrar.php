<?php
declare(strict_types=1);
require_once __DIR__.'/includes/portal.php';
require_once __DIR__.'/includes/admin_views.php';
$db=portalDb();$u=portalUser($db,'admin');$view=(string)($_GET['view']??'overview');$error='';$issued=null;
if($_SERVER['REQUEST_METHOD']==='POST')try{
 portalCsrf();$action=(string)($_POST['action']??'');
 if($action==='create_class'){require_once __DIR__.'/includes/classbook_actions.php';$created=cbCreateOffering($db,$u,(int)($_POST['professor_id']??0),$_POST);portalRedirect('class.php?subject_id='.$created,'Class created and assigned.');}
  if(in_array($action,['release','return','reassign','archive_class','unarchive_class','initialize_legacy'],true)){cbAdminClass($db,$u,$action,$_POST);portalRedirect('registrar.php?view=classes&subject_id='.(int)($_POST['subject_id']??0),'Class record updated.');}
 if($action!=='create_account'&&($_POST['role']??'')==='admin'&&(int)($_POST['account_id']??0)===(int)$u['id'])throw new RuntimeException('Use Account settings to update your own profile or password.');
 $issued=cbAdminAccount($db,$u,$action,$_POST);if(!$issued)portalRedirect('registrar.php?view=accounts&role='.urlencode((string)($_POST['role']??'professor')),'Account updated.');
}catch(Throwable $e){$issued=null;$error=portalError($e);}
if($_SERVER['REQUEST_METHOD']==='GET'&&$view==='classes'&&(int)($_GET['subject_id']??0)>0){header('Location: class.php?tab=review&subject_id='.(int)$_GET['subject_id']);exit;}
$pages=[
 'overview'=>['Administrator dashboard','An overview of your academic workspace.'],
 'accounts'=>['Accounts','Manage school identities, approve access and help users activate their accounts.'],
 'classes'=>['Subjects','Choose a subject, select its professor and section, then open the student masterlist.'],
 'audit'=>['Activity log','See who changed a record, what they did and when it happened.'],
 'maintenance'=>['Backup & recovery','Protect academic records and help verified account owners regain access.'],
];
if(!isset($pages[$view]))$view='overview';
require_once __DIR__.'/includes/admin_presentation.php';
portalStart($pages[$view][0],$u,$view);
if($view!=='overview'){?><div class="admin-page-top"><div><span class="admin-kicker">ADMINISTRATOR / <?=h(strtoupper($pages[$view][0]))?></span><h1><?=h($pages[$view][0])?></h1><p><?=h($pages[$view][1])?></p></div></div><?php }
if($error)portalNotice($error,'danger');
if($issued)portalNotice('One-time activation code: '.$issued.' — share privately with the verified owner. Expires in 24 hours. Shown only now.');
if($view==='accounts')cbAdminAccounts($db,$u);
elseif($view==='classes')cbAdminClasses($db,$u);
elseif($view==='audit')cbAdminAudit($db);
elseif($view==='maintenance')cbAdminMaintenance();
else cbAdminDashboard($db,$u);
portalEnd();
