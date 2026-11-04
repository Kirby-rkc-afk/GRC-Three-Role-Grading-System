<?php
declare(strict_types=1);

function cbFacultyClassDirectory(array $classes,string $view='classes'):void {
    ?><section class="faculty-directory"><div class="panel-heading"><div><h2><?=h($view==='classes'?'Assigned classes':'Choose a class')?></h2><p>Select a subject and section to open its records.</p></div><span class="pill"><?=count($classes)?> classes</span></div><div class="subject-grid"><?php
    foreach($classes as $c){
        $target='professor-dashboard.php?'.http_build_query(['view'=>$view==='classes'?'roster':$view,'subject_id'=>$c['id']]);
        ?><article class="faculty-class-card"><div class="faculty-card-top"><span class="subject-symbol"><?=portalIcon('book-open')?></span><span class="pill"><?=h($c['archived']?'Archived':ucfirst($c['state']??'Legacy'))?></span></div><span class="eyebrow"><?=h($c['subject_code'])?></span><h2><?=h($c['subject_title'])?></h2><p><?=h($c['section'])?> · <?=(int)$c['student_count']?> students</p><small><?=h($c['school_year'].' · '.$c['semester'])?></small><a class="button secondary" href="<?=h($target)?>"><?=portalIcon('book-open')?>Open class</a></article><?php
    }?></div><?php if(!$classes):?><div class="card"><h3>No assigned classes yet</h3><p>Your administrator assigns classes to you. They will appear here as soon as they are created.</p></div><?php endif;?></section><?php
}

/** Class heading with visible tabs for the four daily tasks plus settings. */
function cbFacultyClassContext(array $s,string $view):void {
    $tabs=['roster'=>['Students','users'],'attendance'=>['Attendance','clipboard-check'],'activities'=>['Scores','file-text'],'grades'=>['Grades','graduation-cap'],'settings'=>['Settings','settings-2']];
    $current=$view==='import'?'attendance':$view;
    ?><link rel="stylesheet" href="assets/css/faculty-records.css?v=<?=filemtime(__DIR__.'/../assets/css/faculty-records.css')?>">
    <div class="faculty-class-context"><div><span class="eyebrow"><?=h($s['subject_code'])?></span><h2><?=h($s['subject_title'])?></h2><p><?=h($s['section'].' · '.$s['school_year'].' · '.$s['semester'])?></p></div><div class="actions"><span class="pill"><?=h(ucfirst($s['state']))?></span><a class="button secondary" href="professor-dashboard.php"><?=portalIcon('book-open')?>Change class</a></div></div>
    <nav class="faculty-class-tabs" aria-label="Class sections"><?php foreach($tabs as $key=>[$label,$icon]):?><a href="<?=h('professor-dashboard.php?view='.$key.'&subject_id='.(int)$s['id'])?>"<?=$current===$key?' aria-current="page"':''?>><?=portalIcon($icon)?><?=h($label)?></a><?php endforeach;?></nav><?php
}

/** Attendance criterion for today's period: Final once a Final meeting exists, otherwise Midterm. */
function cbFacultyCurrentAttendance(array $criteria,array $groups):int {
    $first = null;
    $last = null;

    foreach ($criteria as $c) {
        if (($c['kind'] ?? null) !== 'attendance' || (float)($c['weight'] ?? 0) <= 0) {
            continue;
        }

        $id = (int)($c['id'] ?? 0);
        if ($first === null) {
            $first = $id;
        }
        $last = $id;
    }

    if ($first === null) {
        foreach ($criteria as $c) {
            if (($c['kind'] ?? null) === 'attendance') {
                $first = (int)($c['id'] ?? 0);
                break;
            }
        }
    }

    return (int)($_GET['period'] ?? 0) ?: ($first ?? 0);
}
