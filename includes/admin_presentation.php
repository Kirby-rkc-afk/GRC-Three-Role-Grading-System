<?php
declare(strict_types=1);

function cbAdminDirectoryStyles(): void { ?><link rel="stylesheet" href="assets/css/admin-directory.css?v=<?=filemtime(__DIR__.'/../assets/css/admin-directory.css')?>"><?php }

function cbAdminStatus(string $status): string {
    $label=match($status){'pending'=>'Pending approval','active'=>'Active','inactive'=>'Deactivated','draft'=>'Draft','submitted'=>'For review','released'=>'Released',default=>ucfirst($status)};
    $tone=match($status){'active','released'=>'success','pending','submitted'=>'warning',default=>'neutral'};
    return '<span class="status-badge '.$tone.'"><span class="status-dot" aria-hidden="true"></span>'.h($label).'</span>';
}
function cbAdminEmpty(string $icon,string $title,string $description,string $url='',string $action=''): void {?>
    <div class="admin-empty"><span class="empty-icon"><?=portalIcon($icon)?></span><h3><?=h($title)?></h3><p><?=h($description)?></p><?php if($url):?><a class="button secondary" href="<?=h($url)?>"><?=h($action)?><?=portalIcon('arrow-right')?></a><?php endif;?></div>
<?php }
function cbAdminActionLabel(string $action): string {
    return match($action){'signed_in'=>'Signed in','create_account'=>'Created an account','edit_account'=>'Updated account details','approve','activate'=>'Approved account access','deactivate'=>'Deactivated account','issue_code'=>'Issued an activation or reset code','release'=>'Released grades','return'=>'Returned grades for correction','reassign'=>'Reassigned a class','archive_class'=>'Archived a class','unarchive_class'=>'Restored a class','password_changed'=>'Changed password','faculty_requested'=>'Requested faculty access',default=>ucfirst(str_replace('_',' ',$action))};
}
function cbAdminAuditRows(PDO $db,string $where='',array $args=[],int $limit=5,int $offset=0): array {
    return cbQuery($db,"SELECT a.*,CASE a.actor_role WHEN 'admin' THEN r.full_name WHEN 'professor' THEN p.full_name WHEN 'student' THEN CONCAT(s.first_name,' ',s.last_name) ELSE NULL END AS actor_name FROM classbook_audit a LEFT JOIN classbook_admins r ON a.actor_role='admin' AND r.id=a.actor_id LEFT JOIN professors p ON a.actor_role='professor' AND p.id=a.actor_id LEFT JOIN students s ON a.actor_role='student' AND s.id=a.actor_id ".$where." ORDER BY a.id DESC LIMIT ".$limit.' OFFSET '.$offset,$args)->fetchAll();
}
function cbAdminDashboard(PDO $db,array $u): void {
    cbAdminDirectoryStyles();
    $totals=[];$pending=0;$pendingRole='professor';$pendingCounts=[];
    foreach(['student'=>'students','professor'=>'professors','admin'=>'classbook_admins'] as $role=>$table){
        $totals[$role]=(int)$db->query('SELECT COUNT(*) FROM '.$table)->fetchColumn();
        $pendingCounts[$role]=(int)cbQuery($db,"SELECT COUNT(*) FROM $table a JOIN classbook_access x ON x.role=? AND x.account_id=a.id WHERE x.status='pending'",[$role])->fetchColumn();
        $pending+=$pendingCounts[$role];if($pendingCounts[$role])$pendingRole=$role;
    }
    $counts=[];foreach($db->query('SELECT state,COUNT(*) AS total FROM classbook_settings WHERE archived=0 GROUP BY state')->fetchAll() as $c)$counts[$c['state']]=(int)$c['total'];
    $classTotal=(int)$db->query('SELECT COUNT(*) FROM subjects')->fetchColumn();
    $review=$counts['submitted']??0;$recent=cbAdminAuditRows($db);
    ?>
    <div class="admin-dashboard"><div class="admin-page-top"><div><span class="admin-kicker">ACADEMIC ADMINISTRATION</span><h1>Administrator dashboard</h1><p>Welcome back, <?=h($u['full_name'])?>. Here is what needs your attention.</p></div><div class="dashboard-actions"><span class="date-label"><?=h(date('d M Y'))?></span><a class="button secondary" href="?view=classes"><?=portalIcon('book-open')?>Browse classes</a></div></div>
    <div class="admin-metrics">
    <?php foreach([
        ['users','Students',$totals['student'],'Registered student accounts','?view=accounts&role=student'],
        ['graduation-cap','Faculty',$totals['professor'],'Registered professor accounts','?view=accounts&role=professor'],
        ['book-open','Classes',$classTotal,'All class records','?view=classes'],
        ['file-check-2','Grade sheets for review',$review,'Awaiting your verification','?view=classes&state=submitted'],
    ] as [$icon,$label,$value,$help,$url]):?><a class="stat-card" href="<?=h($url)?>"><div class="stat-top"><span><?=h($label)?></span><span class="stat-icon"><?=portalIcon($icon)?></span></div><strong><?=$value?></strong><small><?=h($help)?></small></a><?php endforeach;?>
    </div>
    <div class="admin-dashboard-grid"><section class="admin-panel"><div class="panel-heading"><div><h2>Needs attention</h2><p>Start with requests waiting for a decision.</p></div><span class="count-tag"><?=$pending+$review?></span></div>
        <a class="task-row" href="?view=accounts&amp;status=pending&amp;role=<?=h($pendingRole)?>"><span class="task-icon"><?=portalIcon('user-plus')?></span><span><strong>Account approvals</strong><small><?=$pending?> pending · Verify identities and approve access.</small></span><?=portalIcon('chevron-right')?></a>
        <?php if($pending):?><div class="pending-links"><?php foreach($pendingCounts as $role=>$count)if($count):?><a href="?view=accounts&amp;status=pending&amp;role=<?=h($role)?>"><?=h(portalRoleLabel($role))?>: <?=$count?></a><?php endif;?></div><?php endif;?>
        <a class="task-row" href="?view=classes&state=submitted"><span class="task-icon"><?=portalIcon('clipboard-check')?></span><span><strong>Grade verification</strong><small><?=$review?> waiting · Review evidence before releasing results.</small></span><?=portalIcon('chevron-right')?></a>
        <?php if(!$classTotal):?><div class="getting-started"><span class="mini-label">GETTING STARTED</span><h3>Build your academic workspace</h3><p>Create faculty and student accounts first. Approved professors can then create classes and enroll students.</p><a class="button" href="?view=accounts&role=professor&create=1"><?=portalIcon('user-plus')?>Add a faculty account</a></div><?php else:?><div class="getting-started"><h3>Keep records up to date</h3><p>Manage student identities and faculty access from one place.</p><a class="button secondary" href="?view=accounts">Manage accounts<?=portalIcon('arrow-right')?></a></div><?php endif;?>
    </section><section class="admin-panel"><div class="panel-heading"><div><h2>Recent activity</h2><p>Latest recorded actions in your system.</p></div><a class="text-link" href="?view=audit">View all<?=portalIcon('arrow-right')?></a></div>
        <?php if(!$recent):cbAdminEmpty('history','No activity yet','Account and academic record actions will appear here.');else:?><ul class="activity-list"><?php foreach($recent as $a):?><li><span class="activity-marker"><?=portalIcon('history')?></span><div><strong><?=h(cbAdminActionLabel($a['action']))?></strong><p><?=h($a['actor_name']?:portalRoleLabel($a['actor_role']).' #'.$a['actor_id'])?></p><time><?=h(date('d M Y · g:i a',strtotime($a['created_at'])))?></time></div></li><?php endforeach;?></ul><?php endif;?>
    </section></div>
    <section class="admin-panel workflow-panel"><div class="panel-heading"><div><h2>How grades reach students</h2><p>Your role is to verify and release the records submitted by professors.</p></div></div><ol class="grade-workflow"><li><span>1</span><div><strong>Professor prepares</strong><p>Records attendance and scores; the system computes grades.</p></div></li><li><span>2</span><div><strong>Administrator verifies</strong><p>Reviews submitted grade sheets and supporting records.</p></div></li><li><span>3</span><div><strong>Students view results</strong><p>Released grades become available in each student's account.</p></div></li></ol></section></div>
    <?php
}
function cbAdminMaintenance(): void {?>
    <div class="admin-dashboard-grid"><section class="admin-panel"><div class="feature-icon"><?=portalIcon('database-backup')?></div><h2>Save a recovery backup</h2><p>Download a copy of your academic records so an authorized IT administrator can recover the system if needed.</p><ul class="plain-checklist"><li><?=portalIcon('circle-check')?>Student, faculty and administrator accounts</li><li><?=portalIcon('circle-check')?>Class records, scores and release history</li><li><?=portalIcon('circle-check')?>Activity history and grading configurations</li></ul><div class="security-note"><?=portalIcon('shield-check')?><p>Contains private records and protected account data. Keep the file in a secure location outside this website.</p></div><form method="post" action="backup-download.php"><?=csrfField()?><button><?=portalIcon('download')?>Download backup</button></form></section>
    <section class="admin-panel"><div class="feature-icon"><?=portalIcon('key-round')?></div><h2>Recover account access</h2><p>After verifying the account owner, issue a private activation or reset code from Accounts.</p><a class="button secondary" href="?view=accounts">Open accounts<?=portalIcon('arrow-right')?></a><div class="section-divider"></div><h3>Administrator continuity</h3><p>A second verified administrator can help recover access when the primary account is unavailable.</p><a class="text-link" href="?view=accounts&role=admin">Manage administrators<?=portalIcon('arrow-right')?></a><details class="recovery-details"><summary>How database restoration works</summary><p>An authorized IT administrator uses the local recovery tool with this backup. It restores to a new database and refuses to overwrite existing records. Follow the recovery instructions supplied with the project.</p><p>Activation codes and login sessions are excluded from backups.</p></details></section></div>
<?php }
