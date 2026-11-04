<?php
declare(strict_types=1);
const CB_EXAMS=['prelim'=>'Prelim Exam','midterm'=>'Midterm Exam','final'=>'Final Exam'];
function cbTagDefaultExams(PDO $db,int $subject):void{
    foreach(CB_EXAMS as $key=>$name)cbQuery($db,"UPDATE classbook_criteria SET exam_key=? WHERE subject_id=? AND kind='activity' AND name=? AND group_key IN (?,?)",[$key,$subject,$name,$key==='final'?'fnl_lec':'mid_lec',$key==='final'?'fnl_lab':'mid_lab']);
}
/** Old duplicate seed rows are retained, but one canonical activity is used per exam. */
function cbActivities(PDO $db,int $subject):array{
    return cbQuery($db,"SELECT a.*,c.name,c.exam_key,c.group_key FROM classbook_activities a JOIN classbook_criteria c ON c.id=a.criterion_id JOIN classbook_settings gs ON gs.subject_id=c.subject_id WHERE c.subject_id=? AND (gs.grading_model<>'periods' OR c.group_key IN ('mid_lec','fnl_lec')) AND (c.exam_key IS NULL OR a.id=(SELECT MIN(a2.id) FROM classbook_activities a2 JOIN classbook_criteria c2 ON c2.id=a2.criterion_id WHERE c2.subject_id=c.subject_id AND c2.exam_key=c.exam_key AND (gs.grading_model<>'periods' OR c2.group_key IN ('mid_lec','fnl_lec')))) ORDER BY a.id DESC",[$subject])->fetchAll();
}
function cbActiveActivity(PDO $db,int $subject,int $activity):array{
    foreach(cbActivities($db,$subject) as $a)if((int)$a['id']===$activity)return $a;
    throw new RuntimeException('Activity is unavailable or is a retained duplicate exam. Edit the shared exam instead.');
}
/** One displayed exam, with its separate grading-component weights retained. */
function cbReviewEvidence(array $result):array{
    $rows=[];$seen=[];
    foreach($result['evidence'] as $e){
        $e['components']=[['group'=>$e['group'],'group_label'=>$e['group_label']??CB_GROUPS[$e['group']],'weight'=>$e['weight'],'percentage'=>$e['percentage']]];
        $key=$e['exam_key']??null;
        if($key!==null&&isset($seen[$key])){$rows[$seen[$key]]['components'][]=$e['components'][0];continue;}
        if($key!==null){$seen[$key]=count($rows);$e['criterion']=CB_EXAMS[$key];}
        $rows[]=$e;
    }return $rows;
}
