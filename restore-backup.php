<?php
declare(strict_types=1);
if(PHP_SAPI!=='cli'){http_response_code(404);exit;}
require_once __DIR__.'/includes/db.php';
require_once __DIR__.'/includes/functions.php';
require_once __DIR__.'/includes/classbook.php';
require_once __DIR__.'/includes/backup.php';
try{
    $path=$argv[1]??'';$target=$argv[2]??'';
    if(!$path||!preg_match('/^[a-zA-Z0-9_]{1,64}$/',$target)||$target===DB_NAME)throw new RuntimeException('Usage: php restore-backup.php backup.json NEW_DATABASE_NAME. Target must differ from the configured live database.');
    $bytes=file_get_contents($path);if($bytes===false)throw new RuntimeException('Cannot read backup.');$backup=json_decode($bytes,true,512,JSON_THROW_ON_ERROR);
    if(($backup['format']??'')!=='grc-classbook-backup')throw new RuntimeException('Not a GRC backup.');
    $server=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    // CREATE without IF NOT EXISTS intentionally refuses every existing database.
    $server->exec('CREATE DATABASE '.chr(96).$target.chr(96).' CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci');
    $db=new PDO('mysql:host='.DB_HOST.';port='.DB_PORT.';dbname='.$target.';charset=utf8mb4',DB_USER,DB_PASS,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC,PDO::ATTR_EMULATE_PREPARES=>false]);
    cbInstall($db);cbRestore($db,$backup);echo "Backup restored into the new database. Verify it before changing the live database configuration.\n";
}catch(Throwable $e){fwrite(STDERR,'Restore stopped: '.$e->getMessage()."\n");exit(1);}
