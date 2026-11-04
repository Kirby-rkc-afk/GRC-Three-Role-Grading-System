<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/seed_realistic_demo.php';
/** Correct only the recorded fictional dataset. Prior exam rows/snapshots are retained. */
function cbFixDemoExams(PDO $db):array{
    $db->beginTransaction();try{
        cbLockIdentifiers($db);
        $prior=cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='demo_shared_exams_fixed' ORDER BY id DESC LIMIT 1")->fetchColumn();if($prior){$db->commit();return json_decode($prior,true,512,JSON_THROW_ON_ERROR);}
        $seed=cbQuery($db,"SELECT detail_json FROM classbook_audit WHERE action='realistic_demo_seeded' ORDER BY id DESC LIMIT 1")->fetchColumn();if(!$seed)throw new RuntimeException('Recorded realistic demo dataset required.');$seed=json_decode($seed,true,512,JSON_THROW_ON_ERROR);$manifest=['classes'=>[]];
        foreach($seed['classes'] as $record){
            $id=(int)$record['id'];$before=cbQuery($db,'SELECT * FROM classbook_settings WHERE subject_id=? FOR UPDATE',[$id])->fetch();if(!$before)throw new RuntimeException('Demo class missing.');
            cbTagDefaultExams($db,$id);$exams=[];
            foreach(cbActivities($db,$id) as $a)if($a['exam_key']!==null){$exams[$a['exam_key']]=(int)$a['id'];cbQuery($db,'UPDATE classbook_activities SET title=? WHERE id=?',[CB_EXAMS[$a['exam_key']],$a['id']]);}
            if(count($exams)!==3)throw new RuntimeException('Expected exactly three subject exams.');
            cbQuery($db,'UPDATE classbook_settings SET revision=revision+1 WHERE subject_id=?',[$id]);
            if($before['state']==='released'){
                cbQuery($db,"UPDATE classbook_settings SET state='submitted' WHERE subject_id=?",[$id]);$snapshot=cbCompute($db,$id);foreach($snapshot['students'] as $r)if($r['course']===null)throw new RuntimeException('Corrected demo publication is incomplete.');$snapshot['class']=cbClass($db,$id,['role'=>'admin','id'=>0]);
                cbQuery($db,'INSERT INTO classbook_releases(subject_id,revision,snapshot_json,actor_id) VALUES(?,?,?,0)',[$id,$snapshot['revision'],json_encode($snapshot,JSON_THROW_ON_ERROR)]);cbQuery($db,"UPDATE classbook_settings SET state='released',revision=revision+1 WHERE subject_id=?",[$id]);
            }
            cbAudit($db,['role'=>'system','id'=>0],$id,'demo_exam_corrected',['whole_subject_exams'=>$exams,'previous_revision'=>$before['revision'],'prior_rows_and_releases_retained'=>true]);$manifest['classes'][$id]=$exams;
        }
        cbAudit($db,['role'=>'system','id'=>0],null,'demo_shared_exams_fixed',$manifest);$db->commit();return $manifest;
    }catch(Throwable $e){if($db->inTransaction())$db->rollBack();throw $e;}
}
if(realpath($_SERVER['SCRIPT_FILENAME']??'')===__FILE__){if(!in_array('--apply',$argv,true)){echo "Preview: one shared Prelim, Midterm and Final Exam per recorded demo subject offering. Back up privately before --apply.\n";exit;}cbUpgrade(getDbConnection());echo json_encode(cbFixDemoExams(getDbConnection()),JSON_PRETTY_PRINT|JSON_THROW_ON_ERROR).PHP_EOL;}
