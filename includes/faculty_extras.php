<?php
declare(strict_types=1);

function cbFacultySettings(PDO $db,array $s,array $classes,array $criteria,array $classGroups,string $disabled): void {
    $policy=cbPolicy($db,(int)$s['id']);$conversion='';
    foreach(json_decode($policy['conversion_json']??'[]',true)??[] as $band)$conversion.=$band['minimum'].' = '.$band['label']."\n";
    $otherClasses=array_filter($classes,fn($c)=>(int)$c['id']!==(int)$s['id']);
    ?>
    <?php if($otherClasses):?>
    <form method="post" class="card">
        <?php portalPost('copy_setup',$s);?>
        <h2>Copy setup from another class</h2>
        <p class="muted">Reuse criteria weights, passing rate and attendance rules from one of your other classes in one click.</p>
        <div class="form-grid">
            <label class="field">Source class
                <select name="source_id" required>
                    <option value="">Choose class to copy from</option>
                    <?php foreach($otherClasses as $oc):?>
                    <option value="<?=(int)$oc['id']?>"><?=h($oc['subject_code'].' · '.$oc['subject_title'].' ('.$oc['section'].')')?></option>
                    <?php endforeach;?>
                </select>
            </label>
        </div>
        <button <?=$disabled?>><?=portalIcon('clipboard-check')?>Copy setup to this class</button>
    </form>
    <?php endif;?>

    <form method="post" class="card" id="criteria-form">
        <?php portalPost('save_criteria',$s);?>
        <h2>Class criteria &amp; weights</h2>
        <p class="muted">Each grading period must total 100%. To disable an unused criterion, set its weight to 0%.</p>
        <?php foreach($classGroups as $g=>$label):
            $groupCriteria=array_filter($criteria,fn($c)=>$c['group_key']===$g);
            $totalWeight=array_sum(array_column($groupCriteria,'weight'));
        ?>
        <div style="display:flex;justify-content:space-between;align-items:baseline;margin-top:1.5rem;">
            <h3><?=h($label)?></h3>
            <span class="pill <?=abs($totalWeight-100)<0.001?'':'danger'?>" id="weight-total-<?=$g?>">Total: <?=h(round($totalWeight,2))?>%</span>
        </div>
        <div class="table-wrap" tabindex="0" role="region" aria-label="<?=h($label)?> records">
            <table>
                <thead><tr><th>Criterion</th><th>Weight %</th><th>Calculation</th><th>Excused work</th></tr></thead>
                <tbody>
                <?php foreach($groupCriteria as $c):?>
                <tr>
                    <td>
                        <?php portalField('Criterion name','criteria['.$c['id'].'][name]',$c['name'],'text','required maxlength="100"'); ?>
                        <small class="muted"><?=h($c['kind'])?></small>
                    </td>
                    <td>
                        <?php portalField('Weight','criteria['.$c['id'].'][weight]',$c['weight'],'number','step="0.01" min="0" max="100" required class="period-weight-input" data-group="'.$g.'"'); ?>
                    </td>
                    <td>
                        <?php portalSelect('Calculation','criteria['.$c['id'].'][aggregation]',['pooled'=>'Total points','equal'=>'Equal activity percentages'],$c['aggregation']); ?>
                    </td>
                    <td>
                        <?php portalSelect('Excused','criteria['.$c['id'].'][excused_mode]',['makeup'=>'Require make-up','exclude'=>'Exclude denominator'],$c['excused_mode']); ?>
                    </td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>
        <?php endforeach;?>
        <div style="margin-top:1rem;">
            <button <?=$disabled?>><?=portalIcon('check')?>Save criteria</button>
        </div>
    </form>
    <script>
    (function(){
        var weightInputs = document.querySelectorAll('.period-weight-input');
        function updateWeightTotals(){
            var sums = {};
            weightInputs.forEach(function(inp){
                var g = inp.getAttribute('data-group');
                if(sums[g] === undefined) sums[g] = 0;
                var val = parseFloat(inp.value);
                if(!isNaN(val)) sums[g] += val;
            });
            Object.keys(sums).forEach(function(g){
                var badge = document.getElementById('weight-total-' + g);
                if(badge){
                    var rounded = Math.round(sums[g] * 100) / 100;
                    badge.textContent = 'Total: ' + rounded + '%';
                    if(Math.abs(rounded - 100) < 0.001){
                        badge.classList.remove('danger');
                    } else {
                        badge.classList.add('danger');
                    }
                }
            });
        }
        weightInputs.forEach(function(inp){
            inp.addEventListener('input', updateWeightTotals);
        });
    })();
    </script>

    <details class="card collapsible-card">
        <summary><span>Add extra criterion <small class="muted" style="font-weight:400;margin-left:0.5rem;">Optional custom category or shared exam</small></span></summary>
        <form method="post">
            <?php portalPost('add_criterion',$s);?>
            <p class="muted">New criteria start at 0% weight. Major exams reuse one canonical score for the whole subject.</p>
            <div class="form-grid">
                <?php portalSelect('Period','group_key',$classGroups); ?>
                <?php portalSelect('Type','kind',['activity'=>'Activity','attendance'=>'Attendance','prelim_exam'=>'Shared Prelim Exam','midterm_exam'=>'Shared Midterm Exam','final_exam'=>'Shared Final Exam']); ?>
                <?php portalField('Name','name','','text','required maxlength="100" placeholder="e.g. Project, Recitation"'); ?>
            </div>
            <button <?=$disabled?>><?=portalIcon('plus')?>Add criterion</button>
        </form>
    </details>

    <form method="post" class="card">
        <?php portalPost('save_policy',$s);?>
        <h2>Grade formula &amp; passing policy</h2>
        <p class="muted">Pre-configured with standard GRC period shares and transmutation table. Adjust below only if your department uses a custom scale.</p>
        <div class="form-grid">
            <?php portalField('Policy label / reference','policy_label',$policy['policy_label'],'text','required maxlength="150"'); ?>
            <?php if(!cbPeriodOnly($s))portalField('Lecture share % (lab is remainder)','lecture_percent',$policy['lecture_percent'],'number','step="0.01" min="0" max="100" required'); ?>
            <?php portalField('Midterm share % (final is remainder)','midterm_percent',$policy['midterm_percent'],'number','step="0.01" min="0" max="100" required'); ?>
            <?php portalField('Passing percentage','passing_percent',$policy['passing_percent'],'number','step="0.01" min="0" max="100" required'); ?>
        </div>
        <label class="field">Grade conversion (minimum % = grade label, one per line)
            <textarea name="conversion" rows="6" placeholder="0 = 5.00&#10;75 = 3.00&#10;97 = 1.00"><?=h($conversion)?></textarea>
        </label>
        <button <?=$disabled?>><?=portalIcon('check')?>Save grade formula</button>
    </form>

    <details class="card collapsible-card">
        <summary><span>Class details <small class="muted" style="font-weight:400;margin-left:0.5rem;"><?=h($s['subject_code'].' · '.$s['section'].' · '.$s['school_year'])?></small></span></summary>
        <form method="post">
            <?php portalPost('edit_class',$s);?>
            <div class="form-grid">
                <?php foreach(['subject_code'=>'Subject code','subject_title'=>'Subject title','section'=>'Section','school_year'=>'School year'] as $key=>$label)portalField($label,$key,$s[$key],'text','required'); ?>
                <?php portalField('Units','units',$s['units'],'number','min="1" max="12" required'); ?>
                <?php portalSelect('Semester','semester',array_combine(['1st Semester','2nd Semester','Summer'],['1st Semester','2nd Semester','Summer']),$s['semester']); ?>
            </div>
            <?php portalField('Reason for correction','reason','','text','required maxlength="500" placeholder="Reason for updating class information"'); ?>
            <button <?=$disabled?>>Save class details</button>
        </form>
    </details>
    <?php
}

