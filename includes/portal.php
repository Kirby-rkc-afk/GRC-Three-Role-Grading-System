<?php
declare(strict_types=1);
date_default_timezone_set('Asia/Manila');
require_once __DIR__.'/db.php';
require_once __DIR__.'/auth.php';
require_once __DIR__.'/functions.php';
require_once __DIR__.'/departments.php';
require_once __DIR__.'/classbook.php';
require_once __DIR__.'/security.php';
require_once __DIR__.'/unified_auth.php';
require_once __DIR__.'/page_paint.php';
if(PHP_SAPI!=='cli'&&!headers_sent()){
    header('X-Content-Type-Options: nosniff');header('X-Frame-Options: DENY');header('Referrer-Policy: same-origin');header('Cache-Control: no-store');
    header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline'; img-src 'self' data:; frame-ancestors 'none'; base-uri 'self'; form-action 'self'");
}

function portalDb(): PDO {
    try {$db=getDbConnection();}
    catch(PDOException $e){if(($e->errorInfo[1]??0)===1049){header('Location: index.php');exit;}error_log((string)$e);http_response_code(503);exit('The database is temporarily unavailable. Check that MySQL is running.');}
    if(!cbInstalled($db)||!cbQuery($db,'SELECT COUNT(*) FROM classbook_admins')->fetchColumn()){header('Location: index.php');exit;}
    try{cbUpgrade($db);}catch(Throwable $e){error_log((string)$e);http_response_code(503);exit('The database upgrade could not finish. Contact the system administrator.');}
    return $db;
}
function portalUser(PDO $db,string $role): array {
    $u=getCurrentUser();
    if($u&&((time()-($_SESSION['last_active']??0)>1800)||(time()-($_SESSION['auth_started']??0)>28800))){
        unset($_SESSION['user']);setFlash('warning','Your session expired. Please sign in again.');header('Location: index.php');exit;
    }
    if(!$u||$u['role']!==$role){header('Location: index.php');exit;}
    if(cbStatus($db,$role,(int)$u['id'])!=='active'){
        unset($_SESSION['user']);setFlash('danger','This account is pending approval or deactivated.');header('Location: index.php');exit;
    }
    $table=match($role){'professor'=>'professors','student'=>'students','admin'=>'classbook_admins',default=>throw new RuntimeException('Unknown account role.')};
    $account=cbQuery($db,'SELECT * FROM '.$table.' WHERE id=?',[$u['id']])->fetch();
    if(!$account||!hash_equals($_SESSION['auth_signature']??'',hash('sha256',$account['password_hash']))){unset($_SESSION['user']);setFlash('warning','Your account credentials changed. Please sign in again.');header('Location: index.php');exit;}
    $u['full_name']=$role==='student'?$account['first_name'].' '.$account['last_name']:$account['full_name'];$u['username']=$account['username'];$u['public_id']=cbAccountPublicId($db,$role,(int)$u['id']);$_SESSION['user']=$u;$_SESSION['last_active']=time();return $u;
}
function portalHome(string $role): string {return match($role){'professor'=>'professor-dashboard.php','student'=>'student-dashboard.php','admin'=>'registrar.php',default=>'index.php'};}
function portalCsrf(): void {if(!verifyCsrfToken(is_string($_POST['csrf_token']??null)?$_POST['csrf_token']:null))throw new RuntimeException('Your form expired. Reload and try again.');}
function portalRedirect(string $url,string $message=''): never {if($message!=='')setFlash('success',$message);header('Location: '.$url);exit;}
function portalError(Throwable $e): string {
    if($e instanceof PDOException){error_log((string)$e);return 'The record could not be saved. No partial changes were committed. Please retry or contact the Registrar.';}
    return $e->getMessage();
}
function portalRoleLabel(string $role): string {return match($role){'admin'=>'Administrator','professor'=>'Professor','student'=>'Student',default=>ucfirst($role)};}
function portalIcon(string $name): string {
    if(!preg_match('/^[a-z0-9-]+$/D',$name))return '';
    $path=__DIR__.'/../assets/icons/'.$name.'.svg';
    if(!is_file($path))return '';
    $svg=preg_replace('/class="[^"]*"/','class="ui-icon"',file_get_contents($path),1);
    return str_replace('<svg','<svg aria-hidden="true" focusable="false"',$svg);
}
function portalStart(string $title,?array $u=null,string $active=''): void {
    $flash=getFlash();
    ?><!doctype html><html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><?php portalPagePaint();?><title><?=h($title)?> · GRC</title><link rel="stylesheet" href="assets/css/classbook.css?v=<?=filemtime(__DIR__.'/../assets/css/classbook.css')?>"><link rel="stylesheet" href="assets/css/filters.css?v=<?=filemtime(__DIR__.'/../assets/css/filters.css')?>"><?php if(($u['role']??'')==='admin'):?><link rel="stylesheet" href="assets/css/administrator.css?v=<?=filemtime(__DIR__.'/../assets/css/administrator.css')?>"><link rel="stylesheet" href="assets/css/admin-directory.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-directory.css')?>"><?php endif;?><?php if($u):?><link rel="stylesheet" href="assets/css/workspace.css?v=<?=filemtime(__DIR__.'/../assets/css/workspace.css')?>"><link rel="stylesheet" href="assets/css/profile.css?v=<?=filemtime(__DIR__.'/../assets/css/profile.css')?>"><?php endif;?></head><body class="<?=$u?'workspace-app role-'.h($u['role']).(($u['role']==='admin')?' administrator-app':''):''?>"><?php if($u):?><a class="skip-link" href="#main-content">Skip to content</a><?php endif;?>
    <header class="portal-header"><a class="brand" href="index.php" aria-label="Global Reciprocal Colleges home"><img src="assets/grc-wordmark.png" alt="Global Reciprocal Colleges"></a></header>
    <div class="portal-layout"><?php if($u):?><nav class="portal-nav" aria-label="Workspace"><button type="button" class="workspace-toggle" aria-expanded="false" aria-controls="workspace-links"><span><?=portalIcon("list-filter")?><span><?=h(portalRoleLabel($u["role"]))?> menu</span></span><?=portalIcon("chevron-right")?></button><div class="workspace-links" id="workspace-links"><p><?=h($u['role']==='admin'?'ADMINISTRATION':strtoupper(portalRoleLabel($u['role'])).' WORKSPACE')?></p>
    <?php $items=match($u['role']){'professor'=>['classes'=>['professor-dashboard.php','My classes'],'roster'=>['professor-dashboard.php?view=roster','Student masterlist'],'attendance'=>['professor-dashboard.php?view=attendance','Attendance'],'activities'=>['professor-dashboard.php?view=activities','Scores'],'grades'=>['professor-dashboard.php?view=grades','Grades'],'settings'=>['professor-dashboard.php?view=settings','Class settings']], 'admin'=>['overview'=>['registrar.php','Overview'],'accounts'=>['registrar.php?view=accounts','Accounts & approval'],'classes'=>['registrar.php?view=classes','Classes & verification'],'audit'=>['registrar.php?view=audit','Activity log'],'maintenance'=>['registrar.php?view=maintenance','Backup & recovery']],default=>['grades'=>['student-dashboard.php','My grades']]};
    $icons=['overview'=>'layout-dashboard','accounts'=>'users','classes'=>'book-open','audit'=>'history','maintenance'=>'database-backup','roster'=>'users','criteria'=>'list-filter','attendance'=>'clipboard-check','activities'=>'file-text','grades'=>'graduation-cap','import'=>'download','settings'=>'settings-2','profile'=>'user-round'];
    $navActive=$active==='import'?'attendance':($active==='criteria'?'settings':$active);
    foreach($items as $key=>[$url,$label]){if($u['role']==='admin')$label=match($key){'overview'=>'Dashboard','accounts'=>'Accounts','classes'=>'Classes & grades',default=>$label};if($u['role']==='professor'&&isset($_GET['subject_id']))$url.=(str_contains($url,'?')?'&':'?').'subject_id='.(int)$_GET['subject_id'];?><a class="<?=$navActive===$key?'selected':''?>" <?=$navActive===$key?'aria-current="page"':''?> href="<?=h($url)?>"><?=portalIcon($icons[$key]??'file-text')?><span><?=h($label)?></span></a><?php }?><div class="workspace-account"><a class="account-identity account-profile-link <?=$active==='profile'?'current':''?>" href="profile.php" aria-label="<?=h(portalRoleLabel($u['role']).' account settings')?>" <?=$active==='profile'?'aria-current="page"':''?> title="Account settings"><span class="account-avatar"><?=portalIcon('settings')?></span><div><strong title="<?=h($u['full_name'])?>"><?=h($u['full_name'])?></strong><small><?=h(portalRoleLabel($u['role']))?></small></div><?=portalIcon('chevron-right')?></a><form method="post" action="logout.php"><?=csrfField()?><button class="quiet"><?=portalIcon('log-out')?>Sign out</button></form></div></div></nav><?php endif;?>
    <main id="main-content" tabindex="-1" class="portal-main <?=$u?'':'public-main'?>"><?php if($flash):?><div class="notice <?=h($flash['type'])?>" role="status"><?=h($flash['message'])?></div><?php endif;
}
function portalEnd(): void {?></main></div><script src="assets/js/classbook.js?v=<?=filemtime(__DIR__.'/../assets/js/classbook.js')?>"></script></body></html><?php }
function portalHeading(string $title,string $subtitle=''): void {?><div class="page-heading"><span class="eyebrow">GLOBAL RECIPROCAL COLLEGES</span><h1><?=h($title)?></h1><p><?=h($subtitle)?></p></div><?php }
function portalNotice(string $text,string $kind='info'): void {?><div class="notice <?=h($kind)?>" role="status"><?=h($text);?></div><?php }
function portalField(string $label,string $name,mixed $value='',string $type='text',string $attrs=''): void {?><label class="field"><?=h($label)?><input name="<?=h($name)?>" type="<?=h($type)?>" value="<?=h($value)?>" <?=$attrs?>></label><?php }
function portalSelect(string $label,string $name,array $options,mixed $selected='',string $attrs=''): void {?><label class="field"><?=h($label)?><select name="<?=h($name)?>" <?=$attrs?>><?php foreach($options as $v=>$text):?><option value="<?=h($v)?>" <?=(string)$v===(string)$selected?'selected':''?>><?=h($text)?></option><?php endforeach;?></select></label><?php }
function portalFilterSummary(array $labels,string $resetUrl):void {
    $labels=array_filter($labels,fn($label)=>$label!=='');if(!$labels)return;
    ?><div class="active-filter-banner" role="status"><div class="active-filter-description"><?=portalIcon('list-filter')?><strong>Active filters</strong><div class="active-filter-tags"><?php foreach($labels as $label):?><span><?=h($label)?></span><?php endforeach;?></div></div><a class="filter-reset" href="<?=h($resetUrl)?>" title="Reset filters to show all records">Clear filters<?=portalIcon('x')?></a></div><?php
}
function portalGrade(?float $value): string {return $value===null?'Pending':number_format($value,2).'%';}
function portalPeriod(array $r,string $period,array $policy): string {
    $mid=(float)($policy['midterm_percent']??50);
    if(($period==='midterm'&&$mid===0.0)||($period==='final'&&$mid===100.0))return 'Not used';
    return portalGrade($r[$period]);
}
function portalPost(string $action,?array $s=null): void {echo csrfField();?><input type="hidden" name="action" value="<?=h($action)?>"><?php if($s):?><input type="hidden" name="subject_id" value="<?=(int)$s['id']?>"><input type="hidden" name="revision" value="<?=(int)$s['revision']?>"><?php endif;}
function cbRenderEvidence(array $r): void {
    foreach(cbReviewEvidence($r) as $e){?><details class="card"><summary><?=h(!empty($e['exam_key'])?$e['criterion'].' · Whole subject':($e['group_label']??CB_GROUPS[$e['group']]).' · '.$e['criterion'].' · '.$e['weight'].'%')?> — <?=portalGrade($e['percentage'])?></summary><?php if(!empty($e['exam_key'])):?><p>One shared exam score. <?php foreach($e['components'] as $component):?><?=h(($component['group_label']??CB_GROUPS[$component['group']]).': '.$component['weight'].'%')?>; <?php endforeach;?></p><?php endif;?><p><?=h($e['aggregation']==='equal'?'Average of activity percentages':'Total earned ÷ total possible')?>; excused: <?=h($e['excused_mode'])?>.</p><div class="table-wrap" tabindex="0" role="region" aria-label="Score evidence"><table><thead><tr><th>Meeting / activity</th><th>Status</th><th>Points</th></tr></thead><tbody><?php foreach($e['rows'] as $row):?><tr><td><?=h($row['title'])?></td><td><?=h($row['attendance_status']??$row['status'])?></td><td><?=h($row['earned']??'—')?> / <?=h($row['maximum'])?></td></tr><?php endforeach;?></tbody></table></div></details><?php }
}
