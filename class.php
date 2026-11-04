<?php
declare(strict_types=1);
require_once __DIR__.'/includes/portal.php';
require_once __DIR__.'/includes/admin_views.php';
require_once __DIR__.'/includes/faculty_views.php';
require_once __DIR__.'/includes/classbook_actions.php';
$db=portalDb();$role=(string)(getCurrentUser()['role']??'');
if(!in_array($role,['admin','professor'],true)){http_response_code(403);exit('This class is available only to administrators and its assigned professor.');}
$u=portalUser($db,$role);$id=(int)($_GET['subject_id']??0);
$s=cbQuery($db,'SELECT s.*,p.full_name AS professor_name,c.revision,c.state,c.archived,c.grading_model FROM subjects s JOIN professors p ON p.id=s.professor_id LEFT JOIN classbook_settings c ON c.subject_id=s.id WHERE s.id=?',[$id])->fetch();
if(!$s||($role==='professor'&&(int)$s['professor_id']!==(int)$u['id'])){http_response_code(404);exit('Class not found.');}
$tab=($role==='admin'&&($_GET['tab']??'')==='review')?'review':'masterlist';$error='';
$q=mb_substr(trim((string)($_GET['q']??'')),0,100);$status=in_array($_GET['status']??'',['active','dropped'],true)?$_GET['status']:'';
$requestedSize=(int)($_GET['size']??25);$size=in_array($requestedSize,[25,50,100],true)?$requestedSize:25;
$context=['subject_id'=>$id,'tab'=>$tab,'q'=>$q,'status'=>$status,'size'=>$size];
$url='class.php?'.http_build_query($context);
if($_SERVER['REQUEST_METHOD']==='POST')try{
    portalCsrf();$action=(string)($_POST['action']??'');
    $_POST['subject_id']=$id; // The URL identifies the authorized class, never a hidden target.
    if($role==='admin'){
        if(!in_array($action,['release','return','reassign','archive_class','unarchive_class','initialize_legacy'],true))throw new RuntimeException('Choose an available class action.');
        cbAdminClass($db,$u,$action,$_POST);
    }else{
        if(!in_array($action,['enroll','enroll_many','drop_enrollment','restore_enrollment','initialize_class'],true))throw new RuntimeException('Choose an available roster action.');
        cbProfessorAction($db,$u,$action);
    }
    portalRedirect($url,'Class record updated.');
}catch(Throwable $e){$error=portalError($e);}
$selected=(int)($_GET['student']??0);
if($selected>0){require_once __DIR__.'/includes/student_review.php';cbStudentReviewPage($db,$u,$s,$selected,$url,$error);exit;}
$where='e.subject_id=?';$args=[$id];
if($q!==''){$where.=" AND (CONCAT_WS(' ',st.first_name,st.last_name,st.student_no,st.email,am.public_id) COLLATE utf8mb4_unicode_ci) LIKE ?";$args[]='%'.$q.'%';}
if($status!==''){$where.=" AND COALESCE(m.status,'active')=?";$args[]=$status;}
$from=" FROM enrollments e JOIN students st ON st.id=e.student_id LEFT JOIN classbook_members m ON m.enrollment_id=e.id LEFT JOIN classbook_account_meta am ON am.role='student' AND am.account_id=st.id WHERE ".$where;
$select="SELECT e.id AS enrollment_id,st.*,am.public_id,COALESCE(m.status,'active') AS enrollment_status,COALESCE(m.starts_on,DATE(e.enrolled_at)) AS starts_on";
$total=(int)cbQuery($db,'SELECT COUNT(*)'.$from,$args)->fetchColumn();$page=min(max(1,(int)($_GET['page']??1)),max(1,(int)ceil($total/$size)));
$order=' ORDER BY st.last_name,st.first_name,st.id';
if(($_GET['export']??'')==='csv'){
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="class-'.$id.'-masterlist.csv"');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    fputcsv($out,['Account ID','School number','Last name','First name','Email','Course','Year level','Enrollment status','Starts on'],',','"','');
    foreach(cbQuery($db,$select.$from.$order,$args) as $row){$cells=[$row['public_id'],$row['student_no'],$row['last_name'],$row['first_name'],$row['email'],$row['course'],$row['year_level'],$row['enrollment_status'],$row['starts_on']];$cells=array_map(fn($v)=>preg_match('/^[\s]*[=+@-]/u',(string)$v)?"'".$v:(string)$v,$cells);fputcsv($out,$cells,',','"','');}fclose($out);exit;
}
$rows=cbQuery($db,$select.$from.$order.' LIMIT '.$size.' OFFSET '.(($page-1)*$size),$args)->fetchAll();
$counts=cbQuery($db,"SELECT COUNT(*) AS total,SUM(COALESCE(m.status,'active')='active') AS active,SUM(COALESCE(m.status,'active')='dropped') AS dropped FROM enrollments e LEFT JOIN classbook_members m ON m.enrollment_id=e.id WHERE e.subject_id=?",[$id])->fetch();
$locked=$s['revision']===null||$s['state']!=='draft'||$s['archived'];
portalStart('Class workspace',$u,$role==='admin'?'classes':'roster');
?><link rel="stylesheet" href="assets/css/class-workspace.css?v=<?=filemtime(__DIR__.'/assets/css/class-workspace.css')?>">
<div class="class-workspace">
<?php if($role==='admin'):?>
<header class="class-heading"><div><span class="eyebrow">CLASS WORKSPACE · <?=h($s['subject_code'])?></span><h1><?=h($s['subject_title'])?></h1><p><?=h($s['section'].' · '.$s['school_year'].' · '.$s['semester'])?> · <?=portalIcon('user-round')?><?=h($s['professor_name'])?> <span>· <?=(int)$s['units']?> units</span></p></div><div class="actions"><?=cbAdminStatus($s['state']??'Legacy')?><?php if($s['archived']):?> <?=cbAdminStatus('archived')?><?php endif;?><a class="button secondary" href="<?='registrar.php?'.http_build_query(['view'=>'classes','subject'=>$s['subject_code'],'title'=>$s['subject_title']])?>" aria-label="Back to subject professors" title="Back to subject professors"><?=portalIcon('users')?>Back to professors</a></div></header>
<?php if($error)portalNotice($error,'danger');?>
<nav class="tabs class-tabs" aria-label="Class pages"><a class="<?=$tab==='masterlist'?'current':''?>" href="class.php?subject_id=<?=$id?>" <?=$tab==='masterlist'?'aria-current="page"':''?>><?=portalIcon('users')?>Student masterlist</a><a class="<?=$tab==='review'?'current':''?>" href="class.php?subject_id=<?=$id?>&amp;tab=review" <?=$tab==='review'?'aria-current="page"':''?>><?=portalIcon('file-check-2')?>Grade sheet actions</a></nav>
<?php else:
portalHeading('Students','Students enrolled in this class.');
if($error)portalNotice($error,'danger');
cbFacultyClassContext($s,'roster');
endif;?>
<?php if($tab==='review'){cbAdminClassDetails($db,$u,$id);}else{?>
<div class="class-stats"><?php foreach(['active'=>'Enrolled students','dropped'=>'Dropped students','total'=>'Total records'] as $key=>$label):?><div><span><?=h($label)?></span><strong><?=(int)$counts[$key]?></strong></div><?php endforeach;?></div>
<section class="card masterlist-card"><div class="masterlist-top"><div><h2>Student masterlist</h2><p>Students enrolled in <?=h($s['subject_code'].' · '.$s['section'])?>.</p></div><a class="button quiet" href="<?=h($url.'&export=csv')?>" aria-label="Export masterlist CSV" title="Export masterlist CSV"><?=portalIcon('download')?>Export masterlist</a></div>
<form method="get" class="masterlist-filters" data-auto-filter><input type="hidden" name="subject_id" value="<?=$id?>"><?php portalField('Search student','q',$q,'search','placeholder="Name, account ID, school number or email"');portalSelect('Enrollment','status',[''=>'All records','active'=>'Enrolled','dropped'=>'Dropped'],$status);portalSelect('Rows per page','size',[25=>'25 students',50=>'50 students',100=>'100 students'],$size);?><noscript><button class="icon-action" aria-label="Search students" title="Search students"><?=portalIcon('search')?></button></noscript></form><?php portalFilterSummary([$q!==''?'Search: '.$q:'',$status!==''?'Enrollment: '.($status==='active'?'Enrolled':'Dropped'):''],'class.php?'.http_build_query(['subject_id'=>$id,'size'=>$size]));?>
<div class="masterlist-scroll"><table><thead><tr><th scope="col">Student</th><th scope="col">Account ID</th><th scope="col">Course / year</th><th scope="col">Enrollment</th><th scope="col">Action</th></tr></thead><tbody>
<?php foreach($rows as $m):?><tr><td><strong><a class="student-name-link" href="<?=h('student-review.php?'.http_build_query($context+['page'=>$page,'student'=>$m['enrollment_id']]))?>"><?=h($m['last_name'].', '.$m['first_name'])?></a></strong><small><?=h($m['email']??'No email on file')?></small></td><td><?=h($m['public_id']??$m['student_no'])?><?php if($m['public_id']&&$m['student_no']!==$m['public_id']):?><small><?=h($m['student_no'])?></small><?php endif;?></td><td><?=h($m['course'])?><small><?=h($m['year_level'])?></small></td><td><?=cbAdminStatus($m['enrollment_status'])?><small>Since <?=h($m['starts_on'])?></small></td><td><a class="icon-action" href="<?=h('student-review.php?'.http_build_query($context+['page'=>$page,'student'=>$m['enrollment_id']]))?>" aria-label="<?=h('Review grades for '.$m['first_name'].' '.$m['last_name'])?>" title="Review student grades"><?=portalIcon('file-check-2')?></a></td></tr><?php endforeach;?>
<?php if(!$rows):?><tr><td colspan="5">No students match these filters.</td></tr><?php endif;?></tbody></table></div>
<?php $selected=(int)($_GET['student']??0);$_GET=array_merge($_GET,['subject_id'=>$id,'size'=>$size]);unset($_GET['student'],$_GET['export']);cbPager($page,$total,$size);?></section>
<?php
if($role==='professor'){
    if($s['revision']===null){?><form method="post" class="card"><?php portalPost('initialize_class');?><h2>Start individual class records</h2><p>Existing legacy totals will be retained. Attendance and activity records start empty.</p><button>Initialize class record</button></form><?php }
    elseif($locked)portalNotice('This roster is read-only while the class is locked or archived.');
    else{?><section class="card" id="enroll-student"><h2><?=portalIcon('user-plus')?> Enroll student</h2><p>Use an existing student account ID to reuse their school record. New student records require administrator activation.</p><form method="post"><?php portalPost('enroll',$s);?><div class="form-grid"><?php portalField('Student account ID or school number','student_no','','text','required');portalField('Enrollment starts','starts_on',date('Y-m-d'),'date','required');portalField('First name (new student)','first_name');portalField('Last name (new student)','last_name');portalField('Email','email','','email');?></div><button>Enroll student</button></form></section><?php }
}
}?></div><?php portalEnd();
