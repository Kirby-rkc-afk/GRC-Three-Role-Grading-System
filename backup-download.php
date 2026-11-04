<?php
declare(strict_types=1);
require_once __DIR__.'/includes/portal.php';
require_once __DIR__.'/includes/backup.php';
$db=portalDb();$u=portalUser($db,'admin');
try{
    if($_SERVER['REQUEST_METHOD']!=='POST')throw new RuntimeException('Use the Registrar backup form.');
    portalCsrf();cbAudit($db,$u,null,'backup_exported',[]);$backup=cbBackup($db);$json=json_encode($backup,JSON_THROW_ON_ERROR|JSON_UNESCAPED_UNICODE);
    header('Content-Type: application/json; charset=utf-8');header('Content-Disposition: attachment; filename="GRC-backup-'.gmdate('Ymd-His').'.json"');header('Cache-Control: no-store');
    echo $json;
}catch(Throwable $e){http_response_code(400);portalStart('Backup unavailable',$u);portalNotice(portalError($e),'danger');portalEnd();}
