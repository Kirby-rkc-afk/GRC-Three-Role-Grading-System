<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/fix_demo_exams.php';
function cbAlignPeriodDemo(PDO $db):array{
    $db->beginTransaction();try{
        cbLockIdentifiers($db);
        $prior=cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='demo_periods_aligned' ORDER BY id DESC LIMIT 1")->fetchColumn();if($prior){$db->commit();return json_decode($prior,true,512,JSON_THROW_ON_ERROR);}
        $seed=json_decode((string)cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='realistic_demo_seeded' ORDER BY id DESC LIMIT 1")->fetchColumn(),true,512,JSON_THROW_ON_ERROR);
        // Verified against SMS_WebSys courses and its comprehensive curriculum migration.
        $catalog=[
            'IT101'=>['ITFUND','IT Fundamentals',2],
            'IT102'=>['ITFUNDL','IT Fundamentals Laboratory',1],
            'IT202'=>['PROG2','Object-Oriented Programming',2],
            'ACC101'=>['ACTG101','Financial Accounting and Reporting 1',3],
            'BA101'=>['MGMT101','Principles of Management',3],
            'ED101'=>['EDUC101','The Child and Adolescent Learners and Learning Principles',3],
        ];$manifest=['classes'=>[],'source'=>'SMS_WebSys subject catalog; no enrollment-system data changed'];
        foreach($seed['classes']??[] as $record){
            $id=(int)$record['id'];$s=cbClass($db,$id,['role'=>'admin','id'=>0]);$old=$s;
            cbConvertToPeriods($db,$id);
            if(isset($catalog[$record['code']])&&$s['subject_code']===$record['code']){[$code,$title,$units]=$catalog[$record['code']];cbQuery($db,'UPDATE subjects SET subject_code=?,subject_title=?,units=? WHERE id=?',[$code,$title,$units,$id]);}
            if($old['state']==='released'){
                cbQuery($db,"UPDATE classbook_settings SET state='submitted' WHERE subject_id=?",[$id]);$snapshot=cbCompute($db,$id);foreach($snapshot['students'] as $r)if($r['course']===null)throw new RuntimeException('Demo publication requires complete period grades.');$snapshot['class']=cbClass($db,$id,['role'=>'admin','id'=>0]);
                cbQuery($db,'INSERT INTO classbook_releases(subject_id,revision,snapshot_json,actor_id) VALUES(?,?,?,0)',[$id,$snapshot['revision'],json_encode($snapshot,JSON_THROW_ON_ERROR)]);cbQuery($db,"UPDATE classbook_settings SET state='released',revision=revision+1 WHERE subject_id=?",[$id]);
            }
            cbAudit($db,['role'=>'system','id'=>0],$id,'demo_period_model_updated',['before_subject'=>$old,'retained_previous_component_evidence'=>true,'model'=>'periods']);$manifest['classes'][]=$id;
        }
        if(!$manifest['classes'])throw new RuntimeException('Recorded demo classes required.');
        cbAudit($db,['role'=>'system','id'=>0],null,'demo_periods_aligned',$manifest);$db->commit();return $manifest;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){if(!in_array('--apply',$argv,true)){echo "Preview: two grading periods and verified enrollment catalog names for recorded demo classes. Back up privately before --apply.\n";exit;}cbUpgrade(getDbConnection());echo json_encode(cbAlignPeriodDemo(getDbConnection()),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;}
