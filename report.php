<?php
declare(strict_types=1);
require_once __DIR__.'/includes/portal.php';
$db=portalDb();$u=getCurrentUser();if(!$u)portalRedirect('index.php');$u=portalUser($db,$u['role']);
$id=(int)($_GET['subject_id']??0);$type=(string)($_GET['type']??'grades');$published=($_GET['version']??'')==='published'||$u['role']==='student';$history=[];
try{
    if(!in_array($type,['grades','attendance','activities','roster'],true))throw new RuntimeException('Unknown report.');
    if($u['role']==='student'){
        if($type!=='grades'||!cbQuery($db,'SELECT id FROM enrollments WHERE subject_id=? AND student_id=?',[$id,$u['id']])->fetchColumn())throw new RuntimeException('Report not available.');
    }else cbClass($db,$id,$u);
    if($published){
        $releaseId=(int)($_GET['release_id']??0);
        $r=$releaseId?cbQuery($db,'SELECT * FROM classbook_releases WHERE subject_id=? AND id=?',[$id,$releaseId])->fetch():null;
        $release=$releaseId?($r?['record'=>$r,'snapshot'=>json_decode($r['snapshot_json'],true,512,JSON_THROW_ON_ERROR)]:null):cbLatestRelease($db,$id);
        if(!$release)throw new RuntimeException('No released report is available.');
        $data=$release['snapshot'];$s=$data['class'];
        if($u['role']==='student'){$data['students']=array_filter($data['students'],fn($r)=>(int)$r['student']['student_id']===(int)$u['id']);if(!$data['students'])throw new RuntimeException('You are not included in this release.');}
        $label='RELEASED · '.$release['record']['created_at'].' · revision '.$release['record']['revision'];
        $history=cbQuery($db,'SELECT id,revision,created_at FROM classbook_releases WHERE subject_id=? ORDER BY id DESC',[$id])->fetchAll();
        $type='grades';
    }else{$s=cbClass($db,$id,$u);$data=cbCompute($db,$id);$label=strtoupper($s['state']).' · live class revision '.$s['revision'];}
    $rows=[];$headers=[];
    if($type==='grades'){
        $headers=['Student number','Student name','Units','Midterm %','Final %','Course %','Converted grade','Remarks'];
        foreach($data['students'] as $r){$m=$r['student'];$policy=$data['policy']??['midterm_percent'=>50];$rows[]=[$m['student_no'],$m['last_name'].', '.$m['first_name'],$s['units'],(float)$policy['midterm_percent']===0.0?'Not used':$r['midterm'],(float)$policy['midterm_percent']===100.0?'Not used':$r['final'],$r['course'],$r['converted']??'',$r['remarks']];}
    }elseif($type==='roster'){
        $headers=['Student number','Last name','First name','Email','Enrollment starts','Status'];
        foreach(cbMembers($db,$id,false) as $m)$rows[]=[$m['student_no'],$m['last_name'],$m['first_name'],$m['email'],$m['starts_on'],$m['enrollment_status']];
    }else{
        $headers=['Student number','Student name','Group','Criterion','Item','Status','Earned','Possible','Imported seconds'];
        foreach($data['students'] as $r)foreach(cbReviewEvidence($r) as $e)foreach($e['rows'] as $item){
            $attendance=isset($item['attendance_status']);if(($type==='attendance')!==$attendance)continue;$m=$r['student'];
            $rows[]=[$m['student_no'],$m['last_name'].', '.$m['first_name'],($e['group_label']??CB_GROUPS[$e['group']]),$e['criterion'],$item['title'],$item['attendance_status']??$item['status'],$item['earned'],$item['maximum'],$item['duration_seconds']??null];
        }
    }
}catch(Throwable $e){http_response_code(403);portalStart('Report unavailable',$u);portalNotice(portalError($e),'danger');portalEnd();exit;}
if(($_GET['format']??'')==='csv'){
    header('Content-Type: text/csv; charset=utf-8');header('Content-Disposition: attachment; filename="GRC-'.$type.'-class-'.$id.'.csv"');header('Cache-Control: no-store');
    $out=fopen('php://output','w');fwrite($out,"\xEF\xBB\xBF");
    $safe=static fn($row)=>array_map(static fn($cell)=>is_string($cell)&&preg_match('/^[\s]*[=+\-@\t\r]/u',$cell)?"'".$cell:($cell??'Pending'),$row);
    fputcsv($out,$safe(['GRC '.$type,$s['subject_code'],$s['section'],$s['school_year'],$s['semester'],$label]));fputcsv($out,$headers);foreach($rows as $row)fputcsv($out,$safe($row));fclose($out);exit;
}
portalStart('Academic report',$u);portalHeading(ucfirst($type).' report',$s['subject_code'].' · '.$s['subject_title'].' · '.$s['section'].' · '.$s['school_year'].' / '.$s['semester']);
?><section class="card"><p><strong><?=h($label)?></strong></p><?php if(isset($data['policy'])):$p=$data['policy'];?><p>Policy: <?=h($p['policy_label'])?> · lecture <?=h($p['lecture_percent'])?>% · midterm <?=h($p['midterm_percent'])?>% · passing <?=h($p['passing_percent'])?>%.</p><?php endif;?>
<div class="actions"><button type="button" onclick="window.print()">Print / save as PDF</button><a href="?<?=h(http_build_query(array_merge($_GET,['format'=>'csv'])))?>">Download CSV</a></div>
<div class="table-wrap"><table><thead><tr><?php foreach($headers as $heading):?><th><?=h($heading)?></th><?php endforeach;?></tr></thead><tbody><?php foreach($rows as $row):?><tr><?php foreach($row as $cell):?><td><?=h($cell??'Pending')?></td><?php endforeach;?></tr><?php endforeach;?></tbody></table></div>
<?php if(!$rows):?><p>No records to display.</p><?php endif;?>
<div class="signatures"><div>Prepared by / Professor</div><div>Verified by / Registrar</div></div><p class="muted">A printed report is subject to school verification. No signature is generated automatically.</p></section>
<?php if($type==='grades'&&$u['role']==='student')foreach($data['students'] as $r)cbRenderEvidence($r);?>
<?php if($history):?><details class="card"><summary>Release history</summary><?php foreach($history as $h):?><p><a href="?subject_id=<?=$id?>&version=published&release_id=<?=$h['id']?>">Revision <?=h($h['revision'])?> · <?=h($h['created_at'])?></a></p><?php endforeach;?></details><?php endif;portalEnd();
