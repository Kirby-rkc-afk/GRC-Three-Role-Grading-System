<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/../database/seed_school_emails.php';require_once __DIR__.'/../includes/backup.php';
$name='grc_revision_email_'.bin2hex(random_bytes(5));$checks=0;
function checkEmail(bool $ok,string $why):void{global $checks;if(!$ok)throw new RuntimeException($why);$checks++;}
$server=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
try{
    checkEmail(cbDemoEmailLocal(str_repeat('a',49).' '.str_repeat('b',20))===str_repeat('a',49),'Long names do not leave invalid trailing dots');
    $server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$db=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$name.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);cbInstall($db);$hash=password_hash('Demo-email-password-2026',PASSWORD_DEFAULT);
    cbQuery($db,'INSERT INTO professors(employee_id,username,full_name,email,password_hash) VALUES(?,?,?,?,?)',['REAL-P','existing.owner','Verified Faculty','alyssa.joy.aquino@grc.edu.ph',$hash]);$original=cbQuery($db,'SELECT * FROM professors WHERE id=1')->fetch();
    $accounts=[];
    foreach([['demo.student01','demo.student01@example.invalid'],['demo.student02','demo.student02@example.invalid'],['demo.student03','owner.chosen@grc.edu.ph']] as [$username,$email]){
        cbQuery($db,'INSERT INTO students(student_no,username,first_name,last_name,email,password_hash) VALUES(?,?,?,?,?,?)',[$username,$username,'Alyssa Joy','Aquino',$email,$hash]);$accounts[]=['id'=>(int)$db->lastInsertId(),'role'=>'student','username'=>$username];
    }
    cbQuery($db,'INSERT INTO professors(employee_id,username,full_name,email,password_hash) VALUES(?,?,?,?,?)',['DEMO-P','demo.faculty.ccs','Prof. Adrian Miguel Dela Cruz','demo.faculty.ccs@example.invalid',$hash]);$accounts[]=['role'=>'professor','id'=>2,'username'=>'demo.faculty.ccs'];
    cbAudit($db,['role'=>'system','id'=>0],null,'demo_seed_loaded',['accounts'=>$accounts]);
    $result=cbSeedSchoolEmails($db);checkEmail(count($result['changed'])===3&&$result['skipped']===1,'Scoped sample migration');
    $emails=cbQuery($db,'SELECT email FROM students ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
    checkEmail($emails[0]==='alyssa.joy.aquino.2@grc.edu.ph'&&$emails[1]==='alyssa.joy.aquino.3@grc.edu.ph','Unique allocation across roles and duplicate names');
    checkEmail($emails[2]==='owner.chosen@grc.edu.ph','Edited demo email preserved');
    checkEmail($original===cbQuery($db,'SELECT * FROM professors WHERE id=1')->fetch(),'Non-demo account unchanged');
    checkEmail(cbQuery($db,'SELECT email FROM professors WHERE id=2')->fetchColumn()==='adrian.miguel.dela.cruz@grc.edu.ph','Faculty honorific removed');
    foreach($result['changed'] as $a)checkEmail(filter_var($a['email'],FILTER_VALIDATE_EMAIL)!==false,'Valid email syntax');
    checkEmail(cbResolveLogin($db,'demo.student01','Demo-email-password-2026')['role']==='student','Existing username login unchanged');
    checkEmail(cbResolveLogin($db,$emails[0],'Demo-email-password-2026')['role']==='student','New email login works');
    $before=cbBackup($db)['tables'];cbSeedSchoolEmails($db);checkEmail($before===cbBackup($db)['tables'],'Rerun preserves rows and edits');
    checkEmail($result['mailboxes_verified']===false,'Does not falsely claim mailbox verification');
    echo $checks." school-email checks passed.\n";
}finally{$server->exec('DROP DATABASE IF EXISTS `'.$name.'`');}
