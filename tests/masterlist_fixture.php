<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/seed_fixtures.php';
require_once __DIR__.'/../includes/backup.php';
$name=(string)getenv('GRC_DB_NAME');if(!preg_match('/^grc_revision_masterlist_[a-f0-9]{10}$/',$name))throw new RuntimeException('Isolated test database required.');
$server=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(($argv[1]??'')==='cleanup'){$server->exec('DROP DATABASE IF EXISTS `'.$name.'`');exit;}
$server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$db=getDbConnection();cbInstall($db);seedDemo($db,'GrcDemo!2026');
$manifest=seedMasterlistDemo($db);foreach($manifest['classes'] as $id)if(count(cbMembers($db,$id))!==40)throw new RuntimeException('Expected 40 active students.');
$before=cbBackup($db)['tables'];seedMasterlistDemo($db);if($before!==cbBackup($db)['tables'])throw new RuntimeException('Demo seed must be idempotent.');
echo "40-student classes and idempotency verified.\n";
if(getenv('GRC_REALISTIC_DEMO')==='1'){
    cbQuery($db,'INSERT INTO professors(employee_id,username,full_name,email,password_hash) VALUES(?,?,?,?,?)',['REAL-FACULTY','real.faculty','Verified Faculty','verified@example.invalid',password_hash('Untouched-password-2026',PASSWORD_DEFAULT)]);
    $untouched=cbQuery($db,"SELECT * FROM professors WHERE username='real.faculty'")->fetch();
    $history=$db->query('SELECT * FROM classbook_releases')->fetchAll();
    $m=seedRealisticDemo($db);
    if($untouched!==cbQuery($db,"SELECT * FROM professors WHERE username='real.faculty'")->fetch())throw new RuntimeException('Non-demo account changed.');
    foreach($history as $row)if($row!==cbQuery($db,'SELECT * FROM classbook_releases WHERE id=?',[$row['id']])->fetch())throw new RuntimeException('Historical release changed.');
    foreach($db->query('SELECT subject_code,COUNT(DISTINCT professor_id) AS professors FROM subjects GROUP BY subject_code') as $row)if((int)$row['professors']!==2)throw new RuntimeException('Expected two professors per sample subject.');
    $dupCount=(int)$db->query("SELECT COUNT(*) FROM (SELECT e.student_id,s.subject_code,s.school_year,s.semester FROM enrollments e JOIN subjects s ON s.id=e.subject_id LEFT JOIN classbook_members m ON m.enrollment_id=e.id LEFT JOIN classbook_settings c ON c.subject_id=s.id WHERE COALESCE(m.status,'active')='active' AND COALESCE(c.archived,0)=0 GROUP BY e.student_id,s.subject_code,s.school_year,s.semester HAVING COUNT(*)>1) dup")->fetchColumn();
    if($dupCount>0)throw new RuntimeException('Realistic seed created duplicate subjects for a student in the same term.');
    $before=cbBackup($db)['tables'];seedRealisticDemo($db);if($before!==cbBackup($db)['tables'])throw new RuntimeException('Realistic seed must be idempotent.');
    echo "Realistic cohorts, two professors per subject, preserved accounts/history and rerun safety verified.\n";
}
