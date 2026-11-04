<?php
declare(strict_types=1);
require_once __DIR__.'/includes/portal.php';
require_once __DIR__.'/includes/admin_views.php';
$db=portalDb();
if((getCurrentUser()['role']??'')!=='admin'){http_response_code(403);exit('Administrator access required.');}
$u=portalUser($db,'admin');$id=(int)($_GET['subject_id']??0);$error='';
try{$s=cbClass($db,$id,$u);}catch(Throwable $e){http_response_code(404);exit('Class not found.');}
$current=cbQuery($db,'SELECT full_name FROM professors WHERE id=?',[$s['professor_id']])->fetchColumn();
$locked=$s['state']!=='draft'||$s['archived'];
$q=mb_substr(trim((string)($_GET['q']??'')),0,100);$department=(string)($_GET['department']??'');
if(!array_key_exists($department,CB_DEPARTMENTS))$department='';
$choice=max(0,(int)($_GET['professor_id']??0));
$context=['subject_id'=>$id,'q'=>$q,'department'=>$department];$base='reassign-class.php?'.http_build_query($context);
$selected=$choice?cbQuery($db,"SELECT p.*,am.public_id FROM professors p LEFT JOIN classbook_account_meta am ON am.role='professor' AND am.account_id=p.id LEFT JOIN classbook_access x ON x.role='professor' AND x.account_id=p.id WHERE p.id=? AND p.id<>? AND COALESCE(x.status,'active')='active'",[$choice,$s['professor_id']])->fetch():null;
if($_SERVER['REQUEST_METHOD']==='POST')try{
    portalCsrf();
    if(($_POST['action']??'')!=='reassign')throw new RuntimeException('Choose an available reassignment action.');
    if(!$selected)throw new RuntimeException('Choose an active replacement professor.');
    cbAdminClass($db,$u,'reassign',array_replace($_POST,['subject_id'=>$id,'professor_id'=>$choice]));
    portalRedirect('class.php?subject_id='.$id,'Class reassigned. Student records and grade evidence were retained.');
}catch(Throwable $e){$error=portalError($e);}
$where="p.id<>? AND COALESCE(x.status,'active')='active'";$args=[$s['professor_id']];
if($q!==''){$where.=" AND (CONCAT_WS(' ',p.full_name,p.employee_id,p.email,am.public_id) COLLATE utf8mb4_unicode_ci) LIKE ?";$args[]='%'.$q.'%';}
if($department!==''){$where.=' AND p.department=?';$args[]=$department;}
$from=" FROM professors p LEFT JOIN classbook_access x ON x.role='professor' AND x.account_id=p.id LEFT JOIN classbook_account_meta am ON am.role='professor' AND am.account_id=p.id WHERE ".$where;
$total=(int)cbQuery($db,'SELECT COUNT(*)'.$from,$args)->fetchColumn();$page=min(max(1,(int)($_GET['page']??1)),max(1,(int)ceil($total/20)));
$rows=$locked?[]:cbQuery($db,"SELECT p.*,am.public_id,(SELECT COUNT(*) FROM subjects sub LEFT JOIN classbook_settings cs ON cs.subject_id=sub.id WHERE sub.professor_id=p.id AND COALESCE(cs.archived,0)=0) AS class_count".$from.' ORDER BY p.full_name,p.id LIMIT 20 OFFSET '.(($page-1)*20),$args)->fetchAll();
portalStart('Reassign class',$u,'classes');
?><link rel="stylesheet" href="assets/css/class-workspace.css?v=<?=filemtime(__DIR__.'/assets/css/class-workspace.css')?>"><div class="class-workspace">
<header class="class-heading"><div><span class="eyebrow">CLASS ASSIGNMENT · <?=h($s['subject_code'])?></span><h1>Reassign professor</h1><p><?=h($s['subject_title'].' · '.$s['section'].' · '.$s['school_year'].' · '.$s['semester'])?> · Current professor: <strong><?=h($current)?></strong></p></div><div class="actions"><?=cbAdminStatus($s['state'])?><a class="button secondary" href="class.php?subject_id=<?=$id?>&amp;tab=review" aria-label="Back to class actions" title="Back to class actions"><?=portalIcon('book-open')?>Back to class</a></div></header>
<?php if($error)portalNotice($error,'danger');
if($locked)portalNotice('Return this class to draft and unarchive it before changing its professor.');else{
if($selected){?><section class="card reassignment-confirmation" id="confirm-professor"><span class="eyebrow">SELECTED PROFESSOR</span><h2><?=h($selected['full_name'])?></h2><p><?=h(($selected['public_id']??$selected['employee_id']).' · '.$selected['department'])?></p><p>This professor will manage the entire class. Enrollment, attendance, activities and existing scores will be retained.</p><form method="post" data-confirm="Reassign this class to the selected professor?"><?php portalPost('reassign',$s);?><input type="hidden" name="professor_id" value="<?=$choice?>"><?php portalField('Reason for reassignment','reason',$_POST['reason']??'','text','required maxlength="500"');?><div class="actions"><button aria-label="Confirm reassignment" title="Confirm reassignment"><?=portalIcon('circle-check')?>Confirm reassignment</button><a class="button secondary" href="<?=h($base)?>" aria-label="Clear selected professor" title="Choose another professor"><?=portalIcon('users')?>Choose another professor</a></div></form></section><?php }
elseif($choice)portalNotice('That professor is unavailable or is already assigned to this class.','danger');
?><section class="card"><div class="masterlist-top"><div><h2>Find a replacement professor</h2><p>Search by name, account ID or email. Only active professors are listed.</p></div></div>
<form method="get" class="masterlist-filters professor-search" data-auto-filter><input type="hidden" name="subject_id" value="<?=$id?>"><?php portalField('Search professor','q',$q,'search','placeholder="Name, account ID or email"');portalSelect('Department','department',[''=>'All departments']+CB_DEPARTMENTS,$department);?><noscript><button class="icon-action" aria-label="Search professors" title="Search professors"><?=portalIcon('search')?></button></noscript></form>
<?php portalFilterSummary([$q!==''?'Search: '.$q:'',$department!==''?'Department: '.$department:''],'reassign-class.php?subject_id='.$id);?>
<div class="masterlist-scroll"><table><thead><tr><th scope="col">Professor</th><th scope="col">Account ID</th><th scope="col">Department</th><th scope="col">Current classes</th><th scope="col">Action</th></tr></thead><tbody><?php foreach($rows as $p):?><tr><td><strong><?=h($p['full_name'])?></strong><small><?=h($p['email'])?></small></td><td><?=h($p['public_id']??$p['employee_id'])?></td><td><?=h($p['department'])?></td><td><?=(int)$p['class_count']?></td><td><?php if($choice===(int)$p['id']):?><span aria-label="Selected professor"><?=portalIcon('circle-check')?></span><?php else:?><a class="icon-action" href="<?=h($base.'&page='.$page.'&professor_id='.$p['id'].'#confirm-professor')?>" aria-label="<?=h('Select professor '.$p['full_name'])?>" title="Select professor"><?=portalIcon('user-plus')?></a><?php endif;?></td></tr><?php endforeach;if(!$rows):?><tr><td colspan="5">No active professors match your search.</td></tr><?php endif;?></tbody></table></div>
<?php unset($_GET['professor_id']);cbPager($page,$total,20);?></section><?php }?></div><?php portalEnd();
