<?php
require_once __DIR__.'/includes/portal.php';
$ready=false;$error='';
try{$db=getDbConnection();$ready=cbInstalled($db);if($ready&&cbQuery($db,'SELECT COUNT(*) FROM classbook_admins')->fetchColumn())portalRedirect('index.php');}catch(Throwable $e){$db=null;}
if($_SERVER['REQUEST_METHOD']==='POST')try{
    portalCsrf();$name=cbText($_POST['full_name']??'');$username=cbText($_POST['username']??'',80);$password=(string)($_POST['password']??'');
    cbPassword($password,(string)($_POST['confirm_password']??''));
    if(!$db){$server=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);if(!preg_match('/^[a-zA-Z0-9_]+$/',DB_NAME))throw new RuntimeException('Invalid database name.');$server->exec('CREATE DATABASE IF NOT EXISTS `'.DB_NAME.'` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');$db=getDbConnection();}
    cbInstall($db);$db->beginTransaction();
    if(cbQuery($db,'SELECT COUNT(*) FROM classbook_admins')->fetchColumn())throw new RuntimeException('Setup has already been completed.');
    cbUniqueIdentifiers($db,'admin',['username'=>$username]);
    $publicId=cbNextAccountId($db,'admin');
    cbQuery($db,'INSERT INTO classbook_admins(id,username,full_name,password_hash) VALUES(1,?,?,?)',[$username,$name,password_hash($password,PASSWORD_DEFAULT)]);cbStoreAccountId($db,'admin',1,$publicId);$db->commit();portalRedirect('index.php','Installation complete. Use the shared sign-in page with your Registrar account.');
}catch(Throwable $e){if($db&&$db->inTransaction())$db->rollBack();$error=portalError($e);}
portalStart('Initial setup');portalHeading('Set up your grading workspace','Create the first Registrar account. Existing academic records are preserved; no demo accounts are installed.');if($error)portalNotice($error,'danger');
?><section class="card narrow"><form method="post"><?=csrfField()?><?php portalField('Registrar name','full_name','','text','required maxlength="150"');portalField('Username','username','','text','required maxlength="80" autocomplete="username"');portalField('Password · at least 12 characters','password','','password','required minlength="12" autocomplete="new-password"');portalField('Confirm password','confirm_password','','password','required minlength="12" autocomplete="new-password"');?><button>Initialize and create Registrar account</button></form><p class="muted">This step creates the missing database/tables and adds the classroom-record tables. Complete initial setup on this local installation before sharing access.</p></section><?php portalEnd();
