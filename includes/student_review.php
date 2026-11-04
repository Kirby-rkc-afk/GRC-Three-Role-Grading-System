<?php
declare(strict_types=1);
function cbStudentReviewPage(PDO $db,array $u,array $s,int $enrollment,string $back,string $error):void{
    $student=null;foreach(cbMembers($db,(int)$s['id'],false) as $member)if((int)$member['enrollment_id']===$enrollment)$student=$member;
    if(!$student){http_response_code(404);exit('Student record is not available in this class.');}
    $data=$s['revision']!==null?cbCompute($db,(int)$s['id']):null;$result=$data['students'][$enrollment]??null;
    $evidence=$result['evidence']??[];$chosen=(int)($_GET['criterion']??0);$selected=$evidence[0]??null;
    foreach($evidence as $e)if((int)$e['criterion_id']===$chosen)$selected=$e;
    $baseContext=['subject_id'=>(int)$s['id'],'student'=>$enrollment];
    foreach(['q','status','size','page'] as $key)if(isset($_GET[$key])&&is_scalar($_GET[$key]))$baseContext[$key]=(string)$_GET[$key];
    portalStart('Student grade review',$u,$u['role']==='admin'?'classes':'roster');
    ?><link rel="stylesheet" href="assets/css/class-workspace.css?v=<?=filemtime(__DIR__.'/../assets/css/class-workspace.css')?>"><link rel="stylesheet" href="assets/css/grade-review.css?v=<?=filemtime(__DIR__.'/../assets/css/grade-review.css')?>">
    <div class="class-workspace grade-review">
    <div class="review-toolbar"><a class="button secondary" href="<?=h($back)?>" aria-label="Back to masterlist" title="Back to masterlist"><?=portalIcon('users')?>Back to masterlist</a><span>STUDENT RECORD / GRADE REVIEW</span><div><?=cbAdminStatus($student['enrollment_status'])?> <?=cbAdminStatus($s['state']??'legacy')?></div></div>
    <header class="review-profile"><div class="review-avatar"><?=portalIcon('graduation-cap')?></div><div><span class="eyebrow"><?=h($student['public_id']??$student['student_no'])?></span><h1><?=h($student['first_name'].' '.$student['last_name'])?></h1><p><?=h(implode(' · ',array_filter([$student['course']??'',$student['year_level']??''])))?></p></div></header>
    <dl class="review-facts"><div><dt>Subject</dt><dd><?=h($s['subject_code'].' · '.$s['subject_title'])?></dd></div><div><dt>Professor</dt><dd><?=h($s['professor_name'])?></dd></div><div><dt>Section</dt><dd><?=h($s['section'])?></dd></div><div><dt>Academic term</dt><dd><?=h($s['school_year'].' · '.$s['semester'])?></dd></div></dl>
    <?php if($error)portalNotice($error,'danger');
    if($result){$policy=$data['policy'];$midShare=(float)$policy['midterm_percent'];
    ?><div class="review-metrics">
    <?php foreach(['midterm'=>['Midterm',$midShare],'final'=>['Final',100-$midShare],'course'=>['Course grade',100]] as $key=>[$label,$share]):?><section class="review-metric <?=$key==='course'?'review-total':''?>"><div><span><?=h($label)?></span><?=portalIcon($key==='course'?'graduation-cap':'file-check-2')?></div><strong><?=portalGrade($result[$key])?></strong><small><?=$key==='course'?h($result['remarks'].($result['converted']!==null?' · '.$result['converted']:'')):h($share.'% of course grade')?></small></section><?php endforeach;?></div>
    <div class="review-period-grid" aria-label="Grade breakdown">
    <?php foreach(['mid_lec'=>['midterm','Midterm'],'fnl_lec'=>['final','Final']] as $group=>[$period,$label]):$criteria=array_values(array_filter($evidence,fn($e)=>$e['group']===$group));?>
    <section class="review-period" aria-label="<?=h($label)?> criteria"><header><div><span class="eyebrow"><?=h($label)?> PERIOD</span><h2><?=h($label)?> criteria</h2></div><strong><?=portalGrade($result[$period])?></strong></header>
    <div class="review-column-labels"><span>Criterion / weight</span><span>Achievement</span><span>Weighted</span><span>Action</span></div>
    <?php foreach($criteria as $criterion):$active=$selected&&(int)$selected['criterion_id']===(int)$criterion['criterion_id'];$target='student-review.php?'.http_build_query($baseContext+['criterion'=>$criterion['criterion_id']]).'#criterion-evidence';?>
    <div class="review-criterion <?=$active?'is-selected':''?>"><div><strong><?=h($criterion['criterion'])?></strong><small><?=h($criterion['weight'])?>% of <?=h($label)?></small></div><div><span><?=portalGrade($criterion['percentage'])?></span><div class="review-bar" aria-hidden="true"><i style="width:<?=h(max(0,min(100,(float)($criterion['percentage']??0))))?>%"></i></div></div><strong><?=(float)$criterion['weight']===0.0?'Not used':($criterion['percentage']===null?'Pending':h(number_format($criterion['percentage']*$criterion['weight']/100,2)).'%')?></strong><a class="icon-action" href="<?=h($target)?>" aria-label="<?=h(!empty($criterion['exam_key'])?'View '.$criterion['criterion'].' evidence':'View '.$label.' '.$criterion['criterion'].' evidence')?>" title="View score details" <?=$active?'aria-current="true"':''?>><?=portalIcon('file-text')?></a></div>
    <?php endforeach;?>
    <footer><span>Total criteria weight</span><strong><?=h(array_sum(array_column($criteria,'weight')))?>%</strong></footer></section>
    <?php endforeach;?></div>
    <?php if($selected):?><section class="card review-evidence" id="criterion-evidence"><div class="review-evidence-heading"><div><span class="eyebrow">SCORE DETAILS</span><h2><?=h(($selected['group_label']??'Period').' · '.$selected['criterion'])?></h2><p><?=h($selected['aggregation']==='equal'?'Average of recorded activity percentages':'Total earned points ÷ eligible possible points')?> · <?=h($selected['excused_mode']==='exclude'?'Excused work excluded':'Excused work requires make-up')?></p></div><strong><?=portalGrade($selected['percentage'])?></strong></div><div class="masterlist-scroll"><table><thead><tr><th>Meeting / activity</th><th>Status</th><th>Earned</th><th>Possible</th></tr></thead><tbody><?php foreach($selected['rows'] as $work):?><tr><td><?=h($work['title'])?></td><td><?=h(ucwords(str_replace('_',' ',$work['attendance_status']??$work['status'])))?></td><td><?=h($work['earned']??'Pending')?></td><td><?=h($work['maximum'])?></td></tr><?php endforeach;if(!$selected['rows']):?><tr><td colspan="4">No work recorded for this criterion yet.</td></tr><?php endif;?></tbody></table></div></section><?php endif;?>
    <p class="review-policy"><?=h($policy['policy_label'])?> · Passing <?=h($policy['passing_percent'])?>% · Current class record</p>
    <?php
    }elseif($student['enrollment_status']==='dropped')portalNotice('Dropped enrollment. Earlier attendance and scores are retained. Restore enrollment to include this student in current grade calculations.');
    else portalNotice('Individual class records have not been initialized.');
    if($u['role']==='professor'&&$s['revision']!==null&&$s['state']==='draft'&&!$s['archived']){?><section class="card review-enrollment"><h2><?=h($student['enrollment_status']==='active'?'Drop enrollment':'Restore enrollment')?></h2><form method="post" data-confirm="Update enrollment and retain existing evidence?"><?php portalPost($student['enrollment_status']==='active'?'drop_enrollment':'restore_enrollment',$s);?><input type="hidden" name="enrollment_id" value="<?=$enrollment?>"><?php portalField('Reason','reason','','text','required maxlength="500"');?><button aria-label="Save enrollment change" title="Save enrollment change"><?=portalIcon('circle-check')?>Save enrollment change</button></form></section><?php }
    ?></div><?php portalEnd();
}
