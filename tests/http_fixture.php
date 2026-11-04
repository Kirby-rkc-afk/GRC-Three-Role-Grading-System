<?php
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
session_save_path(sys_get_temp_dir());
require_once __DIR__.'/../includes/portal.php';
$name=getenv('GRC_DB_NAME');if(!preg_match('/^grc_revision_http_[a-f0-9]{10}$/',$name))throw new RuntimeException('Test database name required.');
$server=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT,DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
if(($argv[1]??'')==='cleanup'){$server->exec('DROP DATABASE IF EXISTS `'.$name.'`');exit;}
$server->exec('CREATE DATABASE `'.$name.'` CHARACTER SET utf8mb4');$db=getDbConnection();cbInstall($db);$hash=password_hash('Test-only-password-2026',PASSWORD_DEFAULT);
cbQuery($db,'INSERT INTO classbook_admins(username,full_name,password_hash) VALUES(?,?,?)',['test.registrar','Test Registrar',$hash]);
cbQuery($db,'INSERT INTO professors(employee_id,username,full_name,email,password_hash) VALUES(?,?,?,?,?)',['TEST','test.faculty','Test Professor','faculty@example.invalid',$hash]);
cbQuery($db,'INSERT INTO students(student_no,username,first_name,last_name,password_hash) VALUES(?,?,?,?,?)',['TEST-001','test.student','Test','Student',$hash]);
echo "Fixture ready.\n";
