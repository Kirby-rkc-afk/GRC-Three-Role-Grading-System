<?php
declare(strict_types=1);
const CB_PERIOD_GROUPS=['mid_lec'=>'Midterm','fnl_lec'=>'Final'];
function cbPeriodOnly(array $settings):bool{return ($settings['grading_model']??'legacy_components')==='periods';}
function cbClassGroups(PDO $db,int $subject):array{
    $s=cbQuery($db,'SELECT grading_model FROM classbook_settings WHERE subject_id=?',[$subject])->fetch()?:[];
    return cbPeriodOnly($s)?CB_PERIOD_GROUPS:CB_GROUPS;
}
function cbClassCriteria(PDO $db,int $subject):array{
    $groups=cbClassGroups($db,$subject);
    return array_values(array_filter(cbQuery($db,'SELECT * FROM classbook_criteria WHERE subject_id=? ORDER BY id',[$subject])->fetchAll(),fn($c)=>isset($groups[$c['group_key']])));
}
/** Convert an explicitly selected class; retain former component evidence and publications. */
function cbConvertToPeriods(PDO $db,int $subject):void{
    if(!$db->inTransaction())throw new RuntimeException('Period conversion requires a transaction.');
    $s=cbQuery($db,'SELECT * FROM classbook_settings WHERE subject_id=? FOR UPDATE',[$subject])->fetch();if(!$s)throw new RuntimeException('Class not initialized.');
    if(cbPeriodOnly($s))return;
    // Existing mid_lec/fnl_lec records become the two period records. No score is fabricated.
    cbQuery($db,"UPDATE classbook_settings SET grading_model='periods',revision=revision+1 WHERE subject_id=?",[$subject]);
    cbQuery($db,'INSERT IGNORE INTO classbook_policies(subject_id) VALUES(?)',[$subject]);
    cbQuery($db,'UPDATE classbook_policies SET lecture_percent=100 WHERE subject_id=?',[$subject]);
    cbValidateWeights($db,$subject);
}
