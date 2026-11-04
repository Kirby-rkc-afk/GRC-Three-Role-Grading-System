<?php
declare(strict_types=1);

function cbFacultyRecordStyles():void { ?><link rel="stylesheet" href="assets/css/faculty-records.css?v=<?=filemtime(__DIR__.'/../assets/css/faculty-records.css')?>"><?php }

/** Select only records already scoped to the professor's authorized class. */
function cbFacultySelectedRecord(array $records,string $key):?array {
    if(!isset($_GET[$key]))return $records[0]??null;
    $selected=(int)$_GET[$key];
    foreach($records as $record)if((int)$record['id']===$selected)return $record;
    return null;
}

function cbFacultyActivityRecords(PDO $db,array $s,array $members,?array $criteria=null,?array $options=null):void {
    cbFacultyRecordStyles();
    if($criteria===null)$criteria=cbClassCriteria($db,(int)$s['id']);
    $classGroups=cbClassGroups($db,(int)$s['id']);
    if($options===null){
        $options=[];foreach($criteria as $c)$options[$c['id']]=$classGroups[$c['group_key']].' · '.$c['name'];
    }
    $records=cbActivities($db,(int)$s['id']);$selected=cbFacultySelectedRecord($records,'activity_id');
    $explicit=isset($_GET['activity_id']);$disabled=$s['state']!=='draft'||$s['archived']?'disabled':'';
    $base='professor-dashboard.php?view=activities&subject_id='.(int)$s['id'];

    if($explicit){
        ?><div class="actions"><a class="button secondary faculty-record-back" href="<?=h($base)?>"><?=portalIcon('arrow-right')?>Back to activities</a></div><?php
    }else{
        $activityOptions=[];$examOptions=[];
        foreach($criteria as $c){
            if($c['kind']==='activity'){
                if($c['exam_key']!==null){
                    if(isset($examOptions[$c['exam_key']]))continue;
                    $examOptions[$c['exam_key']]=true;
                    $activityOptions[$c['id']]=CB_EXAMS[$c['exam_key']].' · whole subject';
                }else $activityOptions[$c['id']]=$options[$c['id']];
            }
        }
        $gradedCounts=[];
        if($records){
            foreach(cbQuery($db,"SELECT sc.activity_id,COUNT(*) AS cnt FROM classbook_scores sc JOIN enrollments e ON e.id=sc.enrollment_id LEFT JOIN classbook_members m ON m.enrollment_id=e.id WHERE e.subject_id=? AND COALESCE(m.status,'active')='active' AND sc.status<>'not_graded' GROUP BY sc.activity_id",[(int)$s['id']])->fetchAll() as $gc){
                $gradedCounts[(int)$gc['activity_id']]=(int)$gc['cnt'];
            }
        }
        $activeTotal=count($members);
        ?>
        <form method="post" class="card" id="create-activity-form"><?php portalPost('create_activity',$s);?>
            <h2>Create activity, quiz or exam</h2>
            <p class="muted">Create an item and encode scores right below in the score sheet.</p>
            <div class="form-grid">
                <?php portalField('Activity title','title','','text','required placeholder="e.g. Quiz 1, Seatwork, Prelim Exam"'); ?>
                <?php portalSelect('Grading criterion','criterion_id',$activityOptions); ?>
                <?php portalField('Maximum points','maximum',10,'number','step="0.01" min="0.01" required'); ?>
                <?php portalField('Due date (optional)','due_on','','date'); ?>
            </div>
            <button <?=$disabled?>><?=portalIcon('file-plus')?>Create activity</button>
        </form>

        <section class="card">
            <div class="panel-heading">
                <div><h2>Activity records</h2><p class="muted">Select an activity to open its score sheet.</p></div>
                <span class="pill"><?=count($records)?> records</span>
            </div>
            <?php if($records):?>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Activity records">
                <table>
                    <thead><tr><th>Activity / examination</th><th>Criterion</th><th>Maximum</th><th>Progress</th><th>Due date</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach($records as $record):
                        $done=$gradedCounts[(int)$record['id']]??0;
                        $isCurrent=$selected&&(int)$selected['id']===(int)$record['id'];
                    ?>
                    <tr <?=$isCurrent?'style="background:#fff9fa;"':''?>>
                        <td><strong><?=h($record['title'])?></strong><?php if($isCurrent):?> <span class="pill">Open below</span><?php endif;?></td>
                        <td><?=h($record['exam_key']!==null?'Shared exam · whole subject':$record['name'])?></td>
                        <td><?=h($record['maximum'])?> pts</td>
                        <td><span class="pill <?=$activeTotal>0&&$done===$activeTotal?'':'muted'?>"><?=$done?> / <?=$activeTotal?> graded</span></td>
                        <td><?=h($record['due_on']?:'No due date')?></td>
                        <td><a class="faculty-record-action" href="<?=h($base.'&activity_id='.$record['id'])?>" aria-label="<?=h('Open score sheet for '.$record['title'])?>" title="Open score sheet"><?=portalIcon('file-text')?></a></td>
                    </tr>
                    <?php endforeach;?>
                    </tbody>
                </table>
            </div>
            <?php else:?>
            <p>No activities created yet. Fill out the form above to start recording scores.</p>
            <?php endif;?>
        </section>
        <?php
    }

    if(!$selected){
        if($explicit)portalNotice('This activity is not available in this class. Choose an activity from the activity records.','danger');
        return;
    }

    $map=array_column(cbQuery($db,'SELECT * FROM classbook_scores WHERE activity_id=?',[$selected['id']])->fetchAll(),null,'enrollment_id');
    ?>
    <section class="card" id="activity-score-sheet">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">STUDENT SCORE SHEET</span>
                <h2><?=h($selected['title'])?></h2>
                <p class="muted"><?=h(($selected['exam_key']!==null?'Shared exam · whole subject':$selected['name']).' · Maximum '.$selected['maximum'].' points')?></p>
            </div>
            <span class="pill" id="score-sheet-progress"><?=count($members)?> students</span>
        </div>
        <form method="post" id="score-sheet-form">
            <?php portalPost('save_scores',$s);?>
            <input type="hidden" name="activity_id" value="<?=(int)$selected['id']?>">
            <?php if($members):?>
            <div class="sheet-toolbar">
                <div>
                    <strong>Single-box encoding:</strong> Type a score (<strong>0 – <?=h($selected['maximum'])?></strong>), <strong>M</strong> = Missing, <strong>E</strong> = Excused, or blank = Not graded. Press <strong>Enter</strong> or <strong>↓</strong> for next student.
                </div>
                <div class="actions">
                    <button type="button" class="button secondary" id="btn-fill-ungraded-max" <?=$disabled?>><?=portalIcon('check')?>Fill ungraded with <?=h($selected['maximum'])?></button>
                </div>
            </div>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Student score sheet">
                <table>
                    <thead><tr><th>Student</th><th>Score / Input</th><th>Quick mark</th><th>Status</th></tr></thead>
                    <tbody>
                    <?php foreach($members as $idx=>$m):
                        $key=$m['enrollment_id'];$r=$map[$key]??[];
                        $curStatus=$r['status']??'not_graded';
                        $curEarned=$r['earned']??'';
                        $quickVal=$curStatus==='scored'?$curEarned:($curStatus==='missing'?'M':($curStatus==='excused'?'E':''));
                        $badgeLabel=match($curStatus){
                            'scored'=>'Scored · '.$curEarned.' / '.$selected['maximum'],
                            'missing'=>'Missing (0)',
                            'excused'=>'Excused',
                            default=>'Not graded'
                        };
                    ?>
                    <tr>
                        <td><strong><?=h($m['last_name'].', '.$m['first_name'])?></strong><br><small class="muted"><?=h($m['student_no'])?></small></td>
                        <td>
                            <input type="hidden" name="scores[<?=$key?>][status]" id="status-<?=$key?>" value="<?=h($curStatus)?>">
                            <input type="hidden" name="scores[<?=$key?>][earned]" id="earned-<?=$key?>" value="<?=h((string)$curEarned)?>">
                            <input type="hidden" name="scores[<?=$key?>][initial_value]" value="<?=h((string)$quickVal)?>">
                            <input type="text" inputmode="decimal" enterkeyhint="next" name="scores[<?=$key?>][value]" id="quick-<?=$key?>" class="score-quick-input" data-index="<?=$idx?>" data-key="<?=$key?>" data-max="<?=h($selected['maximum'])?>" value="<?=h((string)$quickVal)?>" placeholder="—" aria-label="<?=h('Score for '.$m['last_name'].', '.$m['first_name'])?>" autocomplete="off" <?=$disabled?>>
                        </td>
                        <td>
                            <div class="quick-pill-group">
                                <button type="button" class="quick-pill" data-score-btn="<?=$key?>" data-set="<?=h($selected['maximum'])?>" <?=$disabled?> title="Set full score (<?=h($selected['maximum'])?>)"><?=h($selected['maximum'])?></button>
                                <button type="button" class="quick-pill <?=$curStatus==='missing'?'active-missing':''?>" data-score-btn="<?=$key?>" data-set="M" <?=$disabled?> title="Mark Missing (counts as 0)">M</button>
                                <button type="button" class="quick-pill <?=$curStatus==='excused'?'active-excused':''?>" data-score-btn="<?=$key?>" data-set="E" <?=$disabled?> title="Mark Excused">E</button>
                                <button type="button" class="quick-pill" data-score-btn="<?=$key?>" data-set="" <?=$disabled?> title="Clear score">Clear</button>
                            </div>
                        </td>
                        <td>
                            <span id="badge-<?=$key?>" class="score-status-badge status-<?=h($curStatus)?>"><?=h($badgeLabel)?></span>
                        </td>
                    </tr>
                    <?php endforeach;?>
                    </tbody>
                </table>
            </div>
            <script>
            (function(){
                var inputs = document.querySelectorAll('.score-quick-input');
                function syncRow(inp){
                    var key = inp.getAttribute('data-key');
                    var maxVal = parseFloat(inp.getAttribute('data-max')) || 100;
                    var statusEl = document.getElementById('status-' + key);
                    var earnedEl = document.getElementById('earned-' + key);
                    var badgeEl = document.getElementById('badge-' + key);
                    var v = inp.value.trim();
                    var low = v.toLowerCase();
                    inp.classList.remove('input-error');
                    if(v === ''){
                        statusEl.value = 'not_graded';
                        earnedEl.value = '';
                        badgeEl.className = 'score-status-badge status-not_graded';
                        badgeEl.textContent = 'Not graded';
                    } else if(low === 'm' || low === 'missing'){
                        statusEl.value = 'missing';
                        earnedEl.value = '';
                        badgeEl.className = 'score-status-badge status-missing';
                        badgeEl.textContent = 'Missing (0)';
                    } else if(low === 'e' || low === 'ex' || low === 'excused'){
                        statusEl.value = 'excused';
                        earnedEl.value = '';
                        badgeEl.className = 'score-status-badge status-excused';
                        badgeEl.textContent = 'Excused';
                    } else if(!isNaN(v) && isFinite(v)){
                        var num = parseFloat(v);
                        if(num >= 0 && num <= maxVal){
                            statusEl.value = 'scored';
                            earnedEl.value = num;
                            badgeEl.className = 'score-status-badge status-scored';
                            badgeEl.textContent = 'Scored · ' + num + ' / ' + maxVal;
                        } else {
                            inp.classList.add('input-error');
                            badgeEl.className = 'score-status-badge status-invalid';
                            badgeEl.textContent = 'Max is ' + maxVal;
                        }
                    } else {
                        inp.classList.add('input-error');
                        badgeEl.className = 'score-status-badge status-invalid';
                        badgeEl.textContent = 'Enter 0–' + maxVal + ', M or E';
                    }
                    document.querySelectorAll('[data-score-btn="' + key + '"]').forEach(function(btn){
                        var setVal = btn.getAttribute('data-set');
                        btn.classList.remove('active-missing', 'active-excused', 'active-scored');
                        if(setVal === 'M' && statusEl.value === 'missing') btn.classList.add('active-missing');
                        else if(setVal === 'E' && statusEl.value === 'excused') btn.classList.add('active-excused');
                        else if(setVal !== '' && setVal !== 'M' && setVal !== 'E' && statusEl.value === 'scored' && parseFloat(earnedEl.value) === parseFloat(setVal)) btn.classList.add('active-scored');
                    });
                    updateProgress();
                }
                function updateProgress(){
                    var done = 0;
                    inputs.forEach(function(inp){
                        var key = inp.getAttribute('data-key');
                        var st = document.getElementById('status-' + key);
                        if(st && st.value !== 'not_graded') done++;
                    });
                    var prog = document.getElementById('score-sheet-progress');
                    if(prog) prog.textContent = done + ' / ' + inputs.length + ' graded';
                }
                inputs.forEach(function(inp){
                    inp.addEventListener('input', function(){ syncRow(inp); });
                    inp.addEventListener('keydown', function(e){
                        if(e.isComposing) return;
                        if(e.key === 'Enter' || e.key === 'ArrowDown'){
                            e.preventDefault();
                            var curIdx = parseInt(inp.getAttribute('data-index'), 10);
                            var next = document.querySelector('.score-quick-input[data-index="' + (curIdx + 1) + '"]');
                            if(next){ next.focus(); next.select(); }
                        } else if(e.key === 'ArrowUp'){
                            e.preventDefault();
                            var curIdx = parseInt(inp.getAttribute('data-index'), 10);
                            var prev = document.querySelector('.score-quick-input[data-index="' + (curIdx - 1) + '"]');
                            if(prev){ prev.focus(); prev.select(); }
                        }
                    });
                });
                document.querySelectorAll('[data-score-btn]').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        var key = btn.getAttribute('data-score-btn');
                        var inp = document.getElementById('quick-' + key);
                        if(inp && !inp.disabled){
                            inp.value = btn.getAttribute('data-set');
                            syncRow(inp);
                        }
                    });
                });
                var fillBtn = document.getElementById('btn-fill-ungraded-max');
                if(fillBtn){
                    fillBtn.addEventListener('click', function(){
                        inputs.forEach(function(inp){
                            if(!inp.disabled && inp.value.trim() === ''){
                                inp.value = inp.getAttribute('data-max');
                                syncRow(inp);
                            }
                        });
                    });
                }
                updateProgress();
            })();
            </script>
            <?php else:?>
            <p>No students are enrolled in this class yet. Add students from the Students tab.</p>
            <?php endif;?>
            <div style="margin-top: 1rem;">
                <button <?=$disabled?>><?=portalIcon('check')?>Save scores</button>
            </div>
        </form>
    </section>
    <?php
}