function cbFacultyGrades(PDO $db,array $s,string $disabled): void {
    $computed=cbCompute($db,(int)$s['id']);
    $policy=$computed['policy'];
    $incompleteCount=0;
    $pendingByCriterion=[];
    $groups=cbClassGroups($db,(int)$s['id']);
    foreach($computed['students'] as $r){
        if($r['course']===null)$incompleteCount++;
        foreach($r['evidence']??[] as $cid=>$ev){
            if((float)($ev['weight']??0)>0&&$ev['percentage']===null){
                $pendingByCriterion[$cid]=($pendingByCriterion[$cid]??0)+1;
            }
        }
    }

    portalNotice('Draft grades · '.$policy['policy_label'].' · Midterm '.$policy['midterm_percent'].'% / Final '.(100-$policy['midterm_percent']).'% · Passing '.$policy['passing_percent'].'%.');
    ?>
    <section class="card">
        <div class="panel-heading">
            <div>
                <h2>Computed grades &amp; submission</h2>
                <p class="muted">Grades are computed automatically from your attendance and score sheets.</p>
            </div>
            <span class="pill <?=empty($incompleteCount)?'':'danger'?>"><?=$incompleteCount?$incompleteCount.' students incomplete':'Ready to submit'?></span>
        </div>

        <?php if($incompleteCount):?>
        <div class="notice danger" role="alert">
            <strong>Incomplete grade sheets:</strong> <?=$incompleteCount?> student(s) still have unrecorded work or missing scores on positively weighted criteria.
            <?php if($pendingByCriterion):?>
            <div style="margin-top:0.5rem;display:flex;flex-wrap:wrap;gap:0.5rem;">
                <?php foreach($computed['criteria'] as $c):
                    $cid=(int)$c['id'];
                    if(empty($pendingByCriterion[$cid]))continue;
                    $targetView=$c['kind']==='attendance'?'attendance':'activities';
                    $groupName=$groups[$c['group_key']]??$c['group_key'];
                ?>
                <a class="button secondary" style="font-size:12px;padding:4px 10px;min-height:32px;" href="professor-dashboard.php?view=<?=$targetView?>&amp;subject_id=<?=(int)$s['id']?>"><?=h($groupName.' · '.$c['name'].' ('.$pendingByCriterion[$cid].' pending)')?></a>
                <?php endforeach;?>
            </div>
            <?php endif;?>
        </div>
        <?php else:?>
        <div class="notice success" role="status">
            <strong>Ready:</strong> All active students have complete records. You can now submit this grade sheet to the Registrar.
        </div>
        <?php endif;?>

        <label class="field">Find student<input data-search-table="#grades-table" type="search" placeholder="Student number or name"></label>
        <div class="table-wrap" tabindex="0" role="region" aria-label="Class records">
            <table id="grades-table">
                <thead><tr><th>Student</th><th>Midterm</th><th>Final</th><th>Course</th><th>Action</th></tr></thead>
                <tbody>
                <?php foreach($computed['students'] as $r):?>
                <tr>
                    <td><strong><?=h($r['student']['last_name'].', '.$r['student']['first_name'])?></strong><br><small class="muted"><?=h($r['student']['student_no'])?></small></td>
                    <td><?=portalPeriod($r,'midterm',$policy)?></td>
                    <td><?=portalPeriod($r,'final',$policy)?></td>
                    <td><?=portalGrade($r['course'])?> <?=h($r['converted']??'')?></td>
                    <td><a class="account-settings-action" href="student-review.php?subject_id=<?=(int)$s['id']?>&amp;student=<?=(int)$r['student']['enrollment_id']?>" aria-label="<?=h('Review grades for '.$r['student']['first_name'].' '.$r['student']['last_name'])?>" title="Review student grades"><?=portalIcon('file-check-2')?></a></td>
                </tr>
                <?php endforeach;?>
                </tbody>
            </table>
        </div>

        <div style="display:flex;gap:0.75rem;align-items:center;margin-top:1.5rem;flex-wrap:wrap;">
            <form method="post" style="display:inline;">
                <?php portalPost('submit',$s);?>
                <button <?=$disabled||$incompleteCount?'disabled':''?>><?=portalIcon('shield-check')?>Submit complete grade sheet to Registrar</button>
            </form>
            <button type="button" class="button secondary" onclick="window.print()"><?=portalIcon('file-text')?>Print draft</button>
        </div>
    </section>
    <?php
}

function cbFacultyReports(array $s,string $view): void {
    if(!in_array($view,['grades','roster','attendance','activities','settings'],true))return;
    ?>
    <section class="card">
        <h3>Class reports &amp; exports</h3>
        <p class="muted">Download reports in printable format or CSV spreadsheets.</p>
        <div class="actions">
            <?php foreach(['grades'=>'Grade sheet','attendance'=>'Attendance register','activities'=>'Activity gradebook','roster'=>'Student roster'] as $type=>$label):?>
            <a class="button secondary" href="report.php?subject_id=<?=(int)$s['id']?>&type=<?=$type?>"><?=portalIcon('download')?><?=h($label)?></a>
            <?php endforeach;?>
        </div>
        <div style="margin-top:0.75rem;">
            <a href="report.php?subject_id=<?=(int)$s['id']?>&version=published">View last released grade sheet and release history</a>
        </div>
    </section>
    <?php
}

function cbFacultyExtras(PDO $db,array $s,string $view): void {
    cbFacultyReports($s,$view);
}