function cbFacultyAttendanceRecords(PDO $db,array $s,array $members,?array $criteria=null,?array $options=null):void {
    cbFacultyRecordStyles();
    if($criteria===null)$criteria=cbClassCriteria($db,(int)$s['id']);
    $classGroups=cbClassGroups($db,(int)$s['id']);
    if($options===null){
        $options=[];foreach($criteria as $c)$options[$c['id']]=$classGroups[$c['group_key']].' · '.$c['name'];
    }
    $records=cbQuery($db,"SELECT m.*,c.name AS criterion_name FROM classbook_meetings m JOIN classbook_criteria c ON c.id=m.criterion_id JOIN classbook_settings gs ON gs.subject_id=c.subject_id WHERE c.subject_id=? AND (gs.grading_model<>'periods' OR c.group_key IN ('mid_lec','fnl_lec')) ORDER BY m.meeting_date DESC,m.id DESC",[$s['id']])->fetchAll();
    $selected=cbFacultySelectedRecord($records,'meeting_id');$explicit=isset($_GET['meeting_id']);
    $disabled=$s['state']!=='draft'||$s['archived']?'disabled':'';$base='professor-dashboard.php?view=attendance&subject_id='.(int)$s['id'];

    if($explicit){
        ?><div class="actions"><a class="button secondary faculty-record-back" href="<?=h($base)?>"><?=portalIcon('arrow-right')?>Back to attendance records</a></div><?php
    }else{
        $attOptions=[];foreach($criteria as $c)if($c['kind']==='attendance')$attOptions[$c['id']]=$options[$c['id']];
        $defaultAttCriterion=cbFacultyCurrentAttendance($criteria,$classGroups);
        ?>
        <div class="card" style="display:flex;justify-content:space-between;align-items:center;flex-wrap:wrap;gap:1rem;">
            <div>
                <span class="eyebrow">ONE-CLICK DAILY ATTENDANCE</span>
                <h2 style="margin:0.2rem 0 0.25rem;">Take today's attendance (<?=h(date('M j, Y'))?>)</h2>
                <p class="muted" style="margin:0;">Opens today's meeting sheet with all active students marked Present automatically, or import a Google Meet / CSV report.</p>
            </div>
            <div class="actions">
                <form method="post" class="inline-action">
                    <?php portalPost('take_attendance_today',$s);?>
                    <input type="hidden" name="criterion_id" value="<?=(int)$defaultAttCriterion?>">
                    <button <?=$disabled?>><?=portalIcon('clipboard-check')?>Take attendance today</button>
                </form>
                <a class="button secondary" href="professor-dashboard.php?view=import&subject_id=<?=(int)$s['id']?>"><?=portalIcon('download')?>Import online report</a>
            </div>
        </div>

        <form method="post" class="card"><?php portalPost('create_meeting',$s);?>
            <h2>Create class meeting</h2>
            <p class="muted">Choose a period criterion and date. Leave the title blank to name it automatically by date.</p>
            <div class="form-grid">
                <?php portalSelect('Period criterion','criterion_id',$attOptions,$defaultAttCriterion); ?>
                <?php portalField('Meeting date','meeting_date',date('Y-m-d'),'date','required'); ?>
                <?php portalField('Meeting title (optional)','title','','text','placeholder="Auto-named if left blank"'); ?>
            </div>
            <button <?=$disabled?>><?=portalIcon('clipboard-check')?>Create meeting</button>
        </form>

        <details class="card collapsible-card">
            <summary><span>Attendance scoring rules <small class="muted" style="font-weight:400;margin-left:0.5rem;">Present = 5 pts · Late = <?=h($s['late_points'])?> pts · Absent = <?=h($s['absent_points'])?> pts</small></span></summary>
            <form method="post"><?php portalPost('save_rules',$s);?>
                <p class="muted">Present always earns 5 points. Customize Late, Absent and online attendance minute thresholds below.</p>
                <div class="form-grid">
                    <?php portalField('Late points (out of 5)','late_points',$s['late_points'],'number','step="0.01" min="0" max="5" required'); ?>
                    <?php portalField('Absent points (out of 5)','absent_points',$s['absent_points'],'number','step="0.01" min="0" max="5" required'); ?>
                    <?php portalSelect('Excused attendance','excused_mode',['makeup'=>'Require make-up','exclude'=>'Exclude denominator'],$s['excused_mode']); ?>
                    <?php portalField('Online full credit (min. minutes)','online_full_minutes',$s['online_full_minutes'],'number','step="0.01" min="0"'); ?>
                    <?php portalField('Online partial credit (min. minutes)','online_partial_minutes',$s['online_partial_minutes'],'number','step="0.01" min="0"'); ?>
                </div>
                <button <?=$disabled?>>Save rules</button>
            </form>
        </details>

        <section class="card">
            <div class="panel-heading">
                <div><h2>Attendance records</h2><p class="muted">Select a meeting to view or update attendance.</p></div>
                <span class="pill"><?=count($records)?> meetings</span>
            </div>
            <?php if($records):?>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Attendance meeting records">
                <table>
                    <thead><tr><th>Meeting</th><th>Date</th><th>Criterion</th><th>Action</th></tr></thead>
                    <tbody>
                    <?php foreach($records as $record):
                        $isCurrent=$selected&&(int)$selected['id']===(int)$record['id'];
                    ?>
                    <tr <?=$isCurrent?'style="background:#fff9fa;"':''?>>
                        <td><strong><?=h($record['title'])?></strong><?php if($isCurrent):?> <span class="pill">Open below</span><?php endif;?></td>
                        <td><?=h($record['meeting_date'])?></td>
                        <td><?=h($record['criterion_name'])?></td>
                        <td><a class="faculty-record-action" href="<?=h($base.'&meeting_id='.$record['id'])?>" aria-label="<?=h('Open attendance sheet for '.$record['title'])?>" title="Open attendance sheet"><?=portalIcon('clipboard-check')?></a></td>
                    </tr>
                    <?php endforeach;?>
                    </tbody>
                </table>
            </div>
            <?php else:?>
            <p>No meetings recorded yet. Click "Take attendance today" above or create a meeting for another date.</p>
            <?php endif;?>
        </section>
        <?php
    }

    if(!$selected){
        if($explicit)portalNotice('This meeting is not available in this class. Choose a meeting from the attendance records.','danger');
        return;
    }

    $map=array_column(cbQuery($db,'SELECT * FROM classbook_attendance WHERE meeting_id=?',[$selected['id']])->fetchAll(),null,'enrollment_id');
    $eligible=$members;
    ?>
    <section class="card" id="meeting-attendance-sheet">
        <div class="panel-heading">
            <div>
                <span class="eyebrow">MEETING ATTENDANCE SHEET</span>
                <h2><?=h($selected['title'])?></h2>
                <p class="muted"><?=h($selected['meeting_date'].' · '.$selected['criterion_name'])?></p>
            </div>
            <div class="sheet-summary-pills" id="attendance-summary-badges"></div>
        </div>
        <form method="post">
            <?php portalPost('save_attendance',$s);?>
            <input type="hidden" name="meeting_id" value="<?=(int)$selected['id']?>">
            <div class="sheet-toolbar">
                <div><strong>Everyone starts as Present.</strong> Click <strong>Late</strong>, <strong>Absent</strong> or <strong>Excused</strong> for exceptions, then click Save attendance.</div>
                <button type="button" data-mark-present class="button secondary" id="btn-mark-all-present" <?=$disabled?>><?=portalIcon('check')?>Mark all present</button>
            </div>
            <?php if($eligible):?>
            <div class="table-wrap" tabindex="0" role="region" aria-label="Student attendance sheet">
                <table>
                    <thead><tr><th>Student</th><th>Attendance status</th><th>Imported minutes</th></tr></thead>
                    <tbody>
                    <?php foreach($eligible as $m):
                        $eid=(int)$m['enrollment_id'];
                        $r=$map[$eid]??[];
                        $curAtt=$r['status']??'present';
                    ?>
                    <tr>
                        <td><strong><?=h($m['last_name'].', '.$m['first_name'])?></strong><br><small class="muted"><?=h($m['student_no'])?></small></td>
                        <td>
                            <input type="hidden" data-attendance id="att-select-<?=$eid?>" name="attendance[<?=$eid?>]" value="<?=h($curAtt)?>">
                            <div class="quick-pill-group" role="group" aria-label="<?=h('Attendance for '.$m['last_name'].', '.$m['first_name'])?>">
                                <?php foreach(['present'=>'Present','late'=>'Late','absent'=>'Absent','excused'=>'Excused'] as $stKey=>$stLabel):?>
                                <button type="button" class="quick-pill <?=$curAtt===$stKey?'active-'.$stKey:''?>" data-att-btn="<?=$eid?>" data-att-val="<?=$stKey?>" aria-pressed="<?=$curAtt===$stKey?'true':'false'?>" <?=$disabled?>><?=$stLabel?></button>
                                <?php endforeach;?>
                            </div>
                        </td>
                        <td><?=isset($r['duration_seconds'])?h(round($r['duration_seconds']/60,2)).' mins':'—'?></td>
                    </tr>
                    <?php endforeach;?>
                    </tbody>
                </table>
            </div>
            <script>
            (function(){
                var attInputs = document.querySelectorAll('[data-attendance]');
                function syncAttendanceUI(){
                    var counts = {present:0, late:0, absent:0, excused:0};
                    attInputs.forEach(function(inp){
                        var val = inp.value;
                        if(counts[val] !== undefined) counts[val]++;
                        var eid = inp.id.replace('att-select-', '');
                        document.querySelectorAll('[data-att-btn="' + eid + '"]').forEach(function(btn){
                            var bVal = btn.getAttribute('data-att-val');
                            var active = (bVal === val);
                            btn.classList.remove('active-present','active-late','active-absent','active-excused');
                            if(active) btn.classList.add('active-' + val);
                            btn.setAttribute('aria-pressed', active ? 'true' : 'false');
                        });
                    });
                    var summary = document.getElementById('attendance-summary-badges');
                    if(summary){
                        summary.innerHTML =
                            '<span class="score-status-badge status-scored">Present: ' + counts.present + '</span>' +
                            '<span class="score-status-badge status-not_graded">Late: ' + counts.late + '</span>' +
                            '<span class="score-status-badge status-missing">Absent: ' + counts.absent + '</span>' +
                            '<span class="score-status-badge status-excused">Excused: ' + counts.excused + '</span>';
                    }
                }
                document.querySelectorAll('[data-att-btn]').forEach(function(btn){
                    btn.addEventListener('click', function(){
                        if(btn.disabled) return;
                        var eid = btn.getAttribute('data-att-btn');
                        var inp = document.getElementById('att-select-' + eid);
                        if(inp){
                            inp.value = btn.getAttribute('data-att-val');
                            syncAttendanceUI();
                        }
                    });
                });
                var markAllBtn = document.getElementById('btn-mark-all-present');
                if(markAllBtn){
                    markAllBtn.addEventListener('click', function(){
                        if(markAllBtn.disabled) return;
                        attInputs.forEach(function(inp){ inp.value = 'present'; });
                        syncAttendanceUI();
                    });
                }
                syncAttendanceUI();
            })();
            </script>
            <?php else:?>
            <p>No students were enrolled on this meeting date. Check the student masterlist and enrollment start dates.</p>
            <?php endif;?>
            <div style="margin-top: 1rem;">
                <button <?=$disabled?>><?=portalIcon('check')?>Save attendance</button>
            </div>
        </form>
    </section>
    <?php
}
